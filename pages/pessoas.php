<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/layout.php';

$q = trim((string) ($_GET['q'] ?? ''));
if (strlen($q) > 100) $q = substr($q, 0, 100);

$status = (string) ($_GET['status'] ?? 'ativos');
if (!in_array($status, ['todos', 'ativos', 'inativos'], true)) $status = 'ativos';

$pagina = max(1, min(10000, (int) ($_GET['pagina'] ?? 1)));
$limite = 12;
$clausulas = [];
$parametros = [];

if ($q !== '') {
    $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
    
    // Adicionado OR NUMERO LIKE :numero na busca
    $clausulas[] = "(NOME LIKE :nome ESCAPE '!' OR NOME_GUERRA LIKE :guerra ESCAPE '!' OR RA LIKE :ra ESCAPE '!' OR NUMERO LIKE :numero ESCAPE '!')";
    
    $parametros[':nome'] = '%' . $literal . '%';
    $parametros[':guerra'] = '%' . $literal . '%';
    $parametros[':ra'] = '%' . $literal . '%';
    $parametros[':numero'] = '%' . $literal . '%';
}

if ($status !== 'todos') {
    $clausulas[] = 'ATIVO = :ativo';
    $parametros[':ativo'] = $status === 'ativos' ? 1 : 0;
}

$where = $clausulas ? ' WHERE ' . implode(' AND ', $clausulas) : '';
$pdo = conectar();

$contagem = $pdo->prepare('SELECT COUNT(*) FROM ATIRADOR' . $where);
foreach ($parametros as $chave => $valor) {
    $contagem->bindValue($chave, $valor, $chave === ':ativo' ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$contagem->execute();
$total = (int) $contagem->fetchColumn();

$paginas = max(1, (int) ceil($total / $limite));
$pagina = min($pagina, $paginas);
$offset = ($pagina - 1) * $limite;

$consulta = $pdo->prepare('SELECT ID_ATIRADOR, RA, NOME, NOME_GUERRA, NUMERO,
        FOTO, ATIVO, ID_DIGITAL FROM ATIRADOR' . $where
        . ' ORDER BY NUMERO ASC, NOME ASC LIMIT :limite OFFSET :offset');

foreach ($parametros as $chave => $valor) {
    $consulta->bindValue($chave, $valor, $chave === ':ativo' ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$consulta->bindValue(':limite', $limite, PDO::PARAM_INT);
$consulta->bindValue(':offset', $offset, PDO::PARAM_INT);
$consulta->execute();
$atiradores = $consulta->fetchAll(PDO::FETCH_ASSOC);

$flash = recuperar_flash();
abrir_painel('Atiradores', 'pessoas');
?>
<div class="cabecalho-pagina">
  <div><div class="sobretitulo">Tiro de Guerra</div><h1>Atiradores</h1><p>Consulta e gestão da tropa.</p></div>
  <a class="botao-primario" href="pessoa-form.php">+ Novo atirador</a>
</div>

<?php if ($flash): ?>
    <div role="status" class="mensagem <?= e($flash['tipo']) ?>"><?= e($flash['mensagem']) ?></div>
<?php endif; ?>

<section class="painel-card">
  <div class="cartao-topo">
      <div><h2>Lista da Tropa</h2><p><?= $total ?> registro(s) encontrado(s)</p></div>
  </div>

  <form class="filtros" id="formFiltros" method="get" action="pessoas.php">
    <!-- Placeholder atualizado para orientar o usuário -->
    <input type="search" name="q" aria-label="Buscar por nome, nome de guerra, RA ou número" placeholder="Nome, Guerra, RA ou Número" value="<?= e($q) ?>" maxlength="100">
    
    <select name="status" aria-label="Filtrar por status" onchange="this.form.submit()">
        <option value="ativos" <?= $status === 'ativos' ? 'selected' : '' ?>>Ativos</option>
        <option value="inativos" <?= $status === 'inativos' ? 'selected' : '' ?>>Desativados</option>
        <option value="todos" <?= $status === 'todos' ? 'selected' : '' ?>>Todos</option>
    </select>

    <button type="submit" class="botao-secundario">Buscar</button>
    <a class="link-discreto" href="pessoas.php">Limpar filtros</a>
  </form>

  <div class="tabela-scroll">
    <table class="tabela">
      <thead>
          <tr>
              <th>Atirador</th>
              <th>RA / Matrícula</th>
              <th>Número</th>
              <th>Situação</th>
              <th>Ações</th>
          </tr>
      </thead>
      <tbody>
        <?php foreach ($atiradores as $atirador): ?>
          <tr>
            <td>
              <div class="pessoa-identidade">
                <?php if (!empty($atirador['FOTO'])): ?>
                    <img class="pessoa-miniatura" src="pessoa-foto.php?id=<?= (int) $atirador['ID_ATIRADOR'] ?>" alt="Foto de perfil">
                <?php else: ?>
                    <span class="pessoa-miniatura pessoa-sem-foto" aria-hidden="true">?</span>
                <?php endif; ?>
                <div>
                    <strong><?= e($atirador['NOME']) ?></strong>
                    <small style="display: block; color: #666;">Guerra: <?= e($atirador['NOME_GUERRA']) ?></small>
                </div>
              </div>
            </td>
            <td><?= e($atirador['RA']) ?></td>
            <td><?= e($atirador['NUMERO'] !== null && $atirador['NUMERO'] !== '' ? $atirador['NUMERO'] : '—') ?></td>
            <td>
              <span class="estado <?= (int) $atirador['ATIVO'] ? 'estado-ativo' : 'estado-inativo' ?>">
                <?= (int) $atirador['ATIVO'] ? 'Ativo' : 'Desativado' ?>
              </span>
            </td>
            <td>
              <div class="acoes-pessoa">
                <a class="botao-linha" href="pessoa-form.php?id=<?= (int) $atirador['ID_ATIRADOR'] ?>">Editar</a>
                <form method="post" action="pessoa-status.php" data-confirmar="<?= (int) $atirador['ATIVO'] ? 'Desativar este atirador?' : 'Reativar este atirador?' ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $atirador['ID_ATIRADOR'] ?>">
                    <button type="submit" class="botao-linha"><?= (int) $atirador['ATIVO'] ? 'Desativar' : 'Reativar' ?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$atiradores): ?>
            <tr><td colspan="5" class="vazio">Nenhum atirador encontrado.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

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
</section>

<script src="../assets/js/pessoas.js" defer></script>
<?php fechar_painel(); ?>
