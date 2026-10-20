const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const events = fs.readFileSync(path.join(__dirname, '../assets/push-events.js'), 'utf8');
const source = fs.readFileSync(path.join(__dirname, '../assets/worker.js'), 'utf8') + events;
const listeners = {}, notifications = [], opened = [], tracked = [];
let skipped = false, claimed = false;
const scope = 'https://portal.example.test/';
const context = {
  URL, Promise, console,
  fetch: async (url, options) => { tracked.push({ url, options }); return { status: 204 }; },
  self: {
    location: { origin: 'https://portal.example.test' },
    addEventListener: (name, callback) => { listeners[name] = callback; },
    skipWaiting: () => { skipped = true; },
    registration: { scope, showNotification: async (title, options) => { notifications.push({ title, options }); } },
    clients: { claim: async () => { claimed = true; }, matchAll: async () => [], openWindow: async url => { opened.push(url); } }
  }
};
vm.runInNewContext(source, context);
const checks = [];
async function dispatch(name, data = {}) {
  let pending;
  listeners[name]({ ...data, waitUntil: promise => { pending = promise; } });
  if (pending) await pending;
}
(async () => {
  await dispatch('install'); assert.equal(skipped, true); checks.push('Service worker instala a atualização');
  await dispatch('activate'); assert.equal(claimed, true); checks.push('Service worker assume as páginas do portal');
  await dispatch('push', { data: { json: () => ({ title: 'Olá', body: 'Mensagem', url: scope + 'aviso', tag: 'portal-push-12' }) } });
  assert.equal(notifications[0].options.body, 'Mensagem'); assert.equal(notifications[0].options.tag, 'portal-push-12');
  checks.push('Mensagem recebida mantém texto e identificação estável da campanha');
  await dispatch('push', { data: { json: () => ({ title: 'Aviso', url: 'https://attacker.test/' }) } });
  assert.equal(notifications[1].options.data.url, scope); checks.push('Link externo no payload é substituído pelo portal');
  await dispatch('push', { data: { json: () => { throw new Error('Invalid JSON'); } } });
  assert.equal(notifications[2].title, 'Portal'); checks.push('Payload inválido ainda produz uma notificação visível');
  let closed = false;
  await dispatch('notificationclick', { notification: { close: () => { closed = true; }, data: { url: scope + 'aviso', clickUrl: scope + 'click/1', clickToken: 'f'.repeat(64) } } });
  assert.equal(closed, true); assert.equal(opened[0], scope + 'aviso'); checks.push('Clique abre a página correta e fecha a notificação');
  assert.equal(tracked[0].options.credentials, 'omit'); assert.equal(JSON.parse(tracked[0].options.body).token, 'f'.repeat(64));
  checks.push('Rastreamento usa token assinado sem depender da sessão ou de nonce');
  await dispatch('notificationclick', { notification: { close: () => {}, data: { url: 'javascript:alert(1)', clickUrl: 'https://attacker.test/', clickToken: 'f'.repeat(64) } } });
  assert.equal(opened[1], scope); assert.equal(tracked.length, 1); checks.push('Clique não abre endereço inseguro nem envia o token a terceiros');
  const pwaListeners = {}, pwaNotifications = [];
  const pwaSelf = {
    ...context.self,
    PortalPushWorker: undefined,
    PortalPushStandalone: undefined,
    addEventListener: (name, callback) => { assert.equal(pwaListeners[name], undefined, 'Sem listener duplicado'); pwaListeners[name] = callback; },
    registration: { scope, showNotification: async (title, options) => pwaNotifications.push({ title, options }) }
  };
  const pwaContext = vm.createContext({ ...context, self: pwaSelf });
  vm.runInContext(events, pwaContext); vm.runInContext(events, pwaContext);
  assert.equal(pwaListeners.install, undefined); assert.equal(pwaListeners.activate, undefined);
  checks.push('Integração no PWA preserva instalação e ativação e evita ouvintes duplicados');
  let reply;
  pwaListeners.message({ data: { type: 'PORTAL_PUSH_PING' }, ports: [{ postMessage: value => { reply = value; } }] });
  assert.equal(reply.type, 'PORTAL_PUSH_READY'); checks.push('PWA confirma suporte ao push antes da inscrição');
  let pending;
  pwaListeners.push({ data: { json: () => ({ portalPush: 1, title: 'PWA', body: 'No celular' }) }, waitUntil: value => { pending = value; } });
  await pending; assert.equal(pwaNotifications[0].title, 'PWA');
  checks.push('Push integrado chama showNotification do registro existente do PWA');
  pwaListeners.push({ data: { json: () => ({ title: 'Outro serviço' }) }, waitUntil: () => { throw new Error('Evento de outro serviço'); } });
  let otherClosed = false;
  pwaListeners.notificationclick({ notification: { data: { url: scope }, close: () => { otherClosed = true; } } });
  assert.equal(pwaNotifications.length, 1); assert.equal(otherClosed, false);
  checks.push('Integração ignora mensagens e cliques de outros serviços');
  const report = { passed: checks.length, checks };

  console.log(JSON.stringify(report));
})().catch(error => { console.error(error); process.exitCode = 1; });
