(() => {
  'use strict';
  const root = document.querySelector('[data-ppush]');
  if (!root || typeof PortalPush === 'undefined') return;
  const config = PortalPush;
  const status = root.querySelector('.ppush-status');
  const enable = root.querySelector('[data-ppush-enable]');
  const test = root.querySelector('[data-ppush-test]');
  const devices = root.querySelector('[data-ppush-devices]');
  const prompt = root.hasAttribute('data-ppush-prompt');
  const store = {
    get(key) { try { return localStorage.getItem(config.storage + key); } catch { return null; } },
    set(key, value) { try { localStorage.setItem(config.storage + key, value); } catch { throw new Error('Permita o armazenamento local para ativar este dispositivo.'); } }
  };
  const message = text => { status.textContent = text; };
  const disableTest = value => { if (test) test.disabled = value; };
  const dismiss = root.querySelector('[data-ppush-dismiss]');
  if (dismiss) dismiss.addEventListener('click', () => {
    try { store.set('-dismiss', String(Date.now() + 7 * 86400000)); } catch { /* Fechar continua disponível. */ }
    root.hidden = true;
  });
  const api = async (path, method = 'GET', body) => {
    const response = await fetch(config.api + path, {
      method, credentials: 'same-origin', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
      body: body ? JSON.stringify(body) : undefined
    });
    const data = response.status === 204 ? {} : await response.json();
    if (!response.ok) throw new Error(data.message || 'Não foi possível concluir. Atualize a página e tente novamente.');
    return data;
  };
  const fromBase64 = input => {
    const raw = atob(input.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - input.length % 4) % 4));
    return Uint8Array.from(raw, char => char.charCodeAt(0));
  };
  const secret = () => {
    let value = store.get('-secret');
    if (!value) {
      value = btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32)))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
      store.set('-secret', value);
    }
    return value;
  };
  const label = () => {
    const ua = navigator.userAgent;
    const browser = /Edg\//.test(ua) ? 'Edge' : /Firefox\//.test(ua) ? 'Firefox' : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : 'Navegador';
    const os = /iPhone|iPad/.test(ua) ? 'iPhone/iPad' : /Android/.test(ua) ? 'Android' : /Windows/.test(ua) ? 'Windows' : /Mac/.test(ua) ? 'Mac' : 'Computador';
    return browser + ' · ' + os;
  };
  let registration;
  const waitActive = async reg => {
    if (reg.active) return;
    await new Promise((resolve, reject) => {
      const candidate = reg.installing || reg.waiting;
      if (!candidate) { reject(new Error('Não foi possível ativar o recebimento. Atualize a página.')); return; }
      const timer = setTimeout(() => reject(new Error('A ativação demorou mais que o esperado. Atualize a página.')), 15000);
      const check = () => {
        if (candidate.state === 'activated') { clearTimeout(timer); resolve(); }
        if (candidate.state === 'redundant') { clearTimeout(timer); reject(new Error('Não foi possível ativar o recebimento.')); }
      };
      candidate.addEventListener('statechange', check);
      check();
    });
  };
  const supportsPush = script => new Promise(resolve => {
    if (!script) { resolve(false); return; }
    const channel = new MessageChannel();
    const finish = value => { clearTimeout(timer); channel.port1.close(); channel.port2.close(); resolve(value); };
    const timer = setTimeout(() => finish(false), 1200);
    channel.port1.onmessage = event => finish(event.data && event.data.type === 'PORTAL_PUSH_READY');
    try { script.postMessage({ type: 'PORTAL_PUSH_PING' }, [channel.port2]); } catch { finish(false); }
  });
  const worker = async () => {
    if (registration) return registration;
    let existing = await navigator.serviceWorker.getRegistration(location.href);
    if (!existing) existing = await navigator.serviceWorker.getRegistration(config.scope);
    if (!existing && config.pwa) {
      // O plugin do PWA continua responsável por registrar seu próprio worker.
      for (let attempt = 0; attempt < 15 && !existing; attempt++) {
        await new Promise(resolve => setTimeout(resolve, 200));
        existing = await navigator.serviceWorker.getRegistration(location.href);
      }
      if (!existing) throw new Error('Abra o portal pelo PWA e tente novamente. O aplicativo ainda está sendo preparado.');
    }
    const script = existing && (existing.active || existing.waiting || existing.installing);
    if (script) {
      const existingURL = new URL(script.scriptURL);
      const expected = new URL(config.worker);
      if (existingURL.origin !== expected.origin || existingURL.pathname !== expected.pathname || existingURL.searchParams.get('portal_push_worker') !== '1') {
        await waitActive(existing);
        if (!await supportsPush(existing.active)) {
          try { await existing.update(); } catch { /* A próxima visita pode concluir a atualização. */ }
          if (!await supportsPush(existing.active)) {
            throw new Error('O PWA precisa atualizar o recebimento de avisos. Feche todas as janelas do portal e abra novamente. Se persistir, avise a administração para integrar o PWA.');
          }
        }
        registration = existing;
        return registration;
      }
      if (config.pwa) {
        // Não cancela uma instalação do PWA que esteja substituindo o worker antigo do portal.
        await waitActive(existing);
        registration = existing;
        return registration;
      }
    }
    const created = await navigator.serviceWorker.register(config.worker, { scope: config.scope, updateViaCache: 'none' });
    await waitActive(created);
    registration = created;
    return registration;
  };
  const refresh = async () => {
    if (!devices) return;
    const data = await api('subscriptions');
    devices.replaceChildren();
    if (!data.devices.length) { devices.textContent = 'Nenhum dispositivo cadastrado ainda.'; return; }
    for (const item of data.devices) {
      const row = document.createElement('div'); row.className = 'ppush-device';
      const name = document.createElement('span'); name.textContent = item.label + (Number(item.active) ? ' · Ativo' : ' · Pausado');
      row.append(name);
      if (Number(item.active)) {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'ppush-secondary'; button.textContent = 'Desativar';
        button.addEventListener('click', async () => {
          button.disabled = true;
          try {
            await api('subscriptions/' + item.id, 'DELETE');
            if (String(item.id) === store.get('-id')) {
              store.set('-optin', '0');
              try { const reg = await worker(); const sub = await reg.pushManager.getSubscription(); if (sub) await sub.unsubscribe(); } catch { /* A inscrição no servidor já foi pausada. */ }
              disableTest(true); message('Notificações desativadas neste dispositivo.'); enable.textContent = 'Ativar neste dispositivo';
            }
            await refresh();
          } catch (error) { message(error.message); button.disabled = false; }
        });
        row.append(button);
      }
      devices.append(row);
    }
  };
  const sync = async reactivate => {
    const reg = await worker();
    let sub = await reg.pushManager.getSubscription();
    if (sub && sub.options && sub.options.applicationServerKey) {
      const current = new Uint8Array(sub.options.applicationServerKey);
      const expected = fromBase64(config.publicKey);
      if (current.length !== expected.length || current.some((value, index) => value !== expected[index])) {
        if (!reactivate) throw new Error('Ative novamente os avisos para concluir a mudança do serviço de notificações.');
        if (!await sub.unsubscribe()) throw new Error('Não foi possível atualizar a inscrição deste dispositivo. Tente novamente.');
        sub = null;
      }
    }
    if (!sub && !reactivate) { message('Ative os avisos para receber atualizações neste dispositivo.'); return; }
    if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: fromBase64(config.publicKey) });
    const data = await api('subscriptions', 'POST', { subscription: sub.toJSON(), device_secret: secret(), label: label(), reactivate });
    store.set('-id', String(data.id)); store.set('-optin', '1');
    message('Notificações ativadas neste dispositivo.'); enable.textContent = 'Atualizar este dispositivo'; disableTest(false);
    if (prompt) root.hidden = true;
    await refresh();
  };
  enable.addEventListener('click', async () => {
    enable.disabled = true;
    try {
      // O pedido deve ocorrer diretamente no gesto, antes de aguardar rede ou registro.
      const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
      if (permission !== 'granted') throw new Error('Permita as notificações nas configurações do navegador para continuar.');
      await sync(true);
    } catch (error) { message(error.message); }
    finally { enable.disabled = false; }
  });
  if (test) test.addEventListener('click', async () => {
    disableTest(true);
    try { await api('test', 'POST', {}); message('Teste colocado na fila. Ele chegará quando o portal processar o envio.'); }
    catch (error) { message(error.message); }
    finally { disableTest(false); }
  });
  (async () => {
    try {
      await refresh();
      if (prompt && Number(store.get('-dismiss')) > Date.now() && store.get('-optin') !== '1') return;
      if (prompt) root.hidden = false;
      const ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
      if (ios && !navigator.standalone && !matchMedia('(display-mode: standalone)').matches) {
        message('No iPhone ou iPad, adicione este portal à Tela de Início, abra pelo ícone e ative os avisos.'); return;
      }
      if (!window.isSecureContext || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        message('Use um navegador compatível e acesse o portal por HTTPS para receber os avisos.'); return;
      }
      if (!config.publicKey) { message('A administração ainda está configurando as notificações.'); return; }
      enable.disabled = false;
      if (Notification.permission === 'granted' && store.get('-optin') === '1') await sync(false);
      else message('Ative os avisos para receber atualizações neste dispositivo.');
    } catch (error) { message(error.message); }
  })();
})();
