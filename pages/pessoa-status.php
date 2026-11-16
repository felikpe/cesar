<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/proteger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { 
    http_response_code(405); 
    exit('Método não permitido.'); 
}

validar_csrf();

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) { 
    http_response_code(400); 
    exit('Atirador inválido.'); 
}

$pdo = conectar();

try {
    $pdo->beginTransaction();

    // Consulta na tabela oficial ATIRADOR buscando também o NOME_GUERRA
    $stmt = $pdo->prepare('SELECT ATIVO, NOME_GUERRA FROM ATIRADOR WHERE ID_ATIRADOR = :id FOR UPDATE');
    $stmt->execute([':id' => $id]);
    $atirador = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$atirador) {
        throw new RuntimeException('Atirador não encontrado.');
    }

    $ativo = (int) $atirador['ATIVO'];
    $nomeGuerra = (string) $atirador['NOME_GUERRA'];
    $novo = $ativo === 1 ? 0 : 1;

    // Atualização na tabela oficial ATIRADOR
    $stmt = $pdo->prepare('UPDATE ATIRADOR SET ATIVO = :ativo WHERE ID_ATIRADOR = :id');
    $stmt->execute([':ativo' => $novo, ':id' => $id]);

    $statusTexto = $novo ? 'Ativo' : 'Inativo';

    registrar_log(
        $pdo, 
        (int) $_SESSION['id_usuario'], 
        $novo ? 'ATIRADOR_REATIVADO' : 'ATIRADOR_DESATIVADO', 
        "Situação do atirador {$nomeGuerra} alterada para {$statusTexto}."
    );

    $pdo->commit();
    mensagem_flash('sucesso', $novo ? 'Atirador reativado com sucesso.' : 'Atirador desativado com sucesso.');

} catch (Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    mensagem_flash('erro', 'Não foi possível alterar a situação do atirador.');
}

header('Location: pessoas.php', true, 303);
exit;