<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/emprestimo-armamento-funcoes.php';
$id = filter_var($_GET['id'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($id === false) { http_response_code(400); exit('ID inválido.'); }
$item = ['ID_ARMAMENTO'=>0, 'SERIAL'=>'', 'MODELO'=>'', 'CONDICAO'=>'', 'DESCRICAO_CATALOGO'=>'', 'FOTO_ARQUIVO'=>null, 'STATUS_ARMA'=>'DISPONIVEL'];
if ($id > 0) {
    $stmt = conectar()->prepare('SELECT ID_ARMAMENTO,SERIAL,MODELO,CONDICAO,DESCRICAO_CATALOGO,FOTO_ARQUIVO,STATUS_ARMA FROM ARMAMENTO WHERE ID_ARMAMENTO=:id');
    $stmt->execute([':id'=>$id]);
    $item = $stmt->fetch();
    if (!$item) { http_response_code(404); exit('Registro não encontrado.'); }
}
$titulo = $id ? 'Editar arma' : 'Nova arma';
$flash = recuperar_flash();
abrir_painel($titulo, 'armas');
?>
<div class="cabecalho-pagina">
  <div><div class="sobretitulo">Armaria</div><h1><?= e($titulo) ?></h1><p>Dados da arma.</p></div>
  <a class="botao-voltar" href="catalogo.php">← Voltar</a>
</div>
<?php if ($flash): ?><div role="status" class="mensagem <?= e($flash['tipo']) ?>"><?= e($flash['mensagem']) ?></div><?php endif; ?>
<div class="grade-cadastro-pessoa">
  <section class="painel-card">
    <form method="post" action="catalogo-salvar.php" enctype="multipart/form-data" class="form-painel">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$item['ID_ARMAMENTO'] ?>">
      <label for="serial">Número de série *</label><input id="serial" name="serial" type="text" minlength="2" maxlength="100" required value="<?= e($item['SERIAL']) ?>">
      <label for="modelo">Modelo</label><input id="modelo" name="modelo" maxlength="140" value="<?= e($item['MODELO']) ?>">
      <label for="condicao">Observação</label><input id="condicao" name="condicao" maxlength="150" value="<?= e($item['CONDICAO']) ?>">
      <label for="status_arma">Status *</label>
      <select id="status_arma" name="status_arma" required>
        <?php foreach (['DISPONIVEL','EMPRESTADA','MANUTENCAO','INATIVA'] as $status): ?>
          <option value="<?= e($status) ?>" <?= $item['STATUS_ARMA'] === $status ? 'selected' : '' ?>><?= e(status_arma_label($status)) ?></option>
        <?php endforeach; ?>
      </select>
      <small>Se a arma tiver um empréstimo em aberto, o status será mantido como Emprestada.</small>
      <label for="descricao">Descrição</label><textarea name="descricao" id="descricao" maxlength="2000" rows="4"><?= e($item['DESCRICAO_CATALOGO']) ?></textarea>
      <label for="foto">Foto</label><input id="foto" name="foto" type="file" accept="image/jpeg,image/png,image/webp" data-preview="foto-preview" data-placeholder="foto-placeholder">
      <small>JPG, PNG ou WEBP até 2 MB.</small>
      <?php if ($item['FOTO_ARQUIVO']): ?><label class="check-foto"><input type="checkbox" name="remover_foto" value="1"> Remover foto atual</label><?php endif; ?>
      <button class="botao-primario" type="submit">Salvar</button>
    </form>
  </section>
  <aside class="painel-card painel-card-info">
    <div class="foto-preview-container">
      <img id="foto-preview" class="foto-preview" src="<?= $id && $item['FOTO_ARQUIVO'] ? 'catalogo-foto.php?id=' . (int) $id : '' ?>" alt="Prévia da foto" <?= !$id || !$item['FOTO_ARQUIVO'] ? 'hidden' : '' ?>>
      <div id="foto-placeholder" class="foto-placeholder" <?= $id && $item['FOTO_ARQUIVO'] ? 'hidden' : '' ?>>Sem foto</div>
    </div>
    <p><strong>Status atual:</strong> <?= e(status_arma_label((string) $item['STATUS_ARMA'])) ?></p>
    <?php if ($id): ?><p><a class="link-discreto" href="catalogo-detalhe.php?id=<?= (int)$id ?>">Abrir histórico →</a></p><?php endif; ?>
  </aside>
</div>
<script src="../assets/js/pessoas.js" defer></script>
<?php fechar_painel(); ?>
