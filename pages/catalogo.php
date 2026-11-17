<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/layout.php';

$statusPermitidos = ['todos', 'disponivel', 'cautelada', 'desativada'];
$q = trim((string) ($_GET['q'] ?? ''));
if (strlen($q) > 100) {
    $q = substr($q, 0, 100);
}

$status = (string) ($_GET['status'] ?? 'todos');
if (!in_array($status, $statusPermitidos, true)) {
    $status = 'todos';
}

$pagina = max(1, min(10000, (int) ($_GET['pagina'] ?? 1)));
$limite = 12;
$where = [];
$params = [];

// Busca por SERIAL ou MODELO (conforme o schema.sql)
if ($q !== '') {
    $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
    $where[] = "(A.SERIAL LIKE :s ESCAPE '!' OR A.MODELO LIKE :m ESCAPE '!')";
    $params[':s'] = '%' . $literal . '%';
    $params[':m'] = '%' . $literal . '%';
}

if ($status === 'disponivel') {
    $where[] = 'A.ATIVO = 1';
    $where[] = 'A.DISPONIBILIDADE = 1';
    $where[] = 'NOT EXISTS (SELECT 1 FROM CAUTELA C WHERE C.ID_ARMAMENTO = A.ID_ARMAMENTO AND C.HORA_F IS NULL)';
} elseif ($status === 'cautelada') {
    $where[] = 'EXISTS (SELECT 1 FROM CAUTELA C WHERE C.ID_ARMAMENTO = A.ID_ARMAMENTO AND C.HORA_F IS NULL)';
} elseif ($status === 'desativada') {
    $where[] = 'A.ATIVO = 0';
}

$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$pdo = conectar();

// Contagem total para paginação alinhada à tabela ARMAMENTO
$contagem = $pdo->prepare('SELECT COUNT(*) FROM ARMAMENTO A' . $sqlWhere);
$contagem->execute($params);
$total = (int) $contagem->fetchColumn();

$paginas = max(1, (int) ceil($total / $limite));
$pagina = min($pagina, $paginas);
$offset = ($pagina - 1) * $limite;

$stmt = $pdo->prepare('SELECT A.ID_ARMAMENTO, A.SERIAL, A.MODELO, A.DISPONIBILIDADE, A.ATIVO,
        (SELECT MIN(C.ID_CAUTELA) FROM CAUTELA C
          WHERE C.ID_ARMAMENTO = A.ID_ARMAMENTO AND C.HORA_F IS NULL) AS EMPRESTIMO_ABERTO,
        (SELECT COUNT(*) FROM CAUTELA H WHERE H.ID_ARMAMENTO = A.ID_ARMAMENTO) AS TOTAL_CAUTELAS
    FROM ARMAMENTO A'
    . $sqlWhere .
    " ORDER BY A.ATIVO DESC, CASE WHEN A.MODELO = 'PRESSAO' THEN 1 ELSE 0 END ASC,
        A.ID_ARMAMENTO DESC LIMIT :limite OFFSET :offset");

foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, PDO::PARAM_STR);
}
$stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$itens = $stmt->fetchAll(PDO::FETCH_ASSOC);
$flash = recuperar_flash();

abrir_painel('Armas', 'armas');
?>

<div class="cabecalho-pagina">
  <div>
    <div class="sobretitulo">Armaria</div>
    <h1>Armas</h1>
    <p>Cadastro, disponibilidade e histórico do armamento no paiol.</p>
  </div>
  <a class="botao-primario" href="catalogo-form.php">+ Nova arma</a>
</div>

<?php if ($flash): ?>
  <div class="mensagem <?= e($flash['tipo']) ?>" role="status"><?= e($flash['mensagem']) ?></div>
<?php endif; ?>

<section class="painel-card">
  <div class="cartao-topo">
    <div>
      <h2>Acervo</h2>
      <p><?= $total ?> arma(s) cadastrada(s)</p>
    </div>
  </div>

  <form class="filtros" method="get" action="catalogo.php">
    <input type="search" name="q" value="<?= e($q) ?>" maxlength="100" placeholder="Buscar por serial ou modelo">
    <select name="status" aria-label="Filtrar por status" onchange="this.form.submit()">
      <option value="todos">Todos os status</option>
      <option value="disponivel" <?= $status === 'disponivel' ? 'selected' : '' ?>>Disponível no Paiol</option>
      <option value="cautelada" <?= $status === 'cautelada' ? 'selected' : '' ?>>Cautelada / Em Uso</option>
      <option value="desativada" <?= $status === 'desativada' ? 'selected' : '' ?>>Desativadas</option>
    </select>
    <button type="submit" class="botao-secundario">Buscar</button>
    <?php if ($q !== '' || $status !== 'todos'): ?>
      <a class="link-discreto" href="catalogo.php">Limpar</a>
    <?php endif; ?>
  </form>

  <div class="tabela-scroll">
    <table class="tabela">
      <thead>
        <tr>
          <th>Arma (Serial)</th>
          <th>Modelo</th>
          <th>Status</th>
          <th style="text-align: right;">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($itens as $item): ?>
          <tr>
            <td>
              <strong><?= e($item['SERIAL']) ?></strong>
            </td>
            <td><?= e($item['MODELO'] ?: 'Não informado') ?></td>
            <td>
              <?php if (!empty($item['EMPRESTIMO_ABERTO'])): ?>
                <span class="estado estado-alerta">Cautelada</span>
              <?php elseif ((int) $item['ATIVO'] === 0): ?>
                <span class="estado estado-inativo">Desativada</span>
              <?php elseif ((int) $item['DISPONIBILIDADE'] === 1): ?>
                <span class="estado estado-ativo">Disponível</span>
              <?php else: ?>
                <span class="estado estado-alerta">Indisponível</span>
              <?php endif; ?>
            </td>
            <td style="text-align: right;">
              <div class="acoes-linha" style="display: inline-flex; gap: 6px;">
                <?php if (!empty($item['EMPRESTIMO_ABERTO'])): ?>
                  <a class="botao-linha" href="emprestimo-form.php?id=<?= (int) $item['EMPRESTIMO_ABERTO'] ?>">Gerenciar</a>
                <?php elseif ((int) $item['ATIVO'] === 1 && (int) $item['DISPONIBILIDADE'] === 1): ?>
                  <a class="botao-linha" href="emprestimo-form.php?armamento=<?= (int) $item['ID_ARMAMENTO'] ?>">Cautelar</a>
                <?php endif; ?>
                <a class="botao-linha" href="emprestimos.php?busca=<?= urlencode($item['SERIAL']) ?>&amp;status=todos">Histórico</a>
                <a class="botao-linha" href="catalogo-form.php?id=<?= (int) $item['ID_ARMAMENTO'] ?>">Editar</a>
                <?php if ((int) $item['TOTAL_CAUTELAS'] === 0): ?>
                  <form method="post" action="catalogo-excluir.php" onsubmit="return window.confirm('Excluir esta arma? O vínculo de armamento principal dos atiradores será removido.');">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $item['ID_ARMAMENTO'] ?>">
                    <button class="botao-linha" type="submit">Excluir</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$itens): ?>
          <tr><td colspan="4" class="vazio">Nenhuma arma encontrada com os critérios informados.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($paginas > 1): ?>
    <div class="paginacao">
      <span>Página <?= $pagina ?> de <?= $paginas ?></span>
      <div>
        <?php if ($pagina > 1): ?>
          <a href="?<?= e(http_build_query(['q' => $q, 'status' => $status, 'pagina' => $pagina - 1])) ?>">← Anterior</a>
        <?php endif; ?>
        <?php if ($pagina < $paginas): ?>
          <a href="?<?= e(http_build_query(['q' => $q, 'status' => $status, 'pagina' => $pagina + 1])) ?>">Próxima →</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</section>

<?php fechar_painel(); ?>
