<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Método não permitido.'); }
validar_csrf();
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) { http_response_code(400); exit('Atirador inválido.'); }

$pdo = conectar();
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT ATIVO FROM PESSOA WHERE ID_PESSOA = :id FOR UPDATE');
    $stmt->execute([':id' => $id]);
    $ativo = $stmt->fetchColumn();
    if ($ativo === false) throw new RuntimeException('Cadastro não encontrado.');
    $novo = (int) $ativo === 1 ? 0 : 1;
    $stmt = $pdo->prepare('UPDATE PESSOA SET ATIVO = :ativo WHERE ID_PESSOA = :id');
    $stmt->execute([':ativo' => $novo, ':id' => $id]);
    registrar_log($pdo, (int) $_SESSION['id_usuario'], $novo ? 'PESSOA_REATIVADA' : 'PESSOA_DESATIVADA', "Situação do atirador #{$id} alterada.");
    $pdo->commit();
    mensagem_flash('sucesso', $novo ? 'Atirador reativado.' : 'Atirador desativado.');
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    mensagem_flash('erro', 'Não foi possível alterar a situação.');
}
header('Location: pessoas.php', true, 303);
exit;
