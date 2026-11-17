<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/proteger.php';
exigir_admin();
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/perfis.php';

$pdo = conectar();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validar_csrf();
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'criar') {
        mensagem_flash('erro', 'Cadastre o armeiro pela ficha da tropa, preenchendo seus dados reais.');
        header('Location: pessoa-form.php?perfil=ARMEIRO', true, 303);
        exit;
    }
    if ($acao !== 'alterar_status') {
        mensagem_flash('erro', 'Ação não reconhecida.');
    } else {
        $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            mensagem_flash('erro', 'Armeiro inválido.');
        } else {
            try {
                $pdo->beginTransaction();
                $conta = perfis_usuario($pdo, (int) $id, true);
                if (!$conta || $conta['CARGO'] !== 'ARMEIRO') throw new RuntimeException('Somente contas de armeiro podem ser alteradas nesta página.');
                $novoEstado = (int) $conta['ATIVO'] === 1 ? 0 : 1;
                $stmt = $pdo->prepare('SELECT ID_ATIRADOR FROM ATIRADOR WHERE ID_USUARIO = :usuario ORDER BY ID_ATIRADOR');
                $stmt->execute([':usuario' => (int) $id]);
                $fichas = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (count($fichas) > 1) throw new RuntimeException('Esta conta está vinculada a mais de uma ficha. Confira os vínculos antes de alterar o acesso.');
                if (!$fichas) {
                    if ($novoEstado === 1) throw new RuntimeException('Complete a ficha do atirador antes de reativar esta conta.');
                    $stmt = $pdo->prepare('UPDATE USUARIO SET ATIVO = 0 WHERE ID_USUARIO = :id');
                    $stmt->execute([':id' => (int) $id]);
                    registrar_log($pdo, (int) $_SESSION['id_usuario'], 'ARMEIRO_DESATIVADO', 'Acesso de ' . $conta['NOME'] . ' desativado.');
                } else {
                    $ficha = perfis_bloquear_ficha($pdo, (int) $fichas[0]);
                    if ($ficha['PERFIL'] !== 'ARMEIRO') {
                        if ($novoEstado === 1) throw new RuntimeException('Altere o perfil da ficha para Armeiro antes de conceder acesso.');
                        $stmt = $pdo->prepare('UPDATE USUARIO SET ATIVO = 0 WHERE ID_USUARIO = :id');
                        $stmt->execute([':id' => (int) $id]);
                        registrar_log($pdo, (int) $_SESSION['id_usuario'], 'ARMEIRO_DESATIVADO', 'Acesso de ' . $conta['NOME'] . ' desativado.');
                    } else {
                        perfis_sincronizar_status($pdo, $ficha, $novoEstado);
                        $stmt = $pdo->prepare('UPDATE ATIRADOR SET ATIVO = :ativo WHERE ID_ATIRADOR = :pessoa');
                        $stmt->execute([':ativo' => $novoEstado, ':pessoa' => (int) $ficha['ID_ATIRADOR']]);
                        if ((int) $ficha['ATIVO'] !== $novoEstado) registrar_log($pdo, (int) $_SESSION['id_usuario'], $novoEstado ? 'ATIRADOR_REATIVADO' : 'ATIRADOR_DESATIVADO', 'Situação de ' . $conta['NOME'] . ($novoEstado ? ' reativada.' : ' desativada.'));
                    }
                }
                $pdo->commit();
                mensagem_flash('sucesso', $novoEstado ? 'Armeiro e ficha reativados.' : 'Acesso do armeiro desativado.');
            } catch (Throwable $erro) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                mensagem_flash('erro', $erro instanceof RuntimeException && !($erro instanceof PDOException) ? $erro->getMessage() : 'Não foi possível alterar o acesso do armeiro.');
            }
        }
    }
    header('Location: operadores.php', true, 303);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
if (strlen($q) > 100) {
    $q = substr($q, 0, 100);
}
$status = (string) ($_GET['status'] ?? 'todos');
if (!in_array($status, ['todos', 'ativos', 'inativos'], true)) {
    $status = 'todos';
}
$page = max(1, min(10000, (int) ($_GET['pagina'] ?? 1)));
$limit = 15;
$conditions = ["CARGO = 'ARMEIRO'"];
$params = [];

if ($q !== '') {
    $conditions[] = '(NOME LIKE :nome OR ENTRADA LIKE :login)';
    $params[':nome'] = "%{$q}%";
    $params[':login'] = "%{$q}%";
}
if ($status !== 'todos') {
    $conditions[] = 'ATIVO = :ativo';
    $params[':ativo'] = $status === 'ativos' ? 1 : 0;
}

$where = implode(' AND ', $conditions);
$count = $pdo->prepare("SELECT COUNT(*) FROM USUARIO WHERE {$where}");
foreach ($params as $k => $v) {
    $count->bindValue($k, $v, $k === ':ativo' ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$count->execute();
$total = (int) $count->fetchColumn();

$pages = max(1, (int) ceil($total / $limit));
$page = min($page, $pages);
$offset = ($page - 1) * $limit;

$stmt = $pdo->prepare("SELECT U.ID_USUARIO, U.NOME, U.ENTRADA, U.ATIVO, (SELECT MIN(A.ID_ATIRADOR) FROM ATIRADOR A WHERE A.ID_USUARIO = U.ID_USUARIO) AS ID_ATIRADOR, (SELECT MIN(A.PERFIL) FROM ATIRADOR A WHERE A.ID_USUARIO = U.ID_USUARIO) AS PERFIL FROM USUARIO U WHERE {$where} ORDER BY ID_USUARIO DESC LIMIT :limite OFFSET :offset");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, $k === ':ativo' ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue(':limite', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$operadores = $stmt->fetchAll();
$flash = recuperar_flash();

abrir_painel('Armeiros', 'operadores');
?>

<div class="cabecalho-pagina">
  <div>
    <div class="sobretitulo">ADMINISTRAÇÃO</div>
    <h1>Gerenciar Armeiros</h1>
    <p>Cadastre e controle o acesso web dos armeiros da equipe.</p>
  </div>
  <div class="etiqueta-ambiente"><?= $total ?> encontrado(s)</div>
</div>

<?php if ($flash): ?>
  <div class="mensagem <?= e($flash['tipo']) ?>" role="status"><?= e($flash['mensagem']) ?></div>
<?php endif; ?>

<div class="grade-painel grade-operadores">
  <section class="painel-card">
    <div class="cartao-topo">
      <div>
        <h2>Novo Armeiro</h2>
        <p>Acesso restrito a administradores</p>
      </div>
    </div>
    
    <p>O armeiro é cadastrado junto com sua ficha de atirador, incluindo RA, nome de guerra e dados de acesso.</p>
    <br><br>
    <a class="botao-primario" href="pessoa-form.php?perfil=ARMEIRO">Cadastrar armeiro</a>
  </section>

  <section class="painel-card painel-card-largo">
    <div class="cartao-topo">
      <div>
        <h2>Armeiros Cadastrados</h2>
        <p>Desativar uma conta impede o acesso ao sistema imediatamente.</p>
      </div>
    </div>

    <form method="get" class="filtros">
      <input name="q" aria-label="Buscar por nome" placeholder="Buscar por nome ou login" value="<?= e($q) ?>">
      <select name="status" aria-label="Filtrar por situação" onchange="this.form.submit()">
        <option value="todos" <?= $status === 'todos' ? 'selected' : '' ?>>Todos os status</option>
        <option value="ativos" <?= $status === 'ativos' ? 'selected' : '' ?>>Ativos</option>
        <option value="inativos" <?= $status === 'inativos' ? 'selected' : '' ?>>Inativos</option>
      </select>
      <button type="submit" class="botao-secundario">Filtrar</button>
      <?php if ($q !== '' || $status !== 'todos'): ?>
        <a href="operadores.php" class="link-discreto">Limpar filtro</a>
      <?php endif; ?>
    </form>

    <div class="tabela-scroll">
      <table class="tabela">
        <thead>
          <tr>
            <th>Armeiro</th>
            <th>Status</th>
            <th style="text-align: right;">Ação</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($operadores as $op): ?>
            <tr>
              <td>
                <strong><?= e($op['NOME']) ?></strong>
                <span style="display: block; color: #666; font-size: 0.85rem;"><?= e($op['ENTRADA']) ?></span>
              </td>
              <td>
                <span class="estado <?= (int) $op['ATIVO'] === 1 ? 'estado-ativo' : 'estado-inativo' ?>">
                  <?= (int) $op['ATIVO'] === 1 ? 'Ativo' : 'Inativo' ?>
                </span>
              </td>
              <td style="text-align: right;">
                <?php if ($op['ID_ATIRADOR'] !== null): ?>
                    <a class="botao-linha" href="pessoa-form.php?id=<?= (int) $op['ID_ATIRADOR'] ?>">Editar ficha</a>
                <?php else: ?>
                    <a class="botao-linha" href="pessoa-form.php?usuario=<?= (int) $op['ID_USUARIO'] ?>">Completar ficha</a>
                <?php endif; ?>
                <?php if ((int) $op['ATIVO'] === 1 || ($op['ID_ATIRADOR'] !== null && $op['PERFIL'] === 'ARMEIRO')): ?>
                <form method="post" style="display: inline;" onsubmit="return window.confirm('Deseja realmente alterar o status deste armeiro?');">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="acao" value="alterar_status">
                  <input type="hidden" name="id" value="<?= (int) $op['ID_USUARIO'] ?>">
                  <button class="botao-linha" type="submit">
                    <?= (int) $op['ATIVO'] === 1 ? 'Desativar' : 'Reativar' ?>
                  </button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$operadores): ?>
            <tr><td colspan="3" class="vazio">Nenhum armeiro encontrado.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <div class="paginacao">
        <span>Página <?= $page ?> de <?= $pages ?></span>
        <div>
          <?php if ($page > 1): ?>
            <a href="?<?= e(http_build_query(['q' => $q, 'status' => $status, 'pagina' => $page - 1])) ?>">← Anterior</a>
          <?php endif; ?>
          <?php if ($page < $pages): ?>
            <a href="?<?= e(http_build_query(['q' => $q, 'status' => $status, 'pagina' => $page + 1])) ?>">Próxima →</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php fechar_painel(); ?>
