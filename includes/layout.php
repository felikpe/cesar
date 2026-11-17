<?php
declare(strict_types=1);
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/biometria-painel.php';

function abrir_painel(string $titulo, string $secao): void
{
    $admin = ($_SESSION['cargo'] ?? '') === 'ADMINISTRADOR';
    $nome = (string) ($_SESSION['nome'] ?? 'Usuário');
    $primeiroNome = explode(' ', trim($nome))[0] ?: $nome;
    $cargo = $admin ? 'Administrador' : 'Armeiro';
    $inicial = preg_match('/^./u', $primeiroNome, $letra) ? $letra[0] : '?';
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title><?= e($titulo) ?> | C.E.S.A.R.</title>
      <script>
        if (!sessionStorage.getItem('cesar_ativo')) {
          window.location.replace('login.php');
        }
      </script>
      <link rel="stylesheet" href="../assets/css/estilo.css?v=4">
      <link rel="stylesheet" href="../assets/css/painel.css?v=6">
      <link rel="stylesheet" href="../assets/css/biometria.css?v=3">
      <script src="../assets/js/painel.js?v=2" defer></script>
      <script src="../assets/js/senhas.js?v=1" defer></script>
      <script src="../assets/js/busca.js" defer></script>
      <script src="../assets/js/filtros.js?v=1" defer></script>
    </head>
    <body class="painel-body">
      <div class="painel-shell">
        <aside class="sidebar" id="menu-lateral">
          <button class="fechar-menu" id="fechar-menu" type="button" aria-label="Fechar menu">×</button>
          <div class="marca">
            <div class="logo-painel"><img src="../assets/img/logo-painel.png" alt="C.E.S.A.R. — Controle de Entrada e Saída de Armamento"></div>
          </div>
          <nav class="menu" aria-label="Navegação principal">
            <a class="<?= $secao === 'inicio' ? 'ativo' : '' ?>" href="inicio.php">Início</a>
            <a class="<?= $secao === 'pessoas' ? 'ativo' : '' ?>" href="pessoas.php">Atiradores</a>
            <a class="<?= $secao === 'armas' ? 'ativo' : '' ?>" href="catalogo.php">Armas</a>
            <a class="<?= $secao === 'novo_emprestimo' ? 'ativo' : '' ?>" href="emprestimo-form.php">Nova Cautela</a>
            <a class="<?= $secao === 'emprestimos' ? 'ativo' : '' ?>" href="emprestimos.php">Cautelas</a>
            <?php if ($admin): ?>
              <a class="<?= $secao === 'operadores' ? 'ativo' : '' ?>" href="operadores.php">Armeiros</a>
              <a class="<?= $secao === 'logs' ? 'ativo' : '' ?>" href="logs.php">Auditoria</a>
            <?php endif; ?>
            <a class="<?= $secao === 'conta' ? 'ativo' : '' ?>" href="minha-conta.php">Minha conta</a>
            <form method="post" action="sair.php" class="menu-form-sair" onsubmit="if (!window.confirm('Deseja realmente sair do sistema?')) return false; sessionStorage.removeItem('cesar_ativo'); sessionStorage.removeItem('cesar_biometria_porta'); window.CesarBiometria?.leitor?.disconnect();">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <button class="menu-botao-sair" type="submit">Sair</button>
            </form>
          </nav>
          <div class="sidebar-base">C.E.S.A.R.<span>Armaria • Painel interno</span></div>
        </aside>
        <div class="painel-conteudo">
          <header class="barra-superior">
            <button class="alternar-menu" id="alternar-menu" type="button" aria-label="Abrir menu" aria-controls="menu-lateral" aria-expanded="false">☰</button>
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
    <?php renderizar_dialogo_biometria((string) ($_SESSION['biometria_sessao'] ?? '')); ?>
    </body>
    </html>
    <?php
}
