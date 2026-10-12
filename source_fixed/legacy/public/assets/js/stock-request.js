(() => {
  const list = document.getElementById('requestProductList');
  const cartEl = document.getElementById('requestCartItems');
  const countEl = document.getElementById('requestCartCount');
  const totalEl = document.getElementById('requestCartTotal');
  const jsonEl = document.getElementById('requestItemsJson');
  const submit = document.getElementById('requestSubmit');
  const search = document.getElementById('requestProductSearch');
  if (!list || !cartEl || !jsonEl) return;

  const cart = new Map();
  const money = (value) => 'Rp ' + Number(value || 0).toLocaleString('id-ID');
  const esc = (s) => String(s ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));

  function render() {
    const items = [...cart.values()];
    jsonEl.value = JSON.stringify(items.map(i => ({product_id:i.id, variant_id:i.variantId||0, qty:i.qty})));
    countEl.textContent = items.reduce((n, i) => n + i.qty, 0) + ' item';
    totalEl.textContent = money(items.reduce((n, i) => n + i.qty * i.cost, 0));
    submit.disabled = items.length === 0;
    if (!items.length) {
      cartEl.innerHTML = '<div class="request-empty"><i class="bi bi-cart-plus"></i><b>Belum ada barang</b><span>Pilih produk dari daftar gudang.</span></div>';
      return;
    }
    cartEl.innerHTML = items.map(i => `
      <div class="request-cart-item" data-id="${i.key}">
        <div><small>${esc(i.code)}</small><b>${esc(i.name)}</b><span>${money(i.cost)} / ${esc(i.unit)} • stok gudang ${i.stock}</span></div>
        <div class="request-qty"><button type="button" data-act="minus"><i class="bi bi-dash"></i></button><input type="number" min="1" max="${i.stock}" value="${i.qty}" data-act="qty"><button type="button" data-act="plus"><i class="bi bi-plus"></i></button></div>
        <strong>${money(i.qty * i.cost)}</strong>
        <button type="button" class="request-remove" data-act="remove"><i class="bi bi-trash"></i></button>
      </div>`).join('');
  }

  list.addEventListener('click', (e) => {
    const btn = e.target.closest('.request-add-btn');
    if (!btn || btn.disabled) return;
    const row = btn.closest('.request-product-row');
    const id=Number(row.dataset.productId);
    const variantId=Number(row.dataset.variantId||0);
    const key=id+':'+variantId;
    const stock = Number(row.dataset.stock || 0);
    const existing = cart.get(key);
    if (existing) {
      if (existing.qty >= stock) return;
      existing.qty++;
    } else {
      cart.set(key,{key,id,variantId,name:row.dataset.name,code:row.dataset.code,unit:row.dataset.unit,stock,cost:Number(row.dataset.cost||0),qty:1});
    }
    render();
  });

  cartEl.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;
    const wrap = btn.closest('.request-cart-item');
    const id=String(wrap.dataset.id||'');
    const item=cart.get(id);
    if (!item) return;
    const act = btn.dataset.act;
    if (act === 'plus' && item.qty < item.stock) item.qty++;
    if (act === 'minus') item.qty = Math.max(1, item.qty - 1);
    if(act==='remove')cart.delete(id);
    render();
  });

  cartEl.addEventListener('change', (e) => {
    if (e.target.dataset.act !== 'qty') return;
    const wrap = e.target.closest('.request-cart-item');
    const id=String(wrap.dataset.id||'');
    const item=cart.get(id);
    if (!item) return;
    item.qty = Math.max(1, Math.min(item.stock, Number(e.target.value || 1)));
    render();
  });

  search?.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    list.querySelectorAll('[data-product-group]').forEach(group => {
      let visible = 0;
      group.querySelectorAll('.request-product-row').forEach(row => {
        const matched = q === '' || String(row.dataset.search || '').includes(q);
        row.hidden = !matched;
        if (matched) visible++;
      });
      group.hidden = visible === 0;
    });
  });

  document.getElementById('stockRequestForm')?.addEventListener('submit', (e) => {
    if (!cart.size) e.preventDefault();
    submit.disabled = true;
    submit.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Mengirim Request...';
  });

  render();
})();
