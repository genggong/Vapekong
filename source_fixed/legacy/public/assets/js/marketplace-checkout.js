(() => {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const money = n => 'Rp ' + Math.max(0, Number(n || 0)).toLocaleString('id-ID');
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

  function init() {
    const form = $('#marketplaceCheckoutForm');
    if (!form) return;

    const state = {
      shipping: 0,
      subtotal: 0,
      discount: 0,
      pointsDiscount: 0,
      weight: 0,
      shippingLoaded: false,
      destination: null,
      requestSeq: 0,
      fulfillment: 'delivery',
      pickupBranch: null,
    };

    const cartApi = window.VapekongStoreCart;
    if (!cartApi?.getItems) {
      window.setTimeout(init, 50);
      return;
    }
    const cartItems = () => cartApi.getItems().map(i => ({id: Number(i.id), variant_id: Number(i.variant_id || 0), qty: Number(i.qty)})).filter(i => i.id > 0 && i.qty > 0);
    const csrf = $('input[name="_csrf_token"]', form)?.value || '';
    const selectedBox = $('#destinationSelected');
    const provinceSelect = $('#destinationProvinceSelect');
    const citySelect = $('#destinationCitySelect');
    const districtSelect = $('#destinationDistrictSelect');
    const subdistrictSelect = $('#destinationSubdistrictSelect');
    const regionLoading = $('#regionLoading');
    const regionStatus = $('#regionStatus');
    const shippingBox = $('#shippingOptions');
    const shippingStatus = $('#shippingStatusBox');
    const shippingSection = $('#shippingSection');
    const pickupFields = $('#pickupStoreFields');
    const pickupOptions = $('#pickupStoreOptions');
    const pickupStatus = $('#pickupStatus');
    const pickupLoading = $('#pickupLoading');
    const pickupBranchInput = $('#pickupBranchId');
    const fulfillmentInput = $('#fulfillmentMethod');
    const fulfillmentSummary = $('#checkoutFulfillmentText');
    const deliveryReady = form.dataset.deliveryReady === '1';
    const pickupReady = form.dataset.pickupReady === '1';
    const submitBtn = $('#checkoutSubmit');
    const shippingText = $('#checkoutShippingText');
    const grandText = $('#checkoutGrandTotal');
    const weightText = $('#checkoutWeight');
    const discountText = $('#checkoutDiscountText');
    const pointsText = $('#checkoutPointsText');
    const pointsInput = $('#pointsToUse');
    const useMaxPointsBtn = $('#useMaxPointsBtn');
    const pointValue = Math.max(1, Number(form.dataset.pointValue || 100));
    const pointMin = Math.max(1, Number(form.dataset.pointMin || 1));
    const pointAvailable = Math.max(0, Number(form.dataset.pointAvailable || 0));
    const voucherCode = $('#voucherCode');
    const voucherMessage = $('#voucherMessage');
    const applyVoucherBtn = $('#applyVoucherBtn');
    const regionCache = new Map();
    const regionPending = new Map();

    const setSubmitLoading = loading => {
      if (!submitBtn) return;
      submitBtn.classList.toggle('loading', loading);
      submitBtn.disabled = loading || form.dataset.ready !== '1' || !state.shippingLoaded || !cartItems().length;
    };

    const setStatus = (message, type = 'info') => {
      if (!shippingStatus) return;
      shippingStatus.className = `shipping-status-box ${type}`;
      const icon = type === 'error' ? 'bi-exclamation-circle' : type === 'loading' ? 'bi-arrow-repeat' : type === 'success' ? 'bi-check-circle' : 'bi-info-circle';
      shippingStatus.innerHTML = `<i class="bi ${icon}"></i><span>${esc(message)}</span>`;
    };

    const flash = (message, type = 'error') => {
      let alert = $('#checkoutLiveAlert');
      if (!alert) {
        alert = document.createElement('div');
        alert.id = 'checkoutLiveAlert';
        alert.className = 'checkout-live-alert';
        form.prepend(alert);
      }
      alert.className = `checkout-live-alert ${type}`;
      alert.innerHTML = `<i class="bi ${type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'}"></i><span>${esc(message)}</span>`;
      alert.scrollIntoView({behavior: 'smooth', block: 'center'});
      clearTimeout(alert._timer);
      alert._timer = setTimeout(() => alert.classList.add('fade'), 5000);
    };

    const updateTotals = () => {
      const localSubtotal = cartApi?.subtotal ? Number(cartApi.subtotal()) : 0;
      if (!state.subtotal) state.subtotal = localSubtotal;
      document.body.dataset.checkoutShipping = String(state.shipping || 0);
      if (shippingText) shippingText.textContent = money(state.shipping);
      const baseAfterVoucher = Math.max(0, Number(state.subtotal || localSubtotal) - Number(state.discount || 0));
      let requestedPoints = Math.max(0, Math.floor(Number(pointsInput?.value || 0)));
      requestedPoints = Math.min(requestedPoints, pointAvailable, Math.floor(baseAfterVoucher / pointValue));
      if (requestedPoints > 0 && requestedPoints < pointMin) requestedPoints = 0;
      state.pointsDiscount = requestedPoints * pointValue;
      if (discountText) discountText.textContent = '- ' + money(state.discount || 0);
      if (pointsText) pointsText.textContent = '- ' + money(state.pointsDiscount || 0);
      if (grandText) grandText.textContent = money(Math.max(0, baseAfterVoucher - state.pointsDiscount + Number(state.shipping || 0)));
      if (weightText) weightText.textContent = state.weight > 0 ? `${Number(state.weight).toLocaleString('id-ID')} gram` : '-';
      setSubmitLoading(false);
    };

    const clearShipping = (message = 'Pilih alamat tujuan untuk melihat layanan kurir.') => {
      state.shipping = 0;
      state.shippingLoaded = false;
      $('#courierCode').value = '';
      $('#courierService').value = '';
      $('#shippingCostValue').value = '0';
      if (shippingBox) shippingBox.innerHTML = '';
      setStatus(message, 'info');
      updateTotals();
    };

    const setPickupStatus = (message, type = '') => {
      if (!pickupStatus) return;
      pickupStatus.className = `checkout-region-help ${type}`.trim();
      const icon = type === 'success' ? 'bi-check-circle-fill' : type === 'error' ? 'bi-exclamation-triangle-fill' : 'bi-info-circle';
      pickupStatus.innerHTML = `<i class="bi ${icon}"></i><span>${esc(message)}</span>`;
    };

    const renderPickupBranches = rows => {
      if (!pickupOptions) return;
      pickupOptions.innerHTML = rows.map(row => `<label class="pickup-store-card" data-id="${Number(row.branch_id || 0)}">
        <input type="radio" name="pickup_store_choice" value="${Number(row.branch_id || 0)}">
        <span class="pickup-store-icon"><i class="bi bi-shop-window"></i></span>
        <span class="pickup-store-copy"><b>${esc(row.name || 'Toko')}</b><small>${esc(row.address || 'Alamat toko belum diisi')}</small><small><i class="bi bi-clock"></i> ${esc(row.hours || '-')} ${row.phone ? ` • ${esc(row.phone)}` : ''}</small></span>
        <i class="bi bi-check-circle-fill pickup-store-check"></i>
      </label>`).join('');
      $$('.pickup-store-card', pickupOptions).forEach((card, index) => {
        card.addEventListener('click', () => {
          const row = rows[index];
          $$('.pickup-store-card', pickupOptions).forEach(x => x.classList.toggle('active', x === card));
          const radio = $('input[type="radio"]', card); if (radio) radio.checked = true;
          state.pickupBranch = row;
          state.shipping = 0;
          state.shippingLoaded = true;
          state.subtotal = Number(row.subtotal || state.subtotal || cartApi?.subtotal?.() || 0);
          state.weight = Number(row.weight_gram || state.weight || 0);
          if (pickupBranchInput) pickupBranchInput.value = String(row.branch_id || '');
          $('#courierCode').value = 'pickup';
          $('#courierService').value = 'PICKUP';
          $('#shippingCostValue').value = '0';
          setPickupStatus(`Pesanan akan diambil di ${row.name}. Ongkir Rp0.`, 'success');
          if (fulfillmentSummary) fulfillmentSummary.innerHTML = `<i class="bi bi-shop-window"></i><span>Ambil di <b>${esc(row.name || 'Toko')}</b></span>`;
          updateTotals();
        });
      });
      if (rows.length === 1) $$('.pickup-store-card', pickupOptions)[0]?.click();
    };

    async function loadPickupBranches() {
      if (!pickupReady || !form.dataset.pickupUrl) return;
      const items = cartItems();
      if (!items.length) return setPickupStatus('Keranjang kosong. Tambahkan produk terlebih dahulu.', 'error');
      state.shippingLoaded = false;
      state.pickupBranch = null;
      if (pickupBranchInput) pickupBranchInput.value = '';
      if (pickupLoading) pickupLoading.hidden = false;
      if (pickupOptions) pickupOptions.innerHTML = '<div class="shipping-skeleton"><span></span><span></span></div>';
      setPickupStatus('Mengecek stok di toko pengambilan...');
      setSubmitLoading(true);
      try {
        const res = await fetch(form.dataset.pickupUrl, {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({_csrf_token:csrf,items})});
        const json = await res.json().catch(() => ({}));
        if (!res.ok || !json.success) throw new Error(json.message || 'Toko pengambilan tidak tersedia.');
        const rows = Array.isArray(json.data) ? json.data : [];
        if (!rows.length) throw new Error('Tidak ada toko dengan stok lengkap untuk keranjang ini.');
        renderPickupBranches(rows);
        setPickupStatus(`${rows.length} toko tersedia untuk pengambilan. Pilih salah satu.`);
      } catch (err) {
        if (pickupOptions) pickupOptions.innerHTML = '';
        setPickupStatus(err.message || 'Gagal memuat toko pengambilan.', 'error');
        setSubmitLoading(false);
      } finally {
        if (pickupLoading) pickupLoading.hidden = true;
      }
    }

    const setDeliveryFieldsEnabled = enabled => {
      $$('.delivery-only', form).forEach(block => {
        if (block.id === 'destinationSelected') block.hidden = !enabled || !state.destination;
        else block.hidden = !enabled;
        $$('input,select,textarea,button', block).forEach(el => { el.disabled = !enabled; });
      });
      if (enabled) {
        if (provinceSelect) provinceSelect.disabled = provinceSelect.dataset.loading === '1';
        if (citySelect) citySelect.disabled = !provinceSelect?.value || citySelect.dataset.loading === '1';
        if (districtSelect) districtSelect.disabled = !citySelect?.value || districtSelect.dataset.loading === '1';
        if (subdistrictSelect) subdistrictSelect.disabled = !districtSelect?.value || subdistrictSelect.dataset.loading === '1';
      }
    };

    async function setFulfillmentMode(mode) {
      mode = mode === 'pickup' && pickupReady ? 'pickup' : 'delivery';
      if (mode === 'delivery' && !deliveryReady && pickupReady) mode = 'pickup';
      state.fulfillment = mode;
      if (fulfillmentInput) fulfillmentInput.value = mode;
      $$('.fulfillment-method-card', form).forEach(card => card.classList.toggle('active', card.dataset.method === mode));
      $$('input[name="fulfillment_choice"]', form).forEach(r => { r.checked = r.value === mode; });

      if (mode === 'pickup') {
        setDeliveryFieldsEnabled(false);
        if (pickupFields) pickupFields.hidden = false;
        if (shippingSection) shippingSection.hidden = true;
        state.destination = null;
        ['destinationId','destinationLabel','destinationProvince','destinationCity','destinationDistrict','destinationSubdistrict'].forEach(id => { const el=$('#'+id); if(el) el.value=''; });
        if (selectedBox) selectedBox.hidden = true;
        state.shipping = 0;
        state.shippingLoaded = false;
        $('#courierCode').value = 'pickup';
        $('#courierService').value = 'PICKUP';
        $('#shippingCostValue').value = '0';
        if (fulfillmentSummary) fulfillmentSummary.innerHTML = '<i class="bi bi-shop-window"></i><span>Pilih <b>toko pengambilan</b></span>';
        updateTotals();
        await loadPickupBranches();
      } else {
        setDeliveryFieldsEnabled(true);
        if (pickupFields) pickupFields.hidden = true;
        if (shippingSection) shippingSection.hidden = false;
        state.pickupBranch = null;
        if (pickupBranchInput) pickupBranchInput.value = '';
        $('#courierCode').value = '';
        $('#courierService').value = '';
        $('#shippingCostValue').value = '0';
        state.shipping = 0;
        state.shippingLoaded = false;
        if (fulfillmentSummary) fulfillmentSummary.innerHTML = `<i class="bi bi-shop"></i><span>Dikirim dari <b>${esc(fulfillmentSummary.dataset.defaultBranch || 'cabang fulfillment')}</b></span>`;
        const saved = $('.saved-address-choice.active', form) || $('.saved-address-choice.default', form);
        if (saved) setTimeout(() => saved.click(), 30);
        else if (state.destination?.id) loadShipping();
        else { clearShipping(); if (provinceSelect && provinceSelect.options.length <= 1) loadRegion('province',0,provinceSelect,'Pilih Provinsi'); }
      }
    }

    $$('.fulfillment-method-card', form).forEach(card => card.addEventListener('click', () => {
      if (card.classList.contains('disabled')) return;
      setFulfillmentMode(card.dataset.method || 'delivery');
    }));

    const selectShipping = option => {
      state.shipping = Number(option.cost || 0);
      state.shippingLoaded = true;
      $('#courierCode').value = option.code || '';
      $('#courierService').value = option.service || '';
      $('#shippingCostValue').value = String(option.cost || 0);
      $$('.checkout-courier', shippingBox).forEach(el => el.classList.toggle('active', el.dataset.key === `${option.code}|${option.service}|${option.cost}`));
      $$('input[type="radio"]', shippingBox).forEach(radio => { radio.checked = radio.closest('.checkout-courier')?.classList.contains('active') || false; });
      updateTotals();
    };

    const renderShipping = options => {
      if (!shippingBox) return;
      shippingBox.innerHTML = options.map((o, index) => {
        const key = `${o.code}|${o.service}|${o.cost}`;
        return `<label class="checkout-courier ${index === 0 ? 'active' : ''}" data-key="${esc(key)}">
          <input type="radio" name="shipping_option" ${index === 0 ? 'checked' : ''}>
          <span class="courier-logo">${esc(String(o.code || '').toUpperCase())}</span>
          <span class="courier-copy"><b>${esc(o.name || o.code)} • ${esc(o.service)}</b><small>${esc(o.description || 'Layanan pengiriman')} ${o.etd ? `• Estimasi ${esc(o.etd)}` : ''}</small></span>
          <strong>${money(o.cost)}</strong>
        </label>`;
      }).join('');

      $$('.checkout-courier', shippingBox).forEach((el, index) => {
        el.addEventListener('click', () => selectShipping(options[index]));
      });
      if (options[0]) selectShipping(options[0]);
    };

    async function loadShipping() {
      if (!state.destination?.id) return clearShipping();
      const items = cartItems();
      if (!items.length) return clearShipping('Keranjang kosong. Tambahkan produk sebelum menghitung ongkir.');
      state.shippingLoaded = false;
      setSubmitLoading(true);
      setStatus('Menghitung tarif kurir real-time...', 'loading');
      if (shippingBox) shippingBox.innerHTML = '<div class="shipping-skeleton"><span></span><span></span><span></span></div>';
      try {
        const res = await fetch(form.dataset.shippingUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
          body: JSON.stringify({_csrf_token: csrf, destination_id: Number(state.destination.id), province: state.destination.province || '', city: state.destination.city || '', district: state.destination.district || '', subdistrict: state.destination.subdistrict || '', items})
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok || !json.success) throw new Error(json.message || 'Gagal menghitung ongkir.');
        state.subtotal = Number(json.subtotal || 0);
        state.weight = Number(json.weight_gram || 0);
        const options = Array.isArray(json.data) ? json.data : [];
        if (!options.length) throw new Error('Tidak ada layanan kurir tersedia untuk tujuan ini.');
        renderShipping(options);
        setStatus(`${options.length} layanan pengiriman tersedia.`, 'success');
      } catch (err) {
        clearShipping(err.message || 'Gagal menghitung ongkir.');
        setStatus(err.message || 'Gagal menghitung ongkir.', 'error');
      }
    }

    function chooseDestination(item) {
      state.destination = item;
      $('#destinationId').value = String(item.id || '');
      $('#destinationLabel').value = item.label || '';
      $('#destinationProvince').value = item.province || '';
      $('#destinationCity').value = item.city || '';
      $('#destinationDistrict').value = item.district || '';
      $('#destinationSubdistrict').value = item.subdistrict || '';
      if (item.zip_code) $('#postalCode').value = item.zip_code;
      $('#destinationSelectedLabel').textContent = item.label || '';
      $('#destinationSelectedMeta').textContent = [item.province, item.city, item.district, item.subdistrict, item.zip_code].filter(Boolean).join(' • ');
      if (selectedBox) selectedBox.hidden = false;
      if (regionStatus) {
        regionStatus.className = 'checkout-region-help success';
        regionStatus.innerHTML = '<i class="bi bi-check-circle-fill"></i><span>Wilayah siap. Tarif pengiriman sedang dihitung.</span>';
      }
      loadShipping();
    }

    $$('.saved-address-choice', form).forEach(btn => {
      btn.addEventListener('click', () => {
        try {
          const a = JSON.parse(btn.dataset.address || '{}');
          const set = (name, value) => { const el = $(`[name="${name}"]`, form); if (el) el.value = value || ''; };
          set('recipient_name', a.recipient_name); set('recipient_phone', a.recipient_phone); set('address', a.address); set('postal_code', a.postal_code);
          const savedRegion = (select, text) => { if (!select || !text) return; select.innerHTML = `<option value="saved" data-name="${esc(text)}" selected>${esc(text)}</option>`; select.disabled = false; };
          savedRegion(provinceSelect, a.province); savedRegion(citySelect, a.city); savedRegion(districtSelect, a.district); savedRegion(subdistrictSelect, a.subdistrict || a.destination_label);
          chooseDestination({id:Number(a.destination_id||0),label:a.destination_label||'',province:a.province||'',city:a.city||'',district:a.district||'',subdistrict:a.subdistrict||'',zip_code:a.postal_code||''});
          setRegionStatus('Alamat tersimpan dipilih. Ongkir dihitung otomatis.', 'success');
          $$('.saved-address-choice', form).forEach(x => x.classList.toggle('active', x === btn));
        } catch (_) {}
      });
    });

    pointsInput?.addEventListener('input', () => { if(Number(pointsInput.value||0)>pointAvailable) pointsInput.value=String(pointAvailable); updateTotals(); });
    useMaxPointsBtn?.addEventListener('click', () => { const base=Math.max(0,Number(state.subtotal||cartApi?.subtotal?.()||0)-Number(state.discount||0)); const max=Math.min(pointAvailable,Math.floor(base/pointValue)); pointsInput.value=String(max>=pointMin?max:0); updateTotals(); });

    async function applyVoucher() {
      const code = String(voucherCode?.value || '').trim();
      if (!form.dataset.voucherUrl) return;
      const items = cartItems();
      if (!items.length) return flash('Keranjang masih kosong.');
      if (applyVoucherBtn) { applyVoucherBtn.disabled = true; applyVoucherBtn.classList.add('loading'); }
      if (voucherMessage) voucherMessage.textContent = code ? 'Memvalidasi voucher...' : 'Menghapus voucher...';
      try {
        const res = await fetch(form.dataset.voucherUrl, {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({_csrf_token:csrf,voucher_code:code,items})});
        const json = await res.json().catch(() => ({}));
        if (!res.ok || !json.success) throw new Error(json.message || 'Voucher tidak dapat digunakan.');
        state.subtotal = Number(json.subtotal || state.subtotal || 0);
        state.discount = Number(json.discount || 0);
        if (voucherCode) voucherCode.value = json.code || '';
        if (voucherMessage) { voucherMessage.textContent = json.message || 'Voucher berhasil digunakan.'; voucherMessage.className = json.code ? 'success' : ''; }
        updateTotals();
      } catch (err) {
        state.discount = 0; updateTotals();
        if (voucherMessage) { voucherMessage.textContent = err.message || 'Voucher tidak dapat digunakan.'; voucherMessage.className = 'error'; }
      } finally {
        if (applyVoucherBtn) { applyVoucherBtn.disabled = false; applyVoucherBtn.classList.remove('loading'); }
      }
    }
    applyVoucherBtn?.addEventListener('click', applyVoucher);
    voucherCode?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); applyVoucher(); } });

    const regionLabel = select => {
      const o = select?.options?.[select.selectedIndex];
      return o?.dataset?.name || ((select?.value || '') ? (o?.textContent || '').replace(/\s*\([^)]*\)\s*$/, '').trim() : '');
    };

    const resetSelect = (select, label) => {
      if (!select) return;
      select.innerHTML = `<option value="">${esc(label)}</option>`;
      select.disabled = true;
    };

    const setRegionBusy = busy => {
      if (regionLoading) regionLoading.hidden = !busy;
    };

    const setRegionStatus = (message, type = '') => {
      if (!regionStatus) return;
      regionStatus.className = `checkout-region-help ${type}`.trim();
      const icon = type === 'success' ? 'bi-check-circle-fill' : type === 'error' ? 'bi-exclamation-triangle-fill' : 'bi-info-circle';
      regionStatus.innerHTML = `<i class="bi ${icon}"></i><span>${esc(message)}</span>`;
    };

    const clearDestinationState = (message = 'Lengkapi wilayah pengiriman untuk menghitung ongkir.') => {
      state.destination = null;
      ['destinationId','destinationLabel','destinationProvince','destinationCity','destinationDistrict','destinationSubdistrict'].forEach(id => {
        const el = $('#' + id); if (el) el.value = '';
      });
      if (selectedBox) selectedBox.hidden = true;
      clearShipping(message);
    };

    async function fetchRegionRows(level, parentId = 0) {
      const key = `${level}:${Number(parentId || 0)}`;
      if (regionCache.has(key)) return regionCache.get(key);
      if (regionPending.has(key)) return regionPending.get(key);

      const task = (async () => {
        let lastError = null;
        for (let attempt = 0; attempt < 3; attempt++) {
          try {
            if (attempt > 0) await sleep(350 * attempt);
            const params = new URLSearchParams({level});
            if (parentId) params.set('parent_id', String(parentId));
            const res = await fetch(`${form.dataset.regionUrl}?${params.toString()}`, {
              credentials:'same-origin',
              headers:{'Accept':'application/json'},
              cache:'no-store'
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json.success) {
              const err = new Error(json.message || 'Gagal memuat wilayah.');
              err.status = res.status;
              throw err;
            }
            const rows = Array.isArray(json.data) ? json.data : [];
            regionCache.set(key, rows);
            return rows;
          } catch (err) {
            lastError = err;
            const message = String(err?.message || '').toLowerCase();
            const retryable = Number(err?.status || 0) === 429 || message.includes('terlalu cepat') || message.includes('too many');
            if (!retryable || attempt >= 2) break;
          }
        }
        throw lastError || new Error('Gagal memuat wilayah.');
      })();

      regionPending.set(key, task);
      try { return await task; }
      finally { regionPending.delete(key); }
    }

    async function loadRegion(level, parentId, select, placeholder, selectedId = '') {
      if (!select) return [];
      if (select.dataset.loading === '1') {
        const key = `${level}:${Number(parentId || 0)}`;
        return regionPending.has(key) ? regionPending.get(key) : [];
      }
      setRegionBusy(true);
      select.dataset.loading = '1';
      select.disabled = true;
      select.innerHTML = '<option value="">Memuat wilayah...</option>';
      try {
        const rows = await fetchRegionRows(level, parentId);
        select.innerHTML = `<option value="">${esc(placeholder)}</option>` + rows.map(row => {
          const selected = String(row.id) === String(selectedId) ? ' selected' : '';
          const zip = row.zip_code && String(row.zip_code) !== '0' ? ` (${esc(row.zip_code)})` : '';
          return `<option value="${esc(row.id)}" data-name="${esc(row.name || '')}" data-zip="${esc(row.zip_code || '')}"${selected}>${esc(row.name || '')}${zip}</option>`;
        }).join('');
        select.disabled = false;
        select.dataset.regionState = 'ready';
        return rows;
      } catch (err) {
        select.innerHTML = `<option value="">${esc(placeholder)}</option>`;
        select.disabled = false;
        select.dataset.regionState = 'error';
        setRegionStatus(`${err.message || 'Gagal memuat wilayah.'} Ketuk pilihan lagi untuk mencoba ulang.`, 'error');
        return [];
      } finally {
        select.dataset.loading = '0';
        setRegionBusy(false);
      }
    }

    async function resolveSelectedRegion() {
      if (!subdistrictSelect?.value) return clearDestinationState();
      clearDestinationState('Memetakan tujuan pengiriman...');
      setRegionBusy(true);
      setRegionStatus('Memvalidasi wilayah tujuan...', '');
      try {
        const params = new URLSearchParams({
          province: regionLabel(provinceSelect),
          city: regionLabel(citySelect),
          district: regionLabel(districtSelect),
          subdistrict: regionLabel(subdistrictSelect),
          zip_code: subdistrictSelect.options[subdistrictSelect.selectedIndex]?.dataset?.zip || $('#postalCode')?.value || ''
        });
        const res = await fetch(`${form.dataset.resolveUrl}?${params.toString()}`, {credentials:'same-origin', headers:{'Accept':'application/json'}});
        const json = await res.json().catch(() => ({}));
        if (!res.ok || !json.success) throw new Error(json.message || 'Wilayah belum dapat dipetakan.');
        const item = json.data || {};
        item.province = item.province || regionLabel(provinceSelect);
        item.city = item.city || regionLabel(citySelect);
        item.district = item.district || regionLabel(districtSelect);
        item.subdistrict = item.subdistrict || regionLabel(subdistrictSelect);
        item.zip_code = item.zip_code || params.get('zip_code') || '';
        chooseDestination(item);
      } catch (err) {
        clearDestinationState('Pilih wilayah yang valid untuk melihat ongkir.');
        setRegionStatus(err.message || 'Wilayah belum dapat dipetakan.', 'error');
      } finally {
        setRegionBusy(false);
      }
    }

    provinceSelect?.addEventListener('pointerdown', () => {
      if (!provinceSelect.value && provinceSelect.dataset.regionState === 'error' && provinceSelect.dataset.loading !== '1') {
        loadRegion('province', 0, provinceSelect, 'Pilih Provinsi');
      }
    });

    provinceSelect?.addEventListener('change', async () => {
      clearDestinationState();
      resetSelect(citySelect, 'Pilih Kota/Kabupaten');
      resetSelect(districtSelect, 'Pilih Kecamatan');
      resetSelect(subdistrictSelect, 'Pilih Kelurahan/Desa');
      setRegionStatus(provinceSelect.value ? 'Pilih Kota/Kabupaten.' : 'Pilih Provinsi terlebih dahulu.');
      if (provinceSelect.value) await loadRegion('city', provinceSelect.value, citySelect, 'Pilih Kota/Kabupaten');
    });

    citySelect?.addEventListener('change', async () => {
      clearDestinationState();
      resetSelect(districtSelect, 'Pilih Kecamatan');
      resetSelect(subdistrictSelect, 'Pilih Kelurahan/Desa');
      setRegionStatus(citySelect.value ? 'Pilih Kecamatan.' : 'Pilih Kota/Kabupaten.');
      if (citySelect.value) await loadRegion('district', citySelect.value, districtSelect, 'Pilih Kecamatan');
    });

    districtSelect?.addEventListener('change', async () => {
      clearDestinationState();
      resetSelect(subdistrictSelect, 'Pilih Kelurahan/Desa');
      setRegionStatus(districtSelect.value ? 'Pilih Kelurahan/Desa.' : 'Pilih Kecamatan.');
      if (districtSelect.value) await loadRegion('subdistrict', districtSelect.value, subdistrictSelect, 'Pilih Kelurahan/Desa');
    });

    subdistrictSelect?.addEventListener('change', resolveSelectedRegion);

    $('#destinationChange')?.addEventListener('click', async () => {
      $$('.saved-address-choice', form).forEach(x => x.classList.remove('active'));
      clearDestinationState();
      resetSelect(citySelect, 'Pilih Kota/Kabupaten');
      resetSelect(districtSelect, 'Pilih Kecamatan');
      resetSelect(subdistrictSelect, 'Pilih Kelurahan/Desa');
      setRegionStatus('Pilih wilayah baru mulai dari Provinsi.');
      await loadRegion('province', 0, provinceSelect, 'Pilih Provinsi');
      provinceSelect?.focus();
    });

    $$('.payment-method-card', form).forEach(card => {
      card.addEventListener('click', e => {
        if (e.target.tagName === 'SELECT') return;
        $$('.payment-method-card', form).forEach(c => c.classList.remove('active'));
        card.classList.add('active');
        const radio = $('input[type="radio"]', card);
        if (radio) radio.checked = true;
      });
    });

    $('#otherVaSelect')?.addEventListener('change', e => {
      const radio = $('#otherVaRadio');
      if (radio) {
        radio.value = e.target.value;
        radio.checked = true;
        $$('.payment-method-card', form).forEach(c => c.classList.remove('active'));
        radio.closest('.payment-method-card')?.classList.add('active');
      }
    });

    form.addEventListener('submit', async e => {
      e.preventDefault();
      if (form.dataset.ready !== '1') return flash('Checkout belum diaktifkan oleh admin.');
      const items = cartItems();
      if (!items.length) return flash('Keranjang masih kosong.');
      if (state.fulfillment === 'pickup') {
        if (!pickupReady) return flash('Ambil di Toko sedang tidak tersedia.');
        if (!state.pickupBranch || !Number(pickupBranchInput?.value || 0)) return flash('Pilih toko pengambilan terlebih dahulu.');
      } else {
        if (!deliveryReady) return flash('Pengiriman ke alamat belum dikonfigurasi admin.');
        if (!state.destination?.id) return flash('Pilih wilayah tujuan pengiriman.');
        if (!state.shippingLoaded || !$('#courierCode').value) return flash('Pilih layanan pengiriman terlebih dahulu.');
      }
      if (!state.shippingLoaded || !$('#courierCode').value) return flash(state.fulfillment === 'pickup' ? 'Pilih toko pengambilan terlebih dahulu.' : 'Pilih layanan pengiriman terlebih dahulu.');
      if (!form.reportValidity()) return;

      const formData = new FormData(form);
      const payload = Object.fromEntries(formData.entries());
      payload.items = items;
      payload.destination_id = Number(payload.destination_id || 0);
      payload.shipping_cost = Number(payload.shipping_cost || 0);
      payload.pickup_branch_id = Number(payload.pickup_branch_id || 0);

      setSubmitLoading(true);
      try {
        const res = await fetch(form.dataset.orderUrl, {
          method: 'POST', credentials: 'same-origin',
          headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
          body: JSON.stringify(payload)
        });
        // Server bisa balas 419/500 dengan HTML (mis. fatal error). json()
        // akan melempar SyntaxError dan menutupi pesan aslinya, jadi
        // fallback ke teks sebelum decoding JSON.
        const raw = await res.text();
        let json = {};
        try { json = raw ? JSON.parse(raw) : {}; } catch (_) {}
        if (!res.ok || !json.success) {
          const detail = json.message
            || (res.status === 419 ? 'Token keamanan kedaluwarsa. Muat ulang halaman checkout.' : '')
            || (raw ? String(raw).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 180) : '')
            || `Server menolak pesanan (HTTP ${res.status}).`;
          throw new Error(detail);
        }
        flash(json.message || 'Pesanan berhasil dibuat.', 'success');
        if (json.clear_cart && cartApi?.clear) cartApi.clear();
        await sleep(350);
        window.location.href = json.redirect_url;
      } catch (err) {
        setSubmitLoading(false);
        flash(err.message || 'Pesanan gagal dibuat.');
      }
    });

    if (!cartItems().length) {
      clearShipping('Keranjang kosong. Tambahkan produk terlebih dahulu.');
      if (submitBtn) submitBtn.disabled = true;
    } else {
      state.subtotal = cartApi?.subtotal ? Number(cartApi.subtotal()) : 0;
      updateTotals();
      const initialMode = (!deliveryReady && pickupReady) ? 'pickup' : 'delivery';
      setFulfillmentMode(initialMode).then(() => {
        if (initialMode === 'delivery') {
          const preferredAddress = $('.saved-address-choice.default', form) || $('.saved-address-choice', form);
          if (preferredAddress) setTimeout(() => preferredAddress.click(), 80);
          else if (deliveryReady && provinceSelect && !provinceSelect.options.length) loadRegion('province',0,provinceSelect,'Pilih Provinsi');
        }
      });
    }
  }

  // storefront.js dimuat oleh _footer.php, jadi cart API bisa belum ada
  // saat script ini dievaluasi. init diulang sampai API siap.
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
