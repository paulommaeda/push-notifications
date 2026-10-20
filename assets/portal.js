(() => {
  'use strict';
  const root = document.querySelector('[data-ppush]');
  if (!root || typeof PortalPush === 'undefined') return;
  const config = PortalPush;
  const status = root.querySelector('.ppush-status');
  const enable = root.querySelector('[data-ppush-enable]');
  const test = root.querySelector('[data-ppush-test]');
  const devices = root.querySelector('[data-ppush-devices]');
  const store = {
    get(key) { try { return localStorage.getItem(config.storage + key); } catch { return null; } },
    set(key, value) { try { localStorage.setItem(config.storage + key, value); } catch { throw new Error('Permita o armazenamento local para ativar este dispositivo.'); } }
  };
  const message = text => { status.textContent = text; };
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
  const worker = async () => {
    if (registration) return registration;
    const existing = await navigator.serviceWorker.getRegistration(config.scope);
    const script = existing && (existing.active || existing.waiting || existing.installing);
    if (script) {
      const existingURL = new URL(script.scriptURL);
      const expected = new URL(config.worker);
      if (existingURL.origin !== expected.origin || existingURL.pathname !== expected.pathname || existingURL.searchParams.get('portal_push_worker') !== '1') {
        throw new Error('Este portal já possui um aplicativo instalado. A administração precisa integrar o recebimento de notificações a ele.');
      }
    }
    registration = await navigator.serviceWorker.register(config.worker, { scope: config.scope, updateViaCache: 'none' });
    if (!registration.active) {
      await new Promise((resolve, reject) => {
        const candidate = registration.installing || registration.waiting;
        if (!candidate) { reject(new Error('Não foi possível ativar o recebimento. Atualize a página.')); return; }
        const timer = setTimeout(() => reject(new Error('A ativação demorou mais que o esperado. Atualize a página.')), 15000);
        const check = () => {
          if (candidate.state === 'activated') { clearTimeout(timer); resolve(); }
          if (candidate.state === 'redundant') { clearTimeout(timer); reject(new Error('Não foi possível ativar o recebimento.')); }
        };
        candidate.addEventListener('statechange', check);
        check();
      });
    }
    return registration;
  };
  const refresh = async () => {
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
              test.disabled = true; message('Notificações desativadas neste dispositivo.'); enable.textContent = 'Ativar neste dispositivo';
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
    if (!sub && !reactivate) { message('Ative os avisos para receber atualizações neste dispositivo.'); return; }
    if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: fromBase64(config.publicKey) });
    const data = await api('subscriptions', 'POST', { subscription: sub.toJSON(), device_secret: secret(), label: label(), reactivate });
    store.set('-id', String(data.id)); store.set('-optin', '1');
    message('Notificações ativadas neste dispositivo.'); enable.textContent = 'Atualizar este dispositivo'; test.disabled = false;
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
  test.addEventListener('click', async () => {
    test.disabled = true;
    try { await api('test', 'POST', {}); message('Teste colocado na fila. Ele chegará quando o portal processar o envio.'); }
    catch (error) { message(error.message); }
    finally { test.disabled = false; }
  });
  (async () => {
    try {
      await refresh();
      if (!window.isSecureContext || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        message('Use um navegador compatível e acesse o portal por HTTPS para receber os avisos.'); return;
      }
      const ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
      if (ios && !navigator.standalone && !matchMedia('(display-mode: standalone)').matches) {
        message('No iPhone ou iPad, adicione este portal à Tela de Início, abra pelo ícone e ative os avisos.'); return;
      }
      if (!config.publicKey) { message('A administração ainda está configurando as notificações.'); return; }
      enable.disabled = false;
      if (Notification.permission === 'granted' && store.get('-optin') === '1') await sync(false);
      else message('Ative os avisos para receber atualizações neste dispositivo.');
    } catch (error) { message(error.message); }
  })();
})();
