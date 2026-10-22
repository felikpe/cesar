<?php
require __DIR__ . '/../includes/proteger.php';
?>
<p>Login realizado! Bem-vindo, <?= htmlspecialchars($_SESSION['nome']) ?></p>
<a href="sair.php">Sair</a>