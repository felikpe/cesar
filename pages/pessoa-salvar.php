<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/perfis.php';
require_once __DIR__ . '/../includes/upload-pessoa.php'; // Mantém sua função de upload de foto

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { 
    http_response_code(405); 
    exit('Método não permitido.'); 
}

validar_csrf();

$id = filter_var($_POST['id'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($id === false) { 
    http_response_code(400); 
    exit('Identificador inválido.'); 
}

$destino = $id > 0 ? 'pessoa-form.php?id=' . $id : 'pessoa-form.php';

// Limpeza e normalização dos dados conforme o schema ATIRADOR
$ra = trim((string) ($_POST['ra'] ?? ''));
$nome = trim((string) ($_POST['nome'] ?? ''));
$nomeGuerra = trim((string) ($_POST['nome_guerra'] ?? ''));
$numero = trim((string) ($_POST['numero'] ?? ''));
$idDigital = null;
$idArmamento = ($_POST['id_armamento'] ?? '') !== '' ? filter_var($_POST['id_armamento'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
$idUsuario = null;
$usuarioLegado = ($_POST['usuario_legado'] ?? '') !== '' ? filter_var($_POST['usuario_legado'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
$removerFoto = ($_POST['remover_foto'] ?? '') === '1';

if ($idArmamento === false || $usuarioLegado === false) {
    mensagem_flash('erro', 'Informe um armamento principal e uma conta existente válidos.');
    header('Location: ' . $destino, true, 303);
    exit;
}

// Validações básicas de tamanho e obrigatoriedade
if (strlen($ra) < 3 || strlen($ra) > 12 ||
    strlen($nome) < 3 || strlen($nome) > 250 ||
    strlen($nomeGuerra) < 1 || strlen($nomeGuerra) > 50 ||
    ($numero !== '' && strlen($numero) > 2)) {
    
    mensagem_flash('erro', 'Confira os campos obrigatórios (RA de 3 a 12, Nome até 250, Nome de Guerra até 50, Número até 2 dígitos).');
    header('Location: ' . $destino, true, 303);
    exit;
}

function validar_arma_principal(PDO $pdo, ?int $nova, ?int $atual): void
{
    if ($nova === null || $nova === $atual) return;
    $stmt = $pdo->prepare('SELECT A.ID_ARMAMENTO FROM ARMAMENTO A WHERE A.ID_ARMAMENTO = :id AND A.ATIVO = 1 AND A.DISPONIBILIDADE = 1 AND NOT EXISTS (SELECT 1 FROM CAUTELA C WHERE C.ID_ARMAMENTO = A.ID_ARMAMENTO AND C.HORA_F IS NULL)');
    $stmt->execute([':id' => $nova]);
    if (!$stmt->fetchColumn()) throw new RuntimeException('O armamento principal selecionado não está disponível. Atualize a ficha.');
}

$pdo = conectar();
$novaFoto = null;
$fotoAntiga = null;
$fotoFinal = null;

try {
    // Processa o upload da foto se enviada
    if (function_exists('salvar_foto_pessoa')) {
        $novaFoto = salvar_foto_pessoa($_FILES['foto'] ?? []);
    }

    $pdo->beginTransaction();

    if ($id > 0) {
        // Busca a foto atual para gerenciar substituição/remoção
        $registro = perfis_bloquear_ficha($pdo, $id);

        validar_arma_principal($pdo, $idArmamento, $registro['ID_ARMAMENTO'] === null ? null : (int) $registro['ID_ARMAMENTO']);

        $perfil = $_POST['perfil'] ?? $registro['PERFIL'];
        if (!is_string($perfil)) throw new RuntimeException('Selecione um perfil válido.');
        $idUsuario = perfis_gravar_acesso($pdo, $registro, $perfil, $nome, $_POST, $usuarioLegado);
        $fotoAntiga = $registro['FOTO'];
        $idDigital = $registro['ID_DIGITAL'] === null ? null : (int) $registro['ID_DIGITAL'];
        $fotoFinal = $novaFoto ?? ($removerFoto ? null : $fotoAntiga);

        $stmt = $pdo->prepare('UPDATE ATIRADOR SET 
            RA = :ra, 
            NOME = :nome, 
            NOME_GUERRA = :nome_guerra, 
            NUMERO = :numero, 
            FOTO = :foto, 
            ID_DIGITAL = :id_digital, 
            ID_ARMAMENTO = :id_armamento, 
            ID_USUARIO = :id_usuario,
            PERFIL = :perfil
            WHERE ID_ATIRADOR = :id');

        $stmt->execute([
            ':ra' => $ra,
            ':nome' => $nome,
            ':nome_guerra' => $nomeGuerra,
            ':numero' => $numero !== '' ? $numero : null,
            ':foto' => $fotoFinal,
            ':id_digital' => $idDigital,
            ':id_armamento' => $idArmamento,
            ':id_usuario' => $idUsuario,
            ':perfil' => $perfil,
            ':id' => $id,
        ]);

        registrar_log($pdo, (int) $_SESSION['id_usuario'], 'ATIRADOR_ATUALIZADO', "Atirador {$nomeGuerra} (RA: {$ra}) atualizado.");
        $mensagem = 'Cadastro do atirador atualizado com sucesso.';
    } else {
        validar_arma_principal($pdo, $idArmamento, null);
        $perfil = $_POST['perfil'] ?? 'ATIRADOR';
        if (!is_string($perfil)) throw new RuntimeException('Selecione um perfil válido.');
        $idUsuario = perfis_gravar_acesso($pdo, ['ID_ATIRADOR' => 0, 'ID_USUARIO' => null, 'PERFIL' => 'ATIRADOR', 'ATIVO' => 1], $perfil, $nome, $_POST, $usuarioLegado);
        $stmt = $pdo->prepare('INSERT INTO ATIRADOR 
            (RA, NOME, NOME_GUERRA, NUMERO, FOTO, ID_DIGITAL, ID_ARMAMENTO, ID_USUARIO, PERFIL) 
            VALUES (:ra, :nome, :nome_guerra, :numero, :foto, :id_digital, :id_armamento, :id_usuario, :perfil)');

        $stmt->execute([
            ':ra' => $ra,
            ':nome' => $nome,
            ':nome_guerra' => $nomeGuerra,
            ':numero' => $numero !== '' ? $numero : null,
            ':foto' => $novaFoto,
            ':id_digital' => $idDigital,
            ':id_armamento' => $idArmamento,
            ':id_usuario' => $idUsuario,
            ':perfil' => $perfil,
        ]);

        $id = (int) $pdo->lastInsertId();
        registrar_log($pdo, (int) $_SESSION['id_usuario'], 'ATIRADOR_CADASTRADO', "Atirador {$nomeGuerra} (RA: {$ra}) cadastrado.");
        $mensagem = 'Atirador cadastrado com sucesso.';
    }

    $pdo->commit();

    // Remove a foto antiga do servidor caso tenha sido substituída ou removida
    if ($fotoAntiga && $fotoAntiga !== $fotoFinal && function_exists('apagar_foto_pessoa')) {
        apagar_foto_pessoa($fotoAntiga);
    }

    mensagem_flash('sucesso', $mensagem);
    header('Location: pessoas.php', true, 303);
    exit;

} catch (Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($novaFoto !== null && function_exists('apagar_foto_pessoa')) {
        apagar_foto_pessoa($novaFoto);
    }

    if ($ex instanceof RuntimeException && !($ex instanceof PDOException) && $ex->getCode() === 403) {
        http_response_code(403);
        exit(e($ex->getMessage()));
    }

    $msg = ($ex instanceof PDOException && (string) $ex->getCode() === '23000')
        ? 'O RA, a digital ou o login informado já pertencem a outro cadastro.'
        : ($ex instanceof RuntimeException && !($ex instanceof PDOException) ? $ex->getMessage() : 'Não foi possível salvar o atirador. Verifique os dados e tente novamente.');

    mensagem_flash('erro', $msg);
    header('Location: ' . $destino, true, 303);
    exit;
}
