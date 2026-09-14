self.addEventListener('push', function(event) {
  const data = event.data ? event.data.json() : {};

  const title = data.title || 'Realiving Admin';
  const options = {
    body: data.body || 'You have a new notification',
    icon: data.icon,
    data: data.url || '/'
  };

  event.waitUntil(
    self.registration.showNotification(title, options)
  );
});

self.addEventListener('notificationclick', function(event) {
  event.notification.close();
  event.waitUntil(
    clients.openWindow(event.notification.data)
  );
});