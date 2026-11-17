<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'mensagem' => 'A exclusão de atiradores foi desabilitada. Utilize Desativar.'], JSON_UNESCAPED_UNICODE);
