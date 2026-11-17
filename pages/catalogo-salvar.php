<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/proteger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

validar_csrf();

$id = filter_var($_POST['id'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($id === false) {
    http_response_code(400);
    exit('ID inválido.');
}

$destino = $id ? 'catalogo-form.php?id=' . $id : 'catalogo-form.php';

// Limpeza e normalização dos dados conforme schema.sql
$serial = trim((string) ($_POST['serial'] ?? ''));
$modelo = trim((string) ($_POST['modelo'] ?? ''));
$condicao = trim((string) ($_POST['condicao'] ?? ''));
$ativoSolicitado = $_POST['ativo'] ?? null;
if ($ativoSolicitado !== null && (!is_string($ativoSolicitado) || !in_array($ativoSolicitado, ['0', '1'], true))) {
    mensagem_flash('erro', 'Selecione uma situação válida para a arma.');
    header('Location: ' . $destino, true, 303);
    exit;
}

// Validações básicas alinhadas ao banco (Serial com exatos ou até 5 caracteres, modelos MOSQUEFAL ou PRESSAO)
$modelosValidos = ['MOSQUEFAL', 'PRESSAO'];

if (!preg_match('/\A[A-Za-z0-9]{5}\z/D', $serial) || !in_array($modelo, $modelosValidos, true) || preg_match('/\A.{0,250}\z/usD', $condicao) !== 1) {
    mensagem_flash('erro', 'Confira os dados. O serial deve ter exatamente 5 caracteres e o modelo deve ser válido.');
    header('Location: ' . $destino, true, 303);
    exit;
}

$pdo = conectar();

try {
    $pdo->beginTransaction();

    if ($id > 0) {
        // Atualização de arma existente
        $consulta = $pdo->prepare('SELECT ID_ARMAMENTO, ATIVO FROM ARMAMENTO WHERE ID_ARMAMENTO = :id FOR UPDATE');
        $consulta->execute([':id' => $id]);
        $registro = $consulta->fetch(PDO::FETCH_ASSOC);
        if (!$registro) {
            throw new RuntimeException('Arma não encontrada.');
        }

        $ativo = $ativoSolicitado === null ? (int) $registro['ATIVO'] : (int) $ativoSolicitado;
        if ($ativo === 0 && (int) $registro['ATIVO'] !== 0) {
            $cautela = $pdo->prepare('SELECT ID_CAUTELA FROM CAUTELA WHERE ID_ARMAMENTO = :id AND HORA_F IS NULL LIMIT 1');
            $cautela->execute([':id' => $id]);
            if ($cautela->fetchColumn()) {
                throw new RuntimeException('Esta arma está cautelada. Registre a devolução antes de desativá-la.');
            }
        }

        $stmt = $pdo->prepare('UPDATE ARMAMENTO SET SERIAL = :serial, MODELO = :modelo, CONDICAO = :condicao, ATIVO = :ativo WHERE ID_ARMAMENTO = :id');
        $stmt->execute([
            ':serial' => $serial,
            ':modelo' => $modelo,
            ':condicao' => $condicao,
            ':ativo' => $ativo,
            ':id' => $id
        ]);

        registrar_log($pdo, (int)$_SESSION['id_usuario'], 'ARMA_ATUALIZADA', "Arma ID #{$id} atualizada.");
        if ($ativo !== (int) $registro['ATIVO']) {
            registrar_log($pdo, (int) $_SESSION['id_usuario'], $ativo === 1 ? 'ARMA_REATIVADA' : 'ARMA_DESATIVADA', 'Arma ' . $serial . ($ativo === 1 ? ' reativada.' : ' desativada.'));
        }
        $msg = 'Arma atualizada com sucesso.';
    } else {
        // Cadastro de nova arma
        $stmt = $pdo->prepare('INSERT INTO ARMAMENTO (SERIAL, MODELO, CONDICAO, DISPONIBILIDADE, ATIVO) VALUES (:serial, :modelo, :condicao, 1, 1)');
        $stmt->execute([
            ':serial' => $serial,
            ':modelo' => $modelo,
            ':condicao' => $condicao
        ]);

        $id = (int) $pdo->lastInsertId();
        registrar_log($pdo, (int)$_SESSION['id_usuario'], 'ARMA_CADASTRADA', "Arma ID #{$id} cadastrada.");
        $msg = 'Arma cadastrada com sucesso.';
    }

    $pdo->commit();
    mensagem_flash('sucesso', $msg);
    header('Location: catalogo.php', true, 303);
    exit;

} catch (Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Identifica se o erro foi violação de chave única (Serial duplicado no banco)
    $msg = ($ex instanceof PDOException && (string)$ex->getCode() === '23000')
        ? 'Já existe uma arma cadastrada com este número de série.'
        : ($ex instanceof RuntimeException && !($ex instanceof PDOException) ? $ex->getMessage() : 'Falha ao salvar a arma no banco de dados.');

    mensagem_flash('erro', $msg);
    header('Location: ' . $destino, true, 303);
    exit;
}
