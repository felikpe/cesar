<?php
declare(strict_types=1);

/** Armazena foto validada com nome aleatorio em diretorio sem acesso HTTP. */
function salvar_foto_catalogo(array $arquivo): ?string
{
    $erro = (int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($erro === UPLOAD_ERR_NO_FILE) return null;
    if ($erro !== UPLOAD_ERR_OK) throw new RuntimeException('Não foi possível receber a imagem.');
    $tamanho = (int) ($arquivo['size'] ?? 0);
    if ($tamanho < 1 || $tamanho > 2 * 1024 * 1024) {
        throw new RuntimeException('A imagem deve ter no máximo 2 MB.');
    }
    $temp = (string) ($arquivo['tmp_name'] ?? '');
    if ($temp === '' || !is_uploaded_file($temp)) throw new RuntimeException('Arquivo inválido.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temp);
    $extensoes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensoes[$mime]) || @getimagesize($temp) === false) {
        throw new RuntimeException('Selecione uma foto JPG, PNG ou WEBP válida.');
    }
    $pasta = dirname(__DIR__) . '/storage/fotos-catalogo/';
    if (!is_dir($pasta) || !is_writable($pasta)) {
        throw new RuntimeException('A pasta de fotos não permite gravação.');
    }
    $nome = bin2hex(random_bytes(24)) . '.' . $extensoes[$mime];
    if (!move_uploaded_file($temp, $pasta . $nome)) {
        throw new RuntimeException('Erro ao salvar a imagem.');
    }
    return $nome;
}

function apagar_foto_catalogo(?string $nome): void
{
    if (!$nome || !preg_match('/\A[a-f0-9]{48}\.(jpg|png|webp)\z/D', $nome)) return;
    $arquivo = dirname(__DIR__) . '/storage/fotos-catalogo/' . $nome;
    if (is_file($arquivo)) @unlink($arquivo);
}
