<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/biometria-painel.php';
require_once __DIR__ . '/../includes/perfis.php';

$id = filter_var($_GET['id'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($id === false) {
    http_response_code(400);
    exit('Identificador inválido.');
}

// Estrutura alinhada rigorosamente à tabela ATIRADOR do schema.sql
$atirador = [
    'ID_ATIRADOR' => 0,
    'RA' => '',
    'NOME' => '',
    'NOME_GUERRA' => '',
    'NUMERO' => '',
    'FOTO' => null,
    'ID_DIGITAL' => null,
    'ATIVO' => 1,
    'ID_ARMAMENTO' => null,
    'ID_USUARIO' => null,
    'PERFIL' => 'ATIRADOR'
];

if ($id > 0) {
    $stmt = conectar()->prepare('SELECT ID_ATIRADOR, RA, NOME, NOME_GUERRA, NUMERO, FOTO, ID_DIGITAL, ATIVO, ID_ARMAMENTO, ID_USUARIO, PERFIL FROM ATIRADOR WHERE ID_ATIRADOR = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $atirador = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$atirador) {
        http_response_code(404);
        exit('Atirador não encontrado.');
    }
}

$consultaArmas = conectar()->prepare('SELECT ID_ARMAMENTO, SERIAL, MODELO, ATIVO FROM ARMAMENTO A WHERE (A.DISPONIBILIDADE = 1 AND A.ATIVO = 1 AND NOT EXISTS (SELECT 1 FROM CAUTELA C WHERE C.ID_ARMAMENTO = A.ID_ARMAMENTO AND C.HORA_F IS NULL)) OR A.ID_ARMAMENTO = :atual ORDER BY A.ATIVO DESC, A.SERIAL ASC');
$consultaArmas->bindValue(':atual', (int) $atirador['ID_ARMAMENTO'], PDO::PARAM_INT);
$consultaArmas->execute();
$armas = $consultaArmas->fetchAll(PDO::FETCH_ASSOC);

$usuarioLegado = filter_var($_GET['usuario'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($usuarioLegado === false) {
    http_response_code(400);
    exit('Conta inválida.');
}
if ($usuarioLegado > 0 && (!perfis_admin() || $id > 0)) {
    http_response_code(403);
    exit('Acesso não permitido.');
}
$conta = perfis_usuario(conectar(), $atirador['ID_USUARIO'] === null ? null : (int) $atirador['ID_USUARIO']);
if ($usuarioLegado > 0) {
    $conta = perfis_usuario(conectar(), $usuarioLegado);
    if (!$conta || $conta['CARGO'] !== 'ARMEIRO') {
        http_response_code(404);
        exit('Conta de armeiro não encontrada.');
    }
    try {
        perfis_vinculo_exclusivo(conectar(), $usuarioLegado, 0);
    } catch (RuntimeException $erro) {
        http_response_code(409);
        exit(e($erro->getMessage()));
    }
    $atirador['NOME'] = $conta['NOME'];
    $atirador['PERFIL'] = 'ARMEIRO';
}
if ($id === 0 && $usuarioLegado === 0 && perfis_admin() && ($_GET['perfil'] ?? '') === 'ARMEIRO') $atirador['PERFIL'] = 'ARMEIRO';
$contaAdministrador = $conta && $conta['CARGO'] === 'ADMINISTRADOR';
$podeGerirAcesso = perfis_admin() && !$contaAdministrador;
$contaNova = !$conta;

$flash = recuperar_flash();
$titulo = $id ? 'Editar atirador' : 'Cadastrar atirador';

abrir_painel($titulo, 'pessoas');
?>

<div class="cabecalho-pagina">
    <div>
        <div class="sobretitulo">Tiro de Guerra</div>
        <h1><?= e($titulo) ?></h1>
        <p>Dados da tropa e biometria.</p>
    </div>
    <a href="pessoas.php" class="botao-voltar">← Voltar à lista</a>
</div>

<?php if ($flash): ?>
    <div role="status" class="mensagem <?= e($flash['tipo']) ?>"><?= e($flash['mensagem']) ?></div>
<?php endif; ?>

<div class="grade-cadastro-pessoa">
    <section class="painel-card">
        <div class="cartao-topo">
            <div><h2>Ficha do Atirador</h2></div>
        </div>

        <form method="post" action="pessoa-salvar.php" class="form-painel" id="form-atirador" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $atirador['ID_ATIRADOR'] ?>">

            <label for="foto">Foto do Atirador</label>
            <div style="display: flex; gap: 15px; align-items: center; margin-bottom: 15px;">
                <img id="foto-preview" class="pessoa-miniatura" src="<?= !empty($atirador['FOTO']) ? 'pessoa-foto.php?id=' . (int)$atirador['ID_ATIRADOR'] : '' ?>" alt="Prévia" style="width: 60px; height: 60px; border-radius: 50%; object-fit: cover; <?= empty($atirador['FOTO']) ? 'display:none;' : '' ?>">
                <div id="foto-placeholder" style="width: 60px; height: 60px; border-radius: 50%; background: #e2e8f0; display: <?= empty($atirador['FOTO']) ? 'flex' : 'none' ?>; align-items: center; justify-content: center; font-weight: bold; color: #718096;">?</div>
                <div style="flex: 1;">
                    <input id="foto" name="foto" type="file" accept="image/jpeg,image/png,image/webp">
                    <small style="display: block;">Formatos aceitos: JPG, PNG ou WEBP (máx. 2 MB).</small>
                    <?php if (!empty($atirador['FOTO'])): ?>
                        <label style="margin-top: 5px; font-weight: normal; font-size: 0.85rem; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="remover_foto" value="1"> Remover foto atual
                        </label>
                    <?php endif; ?>
                </div>
            </div>

            <label for="ra">RA (Registro Acadêmico / Matrícula) *</label>
            <input id="ra" name="ra" type="text" maxlength="12" minlength="3" value="<?= e($atirador['RA']) ?>" required placeholder="Ex: 202600123456">
            <small>Deve ser único no sistema.</small>

            <label for="nome">Nome Completo *</label>
            <input id="nome" name="nome" type="text" maxlength="250" value="<?= e($atirador['NOME']) ?>" required placeholder="Nome completo do atirador">

            <label for="nome_guerra">Nome de Guerra *</label>
            <input id="nome_guerra" name="nome_guerra" type="text" maxlength="50" value="<?= e($atirador['NOME_GUERRA']) ?>" required placeholder="Ex: SILVA">

            <label for="numero">Número (Antiguidade)</label>
            <input id="numero" name="numero" type="text" maxlength="2" value="<?= e($atirador['NUMERO']) ?>" placeholder="Ex: 01">
            <small>Número baseado na antiguidade na tropa (máx 2 dígitos).</small>

            <label for="id_digital">ID Biométrico (ESP32)</label>
            <div class="biometria-campo">
                <input id="id_digital" name="id_digital" type="number" readonly value="<?= e((string) ($atirador['ID_DIGITAL'] ?? '')) ?>" placeholder="ID retornado pelo sensor biométrico">
                <?php botao_biometria('cadastro', (int) $id, isset($atirador['ID_DIGITAL']) ? (int) $atirador['ID_DIGITAL'] : null, 'Cadastrar digital do atirador', (string) ($atirador['NOME_GUERRA'] ?: $atirador['NOME'])); ?>
            </div>
            <small>ID vinculado automaticamente após o cadastro pelo leitor USB.</small>

            <label for="id_armamento">Armamento Principal (Opcional)</label>
            <select id="id_armamento" name="id_armamento">
                <option value="">Nenhum / Não vinculado</option>
                <?php foreach ($armas as $arma): ?>
                    <option value="<?= (int)$arma['ID_ARMAMENTO'] ?>" <?= (int)$atirador['ID_ARMAMENTO'] === (int)$arma['ID_ARMAMENTO'] ? 'selected' : '' ?>>
                        <?= e($arma['SERIAL']) ?> (<?= e($arma['MODELO']) ?>)<?= (int) $arma['ATIVO'] === 0 ? ' — Desativada' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="perfil">Perfil</label>
            <select id="perfil" name="perfil" <?= !$podeGerirAcesso ? 'disabled' : '' ?>>
                <option value="ATIRADOR" <?= $atirador['PERFIL'] === 'ATIRADOR' ? 'selected' : '' ?>>Atirador</option>
                <option value="ARMEIRO" <?= $atirador['PERFIL'] === 'ARMEIRO' ? 'selected' : '' ?>>Armeiro</option>
            </select>
            <?php if (!$podeGerirAcesso): ?>
                <small><?= $contaAdministrador ? 'A conta de administrador vinculada é preservada; o acesso administrativo não é alterado por esta ficha.' : 'Somente administradores podem conceder ou alterar acesso ao sistema.' ?></small>
            <?php else: ?>
                <?php if ($usuarioLegado > 0): ?>
                    <input type="hidden" name="usuario_legado" value="<?= (int) $usuarioLegado ?>">
                    <small>Complete os dados reais da tropa para vincular esta conta existente.</small>
                <?php endif; ?>
                <fieldset id="perfil-acesso" data-conta-nova="<?= $contaNova ? '1' : '0' ?>" <?= $atirador['PERFIL'] !== 'ARMEIRO' ? 'hidden' : '' ?>>
                    <legend>Acesso do armeiro</legend>
                    <label for="login_acesso">Login *</label>
                    <input id="login_acesso" name="login_acesso" maxlength="150" autocomplete="username" value="<?= e($conta['ENTRADA'] ?? '') ?>" data-obrigatorio="1" <?= $atirador['PERFIL'] === 'ARMEIRO' ? 'required' : 'disabled' ?>>
                    <label for="senha_acesso"><?= $contaNova ? 'Senha inicial *' : 'Nova senha (opcional)' ?></label>
                    <input id="senha_acesso" name="senha_acesso" type="password" minlength="8" maxlength="72" autocomplete="new-password" data-obrigatorio="<?= $contaNova ? '1' : '0' ?>" <?= $atirador['PERFIL'] === 'ARMEIRO' ? ($contaNova ? 'required' : '') : 'disabled' ?>>
                    <label for="confirmar_senha_acesso">Confirmar senha<?= $contaNova ? ' *' : '' ?></label>
                    <input id="confirmar_senha_acesso" name="confirmar_senha_acesso" type="password" minlength="8" maxlength="72" autocomplete="new-password" data-obrigatorio="<?= $contaNova ? '1' : '0' ?>" <?= $atirador['PERFIL'] === 'ARMEIRO' ? ($contaNova ? 'required' : '') : 'disabled' ?>>
                    <small><?= $contaNova ? 'A conta de armeiro será criada junto com a ficha, após salvar os dados obrigatórios.' : 'Deixe a senha em branco para manter a senha atual.' ?></small>
                </fieldset>
            <?php endif; ?>

            <button type="submit" class="botao-primario" style="margin-top: 20px;">Salvar Atirador</button>
        </form>
    </section>

    <aside class="painel-card painel-card-info">
        <div class="cartao-topo">
            <div><h2>Resumo</h2><p>Informações</p></div>
        </div>
        <?php if ($id): ?>
            <p><strong>Registro ID #<?= (int) $id ?></strong></p>
            <p><strong>Situação:</strong> <?= (int) $atirador['ATIVO'] ? 'Ativo na Tropa' : 'Desativado' ?></p>
        <?php else: ?>
            <p>Cadastre os dados da tropa para habilitar a biometria e o controle de cautelas.</p>
        <?php endif; ?>
    </aside>
</div>

<script src="../assets/js/pessoas.js" defer></script>
<script src="../assets/js/pessoa-perfil.js?v=1" defer></script>
<?php fechar_painel(); ?>
