<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/layout.php';

$id = filter_var($_GET['id'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($id === false) { http_response_code(400); exit('Identificador inválido.'); }
$pessoa = [
    'ID_PESSOA' => 0, 'NOME' => '', 'IDENTIFICADOR' => '',
    'OBSERVACOES' => '', 'FOTO_ARQUIVO' => null, 'ATIVO' => 1,
];
if ($id > 0) {
    $stmt = conectar()->prepare('SELECT ID_PESSOA, NOME, IDENTIFICADOR,
        OBSERVACOES, FOTO_ARQUIVO, ATIVO FROM PESSOA WHERE ID_PESSOA = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $pessoa = $stmt->fetch();
    if (!$pessoa) { http_response_code(404); exit('Atirador não encontrado.'); }
}
$flash = recuperar_flash();
$titulo = $id ? 'Editar atirador' : 'Cadastrar atirador';
abrir_painel($titulo, 'pessoas');
?>
<div class="cabecalho-pagina">
    <div><div class="sobretitulo">Atiradores</div><h1><?= e($titulo) ?></h1><p>Dados do atirador.</p></div>
    <a href="pessoas.php" class="botao-voltar">← Voltar à lista</a>
</div>
<?php if ($flash): ?><div role="status" class="mensagem <?= e($flash['tipo']) ?>"><?= e($flash['mensagem']) ?></div><?php endif; ?>
<div class="grade-cadastro-pessoa">
    <section class="painel-card">
        <div class="cartao-topo"><div><h2>Dados</h2></div></div>
        <form method="post" action="pessoa-salvar.php" enctype="multipart/form-data" class="form-painel" id="form-pessoa">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $pessoa['ID_PESSOA'] ?>">
            <label for="nome">Nome do atirador *</label>
            <input id="nome" name="nome" type="text" maxlength="150" minlength="3" value="<?= e($pessoa['NOME']) ?>" autocomplete="name" required>
            <label for="identificador">Identificador interno *</label>
            <input id="identificador" name="identificador" type="text" maxlength="40" minlength="2" value="<?= e($pessoa['IDENTIFICADOR']) ?>" required>
            <small>Exemplo: matrícula do colaborador. Deve ser único.</small>
            <label for="observacoes">Observações</label>
            <textarea id="observacoes" name="observacoes" maxlength="500" rows="4" placeholder="Informações não sensíveis que ajudem no cadastro"><?= e($pessoa['OBSERVACOES']) ?></textarea>
            <label for="foto">Foto do perfil (opcional)</label>
            <input id="foto" name="foto" type="file" accept="image/jpeg,image/png,image/webp" data-preview="foto-preview" data-placeholder="foto-placeholder">
            <small>JPG, PNG ou WEBP, até 2 MB. A imagem será vista apenas por usuários autenticados.</small>
            <?php if ($id && $pessoa['FOTO_ARQUIVO']): ?>
              <label class="check-foto"><input type="checkbox" name="remover_foto" value="1"> Remover foto existente</label>
            <?php endif; ?>
            <button type="submit" class="botao-primario">Salvar cadastro</button>
        </form>
    </section>
    <aside class="painel-card painel-card-info">
        <div class="cartao-topo"><div><h2>Prévia do perfil</h2><p>Pré-visualização</p></div></div>
        <div class="foto-preview-container">
          <img id="foto-preview" class="foto-preview" src="<?= $id && $pessoa['FOTO_ARQUIVO'] ? 'pessoa-foto.php?id=' . (int) $id : '' ?>" alt="Prévia da foto" <?= !$id || !$pessoa['FOTO_ARQUIVO'] ? 'hidden' : '' ?>>
          <div id="foto-placeholder" class="foto-placeholder" <?= $id && $pessoa['FOTO_ARQUIVO'] ? 'hidden' : '' ?>>Sem foto</div>
        </div>
        <?php if ($id): ?><hr><p><strong>Registro #<?= (int) $id ?></strong></p><p>Situação atual: <?= (int) $pessoa['ATIVO'] ? 'Ativo' : 'Inativo' ?></p><?php endif; ?>
    </aside>
</div>
<script src="../assets/js/pessoas.js" defer></script>
<?php fechar_painel(); ?>
