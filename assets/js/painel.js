// Controle do menu lateral responsivo
const menuButton = document.getElementById('alternar-menu');
const menu = document.getElementById('menu-lateral');
if (menuButton && menu) {
  const closeButton = document.getElementById('fechar-menu');
  const definirMenu = (aberto, devolverFoco = false) => {
    menuButton.setAttribute('aria-expanded', String(aberto));
    menuButton.setAttribute('aria-label', aberto ? 'Fechar menu' : 'Abrir menu');
    menu.classList.toggle('aberto', aberto);
    if (aberto) closeButton?.focus();
    else if (devolverFoco) menuButton.focus();
  };
  menuButton.addEventListener('click', () => {
    const expanded = menuButton.getAttribute('aria-expanded') === 'true';
    definirMenu(!expanded);
  });
  closeButton?.addEventListener('click', () => definirMenu(false, true));
  document.addEventListener('keydown', (evento) => {
    if (evento.key === 'Escape' && menu.classList.contains('aberto')) definirMenu(false, true);
  });
  document.addEventListener('click', (evento) => {
    if (!menu.contains(evento.target) && !menuButton.contains(evento.target)) definirMenu(false);
  });
}

let navegandoInternamente = false;
function marcarNavegacaoInterna() {
  navegandoInternamente = true;
}

const submitOriginal = HTMLFormElement.prototype.submit;
HTMLFormElement.prototype.submit = function () {
  marcarNavegacaoInterna();
  submitOriginal.call(this);
};

document.addEventListener('click', (evento) => {
  const link = evento.target.closest('a');
  if (link && link.href) {
    marcarNavegacaoInterna();
  }
});

document.addEventListener('submit', (evento) => {
  if (!evento.defaultPrevented) marcarNavegacaoInterna();
});

window.addEventListener('beforeunload', (evento) => {
  if (!navegandoInternamente) {
    evento.preventDefault();
    evento.returnValue = '';
  }
  navegandoInternamente = false;
});
