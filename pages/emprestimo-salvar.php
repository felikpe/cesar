<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

validar_csrf();
$acao = (string) ($_POST['acao'] ?? '');
$pdo = conectar();

try {
    if ($acao === 'novo') {
        $idArmamento = filter_var($_POST['id_armamento'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $idPessoa = filter_var($_POST['id_pessoa'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        
        $agora = new DateTimeImmutable();
        $dia = $agora->format('Y-m-d');
        $horaInicio = $agora->format('H:i:s');
        
        $missao = trim((string) ($_POST['missao'] ?? 'Instrução / Rotina'));
        $acessorios = trim((string) ($_POST['acessorios'] ?? ''));

        if (!$idArmamento || !$idPessoa) {
            throw new RuntimeException('Preencha os dados da cautela corretamente.');
        }

        if (preg_match('/\A.{0,150}\z/usD', $missao) !== 1 || preg_match('/\A.{0,255}\z/usD', $acessorios) !== 1) {
            throw new RuntimeException('A missão deve ter até 150 caracteres e os acessórios até 255.');
        }

        $pdo->beginTransaction();

        // Valida se a arma está disponível (DISPONIBILIDADE = 1) e já busca o SERIAL
        $stmt = $pdo->prepare("SELECT ID_ARMAMENTO, SERIAL, DISPONIBILIDADE, ATIVO FROM ARMAMENTO WHERE ID_ARMAMENTO = :id FOR UPDATE");
        $stmt->execute([':id' => $idArmamento]);
        $arma = $stmt->fetch();

        if (!$arma) {
            throw new RuntimeException('Arma não encontrada.');
        }
        if ((int) $arma['ATIVO'] !== 1) {
            throw new RuntimeException('Esta arma está desativada e não pode ser cautelada.');
        }
        if ((int) $arma['DISPONIBILIDADE'] !== 1) {
            throw new RuntimeException('Essa arma não está disponível para cautela.');
        }

        // Valida se o atirador existe e está ativo
        $stmt = $pdo->prepare('SELECT ID_ATIRADOR FROM ATIRADOR WHERE ID_ATIRADOR = :id AND ATIVO = 1 LIMIT 1 FOR UPDATE');
        $stmt->execute([':id' => $idPessoa]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('O atirador selecionado não está ativo.');
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM CAUTELA WHERE ID_ARMAMENTO = :id AND HORA_F IS NULL');
        $stmt->execute([':id' => $idArmamento]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('Já existe cautela em aberto para essa arma.');
        }

        // Insere na tabela CAUTELA oficial
        $stmt = $pdo->prepare('INSERT INTO CAUTELA
            (DIA, HORA_I, MISSAO, ACESSORIOS, COM_ALTERACAO, ID_USUARIO, ID_ATIRADOR, ID_ARMAMENTO)
            VALUES (:dia, :hora_i, :missao, :acessorios, 0, :usuario, :atirador, :arma)');
        $stmt->execute([
            ':dia' => $dia,
            ':hora_i' => $horaInicio,
            ':missao' => $missao,
            ':acessorios' => $acessorios,
            ':usuario' => (int) $_SESSION['id_usuario'],
            ':atirador' => $idPessoa,
            ':arma' => $idArmamento,
        ]);

        $idCautela = (int) $pdo->lastInsertId();
        $serialArma = (string) $arma['SERIAL'];

        // Atualiza a disponibilidade da arma para 0 (emprestada)
        $stmt = $pdo->prepare("UPDATE ARMAMENTO SET DISPONIBILIDADE = 0 WHERE ID_ARMAMENTO = :id");
        $stmt->execute([':id' => $idArmamento]);

        // Registra o log utilizando o Serial da arma
        registrar_log($pdo, (int) $_SESSION['id_usuario'], 'CAUTELA_REGISTRADA', "Cautela #{$idCautela} registrada para a arma {$serialArma}.");
        $pdo->commit();

        // Exibe a mensagem de sucesso utilizando o Serial da arma
        mensagem_flash('sucesso', "Cautela #{$idCautela} registrada para a arma {$serialArma}.");
        header('Location: emprestimos.php', true, 303);
        exit;
    }

    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
        throw new RuntimeException('Cautela inválida.');
    }

    if ($acao === 'devolver') {
        $agora = new DateTimeImmutable();
        $diaFim = $agora->format('Y-m-d');
        $horaFim = $agora->format('H:i:s');
        $comAlteracao = isset($_POST['com_alteracao']) ? 1 : 0;
        $descricaoDevolucao = trim((string) ($_POST['descricao_devolucao'] ?? ''));

        if (preg_match('/\A.{0,255}\z/usD', $descricaoDevolucao) !== 1) {
            throw new RuntimeException('A descrição da devolução deve ter até 255 caracteres.');
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT ID_ARMAMENTO, HORA_F FROM CAUTELA WHERE ID_CAUTELA = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        $registro = $stmt->fetch();

        if (!$registro) {
            throw new RuntimeException('Cautela não encontrada.');
        }
        if ($registro['HORA_F'] !== null) {
            throw new RuntimeException('A devolução desta cautela já foi registrada.');
        }

        $stmt = $pdo->prepare('UPDATE CAUTELA SET DIA_F = :dia_f, HORA_F = :hora_f, COM_ALTERACAO = :alt, DESCRICAO_DEVOLUCAO = :desc WHERE ID_CAUTELA = :id');
        $stmt->execute([
            ':dia_f' => $diaFim,
            ':hora_f' => $horaFim,
            ':alt' => $comAlteracao,
            ':desc' => $descricaoDevolucao,
            ':id' => $id
        ]);

        // Retorna a disponibilidade da arma para 1 (disponível)
        $stmt = $pdo->prepare('UPDATE ARMAMENTO SET DISPONIBILIDADE = 1 WHERE ID_ARMAMENTO = :id');
        $stmt->execute([':id' => (int) $registro['ID_ARMAMENTO']]);

        registrar_log($pdo, (int) $_SESSION['id_usuario'], 'DEVOLUCAO_CAUTELA', "Devolução registrada na cautela #{$id}.");
        $pdo->commit();

        mensagem_flash('sucesso', 'Devolução registrada.');
        header('Location: emprestimos.php', true, 303);
        exit;
    }

    throw new RuntimeException('Ação inválida.');
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    mensagem_flash('erro', $ex instanceof RuntimeException ? $ex->getMessage() : 'Não foi possível salvar a operação.');
    
    if ($acao === 'novo') {
        header('Location: emprestimo-form.php', true, 303);
    } else {
        header('Location: emprestimo-form.php?id=' . (int) ($_POST['id'] ?? 0), true, 303);
    }
    exit;
}
