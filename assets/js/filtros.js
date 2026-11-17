(() => {
  'use strict';
  const searchIcon = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path></svg>';
  const filterIcon = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 5h16l-6.5 7.5V19l-3-1.5v-5z"></path></svg>';
  let sequence = 0;
  for (const form of document.querySelectorAll('form.filtros')) {
    if (form.classList.contains('filtros-recolhidos')) continue;
    const search = form.querySelector('input[name="q"], input[name="busca"], input[type="search"]');
    if (!search) continue;
    const fields = [...form.querySelectorAll('select, input:not([type="hidden"]), textarea')].filter(field => field !== search && !['submit', 'button', 'reset'].includes(field.type));
    if (!fields.length) continue;
    const prefix = `filtros-${++sequence}`;
    const originalContainers = [...form.querySelectorAll('div')];
    const searchLabels = [...search.labels];
    let searchBar = search.closest('.busca-com-icone');
    if (!searchBar || !form.contains(searchBar)) {
      searchBar = document.createElement('div');
      searchBar.className = 'busca-com-icone';
      const submit = form.querySelector('button[type="submit"], input[type="submit"]');
      const searchButton = submit?.tagName === 'BUTTON' ? submit : document.createElement('button');
      if (submit?.tagName === 'INPUT') submit.remove();
      searchButton.type = 'submit';
      searchButton.className = 'busca-lupa';
      searchButton.setAttribute('aria-label', 'Pesquisar');
      searchButton.setAttribute('title', 'Pesquisar');
      searchButton.innerHTML = searchIcon;
      searchBar.append(search, searchButton);
    }
    if (!search.id) search.id = `${prefix}-busca`;
    if (!search.getAttribute('aria-label') && !searchLabels.length) search.setAttribute('aria-label', search.placeholder || 'Pesquisar');
    for (const label of searchLabels) {
      label.classList.add('filtros-rotulo-busca');
      searchBar.prepend(label);
    }
    const top = document.createElement('div');
    top.className = 'filtros-topo';
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'filtros-alternar';
    toggle.setAttribute('aria-label', 'Abrir filtros');
    toggle.setAttribute('title', 'Filtros');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-controls', `${prefix}-painel`);
    toggle.innerHTML = filterIcon;
    const badge = document.createElement('span');
    badge.className = 'filtros-contagem';
    badge.setAttribute('aria-hidden', 'true');
    badge.hidden = true;
    toggle.append(badge);
    const panel = document.createElement('section');
    panel.className = 'filtros-painel';
    panel.id = `${prefix}-painel`;
    panel.dataset.campos = String(fields.length);
    panel.setAttribute('aria-label', 'Filtros de busca');
    panel.hidden = true;
    const heading = document.createElement('strong');
    heading.className = 'filtros-titulo';
    heading.textContent = 'Filtros';
    const grid = document.createElement('div');
    grid.className = 'filtros-campos';
    fields.forEach((field, index) => {
      field.removeAttribute('onchange');
      const container = document.createElement('div');
      container.className = 'filtros-campo';
      if (!field.id) field.id = `${prefix}-campo-${index + 1}`;
      const wrappingLabel = field.closest('label');
      if (wrappingLabel && form.contains(wrappingLabel)) {
        if (['checkbox', 'radio'].includes(field.type)) container.classList.add('filtros-campo-opcao');
        container.append(wrappingLabel);
      } else {
        let label = [...field.labels].find(item => form.contains(item));
        if (!label) {
          label = document.createElement('label');
          const text = (field.getAttribute('aria-label') || field.placeholder || field.name || 'Filtro').replace(/^filtrar\s*(?:por\s+)?/i, '').trim();
          label.textContent = text.charAt(0).toLocaleUpperCase('pt-BR') + text.slice(1);
        }
        label.htmlFor = field.id;
        container.append(label, field);
      }
      grid.append(container);
    });
    const actions = document.createElement('div');
    actions.className = 'filtros-acoes';
    const apply = document.createElement('button');
    apply.type = 'submit';
    apply.className = 'botao-primario filtros-aplicar';
    apply.textContent = 'Aplicar filtros';
    let clear = [...form.querySelectorAll('a[href]')].find(link => /limpar/i.test(link.textContent));
    if (!clear) {
      clear = document.createElement('a');
      clear.href = form.getAttribute('action') || window.location.pathname;
    }
    clear.className = 'link-discreto filtros-limpar';
    clear.textContent = 'Limpar filtros';
    actions.append(clear, apply);
    panel.append(heading, grid, actions);
    top.append(searchBar, toggle);
    form.prepend(top);
    form.append(panel);
    form.classList.add('busca-form', 'filtros-recolhidos');
    for (const container of originalContainers.reverse()) {
      if (form.contains(container) && !top.contains(container) && !panel.contains(container) && !container.textContent.trim() && !container.querySelector('input, select, textarea, button, a')) container.remove();
    }
    const isActive = field => {
      if (field.disabled) return false;
      if (field.tagName === 'SELECT') {
        const baseline = field.dataset.filtroPadrao ?? field.options[0]?.value ?? '';
        return [...field.selectedOptions].some(option => option.value !== baseline);
      }
      if (['checkbox', 'radio'].includes(field.type)) return field.checked;
      return field.value !== (field.dataset.filtroPadrao ?? '');
    };
    const updateCount = () => {
      const count = fields.filter(isActive).length;
      badge.textContent = String(count);
      badge.hidden = count === 0;
      toggle.classList.toggle('filtros-ativos', count > 0);
      toggle.dataset.ativos = String(count);
      const label = `${panel.hidden ? 'Abrir' : 'Fechar'} filtros${count ? ` (${count} ativo${count === 1 ? '' : 's'})` : ''}`;
      toggle.setAttribute('aria-label', label);
      toggle.setAttribute('title', label);
    };
    const setOpen = (open, focus = false) => {
      panel.hidden = !open;
      toggle.setAttribute('aria-expanded', String(open));
      updateCount();
      if (focus) (open ? fields.find(field => !field.disabled) : toggle)?.focus();
    };
    toggle.addEventListener('click', () => setOpen(panel.hidden, true));
    form.addEventListener('keydown', event => {
      if (event.key !== 'Escape' || panel.hidden) return;
      event.preventDefault();
      event.stopPropagation();
      setOpen(false, true);
    });
    document.addEventListener('click', event => {
      if (!panel.hidden && !panel.contains(event.target) && !toggle.contains(event.target)) setOpen(false);
    });
    form.addEventListener('input', updateCount);
    form.addEventListener('change', updateCount);
    form.addEventListener('reset', () => setTimeout(updateCount, 0));
    form.addEventListener('invalid', event => {
      if (panel.contains(event.target)) setOpen(true);
    }, true);
    updateCount();
  }
})();
