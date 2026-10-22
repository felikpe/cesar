<?php
session_start();
require __DIR__ . '/../config/database.php';

$erro ='';
$entrada='';

if($_SERVER ['REQUEST_METHOD'] === 'POST'){
    $entrada = trim($_POST['login'] ?? '');
    $senha = $_POST['senha'] ?? '';

    $pdo = conectar();
    $stmt = $pdo->prepare(
        "SELECT ID_USUARIO, NOME, SENHA, CARGO
        FROM USUARIO
        WHERE ENTRADA = :entrada"
    );
    $stmt->execute([':entrada' => $entrada]);
    $usuario = $stmt->fetch();

    if ($usuario && password_verify($senha, $usuario['SENHA'])){
        session_regenerate_id(true);
        $_SESSION['id_usuario'] = $usuario['ID_USUARIO'];
        $_SESSION['nome'] = $usuario['NOME'];
        $_SESSION['cargo'] = $usuario['CARGO'];

        header('Location: inicio.php');
        exit;
    }

    $erro = 'Login ou senha incorretos.';

}

?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="stylesheet" href="../assets/css/estilo.css">
</head>

<body>
    <div class="tela-login">
        <main class="caixa-login">
            <img src="../assets/img/logo_2_cortada.png" alt="Logo C.E.S.A.R" class="logo">
            <h1>Identifique-se, atirador</h1>

            <form method="POST">
                <?php if ($erro !== ''): ?>
                    <p class="erro"><?= htmlspecialchars($erro) ?></p>
                <?php endif; ?>

                <div class="campo">
                    <label for="login">Login</label>
                    <input type="text" id="login" name="login" inputmode="text" placeholder="insira seu login" value="<?= htmlspecialchars($entrada) ?>" required>
                </div>

                <div class="campo">
                    <label for="senha">Senha</label>
                    <input type="password" id="senha" name="senha" autocomplete="current-password" required>
                </div>

                <button type="submit">Entrar</button>
            </form>
        </main>
    </div>
</body>

</html>