<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';
exigir_admin();
require_once __DIR__ . '/../includes/layout.php';
$pdo = conectar();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validar_csrf();
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'criar') {
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $entrada = strtolower(trim((string) ($_POST['entrada'] ?? '')));
        $senha = (string) ($_POST['senha'] ?? '');

        if (strlen($nome) < 3 || strlen($nome) > 250 || !filter_var($entrada, FILTER_VALIDATE_EMAIL) || strlen($entrada) > 150 || strlen($senha) < 12 || strlen($senha) > 72) {
            mensagem_flash('erro', 'Confira nome, e-mail e senha (mínimo de 12 caracteres).');
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO USUARIO (NOME, ENTRADA, SENHA, CARGO, ATIVO, PROTEGIDO)
                    VALUES (:nome, :entrada, :senha, 'AUXILIAR', 1, 0)");
                $stmt->execute([':nome' => $nome, ':entrada' => $entrada, ':senha' => password_hash($senha, PASSWORD_DEFAULT)]);
                $id = (int) $pdo->lastInsertId();
                registrar_log($pdo, (int) $_SESSION['id_usuario'], 'AUXILIAR_CRIADO', "{$nome} cadastrado.");
                $pdo->commit();
                mensagem_flash('sucesso', 'Auxiliar cadastrado com sucesso.');
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ((string) $ex->getCode() === '23000') {
                    mensagem_flash('erro', 'Já existe uma conta com esse login.');
                } else {
                    throw $ex;
                }
            }
        }
    } elseif ($acao === 'alterar_status') {
        $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            mensagem_flash('erro', 'Auxiliar inválido.');
        } else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT ID_USUARIO, NOME, ENTRADA, ATIVO FROM USUARIO
                    WHERE ID_USUARIO = :id AND CARGO = 'AUXILIAR' AND PROTEGIDO = 0 FOR UPDATE");
                $stmt->execute([':id' => $id]);
                $conta = $stmt->fetch();
                if (!$conta) {
                    $pdo->rollBack();
                    mensagem_flash('erro', 'Somente auxiliares não protegidos podem ter o acesso alterado.');
                } else {
                    $novoEstado = (int) $conta['ATIVO'] === 1 ? 0 : 1;
                    $stmt = $pdo->prepare("UPDATE USUARIO SET ATIVO = :ativo WHERE ID_USUARIO = :id AND CARGO = 'AUXILIAR' AND PROTEGIDO = 0");
                    $stmt->execute([':ativo' => $novoEstado, ':id' => $id]);
                    registrar_log($pdo, (int) $_SESSION['id_usuario'], $novoEstado ? 'AUXILIAR_ATIVADO' : 'AUXILIAR_DESATIVADO', "{$conta['NOME']} " . ($novoEstado ? 'ativado.' : 'desativado.'));
                    $pdo->commit();
                    mensagem_flash('sucesso', $novoEstado ? 'Auxiliar reativado.' : 'Auxiliar desativado.');
                }
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $ex;
            }
        }
    } else {
        mensagem_flash('erro', 'Ação não reconhecida.');
    }
    header('Location: operadores.php', true, 303);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
if (strlen($q) > 100) $q = substr($q, 0, 100);
$status = (string) ($_GET['status'] ?? 'todos');
if (!in_array($status, ['todos', 'ativos', 'inativos'], true)) $status = 'todos';
$page = max(1, min(10000, (int) ($_GET['pagina'] ?? 1)));
$limit = 15;
$conditions = ["CARGO = 'AUXILIAR'"];
$params = [];
if ($q !== '') { $conditions[] = '(NOME LIKE :nome OR ENTRADA LIKE :login)'; $params[':nome'] = "%{$q}%"; $params[':login'] = "%{$q}%"; }
if ($status !== 'todos') { $conditions[] = 'ATIVO = :ativo'; $params[':ativo'] = $status === 'ativos' ? 1 : 0; }
$where = implode(' AND ', $conditions);
$count = $pdo->prepare("SELECT COUNT(*) FROM USUARIO WHERE {$where}");
foreach ($params as $k => $v) $count->bindValue($k, $v, $k === ':ativo' ? PDO::PARAM_INT : PDO::PARAM_STR);
$count->execute();
$total = (int) $count->fetchColumn();
$pages = max(1, (int) ceil($total / $limit));
$page = min($page, $pages);
$offset = ($page - 1) * $limit;
$stmt = $pdo->prepare("SELECT ID_USUARIO, NOME, ENTRADA, ATIVO, CRIADO_EM FROM USUARIO WHERE {$where} ORDER BY ID_USUARIO DESC LIMIT :limite OFFSET :offset");
foreach ($params as $k => $v) $stmt->bindValue($k, $v, $k === ':ativo' ? PDO::PARAM_INT : PDO::PARAM_STR);
$stmt->bindValue(':limite', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$operadores = $stmt->fetchAll();
$flash = recuperar_flash();
abrir_painel('Auxiliares', 'operadores');
?>
<div class="cabecalho-pagina"><div><div class="sobretitulo">ADMINISTRAÇÃO</div><h1>Gerenciar auxiliares</h1><p>Cadastre e controle o acesso web dos auxiliares.</p></div><div class="etiqueta-ambiente"><?= $total ?> encontrado(s)</div></div>
<?php if ($flash): ?><div class="mensagem <?= e($flash['tipo']) ?>" role="status"><?= e($flash['mensagem']) ?></div><?php endif; ?>
<div class="grade-painel grade-operadores">
<section class="painel-card"><div class="cartao-topo"><div><h2>Novo auxiliar</h2><p>Disponível somente para administradores</p></div></div>
  <form method="post" class="form-painel" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="acao" value="criar">
    <label for="nome">Nome completo</label><input type="text" name="nome" id="nome" maxlength="250" minlength="3" required>
    <label for="entrada">E-mail de acesso</label><input type="email" name="entrada" id="entrada" maxlength="150" required>
    <label for="senha">Senha inicial</label><input type="password" name="senha" id="senha" minlength="12" maxlength="72" autocomplete="new-password" required>
    <small>Use uma senha única de pelo menos 12 caracteres. O auxiliar poderá alterá-la em Minha conta.</small>
    <button type="submit" class="botao-primario">Cadastrar auxiliar</button>
  </form>
</section>
<section class="painel-card painel-card-largo"><div class="cartao-topo"><div><h2>Auxiliares cadastrados</h2><p>Desativar uma conta bloqueia o próximo acesso</p></div></div>
  <form method="get" class="filtros">
    <input name="q" aria-label="Buscar por nome ou e-mail" placeholder="Buscar nome ou e-mail" value="<?= e($q) ?>">
    <select name="status" aria-label="Filtrar por situação"><option value="todos" <?= $status === 'todos' ? 'selected' : '' ?>>Todos</option><option value="ativos" <?= $status === 'ativos' ? 'selected' : '' ?>>Ativos</option><option value="inativos" <?= $status === 'inativos' ? 'selected' : '' ?>>Inativos</option></select>
    <button type="submit" class="botao-secundario">Filtrar</button>
  </form>
  <div class="tabela-scroll"><table class="tabela"><thead><tr><th>Auxiliar</th><th>Status</th><th>Ação</th></tr></thead><tbody>
  <?php foreach ($operadores as $op): ?>
    <tr><td><strong><?= e($op['NOME']) ?></strong><span><?= e($op['ENTRADA']) ?></span></td><td><span class="estado <?= (int) $op['ATIVO'] === 1 ? 'estado-ativo' : 'estado-inativo' ?>"><?= (int) $op['ATIVO'] === 1 ? 'Ativo' : 'Inativo' ?></span></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="acao" value="alterar_status"><input type="hidden" name="id" value="<?= (int) $op['ID_USUARIO'] ?>"><button class="botao-linha" type="submit"><?= (int) $op['ATIVO'] === 1 ? 'Desativar' : 'Reativar' ?></button></form></td></tr>
  <?php endforeach; ?>
  <?php if (!$operadores): ?><tr><td colspan="3" class="vazio">Nenhum auxiliar encontrado.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="paginacao"><span>Página <?= $page ?> de <?= $pages ?></span><div>
  <?php if ($page > 1): ?><a href="?<?= e(http_build_query(['q'=>$q, 'status'=>$status, 'pagina'=>$page - 1])) ?>">← Anterior</a><?php endif; ?>
  <?php if ($page < $pages): ?><a href="?<?= e(http_build_query(['q'=>$q, 'status'=>$status, 'pagina'=>$page + 1])) ?>">Próxima →</a><?php endif; ?>
  </div></div>
</section></div>
<?php fechar_painel(); ?>
