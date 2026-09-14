(function () {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
    console.log('Push notifications not supported in this browser.');
    return;
  }

  if (!window.PUSH_CONFIG || !window.PUSH_CONFIG.vapidPublicKey) {
    console.log('Push config missing — skipping subscription.');
    return;
  }

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
  }

  navigator.serviceWorker.register(window.PUSH_CONFIG.swPath)
    .then(function (registration) {
      return registration.pushManager.getSubscription().then(function (existingSub) {
        if (existingSub) {
          console.log('Already subscribed to push.');
          return;
        }

        return registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlBase64ToUint8Array(window.PUSH_CONFIG.vapidPublicKey)
        }).then(function (newSub) {
          return fetch(window.PUSH_CONFIG.saveSubscriptionUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(newSub)
          });
        });
      });
    })
    .catch(function (err) {
      console.log('Push subscription failed:', err);
    });
})();