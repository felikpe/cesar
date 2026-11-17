(() => {
  'use strict';
  document.querySelectorAll('input[type="password"]').forEach((input, index) => {
    if (input.closest('.campo-senha')) return;
    if (!input.id) input.id = `senha-protegida-${index}`;
    const wrapper = document.createElement('div');
    wrapper.className = 'campo-senha';
    input.before(wrapper);
    wrapper.append(input);
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'alternar-senha';
    button.setAttribute('aria-controls', input.id);
    button.setAttribute('aria-label', 'Mostrar senha');
    button.setAttribute('aria-pressed', 'false');
    const visibleIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
    const hiddenIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m3 3 18 18M10.5 5.1A14 14 0 0 1 12 5c6.5 0 10 7 10 7a20 20 0 0 1-3.1 3.9M6.1 6.1A24 24 0 0 0 2 12s3.5 7 10 7a13 13 0 0 0 5.9-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
    button.innerHTML = visibleIcon;
    button.addEventListener('click', () => {
      const visible = input.type === 'password';
      input.type = visible ? 'text' : 'password';
      button.setAttribute('aria-label', visible ? 'Ocultar senha' : 'Mostrar senha');
      button.setAttribute('aria-pressed', String(visible));
      button.innerHTML = visible ? hiddenIcon : visibleIcon;
    });
    wrapper.append(button);
  });
})();
