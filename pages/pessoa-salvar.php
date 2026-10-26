<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/upload-pessoa.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Método não permitido.'); }
validar_csrf();

$id = filter_var($_POST['id'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($id === false) { http_response_code(400); exit('Identificador inválido.'); }
$destino = $id > 0 ? 'pessoa-form.php?id=' . $id : 'pessoa-form.php';
$nome = trim((string) ($_POST['nome'] ?? ''));
$identificador = trim((string) ($_POST['identificador'] ?? ''));
$observacoes = trim((string) ($_POST['observacoes'] ?? ''));
$removerFoto = ($_POST['remover_foto'] ?? '') === '1';

if (strlen($nome) < 3 || strlen($nome) > 150 ||
    strlen($identificador) < 2 || strlen($identificador) > 40 ||
    strlen($observacoes) > 500) {
    mensagem_flash('erro', 'Confira os campos: nome (3 a 150), identificador (2 a 40), observações (até 500 caracteres).');
    header('Location: ' . $destino, true, 303);
    exit;
}

$pdo = conectar();
$novaFoto = null;
$fotoAntiga = null;
$fotoFinal = null;
try {
    $novaFoto = salvar_foto_pessoa($_FILES['foto'] ?? []);
    $pdo->beginTransaction();
    if ($id > 0) {
        $consulta = $pdo->prepare('SELECT FOTO_ARQUIVO FROM PESSOA WHERE ID_PESSOA = :id FOR UPDATE');
        $consulta->execute([':id' => $id]);
        $registro = $consulta->fetch();
        if (!$registro) throw new RuntimeException('Atirador não encontrado.');
        $fotoAntiga = $registro['FOTO_ARQUIVO'];
        $fotoFinal = $novaFoto ?? ($removerFoto ? null : $fotoAntiga);
        $stmt = $pdo->prepare('UPDATE PESSOA SET NOME = :nome, IDENTIFICADOR = :identificador,
            OBSERVACOES = :observacoes, FOTO_ARQUIVO = :foto WHERE ID_PESSOA = :id');
        $stmt->execute([
            ':nome' => $nome, ':identificador' => $identificador, 
            ':observacoes' => $observacoes, ':foto' => $fotoFinal, ':id' => $id,
        ]);
        registrar_log($pdo, (int) $_SESSION['id_usuario'], 'PESSOA_ATUALIZADA', "{$nome} atualizado.");
        $mensagem = 'Cadastro atualizado.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO PESSOA
            (NOME, IDENTIFICADOR, OBSERVACOES, FOTO_ARQUIVO, CADASTRADO_POR)
            VALUES (:nome, :identificador, :observacoes, :foto, :autor)');
        $stmt->execute([
            ':nome' => $nome, ':identificador' => $identificador, 
            ':observacoes' => $observacoes, ':foto' => $novaFoto,
            ':autor' => (int) $_SESSION['id_usuario'],
        ]);
        $id = (int) $pdo->lastInsertId();
        registrar_log($pdo, (int) $_SESSION['id_usuario'], 'PESSOA_CADASTRADA', "{$nome} cadastrado.");
        $mensagem = 'Atirador cadastrado com sucesso.';
    }
    $pdo->commit();
    if ($fotoAntiga && $fotoAntiga !== $fotoFinal) apagar_foto_pessoa($fotoAntiga);
    mensagem_flash('sucesso', $mensagem);
    header('Location: pessoas.php', true, 303);
    exit;
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($novaFoto !== null) apagar_foto_pessoa($novaFoto);
    $msg = $ex instanceof PDOException && (string) $ex->getCode() === '23000'
        ? 'Esse identificador já está cadastrado.'
        : ($ex instanceof RuntimeException ? $ex->getMessage() : 'Não foi possível salvar o cadastro. Verifique o banco e tente novamente.');
    mensagem_flash('erro', $msg);
    header('Location: ' . $destino, true, 303);
    exit;
}
