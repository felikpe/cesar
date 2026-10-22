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
                    <label for="ra">R.A.</label>
                    <input type="text" id="ra" name="ra" inputmode="numeric" maxlength="15" placeholder="00.000.000000-0" required>
                </div>

                <div class="campo">
                    <label for="senha">Senha</label>
                    <input type="password" id="senha" name="senha" autocomplete="current-password" required>
                </div>

                <button type="submit">Entrar</button>
            </form>
        </main>
    </div>

    <script src="../assets/js/mascaras.js"></script>
</body>

</html>