<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/funcoes.php';
require_once __DIR__ . '/../config/database.php';

// Se a saída for disparada pelo botão "Sair" do menu (POST com CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['csrf_token']) && hash_equals(csrf_token(), (string)$_POST['csrf_token'])) {
        if (!empty($_SESSION['id_usuario'])) {
            try {
                registrar_log(conectar(), (int) $_SESSION['id_usuario'], 'LOGOUT', 'Sessão encerrada pelo usuário.');
            } catch (Throwable $e) {
                // Log não impede o logout
            }
        }
    }
}

// Limpa todas as variáveis de sessão
$_SESSION = [];

// Remove o cookie de sessão do navegador
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

// Destrói a sessão no servidor
session_destroy();

header('Location: login.php', true, 303);
exit;
