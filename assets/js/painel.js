const menuButton = document.getElementById('alternar-menu');
const menu = document.getElementById('menu-lateral');
if (menuButton && menu) {
  menuButton.addEventListener('click', () => {
    const expanded = menuButton.getAttribute('aria-expanded') === 'true';
    menuButton.setAttribute('aria-expanded', String(!expanded));
    menu.classList.toggle('aberto', !expanded);
  });
}
