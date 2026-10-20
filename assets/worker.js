'use strict';
self.PortalPushStandalone = true;
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));
// O endpoint PHP acrescenta push-events.js. O mesmo módulo é incorporado aos PWAs compatíveis.
