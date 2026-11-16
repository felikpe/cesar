// Pré-visualização da foto de perfil, sem envio automático.
const campoFotoPerfil = document.getElementById('foto');
const fotoPreviewPerfil = document.getElementById('foto-preview');
const fotoPlaceholderPerfil = document.getElementById('foto-placeholder');
const caixaRemoverFoto = document.querySelector('input[name="remover_foto"]');
let urlPerfilTemp = null;

if (campoFotoPerfil && fotoPreviewPerfil && fotoPlaceholderPerfil) {
  campoFotoPerfil.addEventListener('change', () => {
    if (urlPerfilTemp) {
      URL.revokeObjectURL(urlPerfilTemp);
      urlPerfilTemp = null;
    }
    const arquivo = campoFotoPerfil.files?.[0];
    if (!arquivo) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(arquivo.type) || arquivo.size > 2 * 1024 * 1024) {
      alert('Escolha uma foto JPG, PNG ou WEBP de até 2 MB.');
      campoFotoPerfil.value = '';
      return;
    }
    if (caixaRemoverFoto) caixaRemoverFoto.checked = false;
    urlPerfilTemp = URL.createObjectURL(arquivo);
    fotoPreviewPerfil.src = urlPerfilTemp;
    fotoPreviewPerfil.hidden = false;
    fotoPlaceholderPerfil.hidden = true;
  });
  if (caixaRemoverFoto) {
    caixaRemoverFoto.addEventListener('change', () => {
      if (!caixaRemoverFoto.checked) return;
      campoFotoPerfil.value = '';
      if (urlPerfilTemp) URL.revokeObjectURL(urlPerfilTemp);
      urlPerfilTemp = null;
      fotoPreviewPerfil.hidden = true;
      fotoPlaceholderPerfil.hidden = false;
    });
  }
  window.addEventListener('pagehide', () => {
    if (urlPerfilTemp) URL.revokeObjectURL(urlPerfilTemp);
  });
}
