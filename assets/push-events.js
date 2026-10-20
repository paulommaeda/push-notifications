'use strict';
(() => {
  if (self.PortalPushWorker) return;
  self.PortalPushWorker = '__PPUSH_VERSION__';
  self.addEventListener('message', event => {
    if (event.data && event.data.type === 'PORTAL_PUSH_PING' && event.ports && event.ports[0]) {
      event.ports[0].postMessage({ type: 'PORTAL_PUSH_READY', version: self.PortalPushWorker });
    }
  });

self.addEventListener('push', event => {
  let data;
  try { data = event.data ? event.data.json() : {}; } catch { data = {}; }
  if (!data || typeof data !== 'object') data = {};
  const ours = data.portalPush === 1 || (typeof data.tag === 'string' && /^portal-push-\d+$/.test(data.tag));
  if (!ours && !self.PortalPushStandalone) return;
  const safeURL = value => {
    try {
      const url = new URL(value || '', self.location.origin);
      return url.origin === self.location.origin ? url.href : self.registration.scope;
    } catch { return self.registration.scope; }
  };
  const options = {
    body: typeof data.body === 'string' ? data.body : 'Há uma nova atualização no portal.',
    tag: typeof data.tag === 'string' ? data.tag : 'portal-push',
    renotify: false,
    data: { portalPush: true, url: safeURL(data.url), clickUrl: data.clickUrl ? safeURL(data.clickUrl) : '', clickToken: data.clickToken || '' }
  };
  if (typeof data.icon === 'string' && /^https?:\/\//.test(data.icon)) options.icon = data.icon;
  if (typeof data.image === 'string' && /^https:\/\//.test(data.image)) options.image = data.image;
  event.waitUntil(self.registration.showNotification(typeof data.title === 'string' ? data.title : 'Portal', options));
});

self.addEventListener('notificationclick', event => {
  const data = event.notification.data || {};
  if (!data.portalPush && !self.PortalPushStandalone) return;
  event.notification.close();
  let target;
  try { target = new URL(data.url || self.registration.scope); } catch { target = new URL(self.registration.scope); }
  if (target.origin !== self.location.origin) target = new URL(self.registration.scope);
  const open = self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async windows => {
    for (const client of windows) {
      if (client.url === target.href && 'focus' in client) return client.focus();
    }
    return self.clients.openWindow(target.href);
  });
  let track = Promise.resolve();
  if (data.clickUrl && data.clickToken) {
    try {
      const url = new URL(data.clickUrl);
      if (url.origin === self.location.origin && /^[a-f0-9]{64}$/.test(data.clickToken)) {
        track = fetch(url.href, {
          method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ token: data.clickToken })
        }).catch(() => undefined);
      }
    } catch { /* O link do portal continua disponível quando o rastreamento falha. */ }
  }
  event.waitUntil(Promise.allSettled([open, track]));
});

})();
