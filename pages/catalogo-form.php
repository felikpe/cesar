<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/layout.php';

$id = filter_var($_GET['id'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($id === false) {
    http_response_code(400);
    exit('ID inválido.');
}

// Estrutura inicial enxuta baseada no schema.sql
$item = [
    'ID_ARMAMENTO' => 0, 
    'SERIAL' => '', 
    'MODELO' => 'MOSQUEFAL', 
    'CONDICAO' => '', 
    'DISPONIBILIDADE' => 1,
    'ATIVO' => 1,
    'EMPRESTIMO_ABERTO' => null,
    'TOTAL_CAUTELAS' => 0
];

if ($id > 0) {
    $stmt = conectar()->prepare('SELECT A.ID_ARMAMENTO, A.SERIAL, A.MODELO, A.CONDICAO, A.DISPONIBILIDADE, A.ATIVO,
        (SELECT MIN(C.ID_CAUTELA) FROM CAUTELA C WHERE C.ID_ARMAMENTO = A.ID_ARMAMENTO AND C.HORA_F IS NULL) AS EMPRESTIMO_ABERTO,
        (SELECT COUNT(*) FROM CAUTELA H WHERE H.ID_ARMAMENTO = A.ID_ARMAMENTO) AS TOTAL_CAUTELAS
        FROM ARMAMENTO A WHERE A.ID_ARMAMENTO = :id');
    $stmt->execute([':id' => $id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        http_response_code(404);
        exit('Registro não encontrado.');
    }
}

$titulo = $id ? 'Editar arma' : 'Nova arma';
$flash = recuperar_flash();

abrir_painel($titulo, 'armas');
?>

<div class="cabecalho-pagina">
  <div>
      <div class="sobretitulo">Armaria</div>
      <h1><?= e($titulo) ?></h1>
      <p>Dados essenciais da arma no paiol.</p>
  </div>
  <a class="botao-voltar" href="catalogo.php">← Voltar</a>
</div>

<?php if ($flash): ?>
    <div role="status" class="mensagem <?= e($flash['tipo']) ?>"><?= e($flash['mensagem']) ?></div>
<?php endif; ?>

<div class="grade-cadastro-pessoa">
  <section class="painel-card">
    <form method="post" action="catalogo-salvar.php" class="form-painel">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int)$item['ID_ARMAMENTO'] ?>">

      <label for="serial">Número de Série (Exatamente 5 caracteres) *</label>
      <input id="serial" name="serial" type="text" minlength="5" maxlength="5" required value="<?= e($item['SERIAL']) ?>" placeholder="Ex: 01234">

      <label for="modelo">Modelo *</label>
      <select id="modelo" name="modelo" required>
        <option value="MOSQUEFAL" <?= $item['MODELO'] === 'MOSQUEFAL' ? 'selected' : '' ?>>Mosquefal</option>
        <option value="PRESSAO" <?= $item['MODELO'] === 'PRESSAO' ? 'selected' : '' ?>>Pressão</option>
      </select>

      <label for="condicao">Condição / Observação</label>
      <input id="condicao" name="condicao" maxlength="250" value="<?= e($item['CONDICAO']) ?>" placeholder="Ex: Bom estado, com arranhões leves">

      <?php if ($id): ?>
        <label for="ativo">Situação do cadastro</label>
        <select id="ativo" name="ativo" required>
          <option value="1" <?= (int) $item['ATIVO'] === 1 ? 'selected' : '' ?>>Ativa</option>
          <option value="0" <?= (int) $item['ATIVO'] === 0 ? 'selected' : '' ?> <?= !empty($item['EMPRESTIMO_ABERTO']) ? 'disabled' : '' ?>>Desativada</option>
        </select>
        <small><?= !empty($item['EMPRESTIMO_ABERTO']) ? 'Registre a devolução antes de desativar esta arma.' : 'Armas desativadas permanecem no histórico e não podem ser cauteladas.' ?></small>
      <?php endif; ?>

      <button class="botao-primario" type="submit" style="margin-top: 20px;">Salvar Arma</button>
    </form>
  </section>

  <aside class="painel-card painel-card-info">
    <h3>Informações</h3>
    <p><strong>Status atual:</strong> <?= !empty($item['EMPRESTIMO_ABERTO']) ? 'Cautelada' : ((int) $item['ATIVO'] === 0 ? 'Desativada' : ((int) $item['DISPONIBILIDADE'] === 1 ? 'Disponível' : 'Indisponível')) ?></p>
    <p><small>O número de série deve conter exatamente 5 caracteres alfanuméricos e ser único no sistema.</small></p>
    <?php if ($id): ?>
        <p><a class="link-discreto" href="emprestimos.php?busca=<?= urlencode($item['SERIAL']) ?>&amp;status=todos">Abrir histórico →</a></p>
        <?php if ((int) $item['TOTAL_CAUTELAS'] === 0): ?>
          <form method="post" action="catalogo-excluir.php" onsubmit="return window.confirm('Excluir esta arma? O vínculo de armamento principal dos atiradores será removido.');">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $item['ID_ARMAMENTO'] ?>">
            <button class="botao-linha" type="submit">Excluir arma</button>
          </form>
        <?php else: ?>
          <p><small>Esta arma possui histórico e pode ser desativada, mas não excluída.</small></p>
        <?php endif; ?>
    <?php endif; ?>
  </aside>
</div>

<?php fechar_painel(); ?>
