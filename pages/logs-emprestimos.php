<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/proteger.php';
exigir_admin();
require_once __DIR__ . '/../includes/layout.php';

// Histórico somente para consulta: nenhuma operação de empréstimo é alterada.
$pdo = conectar();
$q = trim((string) ($_GET['q'] ?? ''));
if (strlen($q) > 100) $q = substr($q, 0, 100);

$atirador = filter_var($_GET['atirador'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$armeiro = filter_var($_GET['armeiro'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$situacao = (string) ($_GET['situacao'] ?? 'todos');
if (!in_array($situacao, ['todos', 'em_aberto', 'devolvidos'], true)) $situacao = 'todos';
$pagina = max(1, min(10000, (int) ($_GET['pagina'] ?? 1)));
$limite = 20;

$listaAtiradores = $pdo->query('SELECT ATR.ID_ATIRADOR, ATR.NOME FROM ATIRADOR ATR
    WHERE EXISTS (SELECT 1 FROM CAUTELA C WHERE C.ID_ATIRADOR = ATR.ID_ATIRADOR)
    ORDER BY ATR.NOME, ATR.ID_ATIRADOR')->fetchAll();
$listaArmeiroes = $pdo->query('SELECT U.ID_USUARIO, U.NOME FROM USUARIO U
    WHERE EXISTS (SELECT 1 FROM CAUTELA C
        WHERE C.ID_USUARIO = U.ID_USUARIO)
    ORDER BY U.NOME, U.ID_USUARIO')->fetchAll();

$parametros = [];
$condicoes = [];
if ($atirador) {
    $condicoes[] = 'C.ID_ATIRADOR = :atirador';
    $parametros[':atirador'] = $atirador;
}
if ($armeiro) {
    $condicoes[] = '(C.ID_USUARIO = :arm_entrega)';
    $parametros[':arm_entrega'] = $armeiro;
}
if ($situacao === 'em_aberto') $condicoes[] = '(C.HORA_F IS NULL OR C.HORA_F = "00:00:00")';
if ($situacao === 'devolvidos') $condicoes[] = '(C.HORA_F IS NOT NULL AND C.HORA_F <> "00:00:00")';

if ($q !== '') {
    $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
    $termo = '%' . $literal . '%';
    $condicoes[] = "(ATR.NOME LIKE :nome_atirador ESCAPE '!'
        OR ATR.RA LIKE :id_atirador ESCAPE '!'
        OR U.NOME LIKE :nome_armeiro ESCAPE '!'
        OR A.SERIAL LIKE :serie ESCAPE '!'
        OR A.MODELO LIKE :modelo ESCAPE '!')";
    foreach (['nome_atirador', 'id_atirador', 'nome_armeiro', 'serie', 'modelo'] as $nome) {
        $parametros[':' . $nome] = $termo;
    }
}

$origem = ' FROM CAUTELA C
    JOIN ATIRADOR ATR ON ATR.ID_ATIRADOR = C.ID_ATIRADOR
    JOIN ARMAMENTO A ON A.ID_ARMAMENTO = C.ID_ARMAMENTO
    JOIN USUARIO U ON U.ID_USUARIO = C.ID_USUARIO';
$where = $condicoes ? ' WHERE ' . implode(' AND ', $condicoes) : '';

$contagem = $pdo->prepare('SELECT COUNT(*)' . $origem . $where);
foreach ($parametros as $campo => $valor) {
    $contagem->bindValue($campo, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$contagem->execute();
$total = (int) $contagem->fetchColumn();
$paginas = max(1, (int) ceil($total / $limite));
$pagina = min($pagina, $paginas);

$consulta = $pdo->prepare('SELECT C.ID_CAUTELA, C.DIA, C.HORA_I,
    C.HORA_F, C.COM_ALTERACAO, C.DESCRICAO_DEVOLUCAO,
    ATR.ID_ATIRADOR, ATR.NOME AS NOME_ATIRADOR, ATR.RA,
    A.SERIAL, A.MODELO,
    U.NOME AS NOME_ARMEIRO'
    . $origem . $where
    . ' ORDER BY C.DIA DESC, C.ID_CAUTELA DESC
        LIMIT :limite OFFSET :offset');
foreach ($parametros as $campo => $valor) {
    $consulta->bindValue($campo, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$consulta->bindValue(':limite', $limite, PDO::PARAM_INT);
$consulta->bindValue(':offset', ($pagina - 1) * $limite, PDO::PARAM_INT);
$consulta->execute();
$emprestimos = $consulta->fetchAll();

// Os filtros são mantidos ao trocar de página.
$filtros = ['q' => $q, 'atirador' => $atirador, 'armeiro' => $armeiro, 'situacao' => $situacao];
abrir_painel('Logs do sistema', 'logs');
?>
<div class="cabecalho-pagina">
  <div><div class="sobretitulo">Auditoria</div><h1>Logs do sistema</h1><p>Consulta de atividades e cautelas.</p></div>
</div>
<nav class="acoes-pessoa" aria-label="Seções dos logs" style="margin-bottom: 16px; gap: 10px;">
  <a class="botao-linha" href="logs.php">Atividades</a>
  <a class="botao-primario" href="logs-emprestimos.php" aria-current="page">Empréstimos</a>
</nav>
<section class="painel-card">
  <div class="cartao-topo"><div><h2>Histórico de empréstimos</h2><p><?= $total ?> registro(s) encontrado(s)</p></div></div>
  <form class="filtros" method="get" action="logs-emprestimos.php">
    <input type="search" name="q" maxlength="100" value="<?= e($q) ?>"
        placeholder="Pesquisar atirador, armeiro ou arma" aria-label="Pesquisar empréstimos">
    <select name="atirador" aria-label="Filtrar por atirador" onchange="this.form.submit()">
      <option value="0">Todos os atiradores</option>
      <?php foreach ($listaAtiradores as $pessoa): ?>
        <option value="<?= (int) $pessoa['ID_ATIRADOR'] ?>" <?= $atirador === (int) $pessoa['ID_ATIRADOR'] ? 'selected' : '' ?>><?= e($pessoa['NOME']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="armeiro" aria-label="Filtrar por armeiro ou administrador" onchange="this.form.submit()">
      <option value="0">Todos os responsáveis</option>
      <?php foreach ($listaArmeiroes as $usuario): ?>
        <option value="<?= (int) $usuario['ID_USUARIO'] ?>" <?= $armeiro === (int) $usuario['ID_USUARIO'] ? 'selected' : '' ?>><?= e($usuario['NOME']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="situacao" aria-label="Filtrar por situação" onchange="this.form.submit()">
      <option value="todos" <?= $situacao === 'todos' ? 'selected' : '' ?>>Todos os empréstimos</option>
      <option value="em_aberto" <?= $situacao === 'em_aberto' ? 'selected' : '' ?>>Em aberto</option>
      <option value="devolvidos" <?= $situacao === 'devolvidos' ? 'selected' : '' ?>>Devolvidos</option>
    </select>
    <button class="botao-secundario" type="submit">Filtrar</button>
    <a class="link-discreto" href="logs-emprestimos.php">Limpar</a>
  </form>

  <div class="tabela-scroll">
    <table class="tabela">
      <thead><tr><th>Empréstimo</th><th>Atirador</th><th>Arma</th><th>Armeiro responsável</th><th>Devolução</th></tr></thead>
      <tbody>
      <?php foreach ($emprestimos as $registro): ?>
        <?php $aberta = ($registro['HORA_F'] === null || $registro['HORA_F'] === '00:00:00'); ?>
        <tr>
          <td><strong><?= e(date('d/m/Y', strtotime($registro['DIA'])) . ' às ' . $registro['HORA_I']) ?></strong></td>
          <td><strong><?= e($registro['NOME_ATIRADOR']) ?></strong><span>RA: <?= e($registro['RA']) ?></span></td>
          <td><strong><?= e($registro['SERIAL']) ?></strong><span><?= e($registro['MODELO'] ?: '—') ?></span></td>
          <td><?= e($registro['NOME_ARMEIRO']) ?></td>
          <td>
            <?php if (!$aberta): ?>
              <span class="estado estado-ativo">Devolvido</span>
              <span>Às <?= e($registro['HORA_F']) ?></span>
              <?php if ((int) $registro['COM_ALTERACAO'] === 1): ?>
                <br><small style="color: #721c24;" title="<?= e($registro['DESCRICAO_DEVOLUCAO']) ?>">Com alteração</small>
              <?php endif; ?>
            <?php else: ?>
              <span class="estado estado-alerta">Em aberto</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$emprestimos): ?><tr><td colspan="5" class="vazio">Nenhum empréstimo encontrado.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="paginacao">
    <span>Página <?= $pagina ?> de <?= $paginas ?></span>
    <div>
      <?php if ($pagina > 1): ?><a href="?<?= e(http_build_query($filtros + ['pagina' => $pagina - 1])) ?>">← Anterior</a><?php endif; ?>
      <?php if ($pagina < $paginas): ?><a href="?<?= e(http_build_query($filtros + ['pagina' => $pagina + 1])) ?>">Próxima →</a><?php endif; ?>
    </div>
  </div>
</section>
<?php fechar_painel(); ?>