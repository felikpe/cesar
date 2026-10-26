// Interface da Etapa 3: pré-visualização local (sem envio automático ao servidor).
const campoFoto = document.getElementById('foto');
const imagemPreview = document.getElementById('foto-preview');
const vazioPreview = document.getElementById('foto-placeholder');
let urlTemporaria = null;

if (campoFoto && imagemPreview && vazioPreview) {
  campoFoto.addEventListener('change', () => {
    if (urlTemporaria) {
      URL.revokeObjectURL(urlTemporaria);
      urlTemporaria = null;
    }
    const foto = campoFoto.files?.[0];
    if (!foto) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(foto.type) || foto.size > 2 * 1024 * 1024) {
      alert('Escolha uma imagem JPG, PNG ou WEBP de no máximo 2 MB.');
      campoFoto.value = '';
      return;
    }
    urlTemporaria = URL.createObjectURL(foto);
    imagemPreview.src = urlTemporaria;
    imagemPreview.hidden = false;
    vazioPreview.hidden = true;
  });
  window.addEventListener('pagehide', () => {
    if (urlTemporaria) URL.revokeObjectURL(urlTemporaria);
  });
}

document.querySelectorAll('form[data-confirmar]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (!window.confirm(form.dataset.confirmar || 'Confirmar alteração?')) event.preventDefault();
  });
});
