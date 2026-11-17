<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/layout.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Método não permitido.');
}

validar_csrf();

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) {
    http_response_code(400);
    exit('ID inválido.');
}

$pdo = conectar();
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT ID_ARMAMENTO, SERIAL, MODELO FROM ARMAMENTO WHERE ID_ARMAMENTO = :id FOR UPDATE');
    $stmt->execute([':id' => $id]);
    $arma = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$arma) {
        throw new RuntimeException('Arma não encontrada.', 404);
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM CAUTELA WHERE ID_ARMAMENTO = :id');
    $stmt->execute([':id' => $id]);
    if ((int) $stmt->fetchColumn() !== 0) {
        throw new RuntimeException('Esta arma possui histórico de cautelas e não pode ser excluída. Utilize a opção Desativada na edição após registrar as devoluções pendentes.', 409);
    }
    $stmt = $pdo->prepare('UPDATE ATIRADOR SET ID_ARMAMENTO = NULL WHERE ID_ARMAMENTO = :id');
    $stmt->execute([':id' => $id]);
    $stmt = $pdo->prepare('DELETE FROM ARMAMENTO WHERE ID_ARMAMENTO = :id');
    $stmt->execute([':id' => $id]);
    registrar_log($pdo, (int) $_SESSION['id_usuario'], 'ARMA_EXCLUIDA', 'Arma ' . $arma['SERIAL'] . ' (' . $arma['MODELO'] . ') excluída sem histórico de cautelas.');
    $pdo->commit();
    mensagem_flash('sucesso', 'Arma excluída com sucesso.');
    header('Location: catalogo.php', true, 303);
    exit;
} catch (Throwable $erro) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $status = $erro instanceof RuntimeException && !($erro instanceof PDOException) ? (int) $erro->getCode() : 500;
    if (!in_array($status, [404, 409], true)) $status = 500;
    $mensagem = $status === 500 ? 'Não foi possível excluir a arma. Nenhum cadastro foi removido.' : $erro->getMessage();
    http_response_code($status);
    abrir_painel('Exclusão não concluída', 'armas');
    ?>
    <section class="painel-card">
      <h1>Exclusão não concluída</h1>
      <p><?= e($mensagem) ?></p>
      <a class="botao-voltar" href="catalogo.php">Voltar às armas</a>
    </section>
    <?php
    fechar_painel();
}
