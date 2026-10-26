<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Use o botão Sair no painel.');
}
validar_csrf();
registrar_log(conectar(), (int) $_SESSION['id_usuario'], 'LOGOUT', 'Sessão encerrada pelo usuário.');
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}
session_destroy();
header('Location: login.php', true, 303);
exit;
