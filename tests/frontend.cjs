const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const { MessageChannel } = require('node:worker_threads');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../assets/portal.js'), 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
const scope = 'https://portal.example.test/';
const key = Buffer.alloc(65, 2).toString('base64url');
function environment(options = {}) {
  const handlers = {}, messages = [], registrations = [], requests = [];
  const status = { textContent: '' };
  const enable = { disabled: true, addEventListener: (type, handler) => { handlers.enable = handler; } };
  const dismiss = { addEventListener: (type, handler) => { handlers.dismiss = handler; } };
  const root = {
    hidden: true, hasAttribute: () => true,
    querySelector: name => ({ '.ppush-status': status, '[data-ppush-enable]': enable, '[data-ppush-dismiss]': dismiss }[name] || null)
  };
  const storage = new Map(Object.entries(options.storage || {}));
  let permissionRequests = 0, unsubscribed = 0, subscribed = 0, activations = 0;
  const registration = {
    scope,
    active: {
      scriptURL: options.own ? scope + '?portal_push_worker=1' : scope + 'pwa-sw.js',
      postMessage: (message, ports) => {
        messages.push(message);
        if (options.replaceDuringProbe) setTimeout(() => { registration.active = integratedWorker(); }, 100);
        if (options.compatible !== false) ports[0].postMessage({ type: 'PORTAL_PUSH_READY', version: '0.3.0' });
      }
    },
    update: async () => {
      if (options.delayedInstall || options.waiting) {
        const candidate = integratedWorker();
        candidate.state = 'installing';
        registration.installing = candidate;
        setTimeout(() => { registration.installing = null; registration.waiting = candidate; candidate.state = 'installed'; }, options.delayedInstall ? 250 : 0);
      }
    },
    pushManager: {
      getSubscription: async () => options.subscription || null,
      subscribe: async input => {
        subscribed++; assert.equal(input.userVisibleOnly, true);
        return { toJSON: () => ({ endpoint: 'https://fcm.googleapis.com/test', keys: {} }) };
      }
    }
  };
  function integratedWorker() {
    const candidate = {
      scriptURL: scope + 'superpwa-sw.js', state: 'activated',
      postMessage: (message, ports) => {
        messages.push(message);
        if (message.type === 'PORTAL_PUSH_PING' && options.waitingCompatible !== false) ports[0].postMessage({ type: 'PORTAL_PUSH_READY', version: '0.3.1' });
        if (message.type === 'PORTAL_PUSH_ACTIVATE') {
          activations++;
          setTimeout(() => { registration.waiting = null; registration.active = candidate; candidate.state = 'activated'; }, 150);
        }
      }
    };
    return candidate;
  }
  if (options.subscription) options.subscription.unsubscribe = async () => { unsubscribed++; return true; };
  const notification = { permission: options.permission || 'default', requestPermission: async () => { permissionRequests++; return options.grant || 'granted'; } };
  const context = {
    URL, Uint8Array, Promise, setTimeout, clearTimeout, MessageChannel,
    atob: value => Buffer.from(value, 'base64').toString('binary'),
    btoa: value => Buffer.from(value, 'binary').toString('base64'),
    crypto: require('node:crypto').webcrypto,
    location: { href: scope + 'pagina/' },
    matchMedia: () => ({ matches: !!options.standalone }),
    localStorage: { getItem: key => storage.get(key) || null, setItem: (key, value) => storage.set(key, value) },
    document: { querySelector: () => root },
    window: { isSecureContext: true, PushManager: function () {}, Notification: notification },
    Notification: notification,
    navigator: {
      userAgent: options.ios ? 'iPhone' : 'Chrome/120', platform: options.ios ? 'iPhone' : 'Linux',
      serviceWorker: {
        getRegistration: async () => options.none ? undefined : registration,
        register: async (url, input) => { registrations.push({ url, input }); return registration; }
      }
    },
    PortalPush: { scope, worker: scope + '?portal_push_worker=1', publicKey: key, storage: 'test', api: scope + 'api/', nonce: 'nonce', pwa: options.pwa || '' },
    fetch: async (url, input) => {
      requests.push({ url, input });
      return { ok: true, status: 200, json: async () => ({ id: 41, devices: [] }) };
    }
  };
  vm.runInNewContext(source, context);
  return { handlers, root, status, enable, registrations, requests, messages, storage,
    counts: () => ({ permissionRequests, unsubscribed, subscribed, activations }) };
}
(async () => {
  const checks = [];
  const pwa = environment({ pwa: 'PWA for WP' }); await flush();
  assert.equal(pwa.counts().permissionRequests, 0);
  assert.equal(pwa.enable.disabled, false);
  checks.push('Abrir o portal não solicita permissão sem um clique');
  await pwa.handlers.enable();
  assert.equal(pwa.counts().permissionRequests, 1); assert.equal(pwa.counts().subscribed, 1);
  assert.equal(pwa.registrations.length, 0); assert.equal(pwa.messages[0].type, 'PORTAL_PUSH_PING');
  assert.equal(pwa.requests.length, 1); assert.equal(pwa.requests[0].input.headers['X-WP-Nonce'], 'nonce');
  assert.equal(JSON.parse(pwa.requests[0].input.body).reactivate, true); assert.equal(pwa.root.hidden, true);
  checks.push('Clique reutiliza o registro compatível do PWA e associa a inscrição à conta autenticada');
  const unknown = environment({ compatible: false }); await flush(); await unknown.handlers.enable();
  assert.equal(unknown.registrations.length, 0); assert.equal(unknown.counts().subscribed, 0);
  assert.match(unknown.status.textContent, /Reparar integração com o PWA/);
  checks.push('Worker sem integração não é substituído e não recebe inscrição inválida');
  const own = environment({ none: true, own: true }); await flush(); await own.handlers.enable();
  assert.equal(own.registrations.length, 1); assert.equal(own.registrations[0].input.scope, scope);
  checks.push('Portal sem outro worker registra o worker próprio para push');
  const pendingPwa = environment({ own: true, pwa: 'SuperPWA' }); await flush(); await pendingPwa.handlers.enable();
  assert.equal(pendingPwa.registrations.length, 0);
  checks.push('PWA pode substituir o worker anterior sem nova inscrição de um worker concorrente');
  const denied = environment({ grant: 'denied' }); await flush(); await denied.handlers.enable();
  assert.equal(denied.requests.length, 0); assert.equal(denied.counts().subscribed, 0);
  checks.push('Permissão negada não cadastra dispositivo');
  const ios = environment({ ios: true }); await flush();
  assert.match(ios.status.textContent, /Tela de Início/); assert.equal(ios.enable.disabled, true);
  checks.push('iPhone fora do aplicativo orienta a instalação na Tela de Início');
  const oldSubscription = { options: { applicationServerKey: new Uint8Array([9]).buffer }, toJSON: () => ({}) };
  const migrate = environment({ subscription: oldSubscription, pwa: 'SuperPWA' }); await flush(); await migrate.handlers.enable();
  assert.equal(migrate.counts().unsubscribed, 1); assert.equal(migrate.counts().subscribed, 1);
  checks.push('Ativação explícita troca a inscrição de outro emissor para a chave VAPID local');
  const quiet = environment({ permission: 'granted', storage: { 'test-optin': '0' } }); await flush();
  assert.equal(quiet.requests.length, 0); assert.equal(quiet.counts().subscribed, 0);
  checks.push('Dispositivo pausado permanece pausado ao visitar outras páginas');
  quiet.handlers.dismiss(); assert.equal(quiet.root.hidden, true); assert.ok(Number(quiet.storage.get('test-dismiss')) > Date.now());
  checks.push('Pessoa pode dispensar o convite por sete dias sem conceder permissão');
  const delayed = environment({ compatible: false, pwa: 'SuperPWA', delayedInstall: true }); await flush(); await delayed.handlers.enable();
  assert.equal(delayed.counts().activations, 1); assert.equal(delayed.counts().subscribed, 1); assert.equal(delayed.registrations.length, 0);
  checks.push('Atualização que termina depois de update() é aguardada e ativada no mesmo PWA após o clique');
  const waiting = environment({ compatible: false, pwa: 'SuperPWA', waiting: true }); await flush(); await waiting.handlers.enable();
  assert.equal(waiting.counts().activations, 1); assert.equal(waiting.requests.length, 1);
  checks.push('Worker integrado aguardando ativação substitui a versão antiga sem reinstalar o PWA');
  const foreignWaiting = environment({ compatible: false, waiting: true, waitingCompatible: false }); await flush(); await foreignWaiting.handlers.enable();
  assert.equal(foreignWaiting.counts().activations, 0); assert.equal(foreignWaiting.requests.length, 0);
  checks.push('Worker aguardando sem integração não recebe pedido de ativação nem inscrições');
  const raced = environment({ compatible: false, replaceDuringProbe: true, pwa: 'SuperPWA' }); await flush(); await raced.handlers.enable();
  assert.equal(raced.counts().subscribed, 1); assert.equal(raced.counts().activations, 0);
  checks.push('Troca do worker durante a confirmação inicial é reconhecida sem erro de integração');
  const silent = environment({ compatible: false, waiting: true, pwa: 'SuperPWA', permission: 'granted', storage: { 'test-optin': '1' } });
  const until = Date.now() + 7000;
  while (!silent.status.textContent.includes('Reparar integração') && Date.now() < until) await new Promise(resolve => setTimeout(resolve, 20));
  assert.equal(silent.counts().activations, 0); assert.equal(silent.requests.length, 0);
  checks.push('Sincronização em segundo plano não força a ativação de uma atualização do PWA');
  console.log(JSON.stringify({ passed: checks.length, checks }));
})().catch(error => { console.error(error); process.exitCode = 1; });
