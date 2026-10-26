<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/emprestimo-armamento-funcoes.php';
$pdo = conectar();
$id = filter_var($_GET['id'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0;
$armamentoPreSelecionado = filter_var($_GET['armamento'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0;
$flash = recuperar_flash();
$registro = null;
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT M.*, A.SERIAL, A.MODELO, A.FABRICANTE, P.NOME AS PESSOA_NOME, P.IDENTIFICADOR
        FROM EMPRESTIMO_ARMAMENTO M
        JOIN ARMAMENTO A ON A.ID_ARMAMENTO = M.ID_ARMAMENTO
        JOIN PESSOA P ON P.ID_PESSOA = M.ID_PESSOA
        WHERE M.ID_EMPRESTIMO_ARMAMENTO = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $registro = $stmt->fetch();
    if (!$registro) { http_response_code(404); exit('Empréstimo não encontrado.'); }
}
$pessoas = $pdo->query('SELECT ID_PESSOA, NOME, IDENTIFICADOR FROM PESSOA WHERE ATIVO = 1 ORDER BY NOME')->fetchAll();
if ($id === 0) {
    $sql = "SELECT A.ID_ARMAMENTO, A.SERIAL, A.MODELO
        FROM ARMAMENTO A
        WHERE A.STATUS_ARMA = 'DISPONIVEL'
        ORDER BY A.SERIAL";
    $armas = $pdo->query($sql)->fetchAll();
} else {
    $sql = 'SELECT ID_ARMAMENTO, SERIAL, MODELO FROM ARMAMENTO WHERE ID_ARMAMENTO = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => (int) $registro['ID_ARMAMENTO']]);
    $armas = $stmt->fetchAll();
}
$titulo = $id > 0 ? 'Gerenciar empréstimo' : 'Novo empréstimo';
abrir_painel($titulo, $id > 0 ? 'emprestimos' : 'novo_emprestimo');
?>
<div class="cabecalho-pagina">
  <div><div class="sobretitulo">Movimentação</div><h1><?= e($titulo) ?></h1><p><?= $id > 0 ? 'Alterar prazo ou registrar devolução.' : 'Selecione um atirador cadastrado.' ?></p></div>
  <a class="botao-voltar" href="emprestimos.php">← Voltar</a>
</div>
<?php if ($flash): ?><div role="status" class="mensagem <?= e($flash['tipo']) ?>"><?= e($flash['mensagem']) ?></div><?php endif; ?>
<div class="grade-cadastro-pessoa">
  <section class="painel-card">
    <?php if ($id === 0): ?>
      <form method="post" action="emprestimo-salvar.php" class="form-painel">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="acao" value="novo">
        <label for="id_armamento">Arma *</label>
        <select id="id_armamento" name="id_armamento" required>
          <option value="">Selecione</option>
          <?php foreach ($armas as $arma): ?>
            <option value="<?= (int) $arma['ID_ARMAMENTO'] ?>" <?= $armamentoPreSelecionado === (int) $arma['ID_ARMAMENTO'] ? 'selected' : '' ?>><?= e($arma['SERIAL'] . ' - ' . ($arma['MODELO'] ?: 'Sem modelo')) ?></option>
          <?php endforeach; ?>
        </select>
        <label for="id_pessoa">Atirador *</label>
        <select id="id_pessoa" name="id_pessoa" required>
          <option value="">Selecione</option>
          <?php foreach ($pessoas as $pessoa): ?>
            <option value="<?= (int) $pessoa['ID_PESSOA'] ?>"><?= e($pessoa['NOME'] . ' - ' . $pessoa['IDENTIFICADOR']) ?></option>
          <?php endforeach; ?>
        </select>
        <label for="data_emprestimo">Data do empréstimo *</label>
        <input id="data_emprestimo" name="data_emprestimo" type="datetime-local" required value="<?= e(date('Y-m-d\TH:i')) ?>">
        <label for="data_prevista">Data prevista para devolução *</label>
        <input id="data_prevista" name="data_prevista" type="datetime-local" required>
        <label for="observacao">Observação</label>
        <textarea id="observacao" name="observacao" maxlength="500"></textarea>
        <button class="botao-primario" type="submit">Registrar empréstimo</button>
      </form>
    <?php else: ?>
      <?php $aberto = $registro['DATA_DEVOLUCAO'] === null; ?>
      <form method="post" action="emprestimo-salvar.php" class="form-painel">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $registro['ID_EMPRESTIMO_ARMAMENTO'] ?>">
        <input type="hidden" name="acao" value="alterar_prazo">
        <label>Arma</label>
        <input type="text" value="<?= e($registro['SERIAL'] . ' - ' . ($registro['MODELO'] ?: 'Sem modelo')) ?>" readonly>
        <label>Atirador</label>
        <input type="text" value="<?= e($registro['PESSOA_NOME'] . ' - ' . $registro['IDENTIFICADOR']) ?>" readonly>
        <label>Data do empréstimo</label>
        <input type="text" value="<?= e(data_legivel_armaria($registro['DATA_EMPRESTIMO'])) ?>" readonly>
        <label for="data_prevista">Data prevista *</label>
        <input id="data_prevista" name="data_prevista" type="datetime-local" required value="<?= e(data_banco_para_form_armaria($registro['DATA_PREVISTA_DEVOLUCAO'])) ?>" <?= !$aberto ? 'disabled' : '' ?>>
        <label for="justificativa">Justificativa da alteração *</label>
        <textarea id="justificativa" name="justificativa" maxlength="500" <?= !$aberto ? 'disabled' : '' ?>></textarea>
        <?php if ($aberto): ?><button class="botao-primario" type="submit">Salvar novo prazo</button><?php endif; ?>
      </form>
      <?php if ($aberto): ?>
        <hr style="border:0;border-top:1px solid #dde3da;margin:18px 0;">
        <form method="post" action="emprestimo-salvar.php" class="form-painel">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="id" value="<?= (int) $registro['ID_EMPRESTIMO_ARMAMENTO'] ?>">
          <input type="hidden" name="acao" value="devolver">
          <label for="data_devolucao">Data da devolução *</label>
          <input id="data_devolucao" name="data_devolucao" type="datetime-local" required value="<?= e(date('Y-m-d\TH:i')) ?>">
          <label for="observacao_devolucao">Observação da devolução</label>
          <textarea id="observacao_devolucao" name="observacao_devolucao" maxlength="500"></textarea>
          <button class="botao-secundario" type="submit">Registrar devolução</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </section>
  <aside class="painel-card painel-card-info">
    <?php if ($id === 0): ?>
      <p>Somente armas com status <strong>Disponível</strong> aparecem para novo empréstimo.</p>
      <p>Somente atiradores ativos aparecem para seleção.</p>
    <?php else: ?>
      <p><strong>Status:</strong> <?= $registro['DATA_DEVOLUCAO'] === null ? 'Em uso' : 'Devolvida' ?></p>
      <p><strong>Prevista:</strong> <?= e(data_legivel_armaria($registro['DATA_PREVISTA_DEVOLUCAO'])) ?></p>
      <?php if ($registro['DATA_DEVOLUCAO'] !== null): ?><p><strong>Devolução:</strong> <?= e(data_legivel_armaria($registro['DATA_DEVOLUCAO'])) ?></p><?php endif; ?>
      <p><a class="link-discreto" href="catalogo-detalhe.php?id=<?= (int) $registro['ID_ARMAMENTO'] ?>">Abrir histórico da arma →</a></p>
    <?php endif; ?>
  </aside>
</div>
<?php fechar_painel(); ?>
