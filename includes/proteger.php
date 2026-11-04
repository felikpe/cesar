<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['id_usuario'])) {
    header('Location: login.php');
    exit;
}

$pdo = conectar();

$stmt = $pdo->prepare(
    'SELECT ID_USUARIO, NOME, CARGO, ATIVO
     FROM USUARIO
     WHERE ID_USUARIO = :id
     LIMIT 1'
);

$stmt->execute([
    ':id' => (int) $_SESSION['id_usuario']
]);

$contaAtual = $stmt->fetch(PDO::FETCH_ASSOC);

if (
    !$contaAtual ||
    (int) $contaAtual['ATIVO'] !== 1 ||
    !in_array(
        $contaAtual['CARGO'],
        ['ARMEIRO', 'ADMINISTRADOR'],
        true
    )
) {
    $_SESSION = [];
    session_regenerate_id(true);

    header('Location: login.php');
    exit;
}

$_SESSION['id_usuario'] = (int) $contaAtual['ID_USUARIO'];
$_SESSION['nome'] = $contaAtual['NOME'];
$_SESSION['cargo'] = $contaAtual['CARGO'];

function exigir_admin(): void
{
    if (($_SESSION['cargo'] ?? '') !== 'ADMINISTRADOR') {
        http_response_code(403);
        exit('Acesso restrito à administração.');
    }
}