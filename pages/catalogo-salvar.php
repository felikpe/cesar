<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/upload-catalogo.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Método não permitido.'); }
validar_csrf();
$id = filter_var($_POST['id'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($id === false) { http_response_code(400); exit('ID inválido.'); }
$destino = $id ? 'catalogo-form.php?id=' . $id : 'catalogo-form.php';
$serial = trim((string) ($_POST['serial'] ?? ''));
$modelo = trim((string) ($_POST['modelo'] ?? ''));
$condicao = trim((string) ($_POST['condicao'] ?? ''));
$descricao = trim((string) ($_POST['descricao'] ?? ''));
$statusArma = (string) ($_POST['status_arma'] ?? 'DISPONIVEL');
$removerFoto = ($_POST['remover_foto'] ?? '') === '1';
$statusValidos = ['DISPONIVEL','EMPRESTADA','MANUTENCAO','INATIVA'];
if (strlen($serial) < 2 || strlen($serial) > 100 || strlen($modelo) > 140 || strlen($condicao) > 150 || strlen($descricao) > 2000 || !in_array($statusArma, $statusValidos, true)) {
    mensagem_flash('erro', 'Confira os campos obrigatórios e os tamanhos informados.');
    header('Location: ' . $destino, true, 303); exit;
}
$pdo = conectar();
$novaFoto = null;
$antiga = null;
$final = null;
try {
    $novaFoto = salvar_foto_catalogo($_FILES['foto'] ?? []);
    $pdo->beginTransaction();
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT FOTO_ARQUIVO, EXISTS(SELECT 1 FROM EMPRESTIMO_ARMAMENTO WHERE ID_ARMAMENTO = :id_aberto AND DATA_DEVOLUCAO IS NULL) AS TEM_EMPRESTIMO FROM ARMAMENTO WHERE ID_ARMAMENTO=:id_registro FOR UPDATE');
        $stmt->execute([':id_aberto'=>$id, ':id_registro'=>$id]);
        $atual = $stmt->fetch();
        if (!$atual) throw new RuntimeException('Registro não encontrado.');
        $antiga = $atual['FOTO_ARQUIVO'];
        $final = $novaFoto ?? ($removerFoto ? null : $antiga);
        if ((int) $atual['TEM_EMPRESTIMO'] === 1) {
            $statusArma = 'EMPRESTADA';
        }
        $stmt = $pdo->prepare('UPDATE ARMAMENTO SET SERIAL=:serial, MODELO=:modelo,
            CONDICAO=:condicao, DESCRICAO_CATALOGO=:descricao, FOTO_ARQUIVO=:foto, STATUS_ARMA=:status,
            DISPONIBILIDADE=:disponibilidade WHERE ID_ARMAMENTO=:id');
        $stmt->execute([':serial'=>$serial,':modelo'=>$modelo,':condicao'=>$condicao,
            ':descricao'=>$descricao,':foto'=>$final,':status'=>$statusArma,
            ':disponibilidade'=>$statusArma === 'DISPONIVEL' ? 1 : 0, ':id'=>$id]);
        registrar_log($pdo, (int)$_SESSION['id_usuario'], 'ARMA_ATUALIZADA', "Arma #{$id} atualizada.");
        $msg = 'Arma atualizada.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO ARMAMENTO (SERIAL, MODELO, CONDICAO, DESCRICAO_CATALOGO, FOTO_ARQUIVO, DISPONIBILIDADE, STATUS_ARMA)
            VALUES (:serial, :modelo, :condicao, :descricao, :foto, :disponibilidade, :status)');
        $stmt->execute([':serial'=>$serial,':modelo'=>$modelo,':condicao'=>$condicao,
            ':descricao'=>$descricao,':foto'=>$novaFoto, ':disponibilidade'=>$statusArma === 'DISPONIVEL' ? 1 : 0, ':status'=>$statusArma]);
        $id = (int) $pdo->lastInsertId();
        registrar_log($pdo, (int)$_SESSION['id_usuario'], 'ARMA_CADASTRADA', "Arma #{$id} cadastrada.");
        $msg = 'Arma cadastrada.';
    }
    $pdo->commit();
    if ($antiga && $antiga !== $final) apagar_foto_catalogo($antiga);
    mensagem_flash('sucesso', $msg);
    header('Location: catalogo.php', true, 303); exit;
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($novaFoto) apagar_foto_catalogo($novaFoto);
    $msg = $ex instanceof PDOException && (string)$ex->getCode() === '23000'
        ? 'Já existe uma arma com este número de série.'
        : ($ex instanceof RuntimeException ? $ex->getMessage() : 'Falha ao salvar. Confira a migração do banco.');
    mensagem_flash('erro', $msg);
    header('Location: ' . $destino, true, 303); exit;
}
