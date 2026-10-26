<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';

// A rota exibe exclusivamente a foto da conta logada. Não aceita IDs externos.
$stmt = conectar()->prepare('SELECT FOTO_PERFIL FROM USUARIO WHERE ID_USUARIO = :id LIMIT 1');
$stmt->execute([':id' => (int) $_SESSION['id_usuario']]);
$foto = $stmt->fetchColumn();
if (!is_string($foto) || !preg_match('/\A[a-f0-9]{48}\.(jpg|png|webp)\z/D', $foto)) {
    http_response_code(404);
    exit;
}
$caminho = dirname(__DIR__) . '/storage/fotos-perfis/' . $foto;
if (!is_file($caminho)) {
    http_response_code(404);
    exit;
}
$ext = strtolower(pathinfo($foto, PATHINFO_EXTENSION));
$tipo = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext];
header('Content-Type: ' . $tipo);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Content-Length: ' . filesize($caminho));
readfile($caminho);
