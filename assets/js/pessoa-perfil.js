(() => {
  'use strict';
  const select = document.getElementById('perfil');
  const fields = document.getElementById('perfil-acesso');
  if (!select || !fields) return;
  const inputs = [...fields.querySelectorAll('input')];
  const update = () => {
    const enabled = select.value === 'ARMEIRO' && !select.disabled;
    fields.hidden = !enabled;
    fields.style.display = enabled ? '' : 'none';
    inputs.forEach(input => {
      input.disabled = !enabled;
      input.required = enabled && input.dataset.obrigatorio === '1';
    });
    const password = document.getElementById('senha_acesso');
    const confirmation = document.getElementById('confirmar_senha_acesso');
    if (confirmation) confirmation.required = enabled && (fields.dataset.contaNova === '1' || password?.value !== '');
    if (password && confirmation) confirmation.setCustomValidity(enabled && confirmation.value !== password.value ? 'A confirmação deve ser igual à senha.' : '');
  };
  select.addEventListener('change', update);
  inputs.forEach(input => input.addEventListener('input', update));
  update();
})();
