/* Service worker do painel Sentinela — instância NOVA (nova.monitoramento.top).
   Recebe Web Push e abre o painel no clique.
   Escopo: /monitoramentotop/nova/ (o arquivo mora na própria pasta). */

self.addEventListener('install', (e) => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
  let d = {};
  try { d = event.data ? event.data.json() : {}; } catch (e) { d = {}; }

  const titulo = d.titulo || 'Sentinela · nova.monitoramento.top';
  const opcoes = {
    body: d.corpo || '',
    icon: d.icone || 'https://nova.monitoramento.top/favicon.ico',
    badge: d.icone || 'https://nova.monitoramento.top/favicon.ico',
    // tag por tipo: um alerta novo substitui o anterior do mesmo tipo,
    // em vez de empilhar notificação repetida na bandeja.
    tag: d.tag || 'sentinela-nova',
    renotify: true,
    requireInteraction: d.critico === true,   // queda fica na tela até você ver
    timestamp: Date.now(),
    data: { url: d.url || '/monitoramentotop/nova/' }
  };
  event.waitUntil(self.registration.showNotification(titulo, opcoes));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/monitoramentotop/nova/';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((lista) => {
      for (const c of lista) {
        if (c.url.indexOf('/monitoramentotop/nova/') !== -1 && 'focus' in c) { return c.focus(); }
      }
      return self.clients.openWindow(url);
    })
  );
});
