<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/emprestimo-armamento-funcoes.php';
$pdo = conectar();
$q = trim((string) ($_GET['q'] ?? ''));
if (strlen($q) > 100) $q = substr($q, 0, 100);
$status = (string) ($_GET['status'] ?? 'em_uso');
if (!in_array($status, ['em_uso', 'atrasados', 'devolvidos', 'todos'], true)) $status = 'em_uso';
$pagina = max(1, min(10000, (int) ($_GET['pagina'] ?? 1)));
$limite = 15;
$agora = agora_armaria();
$where = [];
$params = [];
if ($q !== '') {
    $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
    $where[] = "(A.SERIAL LIKE :serial ESCAPE '!'
        OR A.MODELO LIKE :modelo ESCAPE '!'
        OR P.NOME LIKE :atirador ESCAPE '!'
        OR P.IDENTIFICADOR LIKE :identificador ESCAPE '!'
        OR U.NOME LIKE :auxiliar ESCAPE '!'
        OR UD.NOME LIKE :auxiliar_devolucao ESCAPE '!')";
    $termo = '%' . $literal . '%';
    $params[':serial'] = $termo;
    $params[':modelo'] = $termo;
    $params[':atirador'] = $termo;
    $params[':identificador'] = $termo;
    $params[':auxiliar'] = $termo;
    $params[':auxiliar_devolucao'] = $termo;
}
if ($status === 'em_uso') $where[] = 'M.DATA_DEVOLUCAO IS NULL';
if ($status === 'devolvidos') $where[] = 'M.DATA_DEVOLUCAO IS NOT NULL';
if ($status === 'atrasados') { $where[] = 'M.DATA_DEVOLUCAO IS NULL AND M.DATA_PREVISTA_DEVOLUCAO < :agora'; $params[':agora'] = $agora; }
$base = ' FROM EMPRESTIMO_ARMAMENTO M
    JOIN ARMAMENTO A ON A.ID_ARMAMENTO = M.ID_ARMAMENTO
    JOIN PESSOA P ON P.ID_PESSOA = M.ID_PESSOA
    JOIN USUARIO U ON U.ID_USUARIO = M.ID_USUARIO_RESPONSAVEL
    LEFT JOIN USUARIO UD ON UD.ID_USUARIO = M.ID_USUARIO_DEVOLUCAO';
$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$count = $pdo->prepare('SELECT COUNT(*)' . $base . $sqlWhere);
$count->execute($params);
$total = (int) $count->fetchColumn();
$paginas = max(1, (int) ceil($total / $limite));
$pagina = min($pagina, $paginas);
$stmt = $pdo->prepare('SELECT M.*, A.SERIAL, A.MODELO, P.NOME AS PESSOA_NOME, P.IDENTIFICADOR, U.NOME AS OPERADOR_NOME, UD.NOME AS DEVOLUCAO_AUXILIAR_NOME'
    . $base . $sqlWhere . ' ORDER BY (M.DATA_DEVOLUCAO IS NULL) DESC, M.DATA_PREVISTA_DEVOLUCAO ASC, M.ID_EMPRESTIMO_ARMAMENTO DESC LIMIT :limite OFFSET :offset');
foreach ($params as $chave => $valor) $stmt->bindValue($chave, $valor, PDO::PARAM_STR);
$stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($pagina - 1) * $limite, PDO::PARAM_INT);
$stmt->execute();
$lista = $stmt->fetchAll();
$totais = $pdo->prepare('SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN DATA_DEVOLUCAO IS NULL THEN 1 ELSE 0 END) AS em_uso,
    SUM(CASE WHEN DATA_DEVOLUCAO IS NULL AND DATA_PREVISTA_DEVOLUCAO < :agora THEN 1 ELSE 0 END) AS atrasados
    FROM EMPRESTIMO_ARMAMENTO');
$totais->execute([':agora' => $agora]);
$totais = $totais->fetch();
$flash = recuperar_flash();
$historicoCompleto = $status === 'todos';
abrir_painel($historicoCompleto ? 'Histórico de empréstimos' : 'Armas em uso', 'emprestimos');
?>
<div class="cabecalho-pagina">
  <div>
    <div class="sobretitulo">Registros</div>
    <h1><?= $historicoCompleto ? 'Histórico de empréstimos' : 'Armas em uso' ?></h1>
    <p><?= $historicoCompleto ? 'Todos os registros de empréstimo e devolução.' : 'Empréstimos, prazos e devoluções.' ?></p>
  </div>
  <a href="emprestimo-form.php" class="botao-primario">+ Novo empréstimo</a>
</div>
<?php if ($flash): ?><div class="mensagem <?= e($flash['tipo']) ?>" role="status"><?= e($flash['mensagem']) ?></div><?php endif; ?>
<div class="metricas">
  <article class="metrica"><span>Total</span><strong><?= (int) ($totais['total'] ?? 0) ?></strong></article>
  <article class="metrica"><span>Em uso</span><strong><?= (int) ($totais['em_uso'] ?? 0) ?></strong><small>Sem devolução</small></article>
  <article class="metrica"><span>Atrasadas</span><strong><?= (int) ($totais['atrasados'] ?? 0) ?></strong><small>Prazo vencido</small></article>
  <article class="metrica"><span>Novo</span><strong>+</strong><small><a href="emprestimo-form.php">Registrar empréstimo →</a></small></article>
</div>
<section class="painel-card">
  <div class="cartao-topo"><div><h2>Lista</h2><p><?= $total ?> resultado(s)</p></div></div>
  <form class="filtros" method="get" action="emprestimos.php">
    <input type="search" name="q" placeholder="Arma, atirador ou auxiliar" aria-label="Pesquisar por arma, atirador ou auxiliar" maxlength="100" value="<?= e($q) ?>">
    <select name="status">
      <option value="em_uso" <?= $status==='em_uso'?'selected':'' ?>>Em uso</option>
      <option value="atrasados" <?= $status==='atrasados'?'selected':'' ?>>Atrasadas</option>
      <option value="devolvidos" <?= $status==='devolvidos'?'selected':'' ?>>Devolvidas</option>
      <option value="todos" <?= $status==='todos'?'selected':'' ?>>Todos</option>
    </select>
    <button class="botao-secundario" type="submit">Buscar</button>
    <a class="link-discreto" href="emprestimos.php">Limpar</a>
  </form>
  <div class="tabela-scroll"><table class="tabela"><thead><tr><th>Arma</th><th>Atirador</th><th>Empréstimo</th><th>Previsão</th><th>Status</th><th>Ações</th></tr></thead><tbody>
  <?php foreach ($lista as $linha):
      $aberto = $linha['DATA_DEVOLUCAO'] === null;
      $atrasado = $aberto && $linha['DATA_PREVISTA_DEVOLUCAO'] < $agora;
      $situacao = $aberto ? ($atrasado ? 'Atrasada' : 'Em uso') : 'Devolvida';
  ?>
    <tr class="<?= $atrasado ? 'linha-atrasada' : '' ?>">
      <td><strong><?= e($linha['SERIAL']) ?></strong><span><?= e($linha['MODELO'] ?: 'Modelo não informado') ?></span></td>
      <td><strong><?= e($linha['PESSOA_NOME']) ?></strong><span><?= e($linha['IDENTIFICADOR']) ?></span></td>
      <td><?= e(data_legivel_armaria($linha['DATA_EMPRESTIMO'])) ?><span>Auxiliar: <?= e($linha['OPERADOR_NOME']) ?></span></td>
      <td><strong><?= e(data_legivel_armaria($linha['DATA_PREVISTA_DEVOLUCAO'])) ?></strong></td>
      <td>
        <span class="estado <?= $atrasado ? 'estado-atraso' : ($aberto ? 'estado-alerta' : 'estado-ativo') ?>"><?= e($situacao) ?></span>
        <?php if (!$aberto): ?>
          <span>Devolvida em <?= e(data_legivel_armaria($linha['DATA_DEVOLUCAO'])) ?></span>
          <?php if (!empty($linha['DEVOLUCAO_AUXILIAR_NOME'])): ?>
            <span>Recebida por <?= e($linha['DEVOLUCAO_AUXILIAR_NOME']) ?></span>
          <?php endif; ?>
        <?php endif; ?>
      </td>
      <td><a class="botao-linha" href="emprestimo-form.php?id=<?= (int) $linha['ID_EMPRESTIMO_ARMAMENTO'] ?>"><?= $aberto ? 'Gerenciar' : 'Ver' ?></a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$lista): ?><tr><td colspan="6" class="vazio">Nenhum registro encontrado.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="paginacao"><span>Página <?= $pagina ?> de <?= $paginas ?></span><div>
    <?php if ($pagina > 1): ?><a href="?<?= e(http_build_query(['q'=>$q,'status'=>$status,'pagina'=>$pagina-1])) ?>">← Anterior</a><?php endif; ?>
    <?php if ($pagina < $paginas): ?><a href="?<?= e(http_build_query(['q'=>$q,'status'=>$status,'pagina'=>$pagina+1])) ?>">Próxima →</a><?php endif; ?>
  </div></div>
</section>
<?php fechar_painel(); ?>
