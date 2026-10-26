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
$auxiliar = filter_var($_GET['auxiliar'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$situacao = (string) ($_GET['situacao'] ?? 'todos');
if (!in_array($situacao, ['todos', 'em_aberto', 'devolvidos'], true)) $situacao = 'todos';
$pagina = max(1, min(10000, (int) ($_GET['pagina'] ?? 1)));
$limite = 20;

$listaAtiradores = $pdo->query('SELECT P.ID_PESSOA, P.NOME FROM PESSOA P
    WHERE EXISTS (SELECT 1 FROM EMPRESTIMO_ARMAMENTO E WHERE E.ID_PESSOA = P.ID_PESSOA)
    ORDER BY P.NOME, P.ID_PESSOA')->fetchAll();
$listaAuxiliares = $pdo->query('SELECT U.ID_USUARIO, U.NOME FROM USUARIO U
    WHERE EXISTS (SELECT 1 FROM EMPRESTIMO_ARMAMENTO E
        WHERE E.ID_USUARIO_RESPONSAVEL = U.ID_USUARIO OR E.ID_USUARIO_DEVOLUCAO = U.ID_USUARIO)
    ORDER BY U.NOME, U.ID_USUARIO')->fetchAll();

$parametros = [];
$condicoes = [];
if ($atirador) {
    $condicoes[] = 'E.ID_PESSOA = :atirador';
    $parametros[':atirador'] = $atirador;
}
if ($auxiliar) {
    // Abrange o responsável pelo registro e quem recebeu a devolução.
    $condicoes[] = '(E.ID_USUARIO_RESPONSAVEL = :aux_entrega OR E.ID_USUARIO_DEVOLUCAO = :aux_devolucao)';
    $parametros[':aux_entrega'] = $auxiliar;
    $parametros[':aux_devolucao'] = $auxiliar;
}
if ($situacao === 'em_aberto') $condicoes[] = 'E.DATA_DEVOLUCAO IS NULL';
if ($situacao === 'devolvidos') $condicoes[] = 'E.DATA_DEVOLUCAO IS NOT NULL';
if ($q !== '') {
    // '%' e '_' digitados pelo usuário são tratados literalmente.
    $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
    $termo = '%' . $literal . '%';
    $condicoes[] = "(P.NOME LIKE :nome_atirador ESCAPE '!'
        OR P.IDENTIFICADOR LIKE :id_atirador ESCAPE '!'
        OR U.NOME LIKE :nome_auxiliar ESCAPE '!'
        OR UD.NOME LIKE :nome_devolucao ESCAPE '!'
        OR A.SERIAL LIKE :serie ESCAPE '!'
        OR A.MODELO LIKE :modelo ESCAPE '!')";
    foreach (['nome_atirador', 'id_atirador', 'nome_auxiliar', 'nome_devolucao', 'serie', 'modelo'] as $nome) {
        $parametros[':' . $nome] = $termo;
    }
}

$origem = ' FROM EMPRESTIMO_ARMAMENTO E
    JOIN PESSOA P ON P.ID_PESSOA = E.ID_PESSOA
    JOIN ARMAMENTO A ON A.ID_ARMAMENTO = E.ID_ARMAMENTO
    JOIN USUARIO U ON U.ID_USUARIO = E.ID_USUARIO_RESPONSAVEL
    LEFT JOIN USUARIO UD ON UD.ID_USUARIO = E.ID_USUARIO_DEVOLUCAO';
$where = $condicoes ? ' WHERE ' . implode(' AND ', $condicoes) : '';

$contagem = $pdo->prepare('SELECT COUNT(*)' . $origem . $where);
foreach ($parametros as $campo => $valor) {
    $contagem->bindValue($campo, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$contagem->execute();
$total = (int) $contagem->fetchColumn();
$paginas = max(1, (int) ceil($total / $limite));
$pagina = min($pagina, $paginas);

$consulta = $pdo->prepare('SELECT E.ID_EMPRESTIMO_ARMAMENTO, E.DATA_EMPRESTIMO,
    E.DATA_PREVISTA_DEVOLUCAO, E.DATA_DEVOLUCAO,
    P.NOME AS NOME_ATIRADOR, P.IDENTIFICADOR,
    A.SERIAL, A.MODELO,
    U.NOME AS NOME_AUXILIAR, UD.NOME AS NOME_RECEBEDOR'
    . $origem . $where
    . ' ORDER BY E.DATA_EMPRESTIMO DESC, E.ID_EMPRESTIMO_ARMAMENTO DESC
        LIMIT :limite OFFSET :offset');
foreach ($parametros as $campo => $valor) {
    $consulta->bindValue($campo, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$consulta->bindValue(':limite', $limite, PDO::PARAM_INT);
$consulta->bindValue(':offset', ($pagina - 1) * $limite, PDO::PARAM_INT);
$consulta->execute();
$emprestimos = $consulta->fetchAll();

// Os filtros são mantidos ao trocar de página.
$filtros = ['q' => $q, 'atirador' => $atirador, 'auxiliar' => $auxiliar, 'situacao' => $situacao];
abrir_painel('Logs do sistema', 'logs');
?>
<div class="cabecalho-pagina">
  <div><div class="sobretitulo">Auditoria</div><h1>Logs do sistema</h1><p>Consulta de atividades e empréstimos.</p></div>
</div>
<nav class="acoes-pessoa" aria-label="Seções dos logs" style="margin-bottom: 16px; gap: 10px;">
  <a class="botao-linha" href="logs.php">Atividades</a>
  <a class="botao-primario" href="logs-emprestimos.php" aria-current="page">Empréstimos</a>
</nav>
<section class="painel-card">
  <div class="cartao-topo"><div><h2>Histórico de empréstimos</h2><p><?= $total ?> registro(s) encontrado(s)</p></div></div>
  <form class="filtros" method="get" action="logs-emprestimos.php">
    <input type="search" name="q" maxlength="100" value="<?= e($q) ?>"
        placeholder="Pesquisar atirador, auxiliar ou arma" aria-label="Pesquisar empréstimos">
    <select name="atirador" aria-label="Filtrar por atirador">
      <option value="0">Todos os atiradores</option>
      <?php foreach ($listaAtiradores as $pessoa): ?>
        <option value="<?= (int) $pessoa['ID_PESSOA'] ?>" <?= $atirador === (int) $pessoa['ID_PESSOA'] ? 'selected' : '' ?>><?= e($pessoa['NOME']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="auxiliar" aria-label="Filtrar por auxiliar ou administrador">
      <option value="0">Todos os responsáveis</option>
      <?php foreach ($listaAuxiliares as $usuario): ?>
        <option value="<?= (int) $usuario['ID_USUARIO'] ?>" <?= $auxiliar === (int) $usuario['ID_USUARIO'] ? 'selected' : '' ?>><?= e($usuario['NOME']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="situacao" aria-label="Filtrar por situação">
      <option value="todos" <?= $situacao === 'todos' ? 'selected' : '' ?>>Todos os empréstimos</option>
      <option value="em_aberto" <?= $situacao === 'em_aberto' ? 'selected' : '' ?>>Em aberto</option>
      <option value="devolvidos" <?= $situacao === 'devolvidos' ? 'selected' : '' ?>>Devolvidos</option>
    </select>
    <button class="botao-secundario" type="submit">Filtrar</button>
    <a class="link-discreto" href="logs-emprestimos.php">Limpar</a>
  </form>

  <div class="tabela-scroll">
    <table class="tabela">
      <thead><tr><th>Empréstimo</th><th>Atirador</th><th>Arma</th><th>Auxiliar responsável</th><th>Devolução</th></tr></thead>
      <tbody>
      <?php foreach ($emprestimos as $registro): ?>
        <tr>
          <td><strong><?= e(date('d/m/Y H:i', strtotime($registro['DATA_EMPRESTIMO']))) ?></strong>
            <span>Previsão: <?= e(date('d/m/Y H:i', strtotime($registro['DATA_PREVISTA_DEVOLUCAO']))) ?></span>
          </td>
          <td><strong><?= e($registro['NOME_ATIRADOR']) ?></strong><span><?= e($registro['IDENTIFICADOR']) ?></span></td>
          <td><strong><?= e($registro['SERIAL']) ?></strong><span><?= e($registro['MODELO'] ?: '—') ?></span></td>
          <td><?= e($registro['NOME_AUXILIAR']) ?></td>
          <td>
            <?php if ($registro['DATA_DEVOLUCAO'] !== null): ?>
              <span class="estado estado-ativo">Devolvido</span>
              <span><?= e(date('d/m/Y H:i', strtotime($registro['DATA_DEVOLUCAO']))) ?></span>
              <?php if ($registro['NOME_RECEBEDOR']): ?><span>Recebido por <?= e($registro['NOME_RECEBEDOR']) ?></span><?php endif; ?>
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
