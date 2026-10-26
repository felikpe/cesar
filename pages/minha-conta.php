<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/upload-perfil.php';

$pdo = conectar();
$usuarioId = (int) $_SESSION['id_usuario'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validar_csrf();
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'alterar_senha') {
        $senhaAtual = (string) ($_POST['senha_atual'] ?? '');
        $senhaNova = (string) ($_POST['senha_nova'] ?? '');
        $confirmacao = (string) ($_POST['confirmar_senha'] ?? '');
        $stmt = $pdo->prepare('SELECT SENHA FROM USUARIO WHERE ID_USUARIO = :id LIMIT 1');
        $stmt->execute([':id' => $usuarioId]);
        $hashAtual = $stmt->fetchColumn();

        if (!$hashAtual || !password_verify($senhaAtual, (string) $hashAtual)) {
            mensagem_flash('erro', 'A senha atual está incorreta.');
        } elseif (strlen($senhaNova) < 12 || strlen($senhaNova) > 72) {
            mensagem_flash('erro', 'A nova senha deve ter entre 12 e 72 caracteres.');
        } elseif ($senhaNova !== $confirmacao) {
            mensagem_flash('erro', 'A confirmação não corresponde à nova senha.');
        } elseif (password_verify($senhaNova, (string) $hashAtual)) {
            mensagem_flash('erro', 'A nova senha deve ser diferente da anterior.');
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('UPDATE USUARIO SET SENHA = :senha WHERE ID_USUARIO = :id');
                $stmt->execute([':senha' => password_hash($senhaNova, PASSWORD_DEFAULT), ':id' => $usuarioId]);
                registrar_log($pdo, $usuarioId, 'SENHA_ALTERADA', 'Senha da própria conta alterada.');
                $pdo->commit();
                session_regenerate_id(true);
                mensagem_flash('sucesso', 'Senha atualizada.');
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                mensagem_flash('erro', 'Não foi possível alterar a senha.');
            }
        }
    } elseif ($acao === 'atualizar_foto') {
        $remover = ($_POST['remover_foto'] ?? '') === '1';
        $novaFoto = null;
        $antigaFoto = null;
        try {
            $novaFoto = salvar_foto_perfil($_FILES['foto'] ?? []);
            if ($novaFoto === null && !$remover) {
                throw new RuntimeException('Selecione uma foto ou marque a opção para remover a atual.');
            }
            if ($novaFoto !== null && $remover) {
                throw new RuntimeException('Escolha entre substituir a foto ou removê-la.');
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT FOTO_PERFIL FROM USUARIO WHERE ID_USUARIO = :id FOR UPDATE');
            $stmt->execute([':id' => $usuarioId]);
            $registro = $stmt->fetch();
            if (!$registro) throw new RuntimeException('Conta não encontrada.');

            $antigaFoto = $registro['FOTO_PERFIL'];
            $fotoFinal = $remover ? null : $novaFoto;
            $stmt = $pdo->prepare('UPDATE USUARIO SET FOTO_PERFIL = :foto WHERE ID_USUARIO = :id');
            $stmt->execute([':foto' => $fotoFinal, ':id' => $usuarioId]);
            registrar_log($pdo, $usuarioId, $remover ? 'FOTO_PERFIL_REMOVIDA' : 'FOTO_PERFIL_ATUALIZADA',
                $remover ? 'Foto da própria conta removida.' : 'Foto da própria conta atualizada.');
            $pdo->commit();
            $_SESSION['foto_perfil'] = $fotoFinal;
            if ($antigaFoto && $antigaFoto !== $fotoFinal) apagar_foto_perfil($antigaFoto);
            mensagem_flash('sucesso', $remover ? 'Foto removida.' : 'Foto de perfil atualizada.');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($novaFoto) apagar_foto_perfil($novaFoto);
            mensagem_flash('erro', $ex instanceof RuntimeException ? $ex->getMessage() : 'Não foi possível atualizar a foto.');
        }
    } else {
        mensagem_flash('erro', 'Ação não reconhecida.');
    }
    header('Location: minha-conta.php', true, 303);
    exit;
}

$flash = recuperar_flash();
$temFoto = !empty($_SESSION['foto_perfil']);
abrir_painel('Minha conta', 'conta');
?>
<div class="cabecalho-pagina">
  <div><div class="sobretitulo">Configurações</div><h1>Minha conta</h1></div>
</div>
<?php if ($flash): ?><div class="mensagem <?= e($flash['tipo']) ?>" role="status"><?= e($flash['mensagem']) ?></div><?php endif; ?>

<div class="grade-painel grade-conta">
  <section class="painel-card">
    <div class="cartao-topo"><div><h2>Foto de perfil</h2></div></div>
    <div class="foto-perfil-layout">
      <div class="foto-perfil-preview">
        <img id="foto-preview" class="foto-perfil-grande" src="<?= $temFoto ? 'perfil-foto.php' : '' ?>" alt="Prévia da sua foto" <?= !$temFoto ? 'hidden' : '' ?>>
        <div id="foto-placeholder" class="foto-perfil-vazia" <?= $temFoto ? 'hidden' : '' ?>>Sem foto</div>
      </div>
      <div class="foto-perfil-dados">
        <strong><?= e($_SESSION['nome']) ?></strong>
        <span><?= $_SESSION['cargo'] === 'ADMINISTRADOR' ? 'Administrador' : 'Auxiliar' ?></span>
        <span class="estado estado-ativo">Ativo</span>
      </div>
    </div>
    <form method="post" enctype="multipart/form-data" class="form-painel" id="form-foto-conta">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="acao" value="atualizar_foto">
      <label for="foto">Escolher foto</label>
      <input id="foto" name="foto" type="file" accept="image/jpeg,image/png,image/webp">
      <small>JPG, PNG ou WEBP, até 2 MB.</small>
      <?php if ($temFoto): ?>
        <label class="check-foto"><input type="checkbox" name="remover_foto" value="1"> Remover foto atual</label>
      <?php endif; ?>
      <button type="submit" class="botao-primario">Salvar foto</button>
    </form>
  </section>

  <section class="painel-card">
    <div class="cartao-topo"><div><h2>Alterar senha</h2></div></div>
    <form method="post" class="form-painel">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="acao" value="alterar_senha">
      <label for="senha_atual">Senha atual</label>
      <input type="password" id="senha_atual" name="senha_atual" required autocomplete="current-password">
      <label for="senha_nova">Nova senha</label>
      <input type="password" id="senha_nova" name="senha_nova" minlength="12" maxlength="72" required autocomplete="new-password">
      <label for="confirmar_senha">Confirmar nova senha</label>
      <input type="password" id="confirmar_senha" name="confirmar_senha" minlength="12" maxlength="72" required autocomplete="new-password">
      <button type="submit" class="botao-primario">Salvar senha</button>
    </form>
  </section>
</div>
<script src="../assets/js/perfil.js" defer></script>
<?php fechar_painel(); ?>
