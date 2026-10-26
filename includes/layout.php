<?php
declare(strict_types=1);
require_once __DIR__ . '/funcoes.php';

function abrir_painel(string $titulo, string $secao): void
{
    $admin = ($_SESSION['cargo'] ?? '') === 'ADMINISTRADOR';
    $nome = (string) ($_SESSION['nome'] ?? 'Usuário');
    $primeiroNome = explode(' ', trim($nome))[0] ?: $nome;
    $cargo = $admin ? 'Administrador' : 'Auxiliar';
    $inicial = preg_match('/^./u', $primeiroNome, $letra) ? $letra[0] : '?';
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title><?= e($titulo) ?> | C.E.S.A.R.</title>
      <link rel="stylesheet" href="../assets/css/estilo.css">
      <link rel="stylesheet" href="../assets/css/painel.css">
      <script src="../assets/js/painel.js" defer></script>
      <script src="../assets/js/busca.js" defer></script>
    </head>
    <body class="painel-body">
      <div class="painel-shell">
        <aside class="sidebar" id="menu-lateral">
          <div class="marca">
            <img src="../assets/img/logo_2_cortada.png" alt="C.E.S.A.R.">
            <span>Controle de Armaria</span>
          </div>
          <nav class="menu" aria-label="Navegação principal">
            <a class="<?= $secao === 'inicio' ? 'ativo' : '' ?>" href="inicio.php">Início</a>
            <a class="<?= $secao === 'pessoas' ? 'ativo' : '' ?>" href="pessoas.php">Atiradores</a>
            <a class="<?= $secao === 'armas' ? 'ativo' : '' ?>" href="catalogo.php">Armas</a>
            <a class="<?= $secao === 'novo_emprestimo' ? 'ativo' : '' ?>" href="emprestimo-form.php">Novo Empréstimo</a>
            <a class="<?= $secao === 'emprestimos' ? 'ativo' : '' ?>" href="emprestimos.php">Armas em uso</a>
            <?php if ($admin): ?>
              <a class="<?= $secao === 'operadores' ? 'ativo' : '' ?>" href="operadores.php">Auxiliares</a>
              <a class="<?= $secao === 'logs' ? 'ativo' : '' ?>" href="logs.php">Logs</a>
            <?php endif; ?>
            <a class="<?= $secao === 'conta' ? 'ativo' : '' ?>" href="minha-conta.php">Minha conta</a>
            <form method="post" action="sair.php" class="menu-form-sair" onsubmit="return window.confirm('Deseja realmente sair do sistema?');">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <button class="menu-botao-sair" type="submit">Sair</button>
            </form>
          </nav>
          <div class="sidebar-base">C.E.S.A.R.<span>Armaria • Painel interno</span></div>
        </aside>
        <div class="painel-conteudo">
          <header class="barra-superior">
            <button class="alternar-menu" id="alternar-menu" type="button" aria-controls="menu-lateral" aria-expanded="false">☰</button>
            <div class="barra-titulo"><strong><?= e($titulo) ?></strong></div>
            <a class="barra-usuario barra-perfil" href="minha-conta.php" aria-label="Abrir Minha conta">
              <div class="avatar" aria-hidden="true">
                <?php if (!empty($_SESSION['foto_perfil'])): ?>
                  <img class="avatar-imagem" src="perfil-foto.php" alt="">
                <?php else: ?>
                  <?= e($inicial) ?>
                <?php endif; ?>
              </div>
              <div><strong><?= e($primeiroNome) ?></strong><span><?= e($cargo) ?></span></div>
            </a>
          </header>
          <main class="conteudo-principal">
    <?php
}

function fechar_painel(): void
{
    ?>
          </main>
        </div>
      </div>
    </body>
    </html>
    <?php
}
