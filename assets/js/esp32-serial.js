(() => {
  'use strict';

  class CesarESP32 extends EventTarget {
    #port = null;
    #reader = null;
    #writer = null;
    #readTask = null;
    #writeChain = Promise.resolve();
    #connecting = null;
    #closing = null;
    #connected = false;
    #generation = 0;
    #active = null;
    #lastStatus = null;
    #buffer = '';
    #lineBytes = 0;
    #dropping = false;
    #decoder = new TextDecoder();
    #options;
    #serial;
    #onUnplug;
    #onPageHide;

    constructor(options = {}) {
      super();
      this.#options = {
        resetDelay: options.resetDelay ?? 1500,
        handshakeTimeout: options.handshakeTimeout ?? 3000,
        timeouts: { status: 8000, scan: 20000, enroll: 70000, delete: 8000, cancel: 3000, ...options.timeouts }
      };
      this.#serial = globalThis.navigator?.serial;
      this.#onUnplug = event => {
        if (event.target === this.#port || event.port === this.#port) {
          void this.#close(this.#error('DISCONNECTED', 'O ESP32 foi desconectado.'));
        }
      };
      this.#onPageHide = () => { void this.#close(this.#error('PAGEHIDE', 'Conexão USB pausada durante a navegação.')); };
    }

    get isConnected() {
      return this.#connected;
    }

    connect() {
      return this.#startConnection();
    }

    reconnect(saved) {
      if (!saved || typeof saved.device !== 'string' || !saved.device || !Number.isInteger(saved.usbVendorId) || saved.usbVendorId < 0 || saved.usbVendorId > 65535 || !Number.isInteger(saved.usbProductId) || saved.usbProductId < 0 || saved.usbProductId > 65535) {
        return Promise.reject(this.#error('RESELECT_REQUIRED', 'Selecione o ESP32 novamente para autorizar a conexão nesta sessão.'));
      }
      if (this.#connected && this.#lastStatus?.device !== saved.device) {
        return Promise.reject(this.#error('RESELECT_REQUIRED', 'O ESP32 conectado é diferente do dispositivo autorizado nesta sessão.'));
      }
      return this.#startConnection(saved);
    }

    getRememberInfo() {
      if (!this.#connected || !this.#port || !this.#lastStatus) return null;
      try {
        const info = this.#port.getInfo();
        if (!Number.isInteger(info.usbVendorId) || !Number.isInteger(info.usbProductId)) return null;
        return { device: this.#lastStatus.device, usbVendorId: info.usbVendorId, usbProductId: info.usbProductId };
      } catch (error) {
        return null;
      }
    }

    #startConnection(saved = null) {
      if (this.#connected) return Promise.resolve(this.#lastStatus);
      if (this.#connecting) return this.#connecting;
      const task = this.#open(saved);
      this.#connecting = task;
      task.then(() => {
        if (this.#connecting === task) this.#connecting = null;
      }, () => {
        if (this.#connecting === task) this.#connecting = null;
      });
      return task;
    }

    async #open(saved) {
      if (globalThis.isSecureContext === false) {
        throw this.#error('INSECURE_CONTEXT', 'Abra o sistema por localhost ou HTTPS para usar o USB.');
      }
      if (!this.#serial) {
        throw this.#error('UNSUPPORTED', 'Use Google Chrome ou Microsoft Edge com suporte a Web Serial.');
      }
      if (this.#closing) await this.#closing;
      const generation = ++this.#generation;
      let port;
      if (saved) {
        let ports;
        try {
          ports = await this.#serial.getPorts();
        } catch (error) {
          throw this.#error('RESELECT_REQUIRED', 'Não foi possível recuperar a autorização USB. Selecione o ESP32 novamente.');
        }
        const candidates = ports.filter(candidate => {
          try {
            const info = candidate.getInfo();
            return info.usbVendorId === saved.usbVendorId && info.usbProductId === saved.usbProductId;
          } catch (error) {
            return false;
          }
        });
        if (candidates.length !== 1) {
          throw this.#error('RESELECT_REQUIRED', candidates.length ? 'Há mais de um dispositivo USB compatível. Selecione o ESP32 novamente.' : 'O ESP32 autorizado não está disponível. Confira o cabo e conecte novamente.');
        }
        port = candidates[0];
      } else {
        try {
          port = await this.#serial.requestPort();
        } catch (error) {
          throw this.#error(error.name === 'NotFoundError' ? 'CANCELED' : 'PORT_PERMISSION', error.name === 'NotFoundError' ? 'Nenhuma porta USB foi selecionada.' : 'Não foi possível acessar a porta USB.');
        }
      }
      if (generation !== this.#generation) throw this.#error('CANCELED', 'Conexão cancelada.');
      try {
        await port.open({ baudRate: 115200, dataBits: 8, stopBits: 1, parity: 'none', flowControl: 'none' });
      } catch (error) {
        throw this.#error('PORT_OPEN', 'Não foi possível abrir a porta. Feche o Monitor Serial e outros programas que usam o ESP32.');
      }
      if (generation !== this.#generation) {
        await port.close().catch(() => {});
        throw this.#error('CANCELED', 'Conexão cancelada.');
      }
      this.#port = port;
      try {
        this.#resetLine();
        this.#writeChain = Promise.resolve();
        this.#writer = port.writable.getWriter();
        this.#serial.addEventListener('disconnect', this.#onUnplug);
        globalThis.addEventListener('pagehide', this.#onPageHide);
        this.#readTask = this.#read(port);
        await new Promise(resolve => setTimeout(resolve, this.#options.resetDelay));
        let lastError = this.#error('HANDSHAKE', 'O ESP32 não respondeu ao início da conexão.');
        for (let attempt = 0; attempt < 3; attempt++) {
          if (this.#port !== port || generation !== this.#generation) {
            throw this.#error('DISCONNECTED', 'A conexão USB foi encerrada.');
          }
          try {
            let status;
            try {
              status = await this.#send('status', {}, null, this.#options.handshakeTimeout);
            } catch (error) {
              if (saved && typeof error.detail?.device === 'string' && error.detail.device !== saved.device) {
                throw this.#error('RESELECT_REQUIRED', 'O dispositivo nesta porta não é o ESP32 autorizado. Selecione a porta novamente.');
              }
              if (!saved || String(error.code).toLowerCase() !== 'busy' || error.detail?.device !== saved.device) throw error;
              const canceled = await this.#send('cancel', {}, null, this.#options.timeouts.cancel);
              if (canceled.device !== saved.device) {
                throw this.#error('RESELECT_REQUIRED', 'O dispositivo nesta porta não é o ESP32 autorizado. Selecione a porta novamente.');
              }
              status = await this.#send('status', {}, null, this.#options.handshakeTimeout);
            }
            if (saved && status.device !== saved.device) {
              throw this.#error('RESELECT_REQUIRED', 'O dispositivo nesta porta não é o ESP32 autorizado. Selecione a porta novamente.');
            }
            this.#validateStatus(status);
            this.#lastStatus = status;
            this.#connected = true;
            this.dispatchEvent(new CustomEvent('connect', { detail: status }));
            return status;
          } catch (error) {
            lastError = error;
            if (error.code === 'RESELECT_REQUIRED') throw error;
            if (this.#port !== port) throw error;
            if (attempt < 2) await new Promise(resolve => setTimeout(resolve, 500));
          }
        }
        throw lastError;
      } catch (error) {
        await this.#close(error);
        throw error;
      }
    }

    #validateStatus(status) {
      if (status.sensor !== true) {
        throw this.#error('SENSOR_NOT_FOUND', 'O ESP32 respondeu, mas não conseguiu comunicar com o leitor biométrico. Confira alimentação, TX e RX.');
      }
      if (typeof status.device !== 'string' || !status.device || !Number.isInteger(status.capacity) || status.capacity < 2 || status.capacity > 65535 || !Array.isArray(status.occupied) || status.occupied.length > status.capacity || status.occupied.some(slot => !Number.isInteger(slot) || slot < 0 || slot >= status.capacity)) {
        throw this.#error('PROTOCOL', 'A resposta do leitor está incompleta. Confira a versão do firmware.');
      }
    }

    command(cmd, params = {}, onProgress = null) {
      if (!this.#connected) return Promise.reject(this.#error('NOT_CONNECTED', 'Conecte o ESP32 antes de continuar.'));
      if (!['status', 'scan', 'enroll', 'delete'].includes(cmd)) return Promise.reject(this.#error('INVALID_COMMAND', 'Comando USB inválido.'));
      return this.#send(cmd, params, onProgress, this.#options.timeouts[cmd]).then(result => {
        if (cmd === 'status') {
          this.#validateStatus(result);
          this.#lastStatus = result;
        }
        return result;
      });
    }

    #send(cmd, params, onProgress, timeout) {
      if (!this.#port || !this.#writer || this.#closing) return Promise.reject(this.#error('DISCONNECTED', 'A conexão USB foi encerrada.'));
      if (this.#active) return Promise.reject(this.#error('BUSY', 'Aguarde a operação atual ou cancele antes de continuar.'));
      const request = Array.from(crypto.getRandomValues(new Uint8Array(16)), value => value.toString(16).padStart(2, '0')).join('');
      const packet = { v: 1, request, cmd };
      if (cmd === 'enroll' || cmd === 'delete') {
        if (!params || !Number.isInteger(params.slot) || params.slot < 1 || params.slot > 65535) {
          return Promise.reject(this.#error('INVALID_SLOT', 'O ID biométrico precisa ser um inteiro positivo.'));
        }
        packet.slot = params.slot;
        if (cmd === 'delete' && params.confirm === true) packet.confirm = true;
      }
      let resolve;
      let reject;
      const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
      const active = { request, cmd, onProgress, resolve, reject, promise, timer: null };
      this.#active = active;
      active.timer = setTimeout(() => {
        if (this.#active !== active) return;
        this.#finish(active, this.#error('TIMEOUT', 'O leitor não concluiu a operação no prazo. Tente novamente.'));
        if (cmd === 'scan' || cmd === 'enroll' || cmd === 'delete') {
          void this.#cancelRemote().catch(() => {});
        }
      }, timeout);
      const bytes = new TextEncoder().encode(JSON.stringify(packet) + '\n');
      const writer = this.#writer;
      const write = this.#writeChain.then(() => writer.write(bytes));
      this.#writeChain = write.catch(() => {});
      void write.catch(() => {
        const error = this.#error('WRITE_FAILED', 'Falha ao enviar dados ao ESP32. Reconecte o cabo USB.');
        this.#finish(active, error);
        void this.#close(error);
      });
      return promise;
    }

    cancel() {
      const active = this.#active;
      if (!active) return Promise.resolve();
      if (active.cmd === 'cancel') return active.promise;
      this.#finish(active, this.#error('CANCELED', 'Operação cancelada.'));
      return this.#cancelRemote();
    }

    async #cancelRemote() {
      try {
        return await this.#send('cancel', {}, null, this.#options.timeouts.cancel);
      } catch (error) {
        await this.#close(error);
        throw error;
      }
    }

    #finish(active, error = null, result = null) {
      if (this.#active !== active) return;
      this.#active = null;
      clearTimeout(active.timer);
      if (error) active.reject(error);
      else active.resolve(result);
    }

    async #read(port) {
      let reason = this.#error('DISCONNECTED', 'A conexão com o ESP32 foi encerrada.');
      let reader;
      try {
        reader = port.readable.getReader();
        this.#reader = reader;
        while (this.#port === port) {
          const { value, done } = await reader.read();
          if (done) break;
          if (value) this.#receive(value);
        }
      } catch (error) {
        reason = this.#error('READ_FAILED', 'Falha na leitura USB. Reconecte o ESP32.');
      } finally {
        if (reader) {
          try { reader.releaseLock(); } catch (error) {}
        }
        if (this.#reader === reader) this.#reader = null;
        if (this.#port === port && !this.#closing) void this.#close(reason);
      }
    }

    #receive(bytes) {
      let start = 0;
      while (start < bytes.length) {
        const newline = bytes.indexOf(10, start);
        const end = newline === -1 ? bytes.length : newline;
        const segment = bytes.subarray(start, end);
        if (!this.#dropping) {
          this.#lineBytes += segment.length;
          if (this.#lineBytes > 8192) {
            this.#dropping = true;
            this.#buffer = '';
            this.#decoder = new TextDecoder();
          } else {
            this.#buffer += this.#decoder.decode(segment, { stream: true });
          }
        }
        if (newline === -1) break;
        if (!this.#dropping) this.#processLine(this.#buffer + this.#decoder.decode());
        this.#resetLine();
        start = end + 1;
      }
    }

    #resetLine() {
      this.#buffer = '';
      this.#lineBytes = 0;
      this.#dropping = false;
      this.#decoder = new TextDecoder();
    }

    #processLine(line) {
      let packet;
      try { packet = JSON.parse(line.trim()); } catch (error) { return; }
      const active = this.#active;
      if (!active || !packet || Array.isArray(packet) || packet.v !== 1 || packet.request !== active.request || packet.cmd !== active.cmd) return;
      if (packet.event === 'progress') {
        if (typeof active.onProgress === 'function') {
          try { active.onProgress(packet); } catch (error) {
            this.dispatchEvent(new CustomEvent('error', { detail: error }));
          }
        }
        this.dispatchEvent(new CustomEvent('progress', { detail: packet }));
      } else if (packet.event === 'result' && typeof packet.ok === 'boolean') {
        if (packet.ok) this.#finish(active, null, packet);
        else this.#finish(active, this.#error(typeof packet.code === 'string' ? packet.code : 'SENSOR_ERROR', typeof packet.message === 'string' ? packet.message : 'O leitor não concluiu a operação.', packet));
      }
    }

    disconnect() {
      return this.#close(this.#error('DISCONNECTED', 'Conexão USB encerrada.'));
    }

    #close(reason) {
      if (this.#closing) return this.#closing;
      this.#generation++;
      this.#connected = false;
      this.#lastStatus = null;
      const port = this.#port;
      if (!port) return Promise.resolve();
      this.#serial.removeEventListener('disconnect', this.#onUnplug);
      globalThis.removeEventListener('pagehide', this.#onPageHide);
      if (this.#active) this.#finish(this.#active, reason);
      const task = Promise.resolve().then(async () => {
        if (this.#reader) {
          try { await this.#reader.cancel(); } catch (error) {}
        }
        if (this.#readTask) {
          try { await this.#readTask; } catch (error) {}
        }
        if (this.#writer) {
          try { await this.#writer.abort(); } catch (error) {}
          try { this.#writer.releaseLock(); } catch (error) {}
        }
        try { await port.close(); } catch (error) {}
        if (this.#port === port) this.#port = null;
        this.#reader = null;
        this.#writer = null;
        this.#readTask = null;
        this.#writeChain = Promise.resolve();
        this.#resetLine();
        this.dispatchEvent(new CustomEvent('disconnect', { detail: reason }));
      });
      this.#closing = task;
      void task.finally(() => { if (this.#closing === task) this.#closing = null; });
      return task;
    }

    #error(code, message, detail = null) {
      const error = new Error(message);
      error.code = code;
      error.detail = detail;
      return error;
    }
  }

  window.CesarESP32 = CesarESP32;
})();
