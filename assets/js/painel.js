// Controle do menu lateral responsivo
const menuButton = document.getElementById('alternar-menu');
const menu = document.getElementById('menu-lateral');
if (menuButton && menu) {
  menuButton.addEventListener('click', () => {
    const expanded = menuButton.getAttribute('aria-expanded') === 'true';
    menuButton.setAttribute('aria-expanded', String(!expanded));
    menu.classList.toggle('aberto', !expanded);
  });
}

// ── Controle de navegação interna ──────────────────────────────────────────
// Usamos sessionStorage para sinalizar navegação interna.
// ATENÇÃO: this.form.submit() (chamado via onchange/JS) NÃO dispara o evento
// 'submit' do DOM, por isso sobrescrevemos o método nativo para garantir que
// a flag seja sempre marcada, independentemente de como o form é submetido.

function marcarNavegacaoInterna() {
  sessionStorage.setItem('cesar_navegando', '1');
}

// Intercepta form.submit() chamados via JavaScript (ex.: onchange="this.form.submit()")
const submitOriginal = HTMLFormElement.prototype.submit;
HTMLFormElement.prototype.submit = function () {
  marcarNavegacaoInterna();
  submitOriginal.call(this);
};

// Links internos
document.addEventListener('click', (evento) => {
  const link = evento.target.closest('a');
  if (link && link.href) {
    marcarNavegacaoInterna();
  }
});

// Envio de formulários via botão/Enter (complementar ao override acima)
document.addEventListener('submit', () => {
  marcarNavegacaoInterna();
});

// Alerta de confirmação ao tentar fechar aba/navegador diretamente
window.addEventListener('beforeunload', (evento) => {
  const navegando = sessionStorage.getItem('cesar_navegando') === '1';
  if (!navegando) {
    evento.preventDefault();
    evento.returnValue = '';
  }
  // NÃO remove cesar_navegando aqui — o pagehide ainda precisa lê-la
});

// Limpeza da sessão do C.E.S.A.R. somente ao encerrar de verdade
window.addEventListener('pagehide', (evento) => {
  const navegando = sessionStorage.getItem('cesar_navegando') === '1';
  if (!navegando) {
    // Não é uma navegação interna — aba/navegador foi fechado
    sessionStorage.removeItem('cesar_ativo');
  }
  // Sempre limpa a flag de navegação interna
  sessionStorage.removeItem('cesar_navegando');
});
