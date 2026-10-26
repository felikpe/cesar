<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';
exigir_admin();
require_once __DIR__ . '/../includes/layout.php';
$pdo = conectar();

$acoes = $pdo->query('SELECT DISTINCT ACAO FROM LOG_SISTEMA ORDER BY ACAO')->fetchAll(PDO::FETCH_COLUMN);
$q = trim((string) ($_GET['q'] ?? ''));
if (strlen($q) > 100) $q = substr($q, 0, 100);
$acao = (string) ($_GET['acao'] ?? '');
if ($acao !== '' && !in_array($acao, $acoes, true)) $acao = '';
$pagina = max(1, min(10000, (int) ($_GET['pagina'] ?? 1)));
$limite = 20;
$clausulas = [];
$params = [];
if ($acao !== '') { $clausulas[] = 'L.ACAO = :acao'; $params[':acao'] = $acao; }
if ($q !== '') {
    $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
    $clausulas[] = "(L.DESCRICAO LIKE :descricao ESCAPE '!' OR U.NOME LIKE :autor ESCAPE '!')";
    $params[':descricao'] = '%' . $literal . '%';
    $params[':autor'] = '%' . $literal . '%';
}
$where = $clausulas ? ' WHERE ' . implode(' AND ', $clausulas) : '';
$from = ' FROM LOG_SISTEMA L LEFT JOIN USUARIO U ON U.ID_USUARIO = L.ID_USUARIO';
$totalStmt = $pdo->prepare('SELECT COUNT(*)' . $from . $where);
$totalStmt->execute($params);
$total = (int) $totalStmt->fetchColumn();
$paginas = max(1, (int) ceil($total / $limite));
$pagina = min($pagina, $paginas);
$stmt = $pdo->prepare('SELECT L.ID_LOG, L.ACAO, L.DESCRICAO, L.CRIADO_EM, U.NOME
    ' . $from . $where . ' ORDER BY L.ID_LOG DESC LIMIT :limite OFFSET :offset');
foreach ($params as $key => $value) $stmt->bindValue($key, $value, PDO::PARAM_STR);
$stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($pagina - 1) * $limite, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();
abrir_painel('Logs do sistema', 'logs');
?>
<div class="cabecalho-pagina"><div><div class="sobretitulo">AUDITORIA</div><h1>Logs do sistema</h1><p>Ações realizadas no sistema.</p></div><div class="etiqueta-ambiente"><?= $total ?> registro(s)</div></div>
<nav class="acoes-pessoa" aria-label="Seções dos logs" style="margin-bottom: 16px; gap: 10px;">
  <a class="botao-primario" href="logs.php" aria-current="page">Atividades</a>
  <a class="botao-linha" href="logs-emprestimos.php">Empréstimos</a>
</nav>
<section class="painel-card">
  <div class="cartao-topo"><div><h2>Registros de atividade</h2><p>Responsável, ação e horário.</p></div></div>
  <form class="filtros" method="get">
    <input type="search" name="q" maxlength="100" value="<?= e($q) ?>" placeholder="Buscar responsável ou descrição" aria-label="Buscar logs">
    <select name="acao" aria-label="Filtrar ação"><option value="">Todas as ações</option>
    <?php foreach ($acoes as $opcao): ?><option value="<?= e($opcao) ?>" <?= $acao === $opcao ? 'selected' : '' ?>><?= e(texto_auditoria($opcao)) ?></option><?php endforeach; ?>
    </select><button type="submit" class="botao-secundario">Filtrar</button>
    <a href="logs.php" class="link-discreto">Limpar filtro</a>
  </form>
  <div class="tabela-scroll"><table class="tabela tabela-logs"><thead><tr><th>Data e hora</th><th>Usuário</th><th>Ação / descrição</th></tr></thead><tbody>
  <?php foreach ($logs as $log): ?>
    <tr><td><?= e(date('d/m/Y H:i:s', strtotime($log['CRIADO_EM']))) ?></td><td><?= e($log['NOME'] ?? 'Sistema') ?></td><td><strong><?= e(texto_auditoria($log['ACAO'])) ?></strong><span><?= e(descricao_log_visivel($pdo, (string) $log['ACAO'], $log['DESCRICAO'] ?? '')) ?></span></td></tr>
  <?php endforeach; ?>
  <?php if (!$logs): ?><tr><td colspan="3" class="vazio">Nenhum registro para este filtro.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="paginacao"><span>Página <?= $pagina ?> de <?= $paginas ?></span><div>
    <?php if ($pagina > 1): ?><a href="?<?= e(http_build_query(['q' => $q, 'acao' => $acao, 'pagina' => $pagina - 1])) ?>">← Anterior</a><?php endif; ?>
    <?php if ($pagina < $paginas): ?><a href="?<?= e(http_build_query(['q' => $q, 'acao' => $acao, 'pagina' => $pagina + 1])) ?>">Próxima →</a><?php endif; ?>
  </div></div>
</section>
<?php fechar_painel(); ?>
