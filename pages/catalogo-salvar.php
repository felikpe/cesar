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
$disponibilidade = isset($_POST['disponibilidade']) ? (int)$_POST['disponibilidade'] : 1;

// Validações básicas alinhadas ao banco (Serial com exatos ou até 5 caracteres, modelos MOSQUEFAL ou PRESSAO)
$modelosValidos = ['MOSQUEFAL', 'PRESSAO'];

if (strlen($serial) !== 5 || !in_array($modelo, $modelosValidos, true) || strlen($condicao) > 250) {
    mensagem_flash('erro', 'Confira os dados. O serial deve ter exatamente 5 caracteres e o modelo deve ser válido.');
    header('Location: ' . $destino, true, 303);
    exit;
}

$pdo = conectar();

try {
    $pdo->beginTransaction();

    if ($id > 0) {
        // Atualização de arma existente
        $stmt = $pdo->prepare('UPDATE ARMAMENTO SET SERIAL = :serial, MODELO = :modelo, CONDICAO = :condicao, DISPONIBILIDADE = :disponibilidade WHERE ID_ARMAMENTO = :id');
        $stmt->execute([
            ':serial' => $serial,
            ':modelo' => $modelo,
            ':condicao' => $condicao,
            ':disponibilidade' => $disponibilidade,
            ':id' => $id
        ]);

        registrar_log($pdo, (int)$_SESSION['id_usuario'], 'ARMA_ATUALIZADA', "Arma ID #{$id} atualizada.");
        $msg = 'Arma atualizada com sucesso.';
    } else {
        // Cadastro de nova arma
        $stmt = $pdo->prepare('INSERT INTO ARMAMENTO (SERIAL, MODELO, CONDICAO, DISPONIBILIDADE) VALUES (:serial, :modelo, :condicao, :disponibilidade)');
        $stmt->execute([
            ':serial' => $serial,
            ':modelo' => $modelo,
            ':condicao' => $condicao,
            ':disponibilidade' => $disponibilidade
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
        : ($ex instanceof RuntimeException ? $ex->getMessage() : 'Falha ao salvar a arma no banco de dados.');

    mensagem_flash('erro', $msg);
    header('Location: ' . $destino, true, 303);
    exit;
}