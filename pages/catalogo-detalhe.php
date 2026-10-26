<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/emprestimo-armamento-funcoes.php';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$id) { http_response_code(404); exit('Registro não encontrado.'); }
$pdo = conectar();
$stmt = $pdo->prepare('SELECT ID_ARMAMENTO,SERIAL,MODELO,CONDICAO,DESCRICAO_CATALOGO,
  FOTO_ARQUIVO,ESTADO_DOCUMENTAL,STATUS_ARMA,CRIADO_EM,ATUALIZADO_EM FROM ARMAMENTO WHERE ID_ARMAMENTO=:id');
$stmt->execute([':id'=>$id]);
$item = $stmt->fetch();
if (!$item) { http_response_code(404); exit('Registro não encontrado.'); }
$stmt = $pdo->prepare('SELECT H.ID_HISTORICO,H.TIPO_EVENTO,H.DESCRICAO,H.CRIADO_EM,U.NOME
 FROM HISTORICO_CATALOGO H LEFT JOIN USUARIO U ON U.ID_USUARIO=H.ID_USUARIO
 WHERE H.ID_ARMAMENTO=:id ORDER BY H.CRIADO_EM DESC,H.ID_HISTORICO DESC LIMIT 150');
$stmt->execute([':id'=>$id]);
$historico = $stmt->fetchAll();
$tipos = ['OBSERVACAO'=>'Observação','DEFEITO_RELATADO'=>'Defeito relatado',
          'ENVIADO_MANUTENCAO'=>'Enviado à manutenção','RETORNOU_MANUTENCAO'=>'Retornou da manutenção'];
$flash = recuperar_flash();
abrir_painel('Histórico da arma', 'armas');
?>
<div class="cabecalho-pagina"><div><div class="sobretitulo">Armaria</div><h1><?= e($item['SERIAL']) ?></h1><p>Histórico e manutenção.</p></div><a class="botao-voltar" href="catalogo.php">← Voltar</a></div>
<?php if ($flash): ?><div role="status" class="mensagem <?= e($flash['tipo']) ?>"><?= e($flash['mensagem']) ?></div><?php endif; ?>
<div class="grade-cadastro-pessoa">
  <section class="painel-card">
    <div class="cartao-topo"><div><h2>Histórico</h2><p>Linha do tempo</p></div></div>
    <?php if (!$historico): ?><p class="vazio">Sem eventos registrados.</p><?php else: ?>
      <div class="historico-lista">
        <?php foreach ($historico as $evento): ?>
          <article class="historico-evento">
            <strong><?= e($tipos[$evento['TIPO_EVENTO']] ?? $evento['TIPO_EVENTO']) ?></strong>
            <time><?= e(date('d/m/Y H:i:s', strtotime($evento['CRIADO_EM']))) ?> · <?= e($evento['NOME'] ?? 'Conta removida') ?></time>
            <p><?= nl2br(e($evento['DESCRICAO'])) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="cartao-topo"><div><h2>Novo evento</h2><p>Manutenção e observações</p></div></div>
    <form class="form-painel" method="post" action="catalogo-evento.php">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>">
      <label for="tipo">Tipo *</label><select id="tipo" name="tipo" required>
        <?php foreach ($tipos as $chave=>$titulo): ?><option value="<?= e($chave) ?>"><?= e($titulo) ?></option><?php endforeach; ?>
      </select>
      <label for="descricao">Descrição *</label><textarea id="descricao" name="descricao" maxlength="2000" minlength="5" rows="4" required></textarea>
      <button type="submit" class="botao-primario">Registrar</button>
    </form>
  </section>
  <aside class="painel-card painel-card-info">
    <div class="foto-preview-container">
      <?php if ($item['FOTO_ARQUIVO']): ?><img class="foto-preview" src="catalogo-foto.php?id=<?= $id ?>" alt="Foto da arma <?= e($item['SERIAL']) ?>">
      <?php else: ?><div class="foto-placeholder">Sem foto</div><?php endif; ?>
    </div>
    <p><strong>Status:</strong> <?= e(status_arma_label((string) $item['STATUS_ARMA'])) ?></p>
    <p><strong>Modelo:</strong> <?= e($item['MODELO'] ?: 'Não informado') ?></p>
    <p><strong>Observação:</strong> <?= e($item['CONDICAO'] ?: '—') ?></p>
    <p><strong>Descrição:</strong> <?= nl2br(e($item['DESCRICAO_CATALOGO'] ?: '—')) ?></p>
    <p><strong>Atualizado em:</strong> <?= e(date('d/m/Y H:i', strtotime($item['ATUALIZADO_EM']))) ?></p>
  </aside>
</div>
<?php fechar_painel(); ?>
