<?php
declare(strict_types=1);
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['id_usuario'])) {
    header('Location: login.php');
    exit;
}

// Permissoes consultadas novamente no banco a cada requisicao.
// Assim, desativar um auxiliar encerra o acesso na proxima navegacao.
$pdo = conectar();
$stmt = $pdo->prepare(
    'SELECT ID_USUARIO, NOME, CARGO, ATIVO, FOTO_PERFIL FROM USUARIO WHERE ID_USUARIO = :id LIMIT 1'
);
$stmt->execute([':id' => (int) $_SESSION['id_usuario']]);
$contaAtual = $stmt->fetch();

if (!$contaAtual || (int) $contaAtual['ATIVO'] !== 1 ||
    !in_array($contaAtual['CARGO'], ['AUXILIAR', 'ADMINISTRADOR'], true)) {
    $_SESSION = [];
    session_regenerate_id(true);
    header('Location: login.php');
    exit;
}

$_SESSION['nome'] = $contaAtual['NOME'];
$_SESSION['cargo'] = $contaAtual['CARGO'];
$_SESSION['foto_perfil'] = $contaAtual['FOTO_PERFIL'];

function exigir_admin(): void
{
    if (($_SESSION['cargo'] ?? '') !== 'ADMINISTRADOR') {
        http_response_code(403);
        exit('Acesso restrito à administração.');
    }
}
