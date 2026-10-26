<?php
declare(strict_types=1);

/** Salva apenas imagens JPEG, PNG ou WEBP verificadas, usando nome aleatório. */
function salvar_foto_perfil(array $arquivo): ?string
{
    $erro = (int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($erro === UPLOAD_ERR_NO_FILE) return null;
    if ($erro !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Não foi possível receber a foto. Tente novamente.');
    }

    $tamanho = (int) ($arquivo['size'] ?? 0);
    if ($tamanho < 1 || $tamanho > 2 * 1024 * 1024) {
        throw new RuntimeException('A foto deve ter no máximo 2 MB.');
    }
    $temporario = (string) ($arquivo['tmp_name'] ?? '');
    if ($temporario === '' || !is_uploaded_file($temporario)) {
        throw new RuntimeException('Arquivo de foto inválido.');
    }

    $extensoes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporario);
    $dimensoes = @getimagesize($temporario);
    if (!isset($extensoes[$mime]) || $dimensoes === false ||
        $dimensoes[0] > 6000 || $dimensoes[1] > 6000) {
        throw new RuntimeException('Escolha uma foto JPG, PNG ou WEBP válida.');
    }

    $diretorio = dirname(__DIR__) . '/storage/fotos-perfis';
    if (!is_dir($diretorio) || !is_writable($diretorio)) {
        throw new RuntimeException('A pasta de fotos de perfil não permite gravação.');
    }
    $nome = bin2hex(random_bytes(24)) . '.' . $extensoes[$mime];
    if (!move_uploaded_file($temporario, $diretorio . '/' . $nome)) {
        throw new RuntimeException('Não foi possível salvar a foto de perfil.');
    }
    return $nome;
}

function apagar_foto_perfil(?string $nome): void
{
    if (!$nome || !preg_match('/\A[a-f0-9]{48}\.(jpg|png|webp)\z/D', $nome)) return;
    $arquivo = dirname(__DIR__) . '/storage/fotos-perfis/' . $nome;
    if (is_file($arquivo)) @unlink($arquivo);
}
