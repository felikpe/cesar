<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/biometria-painel.php';

$pdo = conectar();
$id = filter_var($_GET['id'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0;
$origemAuditoria = ($_GET['origem'] ?? '') === 'auditoria';
$voltar = 'emprestimos.php';
if ($origemAuditoria) {
    exigir_admin();
    if ($id < 1) {
        http_response_code(400);
        exit('Cautela inválida.');
    }
    $filtrosAuditoria = [];
    foreach (['q', 'atirador', 'armeiro', 'situacao', 'pagina'] as $campo) {
        $valor = $_GET[$campo] ?? null;
        if (is_string($valor) && strlen($valor) <= 100) $filtrosAuditoria[$campo] = $valor;
    }
    $voltar = 'logs-emprestimos.php' . ($filtrosAuditoria ? '?' . http_build_query($filtrosAuditoria) : '');
}
$armamentoPreSelecionado = filter_var($_GET['armamento'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0;
$flash = recuperar_flash();
$registro = null;

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT M.*, A.SERIAL, A.MODELO, T.NOME AS ATIRADOR_NOME, T.NOME_GUERRA, T.RA AS IDENTIFICADOR
        FROM CAUTELA M
        JOIN ARMAMENTO A ON A.ID_ARMAMENTO = M.ID_ARMAMENTO
        JOIN ATIRADOR T ON T.ID_ATIRADOR = M.ID_ATIRADOR
        WHERE M.ID_CAUTELA = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $registro = $stmt->fetch();
    if (!$registro) {
        http_response_code(404);
        exit('Cautela não encontrada.');
    }
}

$pessoas = $pdo->query('SELECT ID_ATIRADOR, NOME, NOME_GUERRA, RA FROM ATIRADOR WHERE ATIVO = 1 ORDER BY NOME')->fetchAll();
if ($id === 0) {
    $sql = "SELECT A.ID_ARMAMENTO, A.SERIAL, A.MODELO
        FROM ARMAMENTO A
        WHERE A.DISPONIBILIDADE = 1 AND A.ATIVO = 1
          AND NOT EXISTS (
              SELECT 1 FROM CAUTELA C
              WHERE C.ID_ARMAMENTO = A.ID_ARMAMENTO AND C.HORA_F IS NULL
          )
        ORDER BY A.SERIAL";
    $armas = $pdo->query($sql)->fetchAll();
} else {
    $sql = 'SELECT ID_ARMAMENTO, SERIAL, MODELO FROM ARMAMENTO WHERE ID_ARMAMENTO = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => (int) $registro['ID_ARMAMENTO']]);
    $armas = $stmt->fetchAll();
}

$titulo = $origemAuditoria ? 'Detalhes da Cautela' : ($id > 0 ? 'Gerenciar Cautela' : 'Nova Cautela');
abrir_painel($titulo, $origemAuditoria ? 'logs' : ($id > 0 ? 'emprestimos' : 'novo_emprestimo'));
?>
<div class="cabecalho-pagina">
  <div>
    <div class="sobretitulo">Movimentação</div>
    <h1><?= e($titulo) ?></h1>
    <p><?= $origemAuditoria ? 'Consulta dos dados e da devolução da cautela.' : ($id > 0 ? 'Detalhes da cautela e registro de devolução.' : 'Selecione o armamento e o atirador ativo.') ?></p>
  </div>
  <a class="botao-voltar" href="<?= e($voltar) ?>">← Voltar</a>
</div>

<?php if ($flash): ?>
  <div role="status" class="mensagem <?= e($flash['tipo']) ?>"><?= e($flash['mensagem']) ?></div>
<?php endif; ?>

<div class="grade-cadastro-pessoa">
  <section class="painel-card">
    <?php if ($id === 0): ?>
      <form method="post" action="emprestimo-salvar.php" class="form-painel">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="acao" value="novo">

        <label for="id_armamento">Arma Disponível *</label>
        <select id="id_armamento" name="id_armamento" required>
          <option value="">Selecione a arma</option>
          <?php foreach ($armas as $arma): ?>
            <option value="<?= (int) $arma['ID_ARMAMENTO'] ?>" <?= $armamentoPreSelecionado === (int) $arma['ID_ARMAMENTO'] ? 'selected' : '' ?>>
              <?= e($arma['SERIAL'] . ' - ' . ($arma['MODELO'] ?: 'Sem modelo')) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <label for="id_pessoa">Atirador *</label>
        <div class="biometria-campo">
          <select id="id_pessoa" name="id_pessoa" required>
            <option value="">Selecione o atirador</option>
            <?php foreach ($pessoas as $pessoa): ?>
              <option value="<?= (int) $pessoa['ID_ATIRADOR'] ?>">
                <?= e($pessoa['NOME_GUERRA'] . ' - ' . $pessoa['NOME'] . ' (RA: ' . $pessoa['RA'] . ')') ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php botao_biometria('identificar', 0, null, 'Identificar atirador pela digital'); ?>
        </div>

        <label for="missao">Missão / Motivo</label>
        <input id="missao" name="missao" type="text" maxlength="150" placeholder="Ex.: Guarda ao Quartel, Instrução de Tiro" value="Instrução / Rotina">

        <label for="acessorios">Acessórios</label>
        <textarea id="acessorios" name="acessorios" rows="3" maxlength="255" placeholder="Ex.: Bandoleira, ferrolho, etc."></textarea>

        <button class="botao-primario" type="submit" style="margin-top: 15px;">Registrar Cautela</button>
      </form>
    <?php else: ?>
      <?php $aberto = $registro['HORA_F'] === null; ?>
      
      <div class="form-painel">
        <label>Arma</label>
        <input type="text" value="<?= e($registro['SERIAL'] . ' - ' . ($registro['MODELO'] ?: 'Sem modelo')) ?>" readonly>

        <label>Atirador</label>
        <input type="text" value="<?= e($registro['ATIRADOR_NOME'] . ' (RA: ' . $registro['IDENTIFICADOR'] . ')') ?>" readonly>

        <label>Data e Hora de Saída</label>
        <input type="text" value="<?= e(date('d/m/Y', strtotime($registro['DIA']))) ?> às <?= e($registro['HORA_I']) ?>" readonly>

        <label>Missão</label>
        <input type="text" value="<?= e($registro['MISSAO'] ?: 'Não informada') ?>" readonly>

        <?php if (!empty($registro['ACESSORIOS'])): ?>
          <label>Acessórios</label>
          <input type="text" value="<?= e($registro['ACESSORIOS']) ?>" readonly>
        <?php endif; ?>
      </div>

      <?php if ($aberto && !$origemAuditoria): ?>
        <hr style="border:0;border-top:1px solid #dde3da;margin:24px 0;">
        <h3>Registrar Devolução</h3>
        <form method="post" action="emprestimo-salvar.php" class="form-painel" onsubmit="return window.confirm('Confirmar a devolução deste armamento?');">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="id" value="<?= (int) $registro['ID_CAUTELA'] ?>">
          <input type="hidden" name="acao" value="devolver">

          <div style="margin: 12px 0;">
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
              <input type="checkbox" name="com_alteracao" value="1">
              <strong>Armamento devolvido com alteração / avaria</strong>
            </label>
          </div>

          <label for="descricao_devolucao">Observações / Descrição da Alteração</label>
          <textarea id="descricao_devolucao" name="descricao_devolucao" rows="3" maxlength="255" placeholder="Caso haja alteração ou avaria no armamento, descreva detalhadamente."></textarea>

          <div class="biometria-acao-form" style="margin-top: 15px;">
            <button class="botao-secundario" type="submit">Confirmar Devolução</button>
            <?php botao_biometria('identificar', (int) $registro['ID_ATIRADOR'], null, 'Conferir digital para devolução', (string) ($registro['NOME_GUERRA'] ?: $registro['ATIRADOR_NOME'])); ?>
          </div>
        </form>
      <?php elseif (!$aberto): ?>
        <hr style="border:0;border-top:1px solid #dde3da;margin:24px 0;">
        <div class="form-painel">
          <label>Data e Hora de Devolução</label>
          <input type="text" value="<?= e(data_hora_devolucao($registro)) ?>" readonly>

          <label>Situação da Devolução</label>
          <input type="text" value="<?= (int) $registro['COM_ALTERACAO'] === 1 ? 'Com Alteração / Avaria' : 'Sem Alteração' ?>" readonly>

          <?php if (!empty($registro['DESCRICAO_DEVOLUCAO'])): ?>
            <label>Descrição da Devolução</label>
            <textarea readonly rows="3"><?= e($registro['DESCRICAO_DEVOLUCAO']) ?></textarea>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <aside class="painel-card painel-card-info">
    <?php if ($id === 0): ?>
      <h3>Orientações</h3>
      <p>Somente armas com status <strong>Disponível</strong> aparecem para cautela.</p>
      <p>Somente atiradores <strong>ativos</strong> na tropa podem cautelar armamento.</p>
      <p>A data e o horário de saída são registrados automaticamente pelo sistema.</p>
    <?php else: ?>
      <h3>Status da Cautela #<?= (int) $registro['ID_CAUTELA'] ?></h3>
      <p><strong>Situação:</strong> <?= $aberto ? 'Em uso (Cautelada)' : 'Devolvida ao Paiol' ?></p>
      <p><strong>Data de Saída:</strong> <?= e(date('d/m/Y', strtotime($registro['DIA']))) ?> às <?= e($registro['HORA_I']) ?></p>
      <?php if (!$aberto): ?>
        <p><strong>Devolvida:</strong> <?= e(data_hora_devolucao($registro)) ?></p>
        <?php if ((int) $registro['COM_ALTERACAO'] === 1): ?>
          <p style="color: #c53030;"><strong>Atenção:</strong> Devolução com alteração registrada.</p>
        <?php endif; ?>
      <?php endif; ?>
      <p style="margin-top: 15px;">
        <a class="link-discreto" href="emprestimos.php?busca=<?= urlencode($registro['SERIAL']) ?>&amp;status=todos">Histórico desta arma →</a>
      </p>
    <?php endif; ?>
  </aside>
</div>
<?php fechar_painel(); ?>
