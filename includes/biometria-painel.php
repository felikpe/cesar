<?php
declare(strict_types=1);

function icone_biometria(): string
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 8.5A5.5 5.5 0 0 1 17.5 11v2c0 3-1 5.2-2.4 7"/><path d="M4.5 11a7.5 7.5 0 0 1 15 0v2c0 1.5-.2 2.9-.6 4.2"/><path d="M9 11a3 3 0 0 1 6 0v2c0 3-1.3 5.5-3.1 7.5"/><path d="M12 11v2c0 2.9-1.5 5.1-3.2 6.8M6.5 12.5c0 2.2-.7 4.1-1.8 5.7M9 13c0 1.8-.6 3.1-1.4 4.3M3 7.5A9.5 9.5 0 0 1 20 5"/></svg>';
}

function botao_biometria(string $modo, int $pessoa = 0, ?int $digital = null, string $rotulo = 'Abrir leitor biométrico', string $nome = ''): void
{
    ?>
    <button type="button" class="biometria-abrir" data-bio-abrir
        data-modo="<?= e($modo) ?>" data-pessoa="<?= $pessoa ?>"
        data-digital="<?= (int) $digital ?>" data-nome="<?= e($nome) ?>"
        aria-label="<?= e($rotulo) ?>" title="<?= e($rotulo) ?>"
        aria-haspopup="dialog" aria-controls="biometria-dialogo">
        <?= icone_biometria() ?>
    </button>
    <?php
}

function renderizar_dialogo_biometria(string $sessao = ''): void
{
    static $renderizado = false;
    if ($renderizado) return;
    $renderizado = true;
    ?>
    <link rel="stylesheet" href="../assets/css/biometria.css?v=3">
    <dialog id="biometria-dialogo" aria-labelledby="biometria-titulo" aria-describedby="biometria-instrucao"
        data-csrf="<?= e(csrf_token()) ?>" data-session="<?= e($sessao) ?>">
        <div class="biometria-modal">
            <button type="button" class="biometria-fechar" data-bio-fechar aria-label="Fechar leitor biométrico" autofocus>×</button>
            <div class="biometria-simbolo"><?= icone_biometria() ?></div>
            <h2 id="biometria-titulo">Leitor biométrico</h2>
            <p class="biometria-pessoa" data-bio-pessoa></p>
            <p id="biometria-instrucao" class="biometria-instrucao"></p>
            <div class="biometria-acoes">
                <button type="button" class="biometria-acao biometria-acao-principal" data-bio-cadastrar>Cadastrar digital</button>
                <button type="button" class="biometria-acao" data-bio-inserir>Inserir digital</button>
            </div>
            <p class="biometria-status" data-bio-status role="status" aria-live="polite" aria-atomic="true">Leitor desconectado.</p>
        </div>
    </dialog>
    <aside id="biometria-aviso" role="status" aria-live="polite" hidden>
        <span data-bio-aviso-texto></span>
        <button type="button" data-bio-reconectar>Reconectar</button>
        <button type="button" data-bio-dispensar aria-label="Fechar aviso do leitor">×</button>
    </aside>
    <script src="../assets/js/esp32-serial.js?v=3" defer></script>
    <script src="../assets/js/biometria.js?v=3" defer></script>
    <?php
}
