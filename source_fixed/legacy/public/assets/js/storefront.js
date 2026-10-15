(() => {
  'use strict';
  const KEY = 'vapekong_store_cart_v1';
  const qs = (s, root = document) => root.querySelector(s);
  const qsa = (s, root = document) => Array.from(root.querySelectorAll(s));
  const money = n => 'Rp ' + Math.max(0, Number(n || 0)).toLocaleString('id-ID');
  const safe = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));

  const loadCart = () => {
    try {
      const data = JSON.parse(localStorage.getItem(KEY) || '[]');
      return Array.isArray(data) ? data : [];
    } catch (_) { return []; }
  };
  let cart = loadCart();
  const keyOf = i => `${Number(i.id||0)}:${Number(i.variant_id||0)}`;
  const saveCart = () => { localStorage.setItem(KEY, JSON.stringify(cart)); renderAll(); };
  const subtotal = () => cart.reduce((s, i) => s + Number(i.price || 0) * Number(i.qty || 0), 0);
  const itemCount = () => cart.reduce((s, i) => s + Number(i.qty || 0), 0);

  function toast(message, type = 'success') {
    const wrap = qs('#storeToastWrap');
    if (!wrap) return;
    const el = document.createElement('div');
    el.className = `store-toast ${type}`;
    el.innerHTML = `<i class="bi ${type === 'success' ? 'bi-check-circle-fill' : 'bi-info-circle-fill'}"></i><span>${safe(message)}</span>`;
    wrap.appendChild(el);
    requestAnimationFrame(() => el.classList.add('show'));
    setTimeout(() => { el.classList.remove('show'); setTimeout(() => el.remove(), 260); }, 2400);
  }

  function addProduct(product, options = {}) {
    const opts = {openDrawer: true, notify: true, ...options};
    if (!product || !product.id) return;
    const stock = Number(product.stock || 0);
    if (stock <= 0) return toast('Stok produk sedang habis.', 'info');
    const idx = cart.findIndex(i => keyOf(i) === keyOf(product));
    if (idx >= 0) {
      if (cart[idx].qty >= stock) return toast('Jumlah sudah mencapai stok tersedia.', 'info');
      cart[idx].qty += 1;
      cart[idx].price = Number(product.price || cart[idx].price || 0);
      cart[idx].name = product.name || cart[idx].name;
      cart[idx].image = product.image || cart[idx].image;
      cart[idx].stock = stock;
    } else {
      cart.push({...product, qty: 1, price: Number(product.price || 0), stock});
    }
    saveCart();
    if (opts.notify) toast(`${product.name} ditambahkan ke keranjang.`);
    if (opts.openDrawer) openCart();
    return true;
  }

  function changeQty(key, delta) {
    const idx = cart.findIndex(i => keyOf(i) === String(key));
    if (idx < 0) return;
    const next = cart[idx].qty + delta;
    if (next <= 0) cart.splice(idx, 1);
    else if (next <= Number(cart[idx].stock || 999999)) cart[idx].qty = next;
    else return toast('Jumlah melebihi stok tersedia.', 'info');
    saveCart();
  }
  function removeItem(key) { cart = cart.filter(i => keyOf(i) !== String(key)); saveCart(); }

  function cartItemTemplate(item, compact = false) {
    const fallback = item.fallback_image || '';
    const image = item.image ? `<img src="${safe(item.image)}" ${fallback ? `data-fallback="${safe(fallback)}" onerror="this.onerror=null;this.src=this.dataset.fallback;"` : ''} alt="${safe(item.name)}">` : (fallback ? `<img src="${safe(fallback)}" alt="${safe(item.name)}">` : `<span class="cart-placeholder"><i class="bi bi-box-seam"></i></span>`);
    return `<div class="${compact ? 'checkout-mini-item' : 'drawer-item'}" data-cart-key="${safe(keyOf(item))}">
      <div class="drawer-item-image">${image}</div>
      <div class="drawer-item-copy"><b>${safe(item.name)}</b><small>${safe(item.variant_name ? item.variant_name + ' • ' : '')}${safe(item.category || 'Produk')}</small><strong>${money(item.price)}</strong></div>
      ${compact ? `<span class="mini-qty">× ${Number(item.qty)}</span>` : `<div class="qty-control"><button data-qty="-1">−</button><span>${Number(item.qty)}</span><button data-qty="1">+</button><button class="remove-cart" data-remove aria-label="Hapus"><i class="bi bi-trash3"></i></button></div>`}
    </div>`;
  }

  function renderDrawer() {
    const box = qs('#cartDrawerItems');
    if (!box) return;
    box.innerHTML = cart.length ? cart.map(i => cartItemTemplate(i)).join('') : `<div class="drawer-empty"><span><i class="bi bi-bag"></i></span><h4>Keranjang masih kosong</h4><p>Tambahkan produk favoritmu dari katalog.</p></div>`;
  }
  function renderCartPage() {
    const box = qs('#cartPageItems');
    if (!box) return;
    if (!cart.length) {
      box.innerHTML = `<div class="cart-page-empty"><span><i class="bi bi-cart-x"></i></span><h3>Belum ada produk</h3><p>Keranjang Anda masih kosong.</p><a class="btn-primary-store" href="products.php">Mulai Belanja</a></div>`;
      return;
    }
    box.innerHTML = cart.map(item => `<div class="cart-page-item" data-cart-key="${safe(keyOf(item))}">
      <div class="cart-page-image">${item.image ? `<img src="${safe(item.image)}" ${item.fallback_image ? `data-fallback="${safe(item.fallback_image)}" onerror="this.onerror=null;this.src=this.dataset.fallback;"` : ''} alt="${safe(item.name)}">` : (item.fallback_image ? `<img src="${safe(item.fallback_image)}" alt="${safe(item.name)}">` : `<i class="bi bi-box-seam"></i>`)}</div>
      <div class="cart-page-copy"><span>${safe(item.category || 'Produk')}</span><h3>${safe(item.name)}</h3>${item.variant_name ? `<small>${safe(item.variant_name)}</small>` : ''}<b>${money(item.price)}</b><small>Stok tersedia: ${Number(item.stock || 0)}</small></div>
      <div class="cart-page-actions"><div class="qty-control big"><button data-qty="-1">−</button><span>${Number(item.qty)}</span><button data-qty="1">+</button></div><strong>${money(Number(item.price) * Number(item.qty))}</strong><button class="remove-page-item" data-remove><i class="bi bi-trash3"></i> Hapus</button></div>
    </div>`).join('');
  }
  function renderCheckout() {
    const box = qs('#checkoutItems');
    if (!box) return;
    box.innerHTML = cart.length ? cart.map(i => cartItemTemplate(i, true)).join('') : `<div class="checkout-empty"><i class="bi bi-bag-x"></i><span>Keranjang kosong</span></div>`;
    const grand = qs('#checkoutGrandTotal');
    if (grand) { const liveShipping = Number(document.body.dataset.checkoutShipping || 0); grand.textContent = money(subtotal() + liveShipping); }
  }
  function renderAll() {
    qsa('[data-cart-count]').forEach(el => el.textContent = itemCount());
    qsa('[data-cart-subtotal]').forEach(el => el.textContent = money(subtotal()));
    renderDrawer(); renderCartPage(); renderCheckout(); updatePreviewTotal();
  }

  function updatePreviewTotal() {
    const checked = qs('input[name="preview_courier"]:checked');
    const shipping = cart.length && checked ? Number(checked.value || 0) : 0;
    qsa('[data-shipping-preview]').forEach(el => el.textContent = money(shipping));
    qsa('[data-cart-total]').forEach(el => el.textContent = money(subtotal() + shipping));
  }

  function openCart() { qs('#cartDrawer')?.classList.add('open'); qs('#cartBackdrop')?.classList.add('show'); document.body.classList.add('no-scroll'); }
  function closeCart() { qs('#cartDrawer')?.classList.remove('open'); qs('#cartBackdrop')?.classList.remove('show'); document.body.classList.remove('no-scroll'); }

  document.addEventListener('click', e => {
    const buyNow = e.target.closest('[data-checkout-url][data-product]');
    if (buyNow && !buyNow.disabled && buyNow.id === 'productBuyNow') { try { const ok=addProduct(JSON.parse(buyNow.dataset.product||'{}'),{openDrawer:false,notify:false}); if(ok) window.location.href=buyNow.dataset.checkoutUrl; } catch (_) {} return; }
    const add = e.target.closest('.add-cart-btn');
    if (add && !add.disabled) { try { addProduct(JSON.parse(add.dataset.product || '{}')); } catch (_) {} return; }
    const qty = e.target.closest('[data-qty]');
    if (qty) { const row = qty.closest('[data-cart-key]'); if (row) changeQty(row.dataset.cartKey, Number(qty.dataset.qty)); return; }
    const remove = e.target.closest('[data-remove]');
    if (remove) { const row = remove.closest('[data-cart-key]'); if (row) removeItem(row.dataset.cartKey); return; }
    if (e.target.closest('.cart-open-btn')) { openCart(); return; }
    if (e.target.closest('#cartClose') || e.target.id === 'cartBackdrop') { closeCart(); return; }
    const wish = e.target.closest('.wish-btn');
    if (wish) {
      e.preventDefault();
      const productId = Number(wish.dataset.productId || 0);
      if (!productId) return;
      if (document.body.dataset.marketplaceAuth !== '1') {
        toast('Silakan masuk untuk menyimpan wishlist.', 'info');
        setTimeout(() => { window.location.href = document.body.dataset.loginUrl || 'masuk'; }, 550);
        return;
      }
      if (wish.dataset.loading === '1') return;
      wish.dataset.loading = '1'; wish.classList.add('loading');
      fetch(document.body.dataset.wishlistUrl, {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type':'application/json','Accept':'application/json'},
        body: JSON.stringify({_csrf_token: document.body.dataset.csrf || '', product_id: productId})
      }).then(r => r.json().then(j => ({ok:r.ok,j}))).then(({ok,j}) => {
        if (!ok || !j.success) throw new Error(j.message || 'Wishlist gagal diperbarui.');
        wish.classList.toggle('active', !!j.active);
        const icon = wish.querySelector('i'); if (icon) icon.className = j.active ? 'bi bi-heart-fill' : 'bi bi-heart';
        toast(j.message || (j.active ? 'Ditambahkan ke wishlist.' : 'Dihapus dari wishlist.'), 'info');
        if (!j.active && window.location.pathname.includes('wishlist')) wish.closest('.product-card')?.remove();
      }).catch(err => toast(err.message || 'Wishlist gagal diperbarui.', 'info')).finally(() => { delete wish.dataset.loading; wish.classList.remove('loading'); });
      return;
    }
    const pay = e.target.closest('.payment-pills button');
    if (pay) { qsa('.payment-pills button').forEach(b => b.classList.remove('active')); pay.classList.add('active'); }
    const checkoutPay = e.target.closest('.checkout-payment');
    if (checkoutPay) { qsa('.checkout-payment').forEach(b => b.classList.remove('active')); checkoutPay.classList.add('active'); }
    const checkoutCourier = e.target.closest('.checkout-courier');
    if (checkoutCourier) { qsa('.checkout-courier').forEach(b => b.classList.remove('active')); checkoutCourier.classList.add('active'); }
  });

  qsa('input[name="preview_courier"]').forEach(r => r.addEventListener('change', updatePreviewTotal));
  const menuToggle = qs('#storeMenuToggle');
  const storeNav = qs('#storeNav');
  const menuBackdrop = qs('#mobileNavBackdrop');
  const closeMobileMenu = () => {
    storeNav?.classList.remove('open');
    menuBackdrop?.classList.remove('show');
    menuToggle?.setAttribute('aria-expanded', 'false');
  };
  menuToggle?.addEventListener('click', () => {
    const open = !storeNav?.classList.contains('open');
    storeNav?.classList.toggle('open', open);
    menuBackdrop?.classList.toggle('show', open);
    menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  menuBackdrop?.addEventListener('click', closeMobileMenu);
  qsa('#storeNav a').forEach(link => link.addEventListener('click', closeMobileMenu));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMobileMenu(); });
  window.addEventListener('resize', () => { if (window.innerWidth > 940) closeMobileMenu(); }, {passive:true});

  const demoSubmit = qs('#checkoutDemoSubmit');
  if (demoSubmit) demoSubmit.addEventListener('click', () => {
    if (!cart.length) return toast('Keranjang masih kosong.', 'info');
    toast('Tampilan checkout siap. Aktivasi API order & pengiriman dilakukan pada tahap integrasi berikutnya.', 'info');
  });

  const observer = 'IntersectionObserver' in window ? new IntersectionObserver(entries => entries.forEach(entry => { if (entry.isIntersecting) { entry.target.classList.add('in-view'); observer.unobserve(entry.target); } }), {threshold: .08}) : null;
  qsa('.reveal').forEach(el => observer ? observer.observe(el) : el.classList.add('in-view'));

  window.addEventListener('scroll', () => qs('#storeHeader')?.classList.toggle('scrolled', window.scrollY > 12), {passive: true});
  window.VapekongStoreCart = { getItems: () => cart.map(i => ({...i})), subtotal, money, add: (product, options={}) => addProduct(product, options), clear: () => { cart = []; saveCart(); } };
  window.addEventListener('load', () => setTimeout(() => qs('#storePageLoader')?.classList.add('hide'), 180));
  renderAll();
})();
