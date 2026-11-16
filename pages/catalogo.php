<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/layout.php';

// Filtros de status baseados no campo DISPONIBILIDADE (1 = Disponível, 0 = Cautelada/Indisponível)
$statusPermitidos = ['todos', 'disponivel', 'cautelada'];
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
    $where[] = 'A.DISPONIBILIDADE = 1';
} elseif ($status === 'cautelada') {
    $where[] = 'A.DISPONIBILIDADE = 0';
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

// Consulta ajustada para usar a tabela CAUTELA (onde HORA_F IS NULL indica cautela em aberto)
$stmt = $pdo->prepare('SELECT A.ID_ARMAMENTO, A.SERIAL, A.MODELO, A.DISPONIBILIDADE,
        C.ID_CAUTELA AS EMPRESTIMO_ABERTO
    FROM ARMAMENTO A
    LEFT JOIN CAUTELA C
      ON C.ID_ARMAMENTO = A.ID_ARMAMENTO AND C.HORA_F IS NULL'
    . $sqlWhere .
    ' ORDER BY A.ID_ARMAMENTO DESC LIMIT :limite OFFSET :offset');

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
              <?php if ((int) $item['DISPONIBILIDADE'] === 1): ?>
                <span class="estado estado-ativo">Disponível</span>
              <?php else: ?>
                <span class="estado estado-alerta">Cautelada</span>
              <?php endif; ?>
            </td>
            <td style="text-align: right;">
              <div class="acoes-linha" style="display: inline-flex; gap: 6px;">
                <?php if ((int) $item['DISPONIBILIDADE'] === 1): ?>
                  <a class="botao-linha" href="emprestimo-form.php?armamento=<?= (int) $item['ID_ARMAMENTO'] ?>">Cautelar</a>
                <?php elseif (!empty($item['EMPRESTIMO_ABERTO'])): ?>
                  <a class="botao-linha" href="emprestimo-form.php?id=<?= (int) $item['EMPRESTIMO_ABERTO'] ?>">Gerenciar</a>
                <?php endif; ?>
                <a class="botao-linha" href="emprestimos.php?busca=<?= urlencode($item['SERIAL']) ?>">Histórico</a>
                <a class="botao-linha" href="catalogo-form.php?id=<?= (int) $item['ID_ARMAMENTO'] ?>">Editar</a>
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
