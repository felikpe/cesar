<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/layout.php';

$q = trim((string) ($_GET['q'] ?? ''));
if (strlen($q) > 100) $q = substr($q, 0, 100);
$status = (string) ($_GET['status'] ?? 'todos');
if (!in_array($status, ['todos', 'ativos', 'inativos'], true)) $status = 'todos';
$pagina = max(1, min(10000, (int) ($_GET['pagina'] ?? 1)));
$limite = 12;
$clausulas = [];
$parametros = [];
if ($q !== '') {
    // Procura literalmente o texto, sem tratar % e _ digitados como padrões SQL.
    $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
    $clausulas[] = "(NOME LIKE :nome ESCAPE '!' OR IDENTIFICADOR LIKE :identificador ESCAPE '!')";
    $parametros[':nome'] = '%' . $literal . '%';
    $parametros[':identificador'] = '%' . $literal . '%';
}
if ($status !== 'todos') {
    $clausulas[] = 'ATIVO = :ativo';
    $parametros[':ativo'] = $status === 'ativos' ? 1 : 0;
}
$where = $clausulas ? ' WHERE ' . implode(' AND ', $clausulas) : '';
$pdo = conectar();
$contagem = $pdo->prepare('SELECT COUNT(*) FROM PESSOA' . $where);
foreach ($parametros as $chave => $valor) {
    $contagem->bindValue($chave, $valor, $chave === ':ativo' ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$contagem->execute();
$total = (int) $contagem->fetchColumn();
$paginas = max(1, (int) ceil($total / $limite));
$pagina = min($pagina, $paginas);
$consulta = $pdo->prepare('SELECT ID_PESSOA, NOME, IDENTIFICADOR,
        FOTO_ARQUIVO, ATIVO, ATUALIZADO_EM FROM PESSOA' . $where
        . ' ORDER BY ID_PESSOA DESC LIMIT :limite OFFSET :offset');
foreach ($parametros as $chave => $valor) {
    $consulta->bindValue($chave, $valor, $chave === ':ativo' ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$consulta->bindValue(':limite', $limite, PDO::PARAM_INT);
$consulta->bindValue(':offset', ($pagina - 1) * $limite, PDO::PARAM_INT);
$consulta->execute();
$pessoas = $consulta->fetchAll();
$flash = recuperar_flash();
abrir_painel('Atiradores', 'pessoas');
?>
<div class="cabecalho-pagina">
  <div><div class="sobretitulo">Cadastros</div><h1>Atiradores</h1><p>Consulta e cadastro.</p></div>
  <a class="botao-primario" href="pessoa-form.php">+ Novo atirador</a>
</div>
<?php if ($flash): ?><div role="status" class="mensagem <?= e($flash['tipo']) ?>"><?= e($flash['mensagem']) ?></div><?php endif; ?>
<section class="painel-card">
  <div class="cartao-topo"><div><h2>Lista</h2><p><?= $total ?> resultado(s) com os filtros atuais</p></div></div>
  <form class="filtros" method="get" action="pessoas.php">
    <input type="search" name="q" aria-label="Buscar por nome ou identificador" placeholder="Nome ou identificador" value="<?= e($q) ?>" maxlength="100">
    <select name="status" aria-label="Filtrar por status">
        <option value="todos" <?= $status === 'todos' ? 'selected' : '' ?>>Todos</option>
        <option value="ativos" <?= $status === 'ativos' ? 'selected' : '' ?>>Ativos</option>
        <option value="inativos" <?= $status === 'inativos' ? 'selected' : '' ?>>Inativos</option>
    </select>
    <button type="submit" class="botao-secundario">Buscar</button>
    <a class="link-discreto" href="pessoas.php">Limpar filtros</a>
  </form>
  <div class="tabela-scroll"><table class="tabela">
    <thead><tr><th>Atirador</th><th>Identificador</th><th>Situação</th><th>Ações</th></tr></thead>
    <tbody>
      <?php foreach ($pessoas as $pessoa): ?>
      <tr>
        <td><div class="pessoa-identidade">
            <?php if ($pessoa['FOTO_ARQUIVO']): ?>
                <img class="pessoa-miniatura" src="pessoa-foto.php?id=<?= (int) $pessoa['ID_PESSOA'] ?>" alt="Foto de perfil">
            <?php else: ?>
                <span class="pessoa-miniatura pessoa-sem-foto" aria-hidden="true">?</span>
            <?php endif; ?>
            <strong><?= e($pessoa['NOME']) ?></strong>
        </div></td>
        <td><?= e($pessoa['IDENTIFICADOR']) ?></td>
        <td><span class="estado <?= (int) $pessoa['ATIVO'] ? 'estado-ativo' : 'estado-inativo' ?>"><?= (int) $pessoa['ATIVO'] ? 'Ativo' : 'Inativo' ?></span></td>
        <td><div class="acoes-pessoa">
            <a class="botao-linha" href="pessoa-form.php?id=<?= (int) $pessoa['ID_PESSOA'] ?>">Editar</a>
            <form method="post" action="pessoa-status.php" data-confirmar="<?= (int) $pessoa['ATIVO'] ? 'Desativar este cadastro?' : 'Reativar este cadastro?' ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) $pessoa['ID_PESSOA'] ?>">
                <button type="submit" class="botao-linha"><?= (int) $pessoa['ATIVO'] ? 'Desativar' : 'Reativar' ?></button>
            </form>
        </div></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$pessoas): ?><tr><td colspan="4" class="vazio">Nenhum atirador encontrado.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <div class="paginacao">
    <span>Página <?= $pagina ?> de <?= $paginas ?></span><div>
    <?php if ($pagina > 1): ?><a href="?<?= e(http_build_query(['q' => $q, 'status' => $status, 'pagina' => $pagina - 1])) ?>">← Anterior</a><?php endif; ?>
    <?php if ($pagina < $paginas): ?><a href="?<?= e(http_build_query(['q' => $q, 'status' => $status, 'pagina' => $pagina + 1])) ?>">Próxima →</a><?php endif; ?>
    </div>
  </div>
</section>
<script src="../assets/js/pessoas.js" defer></script>
<?php fechar_painel(); ?>
