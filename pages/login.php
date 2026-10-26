<?php
require_once __DIR__ . '/../includes/funcoes.php';
require_once __DIR__ . '/../config/database.php';

if (isset($_SESSION['id_usuario'], $_SESSION['cargo'])) {
    header('Location: inicio.php');
    exit;
}

$erro = '';
$entrada = '';
$_SESSION['csrf_login'] ??= bin2hex(random_bytes(32));
$_SESSION['falhas_login'] ??= ['tentativas' => 0, 'ate' => 0];

// O prazo de bloqueio é definido quando a quinta tentativa falha.
// Se o bloqueio foi criado antes da atualização, ele conserva a data
// original até terminar, mesmo que agora o intervalo seja de 5 minutos.
if ((int) ($_SESSION['falhas_login']['ate'] ?? 0) > 0
    && (int) $_SESSION['falhas_login']['ate'] <= time()) {
    $_SESSION['falhas_login'] = ['tentativas' => 0, 'ate' => 0];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entrada = trim((string) ($_POST['login'] ?? ''));
    $senha = (string) ($_POST['senha'] ?? '');
    $token = (string) ($_POST['csrf_login'] ?? '');

    if ((int) $_SESSION['falhas_login']['ate'] > time()) {
        // Respeita o bloqueio atual; o tempo restante é exibido abaixo.
    } elseif (!hash_equals($_SESSION['csrf_login'], $token)) {
        $erro = 'Formulário expirado. Atualize a página e tente novamente.';
    } elseif ($entrada === '' || $senha === '' || strlen($entrada) > 150) {
        $erro = 'Informe login e senha.';
    } else {
        $pdo = conectar();
        $stmt = $pdo->prepare(
            'SELECT ID_USUARIO, NOME, SENHA, CARGO, ATIVO FROM USUARIO WHERE ENTRADA = :entrada LIMIT 1'
        );
        $stmt->execute([':entrada' => $entrada]);
        $usuario = $stmt->fetch();

        if (
            $usuario &&
            (int) $usuario['ATIVO'] === 1 &&
            in_array($usuario['CARGO'], ['AUXILIAR', 'ADMINISTRADOR'], true) &&
            password_verify($senha, $usuario['SENHA'])
        ) {
            session_regenerate_id(true);
            $_SESSION['id_usuario'] = (int) $usuario['ID_USUARIO'];
            $_SESSION['nome'] = $usuario['NOME'];
            $_SESSION['cargo'] = $usuario['CARGO'];
            unset($_SESSION['csrf_login'], $_SESSION['falhas_login']);
            registrar_log($pdo, (int) $usuario['ID_USUARIO'], 'LOGIN', 'Acesso administrativo realizado.');
            header('Location: inicio.php');
            exit;
        }
        $_SESSION['falhas_login']['tentativas']++;
        if ($_SESSION['falhas_login']['tentativas'] >= 5) {
            $_SESSION['falhas_login'] = ['tentativas' => 0, 'ate' => time() + 300];
        }
        $erro = 'Login ou senha incorretos, ou conta desativada.';
    }
}

$segundosRestantes = max(0, (int) ($_SESSION['falhas_login']['ate'] ?? 0) - time());
if ($segundosRestantes > 0) {
    $erro = 'Muitas tentativas. Tente novamente após o bloqueio.';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>C.E.S.A.R. | Login</title>
    <link rel="stylesheet" href="../assets/css/estilo.css">
</head>
<body>
    <div class="tela-login">
        <main class="caixa-login">
            <img src="../assets/img/logo_2_cortada.png" alt="Logo C.E.S.A.R." class="logo">
            <h1>Acesso ao painel</h1>

            <form method="post" action="">
                <?php if ($erro !== ''): ?>
                    <?php if ($segundosRestantes > 0): ?>
                        <p class="erro" role="alert" id="aviso-bloqueio" data-restante="<?= $segundosRestantes ?>">
                            Muitas tentativas. Faltam <?= intdiv($segundosRestantes, 60) ?> min e <?= $segundosRestantes % 60 ?> s.
                        </p>
                    <?php else: ?>
                        <p class="erro" role="alert"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                <?php endif; ?>

                <input type="hidden" name="csrf_login" value="<?= htmlspecialchars($_SESSION['csrf_login'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="campo">
                    <label for="login">Login</label>
                    <input type="text" id="login" name="login" maxlength="150" autocomplete="username"
                        placeholder="Insira seu login" value="<?= htmlspecialchars($entrada, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="campo">
                    <label for="senha">Senha</label>
                    <input type="password" id="senha" name="senha" autocomplete="current-password" required>
                </div>
                <button type="submit">Entrar</button>
            </form>
        </main>
    </div>
    <script>
        // Apenas atualiza a informação visual; a liberação real é validada pelo PHP.
        const aviso = document.getElementById('aviso-bloqueio');
        if (aviso) {
            let restante = Number(aviso.dataset.restante);
            const atualizar = () => {
                if (restante <= 0) {
                    aviso.textContent = 'O período de bloqueio terminou. Você pode tentar entrar novamente.';
                    return;
                }
                aviso.textContent = `Muitas tentativas. Faltam ${Math.floor(restante / 60)} min e ${restante % 60} s.`;
                restante--;
                setTimeout(atualizar, 1000);
            };
            atualizar();
        }
    </script>
</body>
</html>
