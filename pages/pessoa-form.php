<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/layout.php';

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
    'ID_USUARIO' => null
];

if ($id > 0) {
    $stmt = conectar()->prepare('SELECT ID_ATIRADOR, RA, NOME, NOME_GUERRA, NUMERO, FOTO, ID_DIGITAL, ATIVO, ID_ARMAMENTO, ID_USUARIO FROM ATIRADOR WHERE ID_ATIRADOR = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $atirador = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$atirador) {
        http_response_code(404);
        exit('Atirador não encontrado.');
    }
}

// Busca armas disponíveis para associar como armamento principal (opcional)
$armas = conectar()->query('SELECT ID_ARMAMENTO, SERIAL, MODELO FROM ARMAMENTO WHERE DISPONIBILIDADE = 1 ORDER BY SERIAL ASC')->fetchAll(PDO::FETCH_ASSOC);

// Busca usuários caso queira vincular o atirador a um armeiro
$usuarios = conectar()->query('SELECT ID_USUARIO, NOME FROM USUARIO WHERE ATIVO = 1 ORDER BY NOME ASC')->fetchAll(PDO::FETCH_ASSOC);

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
            <input id="id_digital" name="id_digital" type="number" min="1" max="99999" value="<?= e($atirador['ID_DIGITAL'] ?? '') ?>" placeholder="ID retornado pelo sensor biométrico">
            <small>Número identificador da digital gravada no sensor do ESP32.</small>

            <label for="id_armamento">Armamento Principal (Opcional)</label>
            <select id="id_armamento" name="id_armamento">
                <option value="">Nenhum / Não vinculado</option>
                <?php foreach ($armas as $arma): ?>
                    <option value="<?= (int)$arma['ID_ARMAMENTO'] ?>" <?= (int)$atirador['ID_ARMAMENTO'] === (int)$arma['ID_ARMAMENTO'] ? 'selected' : '' ?>>
                        <?= e($arma['SERIAL']) ?> (<?= e($arma['MODELO']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="id_usuario">Vínculo com Usuário/Armeiro (Opcional)</label>
            <select id="id_usuario" name="id_usuario">
                <option value="">Não é armeiro</option>
                <?php foreach ($usuarios as $usr): ?>
                    <option value="<?= (int)$usr['ID_USUARIO'] ?>" <?= (int)$atirador['ID_USUARIO'] === (int)$usr['ID_USUARIO'] ? 'selected' : '' ?>>
                        <?= e($usr['NOME']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small>Para quando o atirador também exerce função de auxiliar/armeiro no painel.</small>

            <button type="submit" class="botao-primario" style="margin-top: 20px;">Salvar Atirador</button>
        </form>
    </section>

    <aside class="painel-card painel-card-info">
        <div class="cartao-topo">
            <div><h2>Resumo</h2><p>Informações</p></div>
        </div>
        <?php if ($id): ?>
            <p><strong>Registro ID #<?= (int) $id ?></strong></p>
            <p><strong>Situação:</strong> <?= (int) $atirador['ATIVO'] ? 'Ativo na Tropa' : 'Inativo' ?></p>
        <?php else: ?>
            <p>Cadastre os dados da tropa para habilitar a biometria e o controle de cautelas.</p>
        <?php endif; ?>
    </aside>
</div>

<script src="../assets/js/pessoas.js" defer></script>
<?php fechar_painel(); ?>