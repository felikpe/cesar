<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/emprestimo-armamento-funcoes.php';

$statusPermitidos = ['todos', 'DISPONIVEL', 'EMPRESTADA', 'MANUTENCAO', 'INATIVA'];
$q = trim((string) ($_GET['q'] ?? ''));
if (strlen($q) > 100) $q = substr($q, 0, 100);
$status = (string) ($_GET['status'] ?? 'todos');
if (!in_array($status, $statusPermitidos, true)) $status = 'todos';
$pagina = max(1, min(10000, (int) ($_GET['pagina'] ?? 1)));
$limite = 12;
$where = [];
$params = [];
if ($q !== '') {
    $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
    $where[] = "(A.SERIAL LIKE :s ESCAPE '!' OR A.MODELO LIKE :m ESCAPE '!')";
    $params[':s'] = '%' . $literal . '%';
    $params[':m'] = '%' . $literal . '%';
}
if ($status !== 'todos') {
    $where[] = 'A.STATUS_ARMA = :status';
    $params[':status'] = $status;
}
$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$pdo = conectar();
$contagem = $pdo->prepare('SELECT COUNT(*) FROM ARMAMENTO A' . $sqlWhere);
$contagem->execute($params);
$total = (int) $contagem->fetchColumn();
$paginas = max(1, (int) ceil($total / $limite));
$pagina = min($pagina, $paginas);
$stmt = $pdo->prepare('SELECT A.ID_ARMAMENTO, A.SERIAL, A.MODELO, A.FOTO_ARQUIVO,
        A.STATUS_ARMA, A.ESTADO_DOCUMENTAL,
        EA.ID_EMPRESTIMO_ARMAMENTO AS EMPRESTIMO_ABERTO
    FROM ARMAMENTO A
    LEFT JOIN EMPRESTIMO_ARMAMENTO EA
      ON EA.ID_ARMAMENTO = A.ID_ARMAMENTO AND EA.DATA_DEVOLUCAO IS NULL'
      . $sqlWhere .
    ' ORDER BY A.ID_ARMAMENTO DESC LIMIT :limite OFFSET :offset');
foreach ($params as $k => $v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
$stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($pagina - 1) * $limite, PDO::PARAM_INT);
$stmt->execute();
$itens = $stmt->fetchAll();
$flash = recuperar_flash();
abrir_painel('Armas', 'armas');
?>
<div class="cabecalho-pagina">
  <div>
    <div class="sobretitulo">Armaria</div>
    <h1>Armas</h1>
    <p>Cadastro, status e histórico.</p>
  </div>
  <a class="botao-primario" href="catalogo-form.php">+ Nova arma</a>
</div>
<?php if ($flash): ?><div class="mensagem <?= e($flash['tipo']) ?>" role="status"><?= e($flash['mensagem']) ?></div><?php endif; ?>
<section class="painel-card">
  <div class="cartao-topo"><div><h2>Lista</h2><p><?= $total ?> resultado(s)</p></div></div>
  <form class="filtros" method="get" action="catalogo.php">
    <input type="search" name="q" value="<?= e($q) ?>" maxlength="100" placeholder="Série ou modelo">
    <select name="status">
      <option value="todos">Todos os status</option>
      <option value="DISPONIVEL" <?= $status === 'DISPONIVEL' ? 'selected' : '' ?>>Disponível</option>
      <option value="EMPRESTADA" <?= $status === 'EMPRESTADA' ? 'selected' : '' ?>>Emprestada</option>
      <option value="MANUTENCAO" <?= $status === 'MANUTENCAO' ? 'selected' : '' ?>>Manutenção</option>
      <option value="INATIVA" <?= $status === 'INATIVA' ? 'selected' : '' ?>>Inativa</option>
    </select>
    <button type="submit" class="botao-secundario">Buscar</button>
    <a class="link-discreto" href="catalogo.php">Limpar</a>
  </form>
  <div class="tabela-scroll">
    <table class="tabela">
      <thead><tr><th>Arma</th><th>Modelo</th><th>Status</th><th>Ações</th></tr></thead>
      <tbody>
      <?php foreach ($itens as $item): ?>
        <tr>
          <td>
            <div class="pessoa-identidade">
              <?php if ($item['FOTO_ARQUIVO']): ?>
                <img class="pessoa-miniatura" src="catalogo-foto.php?id=<?= (int) $item['ID_ARMAMENTO'] ?>" alt="Foto da arma">
              <?php else: ?>
                <span class="pessoa-miniatura pessoa-sem-foto" aria-hidden="true">—</span>
              <?php endif; ?>
              <div>
                <strong><?= e($item['SERIAL']) ?></strong>
              </div>
            </div>
          </td>
          <td><?= e($item['MODELO'] ?: 'Não informado') ?></td>
          <td><span class="estado <?= e(status_arma_classe((string) $item['STATUS_ARMA'])) ?>"><?= e(status_arma_label((string) $item['STATUS_ARMA'])) ?></span></td>
          <td>
            <div class="acoes-pessoa">
              <?php if ((string) $item['STATUS_ARMA'] === 'DISPONIVEL'): ?>
                <a class="botao-linha" href="emprestimo-form.php?armamento=<?= (int) $item['ID_ARMAMENTO'] ?>">Emprestar</a>
              <?php elseif ((string) $item['STATUS_ARMA'] === 'EMPRESTADA' && $item['EMPRESTIMO_ABERTO']): ?>
                <a class="botao-linha" href="emprestimo-form.php?id=<?= (int) $item['EMPRESTIMO_ABERTO'] ?>">Gerenciar</a>
              <?php endif; ?>
              <a class="botao-linha" href="catalogo-detalhe.php?id=<?= (int) $item['ID_ARMAMENTO'] ?>">Histórico</a>
              <a class="botao-linha" href="catalogo-form.php?id=<?= (int) $item['ID_ARMAMENTO'] ?>">Editar</a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$itens): ?><tr><td colspan="4" class="vazio">Nenhuma arma encontrada.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="paginacao"><span>Página <?= $pagina ?> de <?= $paginas ?></span><div>
    <?php if ($pagina > 1): ?><a href="?<?= e(http_build_query(['q'=>$q,'status'=>$status,'pagina'=>$pagina-1])) ?>">← Anterior</a><?php endif; ?>
    <?php if ($pagina < $paginas): ?><a href="?<?= e(http_build_query(['q'=>$q,'status'=>$status,'pagina'=>$pagina+1])) ?>">Próxima →</a><?php endif; ?>
  </div></div>
</section>
<?php fechar_painel(); ?>
