<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/emprestimo-armamento-funcoes.php';

$pdo = conectar();
$admin = $_SESSION['cargo'] === 'ADMINISTRADOR';
$nome = (string) $_SESSION['nome'];
$agora = agora_armaria();

$pessoas = $pdo->query('SELECT COUNT(*) AS total FROM PESSOA WHERE ATIVO = 1')->fetch();
$armas = $pdo->query("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN STATUS_ARMA = 'DISPONIVEL' THEN 1 ELSE 0 END) AS disponiveis,
    SUM(CASE WHEN STATUS_ARMA = 'EMPRESTADA' THEN 1 ELSE 0 END) AS emprestadas,
    SUM(CASE WHEN STATUS_ARMA = 'MANUTENCAO' THEN 1 ELSE 0 END) AS manutencao
    FROM ARMAMENTO")->fetch();
$emprestimos = $pdo->prepare("SELECT
    COUNT(*) AS em_uso,
    SUM(CASE WHEN DATA_DEVOLUCAO IS NULL AND DATA_PREVISTA_DEVOLUCAO < :agora THEN 1 ELSE 0 END) AS atrasados
    FROM EMPRESTIMO_ARMAMENTO
    WHERE DATA_DEVOLUCAO IS NULL");
$emprestimos->execute([':agora' => $agora]);
$emprestimos = $emprestimos->fetch();

$atividades = [];
if ($admin) {
    $stmt = $pdo->query("SELECT L.ACAO, L.DESCRICAO, L.CRIADO_EM, U.NOME
        FROM LOG_SISTEMA L
        LEFT JOIN USUARIO U ON U.ID_USUARIO = L.ID_USUARIO
        WHERE L.ACAO NOT IN ('LOGIN', 'LOGOUT')
        ORDER BY L.ID_LOG DESC LIMIT 6");
    $atividades = $stmt->fetchAll();
}

abrir_painel('Início', 'inicio');
?>
<div class="cabecalho-pagina">
  <div>
    <div class="sobretitulo">C.E.S.A.R.</div>
    <h1>Olá, <?= e($nome) ?></h1>
    <p>Resumo da armaria.</p>
  </div>
</div>

<div class="metricas">
  <article class="metrica"><span>Armas cadastradas</span><strong><?= (int) ($armas['total'] ?? 0) ?></strong><small><a href="catalogo.php">Abrir armas →</a></small></article>
  <article class="metrica"><span>Disponíveis</span><strong><?= (int) ($armas['disponiveis'] ?? 0) ?></strong><small>Prontas para empréstimo</small></article>
  <article class="metrica"><span>Emprestadas</span><strong><?= (int) ($armas['emprestadas'] ?? 0) ?></strong><small><a href="emprestimos.php">Ver em uso →</a></small></article>
  <article class="metrica"><span>Manutenção</span><strong><?= (int) ($armas['manutencao'] ?? 0) ?></strong><small>Itens indisponíveis</small></article>
</div>
<div class="metricas">
  <article class="metrica"><span>Atiradores ativos</span><strong><?= (int) ($pessoas['total'] ?? 0) ?></strong><small><a href="pessoas.php">Abrir atiradores →</a></small></article>
  <article class="metrica"><span>Empréstimos em aberto</span><strong><?= (int) ($emprestimos['em_uso'] ?? 0) ?></strong><small>Com devolução pendente</small></article>
  <article class="metrica"><span>Atrasos</span><strong><?= (int) ($emprestimos['atrasados'] ?? 0) ?></strong><small>Devolução vencida</small></article>
  <article class="metrica"><span>Novo empréstimo</span><strong>+</strong><small><a href="emprestimo-form.php">Registrar agora →</a></small></article>
</div>

<div class="grade-painel">
  <section class="painel-card">
    <div class="cartao-topo"><div><h2>Acesso rápido</h2><p>O essencial</p></div></div>
    <div class="atalhos">
      <a href="catalogo.php">Armas <span>→</span></a>
      <a href="emprestimos.php">Armas em uso <span>→</span></a>
      <a href="pessoas.php">Atiradores <span>→</span></a>
      <?php if ($admin): ?><a href="logs.php">Logs <span>→</span></a><?php endif; ?>
    </div>
  </section>

  <?php if ($admin): ?>
  <section class="painel-card">
    <div class="cartao-topo"><div><h2>Atividades recentes</h2><p>Últimos registros</p></div></div>
    <?php if (!$atividades): ?>
      <p class="vazio">Nenhum registro.</p>
    <?php else: ?>
      <div class="lista-eventos">
        <?php foreach ($atividades as $atividade): ?>
          <div class="evento">
            <div>
              <strong><?= e(($atividade['NOME'] ?? 'Sistema') . ': ' . descricao_log_visivel($pdo, $atividade['ACAO'], $atividade['DESCRICAO'] ?? '')) ?></strong>
            </div>
            <time><?= e(date('d/m/Y H:i', strtotime($atividade['CRIADO_EM']))) ?></time>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
  <?php else: ?>
  <section class="painel-card">
    <div class="aviso-sem-logs">
      <span aria-hidden="true">ⓘ</span>
      <p>Você não possui acesso aos logs do sistema.</p>
    </div>
  </section>
  <?php endif; ?>
</div>
<?php fechar_painel(); ?>
