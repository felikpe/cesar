<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/funcoes.php';
require_once __DIR__ . '/../config/database.php';

$erro = '';
if (isset($_GET['expirado'])) {
    $erro = 'Sua sessão expirou por inatividade. Faça login novamente.';
}

$entrada = '';
$_SESSION['csrf_login'] ??= bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entrada = trim((string) ($_POST['login'] ?? ''));
    $senha = (string) ($_POST['senha'] ?? '');
    $token = (string) ($_POST['csrf_login'] ?? '');

    if (!hash_equals($_SESSION['csrf_login'], $token)) {
        $erro = 'Formulário expirado. Atualize a página e tente novamente.';
    } elseif ($entrada === '' || $senha === '' || strlen($entrada) > 150) {
        $erro = 'Informe login e senha.';
    } else {
        $pdo = conectar();
        $stmt = $pdo->prepare(
            'SELECT ID_USUARIO, NOME, SENHA, CARGO, ATIVO, FOTO_PERFIL FROM USUARIO WHERE ENTRADA = :entrada LIMIT 1'
        );
        $stmt->execute([':entrada' => $entrada]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
                conta_tem_acesso($pdo, $usuario) &&
                password_verify($senha, $usuario['SENHA'])
            ) {
            session_regenerate_id(true);
            $_SESSION['biometria_sessao'] = bin2hex(random_bytes(32));
            $_SESSION['id_usuario'] = (int) $usuario['ID_USUARIO'];
            $_SESSION['nome'] = $usuario['NOME'];
            $_SESSION['cargo'] = $usuario['CARGO'];
            $_SESSION['foto_perfil'] = $usuario['FOTO_PERFIL'] ?? null;
            $_SESSION['ultimo_acesso'] = time();
            
            $ip_origem = $_SERVER['REMOTE_ADDR'] ?? null;
            unset($_SESSION['csrf_login']);
            
            if (function_exists('registrar_log')) {
                try {
                    $stmtLog = $pdo->prepare('INSERT INTO LOG_SISTEMA (ID_USUARIO, ACAO, DESCRICAO, IP_ORIGEM) VALUES (:id, :acao, :desc, :ip)');
                    $stmtLog->execute([
                        ':id' => (int) $usuario['ID_USUARIO'],
                        ':acao' => 'LOGIN',
                        ':desc' => 'Entrada no sistema.',
                        ':ip' => $ip_origem
                    ]);
                } catch (Exception $e) {
                    // Falha no log não impede o login
                }
            }

            header('Location: inicio.php');
            exit;
        }
        
        // Mensagem genérica sem contar tentativas ou bloquear
        $erro = 'Login ou senha incorretos, ou conta desativada.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>C.E.S.A.R. | Login</title>
    <script>
        if (!sessionStorage.getItem('cesar_ativo')) {
            <?php if (isset($_SESSION['id_usuario'])): ?>
                const formularioSaida = document.createElement('form');
                formularioSaida.method = 'post';
                formularioSaida.action = 'sair.php';
                const tokenSaida = document.createElement('input');
                tokenSaida.type = 'hidden';
                tokenSaida.name = 'csrf_token';
                tokenSaida.value = <?= json_encode(csrf_token()) ?>;
                formularioSaida.appendChild(tokenSaida);
                document.documentElement.appendChild(formularioSaida);
                formularioSaida.submit();
            <?php endif; ?>
        } else {
            <?php if (isset($_SESSION['id_usuario'])): ?>
                window.location.replace('inicio.php');
            <?php else: ?>
                sessionStorage.removeItem('cesar_ativo');
            <?php endif; ?>
        }
    </script>
    <link rel="stylesheet" href="../assets/css/estilo.css?v=4">
    <script src="../assets/js/senhas.js?v=1" defer></script>
</head>
<body>
    <div class="tela-login">
        <main class="caixa-login">
            <div class="logo-login"><img src="../assets/img/logo-login.png" alt="Logo C.E.S.A.R."></div>
            <h1>Acesso ao painel</h1>

            <form method="post" action="" onsubmit="sessionStorage.setItem('cesar_ativo', '1');">
                <?php if ($erro !== ''): ?>
                    <p class="erro" role="alert"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></p>
                    <script>sessionStorage.removeItem('cesar_ativo');</script>
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
</body>
</html>
