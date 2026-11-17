<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/layout.php';

$pdo = conectar();
$admin = $_SESSION['cargo'] === 'ADMINISTRADOR';
$nome = (string) $_SESSION['nome'];

$pessoas = $pdo->query('SELECT COUNT(*) FROM ATIRADOR WHERE ATIVO = 1')->fetchColumn();
$armas = $pdo->query('SELECT COUNT(*) AS total,
    COALESCE(SUM(CASE WHEN A.DISPONIBILIDADE = 1 AND NOT EXISTS (
        SELECT 1 FROM CAUTELA C WHERE C.ID_ARMAMENTO = A.ID_ARMAMENTO AND C.HORA_F IS NULL
    ) THEN 1 ELSE 0 END), 0) AS disponiveis
    FROM ARMAMENTO A WHERE A.ATIVO = 1')->fetch(PDO::FETCH_ASSOC);
$cautelas = $pdo->query('SELECT COUNT(*) FROM CAUTELA WHERE HORA_F IS NULL')->fetchColumn();

$stmt = $pdo->query('SELECT L.ACAO, L.DESCRICAO, L.CRIADO_EM, U.NOME
    FROM LOG_SISTEMA L
    LEFT JOIN USUARIO U ON U.ID_USUARIO = L.ID_USUARIO
    WHERE L.ACAO IN (' . acoes_auditoria_sql($pdo, !$admin) . ')
    ORDER BY L.ID_LOG DESC LIMIT 6');
$atividades = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
  <article class="metrica">
    <span>Armas cadastradas</span>
    <strong><?= (int) ($armas['total'] ?? 0) ?></strong>
    <small><a href="catalogo.php">Abrir armas →</a></small>
  </article>
  <article class="metrica">
    <span>Armas disponíveis</span>
    <strong><?= (int) ($armas['disponiveis'] ?? 0) ?></strong>
    <small>Prontas para cautela</small>
  </article>
  <article class="metrica">
    <span>Cautelas ativas</span>
    <strong><?= (int) $cautelas ?></strong>
    <small><a href="emprestimos.php">Consultar cautelas →</a></small>
  </article>
  <article class="metrica">
    <span>Atiradores ativos</span>
    <strong><?= (int) $pessoas ?></strong>
    <small><a href="pessoas.php">Abrir atiradores →</a></small>
  </article>
</div>

<section class="painel-card">
  <div class="cartao-topo">
    <div>
      <h2>Atividades recentes</h2>
      <p><?= $admin ? 'Segurança e operação' : 'Movimentações e cadastros da armaria' ?></p>
    </div>
  </div>
  <?php if (!$atividades): ?>
    <p class="vazio">Nenhuma atividade recente.</p>
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

<?php fechar_painel(); ?>
