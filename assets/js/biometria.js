(() => {
  'use strict';
  if (window.CesarBiometria) return;
  const dialog = document.getElementById('biometria-dialogo');
  if (!dialog) return;
  const status = dialog.querySelector('[data-bio-status]');
  const title = document.getElementById('biometria-titulo');
  const instruction = document.getElementById('biometria-instrucao');
  const personName = dialog.querySelector('[data-bio-pessoa]');
  const enrollButton = dialog.querySelector('[data-bio-cadastrar]');
  const insertButton = dialog.querySelector('[data-bio-inserir]');
  const closeButton = dialog.querySelector('[data-bio-fechar]');
  const notice = document.getElementById('biometria-aviso');
  const noticeText = notice?.querySelector('[data-bio-aviso-texto]');
  const portKey = 'cesar_biometria_porta';
  const supported = typeof window.CesarESP32 === 'function' && 'serial' in navigator && window.isSecureContext;
  const reader = supported ? new window.CesarESP32() : null;
  let context = {modo: 'identificar', pessoa: 0, digital: 0, nome: ''};
  let device = null;
  let pending = null;
  let busy = false;
  let canceled = false;
  let connecting = null;
  let activeTrigger = null;
  let manualDisconnect = false;
  const enrollment = () => context.modo === 'cadastro';
  const pendingKey = () => `cesar_biometria_pendente_${context.pessoa}`;
  const message = (text, tone = 'info') => {
    status.textContent = text;
    status.dataset.tom = tone;
  };
  const state = () => ({conectado: Boolean(reader?.isConnected), ocupado: busy, dispositivo: device?.device || null, cancelado: canceled});
  const notifyState = () => window.dispatchEvent(new CustomEvent('cesar:biometria-status', {detail: state()}));
  const showNotice = text => {
    if (!notice) return;
    noticeText.textContent = text;
    notice.hidden = false;
  };
  const hideNotice = () => { if (notice) notice.hidden = true; };
  const forgetPort = () => { try { sessionStorage.removeItem(portKey); } catch (_) {} };
  const rememberPort = () => {
    const info = reader?.getRememberInfo?.();
    if (!info || !dialog.dataset.session) return;
    try { sessionStorage.setItem(portKey, JSON.stringify({...info, session: dialog.dataset.session})); } catch (_) {}
  };
  const loadPending = () => {
    pending = null;
    if (!enrollment() || !context.pessoa) return;
    try { pending = JSON.parse(sessionStorage.getItem(pendingKey()) || 'null'); } catch (_) {}
    if (pending && (!Number.isInteger(pending.slot) || pending.slot < 1 || !/^[a-f0-9]{64}$/.test(pending.token) || typeof pending.device !== 'string')) pending = null;
  };
  const persist = value => {
    pending = value;
    if (value) sessionStorage.setItem(pendingKey(), JSON.stringify(value));
    else sessionStorage.removeItem(pendingKey());
  };
  const update = () => {
    const external = context.modo === 'operacao';
    enrollButton.hidden = !enrollment() || external;
    insertButton.hidden = enrollment() || external;
    enrollButton.disabled = !supported || busy || !context.pessoa || Boolean(context.digital && !pending);
    insertButton.disabled = !supported || busy;
    dialog.querySelector('.biometria-acoes').setAttribute('aria-busy', String(busy));
    closeButton.setAttribute('aria-label', busy ? 'Cancelar leitura e fechar' : 'Fechar leitor biométrico');
    notifyState();
  };
  const instructionForContext = () => {
    if (context.modo === 'operacao') return 'Acompanhe a operação no leitor.';
    if (!enrollment()) return 'Insira o dedo no leitor para identificar o atirador. Confira os dados antes de confirmar a cautela.';
    if (!context.pessoa) return 'Salve o atirador e abra a edição para cadastrar a digital.';
    if (pending) return 'Há um cadastro pendente. Use o mesmo dedo para concluir o vínculo.';
    if (context.digital) return 'Este atirador já possui uma digital cadastrada.';
    return 'Cadastre a digital deste atirador fazendo as duas leituras do mesmo dedo.';
  };
  const readyMessage = () => {
    if (!supported) return message('Use Chrome ou Edge no computador, acessando por localhost ou HTTPS, para utilizar o leitor.', 'erro');
    if (enrollment() && !context.pessoa) return message('Salve o atirador primeiro.');
    if (pending) return message('Cadastro pendente preservado. Clique em Cadastrar digital para retomar com o mesmo dedo.');
    if (enrollment() && context.digital) return message('Digital já cadastrada para este atirador.', 'sucesso');
    message(reader.isConnected ? 'Leitor conectado e pronto.' : 'Selecione a porta do ESP32 para conectar o leitor.');
  };
  const api = async (acao, values) => {
    const response = await fetch('biometria-api.php', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
      body: JSON.stringify({acao, csrf_token: dialog.dataset.csrf, ...values})
    });
    let data;
    try { data = await response.json(); }
    catch (_) { throw new Error('Resposta inválida do servidor. Verifique a sessão e a atualização do banco.'); }
    if (!response.ok || !data.ok) {
      const error = new Error(data.message || 'Não foi possível registrar a biometria.');
      error.code = data.code;
      error.status = response.status;
      throw error;
    }
    return data;
  };
  const progress = result => { if (result.message) message(result.message); };
  const connectDevice = async () => {
    if (!reader) throw new Error('Use Chrome ou Edge com suporte a Web Serial por localhost ou HTTPS.');
    if (reader.isConnected && device) return device;
    if (connecting) return connecting;
    message('Escolha a porta do ESP32. Aguardando o leitor iniciar…');
    const task = reader.connect().then(result => {
      device = result;
      rememberPort();
      notifyState();
      return result;
    });
    connecting = task;
    try { return await task; }
    finally { if (connecting === task) connecting = null; }
  };
  const run = async (action, propagate = false) => {
    if (busy) {
      if (propagate) throw new Error('Aguarde a operação atual do leitor.');
      return;
    }
    busy = true;
    canceled = false;
    update();
    try { return await action(); }
    catch (error) {
      message(pending
        ? `${error.message} O cadastro pendente foi preservado. Use Cadastrar digital para retomar com o mesmo dedo.`
        : error.message, error.code === 'CANCELED' ? 'info' : 'erro');
      if (propagate) throw error;
    } finally { busy = false; update(); }
  };
  const setDigital = person => {
    context.digital = Number(person.id_digital);
    const field = document.getElementById('id_digital');
    if (field && enrollment()) field.value = String(context.digital);
    if (activeTrigger) activeTrigger.dataset.digital = String(context.digital);
    instruction.textContent = instructionForContext();
  };
  const finish = async () => {
    const data = await api('concluir', {token: pending.token, slot: pending.slot, device: pending.device});
    setDigital(data.pessoa);
    persist(null);
    instruction.textContent = instructionForContext();
    message(`Digital cadastrada para ${data.pessoa.nome_guerra || data.pessoa.nome}. Vínculo salvo no sistema.`, 'sucesso');
  };
  const recover = async expectedSlot => {
    if (pending && device.device !== pending.device) throw new Error('Conecte o mesmo ESP32 utilizado no cadastro pendente.');
    const current = await reader.command('status');
    if (canceled) return;
    if (pending && !current.occupied.includes(pending.slot)) {
      try { await api('cancelar_reserva', pending); }
      catch (error) { if (error.code !== 'RESERVA_EXPIRADA') throw error; }
      persist(null);
      instruction.textContent = instructionForContext();
      message('A captura não chegou a ser gravada. Clique em Cadastrar digital para iniciar novamente.');
      return;
    }
    message('Coloque o mesmo dedo no leitor para conferir o cadastro.');
    let result;
    try { result = await reader.command('scan', {}, progress); }
    catch (error) {
      if (error.code === 'not_found') {
        message(pending
          ? 'O dedo lido não foi encontrado. Use o mesmo dedo do cadastro pendente e tente novamente.'
          : 'Este dedo ainda não está gravado. Clique em Cadastrar digital e faça as duas leituras.', 'erro');
        return;
      }
      throw error;
    }
    if (canceled) return;
    if ((pending && result.slot !== pending.slot) || (expectedSlot && result.slot !== expectedSlot)) throw new Error('A digital lida não corresponde ao cadastro que está sendo recuperado.');
    try {
      const existing = await api('identificar', {slot: result.slot, device: current.device});
      if (Number(existing.pessoa.id) !== context.pessoa) throw new Error('Esta digital já está vinculada a outro atirador.');
      setDigital(existing.pessoa);
      persist(null);
      instruction.textContent = instructionForContext();
      message('O vínculo desta digital já estava salvo. Cadastro recuperado.', 'sucesso');
      return;
    } catch (error) { if (error.code !== 'DIGITAL_SEM_VINCULO') throw error; }
    if (pending) {
      try { await finish(); return; }
      catch (error) { if (error.code !== 'RESERVA_EXPIRADA') throw error; }
    }
    if (!window.confirm('Vincular a digital que acabou de ser lida ao atirador desta ficha? Confira o nome antes de confirmar.')) {
      message('Vínculo cancelado. Nenhuma digital foi substituída.');
      return;
    }
    const recovered = await api('recuperar_reserva', {pessoa: context.pessoa, slot: result.slot, device: current.device, capacity: current.capacity, occupied: current.occupied});
    persist({token: recovered.token, slot: recovered.slot, device: current.device});
    await finish();
  };
  const enroll = async () => {
    if (!context.pessoa) throw new Error('Salve o atirador e abra sua edição antes de cadastrar a digital.');
    await connectDevice();
    if (canceled) return;
    if (pending) return recover();
    if (context.digital) return message('Este atirador já possui uma digital cadastrada.', 'sucesso');
    if (!window.confirm('Cadastrar a digital para este atirador?')) return message('Cadastro cancelado antes da captura.');
    message('Consultando as posições livres no leitor…');
    const current = await reader.command('status');
    if (canceled) return;
    message('Reservando o ID da digital no sistema…');
    const reservation = await api('reservar', {pessoa: context.pessoa, device: current.device, capacity: current.capacity, occupied: current.occupied});
    persist({token: reservation.token, slot: reservation.slot, device: current.device});
    if (canceled) {
      await api('cancelar_reserva', pending);
      persist(null);
      return;
    }
    message('Coloque o dedo no leitor e acompanhe as duas capturas.');
    let result;
    try { result = await reader.command('enroll', {slot: pending.slot}, progress); }
    catch (error) {
      const existingSlot = error.detail?.slot;
      if (error.code !== 'duplicate_finger' || !Number.isInteger(existingSlot) || existingSlot < 1) throw error;
      try { await api('cancelar_reserva', pending); }
      catch (cancelError) { if (cancelError.code !== 'RESERVA_EXPIRADA') throw cancelError; }
      persist(null);
      if (!canceled) return recover(existingSlot);
      return;
    }
    if (result.slot !== pending.slot) throw new Error('O leitor retornou um slot diferente do reservado.');
    await finish();
  };
  const identify = async () => {
    await connectDevice();
    if (canceled) return;
    message('Coloque o dedo no leitor para identificar o atirador.');
    const scanned = await reader.command('scan', {}, progress);
    if (canceled) return;
    const found = await api('identificar', {slot: scanned.slot, device: device.device});
    const person = found.pessoa;
    if (context.pessoa && Number(person.id) !== context.pessoa) throw new Error('A digital pertence a outro atirador. Confira a cautela aberta.');
    const select = document.getElementById('id_pessoa');
    if (select) {
      select.value = String(person.id);
      if (select.value !== String(person.id)) throw new Error('O atirador identificado não está disponível neste formulário. Atualize a página.');
      select.dispatchEvent(new Event('change', {bubbles: true}));
      const weapon = document.getElementById('id_armamento');
      if (weapon && !weapon.value && person.id_armamento && [...weapon.options].some(option => option.value === String(person.id_armamento))) weapon.value = String(person.id_armamento);
    }
    message(`Atirador identificado: ${person.nome_guerra || person.nome} (RA ${person.ra}). Confira os dados e confirme a operação no formulário.`, 'sucesso');
  };
  const show = (values, trigger = null) => {
    if (busy) {
      if (!dialog.open) dialog.showModal();
      return false;
    }
    context = {
      modo: values.modo || 'identificar',
      pessoa: Number(values.pessoa) || 0,
      digital: Number(values.digital) || 0,
      nome: String(values.nome || '')
    };
    activeTrigger = trigger;
    loadPending();
    title.textContent = context.modo === 'operacao' ? values.rotulo || 'Leitor biométrico' : enrollment() ? 'Cadastrar digital' : 'Inserir digital';
    personName.textContent = context.nome;
    instruction.textContent = instructionForContext();
    readyMessage();
    update();
    if (!dialog.open) dialog.showModal();
    return true;
  };
  const close = () => {
    if (busy) {
      canceled = true;
      if (reader?.isConnected) reader.cancel().catch(() => {});
      message('Leitura cancelada. O cadastro pendente, se houver, foi preservado.');
    }
    if (dialog.open) dialog.close();
  };
  const open = (values, trigger = null) => {
    if (!show(values, trigger)) return;
    if (supported && !(enrollment() && !context.pessoa) && !reader.isConnected) {
      void run(async () => { await connectDevice(); if (!canceled) readyMessage(); });
    }
  };
  enrollButton.addEventListener('click', () => { void run(enroll); });
  insertButton.addEventListener('click', () => { void run(identify); });
  closeButton.addEventListener('click', close);
  dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
  notice?.querySelector('[data-bio-dispensar]').addEventListener('click', hideNotice);
  notice?.querySelector('[data-bio-reconectar]').addEventListener('click', () => {
    const trigger = activeTrigger || document.querySelector('[data-bio-abrir]');
    const values = trigger ? trigger.dataset : {modo: 'operacao', rotulo: 'Conectar leitor'};
    if (!show(values, trigger)) return;
    void run(async () => { await connectDevice(); if (!canceled) readyMessage(); });
  });
  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-bio-abrir]');
    if (!trigger) return;
    open(trigger.dataset, trigger);
  });
  reader?.addEventListener('connect', event => {
    device = event.detail;
    hideNotice();
    notifyState();
  });
  reader?.addEventListener('disconnect', event => {
    device = null;
    if (event.detail?.code === 'PAGEHIDE') { notifyState(); return; }
    forgetPort();
    if (!manualDisconnect) showNotice('O leitor biométrico foi desconectado. Confira o cabo e reconecte.');
    message(pending ? 'ESP32 desconectado. O cadastro pendente foi preservado.' : 'ESP32 desconectado. Abra o leitor para selecionar a porta novamente.', 'erro');
    update();
  });
  const controller = {
    abrir: open,
    fechar: close,
    conectar: () => run(async () => { const result = await connectDevice(); readyMessage(); return result; }, true),
    desconectar: async () => {
      canceled = true;
      forgetPort();
      manualDisconnect = true;
      try {
        if (reader) { await reader.cancel().catch(() => {}); await reader.disconnect(); }
      } finally {
        manualDisconnect = false;
        device = null;
        hideNotice();
        update();
      }
    },
    executarComLeitor: async (callback, options = {}) => {
      if (typeof callback !== 'function') throw new TypeError('Informe a operação que será executada no leitor.');
      if (busy) throw new Error('Aguarde a operação atual do leitor.');
      show({modo: 'operacao', nome: options.nome, rotulo: options.rotulo});
      return run(async () => {
        await connectDevice();
        const current = await reader.command('status');
        if (canceled) { const error = new Error('Operação cancelada.'); error.code = 'CANCELED'; throw error; }
        return callback(reader, current, message);
      }, true);
    },
    notificar: message,
    get leitor() { return reader; },
    get estado() { return state(); }
  };
  window.CesarBiometria = controller;
  update();
  let remembered = null;
  try { remembered = JSON.parse(sessionStorage.getItem(portKey) || 'null'); } catch (_) {}
  if (remembered && (!dialog.dataset.session || remembered.session !== dialog.dataset.session)) {
    forgetPort();
    remembered = null;
  }
  if (reader && remembered && typeof reader.reconnect === 'function') {
    const task = reader.reconnect(remembered).then(result => {
      device = result;
      rememberPort();
      update();
      return result;
    }).catch(error => {
      forgetPort();
      showNotice('Não foi possível reconectar o leitor. Selecione novamente a porta do ESP32.');
      message('Selecione novamente a porta do ESP32 ao abrir o leitor.', 'erro');
      throw error;
    });
    connecting = task;
    void task.catch(() => {}).finally(() => { if (connecting === task) connecting = null; });
  }
  window.dispatchEvent(new CustomEvent('cesar:biometria-ready', {detail: controller}));
})();
