<?php
require __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST'){
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

    var_dump($usuario);
    exit;
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
                <div class="campo">
                    <label for="login">Login</label>
                    <input type="text" id="login" name="login" inputmode="text" placeholder="insira seu login" required>
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