<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/layout.php';

$pdo = conectar();
$flash = recuperar_flash();

// Paginação e filtros
$busca = trim((string) ($_GET['busca'] ?? ''));
$filtroStatus = (string) ($_GET['status'] ?? 'abertos'); // abertos, devolvidos, todos
if (!in_array($filtroStatus, ['abertos', 'devolvidos', 'todos'], true)) {
    $filtroStatus = 'abertos';
}
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$limite = 20;

// Montagem da query utilizando a tabela oficial CAUTELA
$sqlBase = 'FROM CAUTELA C
    JOIN ARMAMENTO A ON A.ID_ARMAMENTO = C.ID_ARMAMENTO
    JOIN ATIRADOR T ON T.ID_ATIRADOR = C.ID_ATIRADOR
    WHERE 1=1';
$params = [];

if ($busca !== '') {
    $sqlBase .= ' AND (A.SERIAL LIKE :busca1 OR T.NOME LIKE :busca2 OR T.NOME_GUERRA LIKE :busca3 OR T.RA LIKE :busca4)';
    $params[':busca1'] = "%{$busca}%";
    $params[':busca2'] = "%{$busca}%";
    $params[':busca3'] = "%{$busca}%";
    $params[':busca4'] = "%{$busca}%";
}

if ($filtroStatus === 'abertos') {
    $sqlBase .= ' AND C.HORA_F IS NULL';
} elseif ($filtroStatus === 'devolvidos') {
    $sqlBase .= ' AND C.HORA_F IS NOT NULL';
}

// Contagem total
$stmtCount = $pdo->prepare("SELECT COUNT(*) " . $sqlBase);
$stmtCount->execute($params);
$totalRegistros = (int) $stmtCount->fetchColumn();
$paginas = max(1, (int) ceil($totalRegistros / $limite));
$pagina = min($pagina, $paginas);

// Busca os registros ordenados por data/hora mais recente
$sql = "SELECT C.*, A.SERIAL, A.MODELO, T.NOME AS ATIRADOR_NOME, T.NOME_GUERRA, T.RA " . $sqlBase . " ORDER BY C.DIA DESC, C.HORA_I DESC, C.ID_CAUTELA DESC LIMIT :limite OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $campo => $valor) {
    $stmt->bindValue($campo, $valor, PDO::PARAM_STR);
}
$stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($pagina - 1) * $limite, PDO::PARAM_INT);
$stmt->execute();
$cautelas = $stmt->fetchAll();
$filtros = ['busca' => $busca, 'status' => $filtroStatus];

abrir_painel('Gerenciamento de Cautelas', 'emprestimos');
?>

<div class="cabecalho-pagina">
  <div>
    <div class="sobretitulo">Controle de Armaria</div>
    <h1>Cautelas de Armamento</h1>
    <p>Histórico e acompanhamento de armas retiradas e devolvidas.</p>
  </div>
  <div>
    <a class="botao-primario" href="emprestimo-form.php">+ Nova Cautela</a>
  </div>
</div>

<?php if ($flash): ?>
  <div role="status" class="mensagem <?= e($flash['tipo']) ?>"><?= e($flash['mensagem']) ?></div>
<?php endif; ?>

<div class="painel-card" style="margin-bottom: 20px;">
  <form method="get" class="filtros" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
    <div style="flex: 1; min-width: 220px;">
      <label for="busca">Buscar por Serial, Atirador ou RA</label>
      <input type="search" id="busca" name="busca" value="<?= e($busca) ?>" placeholder="Ex: MQ001, Silva, 2026...">
    </div>
    <div>
      <label for="status">Situação</label>
      <select id="status" name="status" aria-label="Filtrar por situação" onchange="this.form.submit()">
        <option value="abertos" <?= $filtroStatus === 'abertos' ? 'selected' : '' ?>>Em Aberto (Cauteladas)</option>
        <option value="devolvidos" <?= $filtroStatus === 'devolvidos' ? 'selected' : '' ?>>Devolvidas</option>
        <option value="todos" <?= $filtroStatus === 'todos' ? 'selected' : '' ?>>Todas</option>
      </select>
    </div>
    <div>
      <button type="submit" class="botao-secundario">Filtrar</button>
      <?php if ($busca !== '' || $filtroStatus !== 'abertos'): ?>
        <a href="emprestimos.php" class="link-discreto" style="margin-left: 10px;">Limpar</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<div class="painel-card">
  <div class="cartao-topo">
    <div>
      <h2>Registros de Cautela</h2>
      <p><?= $totalRegistros ?> registro(s) encontrado(s)</p>
    </div>
  </div>

  <?php if (empty($cautelas)): ?>
    <p class="vazio" style="text-align: center; padding: 30px;">Nenhuma cautela encontrada com os filtros selecionados.</p>
  <?php else: ?>
    <div class="tabela-scroll">
      <table class="tabela">
        <thead>
          <tr>
            <th>ID</th>
            <th>Arma (Serial)</th>
            <th>Atirador</th>
            <th>Saída (Data / Hora)</th>
            <th>Situação</th>
            <th style="text-align: right;">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cautelas as $c): ?>
            <?php $aberta = $c['HORA_F'] === null; ?>
            <tr>
              <td>#<?= (int) $c['ID_CAUTELA'] ?></td>
              <td>
                <strong><?= e($c['SERIAL']) ?></strong>
                <small style="display: block; color: #666;"><?= e($c['MODELO'] ?: 'Sem modelo') ?></small>
              </td>
              <td>
                <strong><?= e($c['NOME_GUERRA']) ?></strong> - <?= e($c['ATIRADOR_NOME']) ?>
                <small style="display: block; color: #666;">RA: <?= e($c['RA']) ?></small>
              </td>
              <td>
                <?= e(date('d/m/Y', strtotime($c['DIA']))) ?> às <?= e($c['HORA_I']) ?>
              </td>
              <td>
                <?php if ($aberta): ?>
                  <span class="estado estado-alerta">Em uso</span>
                <?php else: ?>
                  <span class="estado estado-ativo">Devolvida (<?= e(data_hora_devolucao($c)) ?>)</span>
                  <?php if ((int) $c['COM_ALTERACAO'] === 1): ?>
                    <span class="estado estado-inativo" style="display: inline-block; margin-top: 3px;" title="<?= e($c['DESCRICAO_DEVOLUCAO']) ?>">
                      Com alteração
                    </span>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td style="text-align: right;">
                <a class="botao-linha" href="emprestimo-form.php?id=<?= (int) $c['ID_CAUTELA'] ?>">
                  <?= $aberta ? 'Devolver / Gerenciar' : 'Ver Detalhes' ?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <nav class="paginacao" aria-label="Páginas das cautelas">
    <span>Página <?= $pagina ?> de <?= $paginas ?></span>
    <div>
      <?php if ($pagina > 1): ?><a href="?<?= e(http_build_query($filtros + ['pagina' => $pagina - 1])) ?>">← Anterior</a><?php endif; ?>
      <?php if ($pagina < $paginas): ?><a href="?<?= e(http_build_query($filtros + ['pagina' => $pagina + 1])) ?>">Próxima →</a><?php endif; ?>
    </div>
  </nav>
</div>

<?php fechar_painel(); ?>
