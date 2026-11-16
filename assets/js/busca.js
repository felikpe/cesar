/* Etapa 8: apenas apresentação visual. A busca continua usando o formulário GET existente. */
(() => {
  'use strict';
  const icone = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path></svg>';
  for (const form of document.querySelectorAll('form.filtros')) {
    if (form.querySelector('.busca-com-icone')) continue;
    const campo = form.querySelector('input[name="q"]');
    if (!campo) continue;
    const botoes = [...form.querySelectorAll('button[type="submit"]')];
    const botao = botoes[0];
    const barra = document.createElement('div');
    barra.className = 'busca-com-icone';
    campo.parentElement.insertBefore(barra, campo);
    barra.appendChild(campo);
    if (botao) {
      barra.appendChild(botao);
      botao.className = 'busca-lupa';
      botao.setAttribute('aria-label', 'Pesquisar');
      botao.setAttribute('title', 'Pesquisar');
      botao.innerHTML = icone;
    } else {
      const enviar = document.createElement('button');
      enviar.type = 'submit';
      enviar.className = 'busca-lupa';
      enviar.setAttribute('aria-label', 'Pesquisar');
      enviar.setAttribute('title', 'Pesquisar');
      enviar.innerHTML = icone;
      barra.appendChild(enviar);
    }
    form.classList.add('busca-form');
  }
})();
