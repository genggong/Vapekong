(() => {
  'use strict';
  const config = document.getElementById('orderLiveConfig');
  if (!config) return;
  const qr = document.getElementById('marketplaceQr');
  if (qr && qr.dataset.qr && window.QRCode) {
    qr.innerHTML = '';
    new QRCode(qr, {text: qr.dataset.qr, width: 230, height: 230, correctLevel: QRCode.CorrectLevel.M});
  }
  document.addEventListener('click', async e => {
    const copy = e.target.closest('[data-copy]');
    if (copy) {
      const el = document.querySelector(copy.dataset.copy || '');
      const text = el?.textContent?.trim() || '';
      if (!text) return;
      try { await navigator.clipboard.writeText(text); copy.innerHTML = '<i class="bi bi-check2"></i> Tersalin'; setTimeout(() => copy.innerHTML = '<i class="bi bi-copy"></i> Salin', 1600); } catch (_) {}
    }
  });
  const retry = document.getElementById('retryPaymentBtn');
  retry?.addEventListener('click', async () => {
    retry.disabled = true;
    retry.innerHTML = '<i class="bi bi-arrow-repeat"></i> Memproses...';
    try {
      const res = await fetch(config.dataset.retryUrl, {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({_csrf_token:config.dataset.csrf,order:config.dataset.order,token:config.dataset.token})});
      const json = await res.json().catch(() => ({}));
      if (!res.ok || !json.success) throw new Error(json.message || 'Gagal membuat pembayaran.');
      location.reload();
    } catch (err) {
      alert(err.message || 'Gagal membuat pembayaran.');
      retry.disabled = false;
      retry.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Coba Pembayaran Lagi';
    }
  });
  let last = null;
  async function poll() {
    try {
      const url = `${config.dataset.statusUrl}?order=${encodeURIComponent(config.dataset.order)}&token=${encodeURIComponent(config.dataset.token)}`;
      const res = await fetch(url, {credentials:'same-origin',headers:{'Accept':'application/json'}});
      const json = await res.json().catch(() => ({}));
      if (res.ok && json.success) {
        if (last === null) last = json.payment_status;
        else if (json.payment_status !== last || json.reload) location.reload();
      }
    } catch (_) {}
  }
  setInterval(poll, 9000);
})();
