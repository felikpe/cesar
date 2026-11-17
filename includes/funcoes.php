<?php
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/sessao.php';

function e(?string $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function conta_tem_acesso(PDO $pdo, $conta): bool
{
    if (!is_array($conta) || (int) $conta['ATIVO'] !== 1) return false;
    if ($conta['CARGO'] === 'ADMINISTRADOR') return true;
    if ($conta['CARGO'] !== 'ARMEIRO') return false;
    $stmt = $pdo->prepare("SELECT ID_ATIRADOR FROM ATIRADOR WHERE ID_USUARIO = :id AND PERFIL = 'ARMEIRO' AND ATIVO = 1 LIMIT 1");
    $stmt->execute([':id' => (int) $conta['ID_USUARIO']]);
    return $stmt->fetchColumn() !== false;
}

function data_hora_devolucao(array $cautela): string
{
    if ($cautela['HORA_F'] === null) {
        return 'Em aberto';
    }

    $dia = (string) ($cautela['DIA_F'] ?? '');
    $hora = (string) $cautela['HORA_F'];

    return $dia !== ''
        ? date('d/m/Y', strtotime($dia)) . ' às ' . $hora
        : 'Data não registrada, às ' . $hora;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_painel'])) {
        $_SESSION['csrf_painel'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_painel'];
}

function validar_csrf(): void
{
    $recebido = (string) ($_POST['csrf_token'] ?? '');
    if ($recebido === '' || !hash_equals(csrf_token(), $recebido)) {
        http_response_code(419);
        exit('Formulário inválido ou expirado. Volte, atualize a página e tente novamente.');
    }
}

function acoes_auditoria(bool $somenteOperacionais = false): array
{
    $operacionais = [
        'PESSOA_CADASTRADA', 'PESSOA_ATUALIZADA', 'PESSOA_REATIVADA', 'PESSOA_DESATIVADA',
        'ATIRADOR_CADASTRADO', 'ATIRADOR_ATUALIZADO', 'ATIRADOR_REATIVADO', 'ATIRADOR_DESATIVADO', 'ATIRADOR_EXCLUIDO',
        'CATALOGO_CADASTRADO', 'CATALOGO_ATUALIZADO',
        'ARMA_CADASTRADA', 'ARMA_ATUALIZADA', 'ARMA_ATIVADA', 'ARMA_REATIVADA', 'ARMA_DESATIVADA', 'ARMA_EXCLUIDA',
        'EMPRESTIMO_ARMA', 'DEVOLUCAO_ARMA', 'PRAZO_ALTERADO',
        'CAUTELA_REGISTRADA', 'DEVOLUCAO_CAUTELA',
    ];
    if ($somenteOperacionais) return $operacionais;

    return array_merge($operacionais, [
        'LOGIN', 'LOGOUT', 'SENHA_ALTERADA',
        'OPERADOR_CRIADO', 'OPERADOR_ATIVADO', 'OPERADOR_DESATIVADO',
        'ARMEIRO_CRIADO', 'ARMEIRO_ATIVADO', 'ARMEIRO_DESATIVADO', 'ARMEIRO_ACESSO_ATUALIZADO',
        'USUARIO_CRIADO', 'USUARIO_ATIVADO', 'USUARIO_DESATIVADO',
        'BIOMETRIA_DISPOSITIVO_VINCULADO',
    ]);
}

function acoes_auditoria_sql(PDO $pdo, bool $somenteOperacionais = false): string
{
    return implode(', ', array_map(fn(string $acao): string => $pdo->quote($acao), acoes_auditoria($somenteOperacionais)));
}

function registrar_log(PDO $pdo, ?int $autorId, string $acao, string $descricao): void
{
    if (!in_array($acao, acoes_auditoria(), true)) return;

    $sql = 'INSERT INTO LOG_SISTEMA (ID_USUARIO, ACAO, DESCRICAO, IP_ORIGEM)
            VALUES (:autor, :acao, :descricao, :ip)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':autor' => $autorId,
        ':acao' => $acao,
        ':descricao' => $descricao,
        ':ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    ]);
}

function mensagem_flash(string $tipo, string $mensagem): void
{
    $_SESSION['flash'] = ['tipo' => $tipo, 'mensagem' => $mensagem];
}

function recuperar_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

/** Rótulos de auditoria legíveis, inclusive para eventos históricos. */
function texto_auditoria(?string $texto): string
{
    $texto = (string) $texto;
    $rotulos = [
        'PESSOA_CADASTRADA' => 'Atirador cadastrado',
        'PESSOA_ATUALIZADA' => 'Atirador atualizado',
        'PESSOA_REATIVADA' => 'Atirador reativado',
        'PESSOA_DESATIVADA' => 'Atirador desativado',
        'ATIRADOR_CADASTRADO' => 'Atirador cadastrado',
        'ATIRADOR_ATUALIZADO' => 'Atirador atualizado',
        'ATIRADOR_REATIVADO' => 'Atirador reativado',
        'ATIRADOR_DESATIVADO' => 'Atirador desativado',
        'ATIRADOR_EXCLUIDO' => 'Atirador excluído',
        'CATALOGO_CADASTRADO' => 'Arma cadastrada',
        'CATALOGO_ATUALIZADO' => 'Arma atualizada',
        'CATALOGO_EVENTO' => 'Evento no histórico da arma',
        'ARMA_CADASTRADA' => 'Arma cadastrada',
        'ARMA_ATUALIZADA' => 'Arma atualizada',
        'ARMA_ATIVADA' => 'Arma ativada',
        'ARMA_REATIVADA' => 'Arma reativada',
        'ARMA_DESATIVADA' => 'Arma desativada',
        'ARMA_EXCLUIDA' => 'Arma excluída',
        'ARMA_EVENTO' => 'Evento no histórico da arma',
        'EMPRESTIMO_ARMA' => 'Cautela registrada',
        'DEVOLUCAO_ARMA' => 'Devolução registrada',
        'CAUTELA_REGISTRADA' => 'Cautela registrada',
        'DEVOLUCAO_CAUTELA' => 'Devolução registrada',
        'PRAZO_ALTERADO' => 'Prazo alterado',
        'OPERADOR_CRIADO' => 'Armeiro cadastrado',
        'OPERADOR_ATIVADO' => 'Armeiro ativado',
        'OPERADOR_DESATIVADO' => 'Armeiro desativado',
        'ARMEIRO_CRIADO' => 'Armeiro cadastrado',
        'ARMEIRO_ATIVADO' => 'Armeiro ativado',
        'ARMEIRO_DESATIVADO' => 'Armeiro desativado',
        'ARMEIRO_ACESSO_ATUALIZADO' => 'Acesso de armeiro atualizado',
        'USUARIO_CRIADO' => 'Usuário cadastrado',
        'USUARIO_ATIVADO' => 'Usuário ativado',
        'USUARIO_DESATIVADO' => 'Usuário desativado',
        'BIOMETRIA_DISPOSITIVO_VINCULADO' => 'Leitor biométrico vinculado',
        'FOTO_PERFIL_ATUALIZADA' => 'Foto de perfil atualizada',
        'FOTO_PERFIL_REMOVIDA' => 'Foto de perfil removida',
        'SENHA_ALTERADA' => 'Senha alterada',
        'LOGIN' => 'Entrada no sistema',
        'LOGOUT' => 'Saída do sistema',
        'TI_CADASTRADO' => 'Registro legado',
        'TI_EMPRESTIMO_CRIADO' => 'Registro legado',
        'TI_PRAZO_ALTERADO' => 'Registro legado',
        'TI_DEVOLVIDO' => 'Registro legado',
    ];
    if (isset($rotulos[$texto])) {
        return $rotulos[$texto];
    }
    if (preg_match('/\A[A-Z0-9_]+\z/D', $texto)) {
        return ucfirst(strtolower(str_replace('_', ' ', $texto)));
    }
    return $texto;
}

/** Consulta apenas o nome atual dos cadastros. Nunca altera os dados auditados. */
function auditoria_nome_atual(PDO $pdo, string $entidade, int $id): ?string
{
    if ($id < 1) return null;
    static $cache = [];
    $chave = $entidade . ':' . $id;
    if (array_key_exists($chave, $cache)) return $cache[$chave];

    $consultas = [
        'atirador' => 'SELECT NOME FROM ATIRADOR WHERE ID_ATIRADOR = :id LIMIT 1',
        'arma' => 'SELECT SERIAL FROM ARMAMENTO WHERE ID_ARMAMENTO = :id LIMIT 1',
        'armeiro' => 'SELECT NOME FROM USUARIO WHERE ID_USUARIO = :id LIMIT 1',
    ];
    if (!isset($consultas[$entidade])) return null;
    $stmt = $pdo->prepare($consultas[$entidade]);
    $stmt->execute([':id' => $id]);
    $nome = $stmt->fetchColumn();
    $cache[$chave] = is_string($nome) && trim($nome) !== '' ? trim($nome) : null;
    return $cache[$chave];
}

function auditoria_dados_registro(PDO $pdo, int $id): ?array
{
    if ($id < 1) return null;
    static $cache = [];
    if (array_key_exists($id, $cache)) return $cache[$id];
    $stmt = $pdo->prepare('SELECT A.SERIAL AS SERIE, T.NOME AS ATIRADOR,
        C.COM_ALTERACAO, C.DESCRICAO_DEVOLUCAO
        FROM CAUTELA C
        JOIN ARMAMENTO A ON A.ID_ARMAMENTO = C.ID_ARMAMENTO
        JOIN ATIRADOR T ON T.ID_ATIRADOR = C.ID_ATIRADOR
        WHERE C.ID_CAUTELA = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $dados = $stmt->fetch(PDO::FETCH_ASSOC);
    $cache[$id] = is_array($dados) ? $dados : null;
    return $cache[$id];
}

/**
 * Converte textos legados com IDs em descrições compreensíveis.
 * Não inventa nomes quando o cadastro original já não existe.
 */
function descricao_log_visivel(PDO $pdo, string $acao, ?string $descricao): string
{
    $original = (string) $descricao;
    if (in_array($acao, ['ARMA_EXCLUIDA', 'ATIRADOR_EXCLUIDO'], true)) return $original;

    $cadastros = [
        'PESSOA_CADASTRADA' => ['atirador', 'Atirador', 'cadastrado'],
        'PESSOA_ATUALIZADA' => ['atirador', 'Atirador', 'atualizado'],
        'PESSOA_REATIVADA' => ['atirador', 'Atirador', 'reativado'],
        'PESSOA_DESATIVADA' => ['atirador', 'Atirador', 'desativado'],
        'ATIRADOR_CADASTRADO' => ['atirador', 'Atirador', 'cadastrado'],
        'ATIRADOR_ATUALIZADO' => ['atirador', 'Atirador', 'atualizado'],
        'ATIRADOR_REATIVADO' => ['atirador', 'Atirador', 'reativado'],
        'ATIRADOR_DESATIVADO' => ['atirador', 'Atirador', 'desativado'],
        'CATALOGO_CADASTRADO' => ['arma', 'Arma', 'cadastrada'],
        'CATALOGO_ATUALIZADO' => ['arma', 'Arma', 'atualizada'],
        'ARMA_CADASTRADA' => ['arma', 'Arma', 'cadastrada'],
        'ARMA_ATUALIZADA' => ['arma', 'Arma', 'atualizada'],
        'ARMA_ATIVADA' => ['arma', 'Arma', 'ativada'],
        'ARMA_REATIVADA' => ['arma', 'Arma', 'reativada'],
        'ARMA_DESATIVADA' => ['arma', 'Arma', 'desativada'],
        'OPERADOR_CRIADO' => ['armeiro', 'Armeiro', 'cadastrado'],
        'OPERADOR_ATIVADO' => ['armeiro', 'Armeiro', 'ativado'],
        'OPERADOR_DESATIVADO' => ['armeiro', 'Armeiro', 'desativado'],
        'ARMEIRO_CRIADO' => ['armeiro', 'Armeiro', 'cadastrado'],
        'ARMEIRO_ATIVADO' => ['armeiro', 'Armeiro', 'ativado'],
        'ARMEIRO_DESATIVADO' => ['armeiro', 'Armeiro', 'desativado'],
    ];

    if (isset($cadastros[$acao])) {
        [$tipo, $rotulo, $verbo] = $cadastros[$acao];
        if (preg_match('/(?:#|\bID\s+)(\d+)\b/ui', $original, $m)) {
            $id = (int) $m[1];
            $nome = auditoria_nome_atual($pdo, $tipo, $id);
            if ($nome !== null) return $rotulo . ' ' . $nome . ' ' . $verbo . '.';
            return $rotulo . ' de ID ' . $id . ' ' . $verbo . ' (cadastro não encontrado).';
        }
        return $original;
    }

    if (in_array($acao, ['EMPRESTIMO_ARMA', 'DEVOLUCAO_ARMA', 'PRAZO_ALTERADO', 'CAUTELA_REGISTRADA', 'DEVOLUCAO_CAUTELA'], true)) {
        if (!preg_match('/(?:empr[eé]stimo|cautela)\s*#(\d+)\b/ui', $original, $m)) return $original;
        $id = (int) $m[1];
        $dados = auditoria_dados_registro($pdo, $id);
        if (!$dados) return 'Registro de movimentação de ID ' . $id . ' não encontrado; consulte o histórico original.';
        $referencia = 'arma ' . (string) $dados['SERIE'] . ' — atirador ' . (string) $dados['ATIRADOR'];
        $alteracao = (int) $dados['COM_ALTERACAO'] === 1
            ? ' Com alteração' . (trim((string) $dados['DESCRICAO_DEVOLUCAO']) !== '' ? ': ' . trim((string) $dados['DESCRICAO_DEVOLUCAO']) : '.')
            : '';
        return match ($acao) {
            'EMPRESTIMO_ARMA', 'CAUTELA_REGISTRADA' => 'Cautela registrada: ' . $referencia . '.',
            'DEVOLUCAO_ARMA', 'DEVOLUCAO_CAUTELA' => 'Devolução registrada: ' . $referencia . '.' . $alteracao,
            default => 'Prazo de devolução alterado: ' . $referencia . '.',
        };
    }

    if (in_array($acao, ['ARMA_EVENTO', 'CATALOGO_EVENTO'], true)) {
        if (!preg_match('/(?:arma|registro(?:\s+do\s+cat[aá]logo)?)\s*#(\d+)\b/ui', $original, $m)) return $original;
        $id = (int) $m[1];
        $serie = auditoria_nome_atual($pdo, 'arma', $id);
        $rotulos = [
            'OBSERVACAO' => 'Observação registrada',
            'DEFEITO_RELATADO' => 'Defeito relatado',
            'ENVIADO_MANUTENCAO' => 'Encaminhamento à manutenção registrado',
            'RETORNOU_MANUTENCAO' => 'Retorno da manutenção registrado',
        ];
        preg_match('/Evento\s+([A-Z_]+)\b/u', (string) $descricao, $evento);
        $descricaoEvento = $rotulos[$evento[1] ?? ''] ?? 'Evento registrado';
        $arma = $serie !== null ? 'arma ' . $serie : 'arma de ID ' . $id . ' (cadastro não encontrado)';
        return $descricaoEvento . ': ' . $arma . '.';
    }

    return $original;
}
