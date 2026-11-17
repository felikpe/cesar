<?php
declare(strict_types=1);

class BiometriaErro extends RuntimeException
{
    public int $status;
    public string $codigo;

    public function __construct(string $codigo, string $mensagem, int $status = 422)
    {
        parent::__construct($mensagem);
        $this->status = $status;
        $this->codigo = $codigo;
    }
}

function biometria_inteiro($valor, string $campo, int $minimo = 1, int $maximo = 2147483647): int
{
    if ((!is_int($valor) && !is_string($valor)) || !preg_match('/\A\d{1,10}\z/D', (string) $valor)) {
        throw new BiometriaErro('DADOS_INVALIDOS', 'Informe um valor inteiro válido para ' . $campo . '.');
    }
    $numero = filter_var($valor, FILTER_VALIDATE_INT);
    if ($numero === false || $numero < $minimo || $numero > $maximo) {
        throw new BiometriaErro('DADOS_INVALIDOS', 'Valor fora do intervalo permitido para ' . $campo . '.');
    }
    return $numero;
}

function biometria_dispositivo(array $dados): string
{
    $dispositivo = $dados['device'] ?? null;
    if (!is_string($dispositivo) || !preg_match('/\A[A-Za-z0-9:_-]{1,64}\z/D', $dispositivo)) {
        throw new BiometriaErro('DISPOSITIVO_INVALIDO', 'Identificação do ESP32 inválida. Conecte novamente o leitor.');
    }
    return $dispositivo;
}

function biometria_sessao_hash(): string
{
    if (!isset($_SESSION['biometria_chave'])) {
        $_SESSION['biometria_chave'] = bin2hex(random_bytes(32));
    }
    return hash('sha256', $_SESSION['biometria_chave']);
}

function biometria_bloquear_dispositivo(PDO $pdo, string $dispositivo): void
{
    $stmt = $pdo->query('SELECT IDENTIFICADOR FROM BIOMETRIA_DISPOSITIVO WHERE ID = 1 FOR UPDATE');
    $atual = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$atual) {
        throw new BiometriaErro('MIGRACAO_PENDENTE', 'Execute sql/schema.sql no banco do sistema.', 503);
    }
    if ($atual['IDENTIFICADOR'] === null) {
        $stmt = $pdo->prepare('UPDATE BIOMETRIA_DISPOSITIVO SET IDENTIFICADOR = :dispositivo WHERE ID = 1');
        $stmt->execute([':dispositivo' => $dispositivo]);
        registrar_log($pdo, (int) $_SESSION['id_usuario'], 'BIOMETRIA_DISPOSITIVO_VINCULADO', 'Leitor USB vinculado: ' . $dispositivo . '.');
    } elseif (!hash_equals((string) $atual['IDENTIFICADOR'], $dispositivo)) {
        throw new BiometriaErro('OUTRO_DISPOSITIVO', 'Este ESP32 é diferente do leitor vinculado ao sistema. Use o leitor original.', 409);
    }
}

function biometria_pessoa(PDO $pdo, int $id, bool $bloquear = false): array
{
    $stmt = $pdo->prepare('SELECT ID_ATIRADOR, NOME, NOME_GUERRA, RA, ID_ARMAMENTO, ID_DIGITAL, ATIVO
        FROM ATIRADOR WHERE ID_ATIRADOR = :id' . ($bloquear ? ' FOR UPDATE' : ''));
    $stmt->execute([':id' => $id]);
    $pessoa = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pessoa) {
        throw new BiometriaErro('PESSOA_NAO_ENCONTRADA', 'Atirador não encontrado. Salve o cadastro antes de cadastrar a digital.', 404);
    }
    if ((int) $pessoa['ATIVO'] !== 1) {
        throw new BiometriaErro('PESSOA_INATIVA', 'O atirador está inativo.', 409);
    }
    return $pessoa;
}

function biometria_pessoa_publica(array $pessoa): array
{
    return [
        'id' => (int) $pessoa['ID_ATIRADOR'],
        'nome' => (string) $pessoa['NOME'],
        'nome_guerra' => (string) $pessoa['NOME_GUERRA'],
        'ra' => (string) $pessoa['RA'],
        'id_armamento' => $pessoa['ID_ARMAMENTO'] === null ? null : (int) $pessoa['ID_ARMAMENTO'],
        'id_digital' => $pessoa['ID_DIGITAL'] === null ? null : (int) $pessoa['ID_DIGITAL'],
    ];
}

function biometria_criar_reserva(PDO $pdo, int $pessoaId, int $slot): array
{
    $token = bin2hex(random_bytes(32));
    $expira = time() + 300;
    $stmt = $pdo->prepare('UPDATE BIOMETRIA_DISPOSITIVO SET
        RESERVA_TOKEN_HASH = :token, RESERVA_SESSAO_HASH = :sessao,
        RESERVA_USUARIO = :usuario, RESERVA_ATIRADOR = :pessoa,
        RESERVA_DIGITAL = :slot, RESERVA_EXPIRA_EM = :expira, RESERVA_CONCLUIDA = 0 WHERE ID = 1');
    $stmt->execute([
        ':token' => hash('sha256', $token),
        ':sessao' => biometria_sessao_hash(),
        ':usuario' => (int) $_SESSION['id_usuario'],
        ':pessoa' => $pessoaId,
        ':slot' => $slot,
        ':expira' => gmdate('Y-m-d H:i:s', $expira),
    ]);
    return ['token' => $token, 'slot' => $slot, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expira), 'pessoa' => $pessoaId];
}

function biometria_reservar(PDO $pdo, array $dados): array
{
    $pessoaId = biometria_inteiro($dados['pessoa'] ?? null, 'atirador');
    $capacidade = biometria_inteiro($dados['capacity'] ?? null, 'capacidade', 2, 1024);
    $dispositivo = biometria_dispositivo($dados);
    $ocupados = $dados['occupied'] ?? null;
    if (!is_array($ocupados) || count($ocupados) > $capacidade) {
        throw new BiometriaErro('DADOS_INVALIDOS', 'A lista de posições ocupadas no leitor é inválida. Consulte o leitor novamente.');
    }
    $usados = [0 => true];
    foreach ($ocupados as $slot) {
        $usados[biometria_inteiro($slot, 'posição ocupada', 0, $capacidade - 1)] = true;
    }

    $pdo->beginTransaction();
    try {
        biometria_bloquear_dispositivo($pdo, $dispositivo);
        $limpeza = $pdo->prepare('UPDATE BIOMETRIA_DISPOSITIVO SET RESERVA_TOKEN_HASH = NULL, RESERVA_ATIRADOR = NULL, RESERVA_DIGITAL = NULL WHERE ID = 1 AND RESERVA_EXPIRA_EM <= :agora');
        $limpeza->execute([':agora' => gmdate('Y-m-d H:i:s')]);
        $pessoa = biometria_pessoa($pdo, $pessoaId, true);
        if ($pessoa['ID_DIGITAL'] !== null) {
            throw new BiometriaErro('DIGITAL_JA_VINCULADA', 'Este atirador já possui uma digital vinculada. A digital existente não será substituída.', 409);
        }
        $stmt = $pdo->query('SELECT RESERVA_ATIRADOR FROM BIOMETRIA_DISPOSITIVO WHERE ID = 1 AND RESERVA_TOKEN_HASH IS NOT NULL AND RESERVA_CONCLUIDA = 0');
        if ($stmt->fetchColumn()) {
            throw new BiometriaErro('CADASTRO_EM_ANDAMENTO', 'Já há um cadastro de digital em andamento neste leitor. Aguarde ou cancele a operação anterior.', 409);
        }
        $stmt = $pdo->prepare('SELECT ID_DIGITAL FROM ATIRADOR WHERE ID_DIGITAL BETWEEN 1 AND :limite');
        $stmt->execute([':limite' => $capacidade - 1]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $slot) {
            $usados[(int) $slot] = true;
        }
        foreach ($pdo->query('SELECT RESERVA_DIGITAL FROM BIOMETRIA_DISPOSITIVO WHERE RESERVA_TOKEN_HASH IS NOT NULL AND RESERVA_CONCLUIDA = 0')->fetchAll(PDO::FETCH_COLUMN) as $slot) {
            $usados[(int) $slot] = true;
        }
        $escolhido = null;
        for ($slot = 1; $slot < $capacidade; $slot++) {
            if (!isset($usados[$slot])) {
                $escolhido = $slot;
                break;
            }
        }
        if ($escolhido === null) {
            throw new BiometriaErro('LEITOR_CHEIO', 'Não há posição livre no leitor para uma nova digital.', 409);
        }
        $resultado = biometria_criar_reserva($pdo, $pessoaId, $escolhido);
        $pdo->commit();
        return $resultado;
    } catch (Throwable $erro) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $erro;
    }
}

function biometria_recuperar_reserva(PDO $pdo, array $dados): array
{
    $pessoaId = biometria_inteiro($dados['pessoa'] ?? null, 'atirador');
    $capacidade = biometria_inteiro($dados['capacity'] ?? null, 'capacidade', 2, 1024);
    $slot = biometria_inteiro($dados['slot'] ?? null, 'posição da digital', 1, $capacidade - 1);
    $dispositivo = biometria_dispositivo($dados);
    $ocupados = $dados['occupied'] ?? null;
    if (!is_array($ocupados) || count($ocupados) > $capacidade) {
        throw new BiometriaErro('DADOS_INVALIDOS', 'A lista de posições ocupadas no leitor é inválida. Consulte o leitor novamente.');
    }
    $presente = false;
    foreach ($ocupados as $ocupado) {
        if (biometria_inteiro($ocupado, 'posição ocupada', 0, $capacidade - 1) === $slot) {
            $presente = true;
        }
    }
    if (!$presente) {
        throw new BiometriaErro('DIGITAL_NAO_ARMAZENADA', 'Esta posição não consta no inventário do leitor. Nenhuma reserva será recuperada.', 409);
    }

    $pdo->beginTransaction();
    try {
        biometria_bloquear_dispositivo($pdo, $dispositivo);
        $limpeza = $pdo->prepare('UPDATE BIOMETRIA_DISPOSITIVO SET RESERVA_TOKEN_HASH = NULL, RESERVA_ATIRADOR = NULL, RESERVA_DIGITAL = NULL WHERE ID = 1 AND RESERVA_EXPIRA_EM <= :agora');
        $limpeza->execute([':agora' => gmdate('Y-m-d H:i:s')]);
        $pessoa = biometria_pessoa($pdo, $pessoaId, true);
        if ($pessoa['ID_DIGITAL'] !== null) {
            throw new BiometriaErro('DIGITAL_JA_VINCULADA', 'Este atirador já possui uma digital vinculada. A digital existente não será substituída.', 409);
        }
        $stmt = $pdo->prepare('SELECT ID_ATIRADOR FROM ATIRADOR WHERE ID_DIGITAL = :slot');
        $stmt->execute([':slot' => $slot]);
        if ($stmt->fetchColumn()) {
            throw new BiometriaErro('POSICAO_JA_VINCULADA', 'Esta posição já pertence a outro atirador.', 409);
        }
        $stmt = $pdo->query('SELECT RESERVA_ATIRADOR FROM BIOMETRIA_DISPOSITIVO WHERE ID = 1 AND RESERVA_TOKEN_HASH IS NOT NULL AND RESERVA_CONCLUIDA = 0');
        if ($stmt->fetchColumn()) {
            throw new BiometriaErro('CADASTRO_EM_ANDAMENTO', 'O atirador ou esta posição possui uma reserva ativa. Aguarde o prazo ou retome a sessão que iniciou o cadastro.', 409);
        }
        $resultado = biometria_criar_reserva($pdo, $pessoaId, $slot);
        registrar_log($pdo, (int) $_SESSION['id_usuario'], 'BIOMETRIA_RECUPERACAO_INICIADA', 'Recuperação da digital ' . $slot . ' iniciada para o atirador de ID ' . $pessoaId . '.');
        $pdo->commit();
        return $resultado;
    } catch (Throwable $erro) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $erro;
    }
}

function biometria_reserva(PDO $pdo, array $dados): array
{
    $token = $dados['token'] ?? null;
    if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
        throw new BiometriaErro('RESERVA_INVALIDA', 'A reserva da digital é inválida.', 409);
    }
    $stmt = $pdo->prepare('SELECT RESERVA_ATIRADOR AS ID_ATIRADOR, RESERVA_DIGITAL AS ID_DIGITAL, RESERVA_EXPIRA_EM AS EXPIRA_EM, RESERVA_CONCLUIDA AS CONCLUIDA FROM BIOMETRIA_DISPOSITIVO
        WHERE ID = 1 AND RESERVA_TOKEN_HASH = :token AND RESERVA_SESSAO_HASH = :sessao AND RESERVA_USUARIO = :usuario FOR UPDATE');
    $stmt->execute([
        ':token' => hash('sha256', $token),
        ':sessao' => biometria_sessao_hash(),
        ':usuario' => (int) $_SESSION['id_usuario'],
    ]);
    $reserva = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$reserva || (string) $reserva['EXPIRA_EM'] <= gmdate('Y-m-d H:i:s')) {
        throw new BiometriaErro('RESERVA_EXPIRADA', 'A reserva da digital expirou. Consulte o leitor antes de reiniciar o cadastro.', 409);
    }
    $slot = biometria_inteiro($dados['slot'] ?? null, 'posição da digital', 1, 1023);
    if ((int) $reserva['ID_DIGITAL'] !== $slot) {
        throw new BiometriaErro('RESERVA_DIVERGENTE', 'A posição informada não corresponde à reserva.', 409);
    }
    return $reserva;
}

function biometria_finalizar(PDO $pdo, array $dados, string $acao): array
{
    $dispositivo = biometria_dispositivo($dados);
    $pdo->beginTransaction();
    try {
        biometria_bloquear_dispositivo($pdo, $dispositivo);
        $reserva = biometria_reserva($pdo, $dados);
        $pessoa = biometria_pessoa($pdo, (int) $reserva['ID_ATIRADOR'], true);
        $slot = (int) $reserva['ID_DIGITAL'];
        if ($acao === 'cancelar_reserva') {
            if ((int) $reserva['CONCLUIDA'] === 1) {
                throw new BiometriaErro('CADASTRO_JA_CONCLUIDO', 'A digital já foi vinculada. A reserva não pode ser cancelada.', 409);
            }
            $stmt = $pdo->prepare('UPDATE BIOMETRIA_DISPOSITIVO SET RESERVA_TOKEN_HASH = NULL, RESERVA_ATIRADOR = NULL, RESERVA_DIGITAL = NULL WHERE ID = 1 AND RESERVA_TOKEN_HASH = :token');
            $stmt->execute([':token' => hash('sha256', $dados['token'])]);
            $pdo->commit();
            return ['cancelada' => true, 'slot' => $slot];
        }
        if ($acao === 'estado_reserva') {
            $pdo->commit();
            return ['estado' => (int) $reserva['CONCLUIDA'] === 1 ? 'concluida' : 'reservada', 'slot' => $slot, 'pessoa' => biometria_pessoa_publica($pessoa)];
        }
        if ((int) $reserva['CONCLUIDA'] === 1) {
            if ((int) $pessoa['ID_DIGITAL'] !== $slot) {
                throw new BiometriaErro('VINCULO_ALTERADO', 'O vínculo da digital foi alterado após a conclusão.', 409);
            }
        } else {
            if ($pessoa['ID_DIGITAL'] !== null) {
                throw new BiometriaErro('DIGITAL_JA_VINCULADA', 'O cadastro do atirador foi alterado durante a captura. Nenhum vínculo será substituído.', 409);
            }
            $stmt = $pdo->prepare('SELECT ID_ATIRADOR FROM ATIRADOR WHERE ID_DIGITAL = :slot');
            $stmt->execute([':slot' => $slot]);
            if ($stmt->fetchColumn()) {
                throw new BiometriaErro('POSICAO_JA_VINCULADA', 'Esta posição já pertence a outro atirador.', 409);
            }
            $stmt = $pdo->prepare('UPDATE ATIRADOR SET ID_DIGITAL = :slot WHERE ID_ATIRADOR = :pessoa');
            $stmt->execute([':slot' => $slot, ':pessoa' => (int) $reserva['ID_ATIRADOR']]);
            $stmt = $pdo->prepare('UPDATE BIOMETRIA_DISPOSITIVO SET RESERVA_CONCLUIDA = 1 WHERE ID = 1 AND RESERVA_TOKEN_HASH = :token');
            $stmt->execute([':token' => hash('sha256', $dados['token'])]);
            registrar_log($pdo, (int) $_SESSION['id_usuario'], 'BIOMETRIA_CADASTRADA', 'Digital ' . $slot . ' vinculada ao atirador de ID ' . (int) $reserva['ID_ATIRADOR'] . '.');
            $pessoa['ID_DIGITAL'] = $slot;
        }
        $pdo->commit();
        return ['pessoa' => biometria_pessoa_publica($pessoa), 'slot' => $slot];
    } catch (Throwable $erro) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $erro;
    }
}

function biometria_identificar(PDO $pdo, array $dados): array
{
    $dispositivo = biometria_dispositivo($dados);
    $slot = biometria_inteiro($dados['slot'] ?? null, 'posição da digital', 1, 1023);
    $pdo->beginTransaction();
    try {
        biometria_bloquear_dispositivo($pdo, $dispositivo);
        $stmt = $pdo->prepare('SELECT ID_ATIRADOR FROM ATIRADOR WHERE ID_DIGITAL = :slot');
        $stmt->execute([':slot' => $slot]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            throw new BiometriaErro('DIGITAL_SEM_VINCULO', 'A digital foi reconhecida pelo leitor, mas não está vinculada a um atirador no sistema.', 404);
        }
        $pessoa = biometria_pessoa($pdo, (int) $id);
        $pdo->commit();
        return ['pessoa' => biometria_pessoa_publica($pessoa)];
    } catch (Throwable $erro) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $erro;
    }
}
