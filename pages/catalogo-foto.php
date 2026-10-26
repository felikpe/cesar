<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) { http_response_code(404); exit; }
$stmt = conectar()->prepare('SELECT FOTO_ARQUIVO FROM ARMAMENTO WHERE ID_ARMAMENTO = :id');
$stmt->execute([':id' => $id]);
$foto = $stmt->fetchColumn();
if (!is_string($foto) || !preg_match('/\A[a-f0-9]{48}\.(jpg|png|webp)\z/D', $foto)) {
    http_response_code(404); exit;
}
$arquivo = dirname(__DIR__) . '/storage/fotos-catalogo/' . $foto;
if (!is_file($arquivo)) { http_response_code(404); exit; }
$ext = strtolower(pathinfo($foto, PATHINFO_EXTENSION));
$tipo = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext];
header('Content-Type: ' . $tipo);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Content-Length: ' . filesize($arquivo));
readfile($arquivo);
