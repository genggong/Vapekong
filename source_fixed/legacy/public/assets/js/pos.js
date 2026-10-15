/* =========================================================
   KASIRPRO UMKM - POS JS EXACT CART FIX
   Cocok dengan file asli:
   #posSearch, #clearCartBtn, .pos-product-card, #checkoutForm,
   #cartJson, #cartItems, #manualDiscountInput, #paidInput.

   Fix:
   - Klik 1x produk = qty tambah 1x saja (anti double tap / double event)
   - Tampilan keranjang rapi pakai class CSS asli: .cart-item, .qty-control, .cart-remove
   - Hapus cache cart lama yang formatnya rusak dari patch sebelumnya
   - Submit transaksi tetap lewat form POST asli halaman POS
   ========================================================= */
(function () {
    'use strict';

    const STORAGE_CART = 'kasirpro_pos_cart';
    const STORAGE_PROMO = 'kasirpro_pos_active_promo';
    const STORAGE_TRX = 'kasirpro_pos_active_transaction_id';
    const STORAGE_VERSION = 'kasirpro_pos_js_version';
    const CURRENT_VERSION = 'exact-cart-fix-v4';

    let cart = [];
    let activePromo = null;
    let lastAddKey = '';
    let lastAddAt = 0;
    let scanMode = 'product';
    let scanner = null;
    let scannerRunning = false;

    function qs(selector, parent) {
        return (parent || document).querySelector(selector);
    }

    function qsa(selector, parent) {
        return Array.from((parent || document).querySelectorAll(selector));
    }

    function toNumber(value) {
        if (value === null || value === undefined || value === '') return 0;
        if (typeof value === 'number') return value;
        value = String(value).replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.');
        const num = parseFloat(value);
        return isNaN(num) ? 0 : num;
    }

    function rupiah(value) {
        return new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            maximumFractionDigits: 0
        }).format(toNumber(value));
    }

    function esc(text) {
        return String(text || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function toast(message, icon) {
        icon = icon || 'info';
        if (window.Swal) {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: icon,
                title: message,
                showConfirmButton: false,
                timer: 2200,
                timerProgressBar: true
            });
            return;
        }
        console.log(icon + ': ' + message);
    }

    function baseUrl(path) {
        path = String(path || '').replace(/^\/+/, '');
        if (window.APP_BASE_URL) {
            return String(window.APP_BASE_URL).replace(/\/+$/, '') + '/' + path;
        }
        const idx = window.location.pathname.indexOf('/public/');
        if (idx !== -1) {
            return (window.location.origin + window.location.pathname.substring(0, idx + 8)).replace(/\/+$/, '') + '/' + path.replace(/^public\//, '');
        }
        return '/' + path;
    }

    function ensureCleanVersion() {
        const old = localStorage.getItem(STORAGE_VERSION);
        if (old !== CURRENT_VERSION) {
            localStorage.removeItem(STORAGE_CART);
            localStorage.removeItem(STORAGE_PROMO);
            localStorage.removeItem(STORAGE_TRX);
            localStorage.setItem(STORAGE_VERSION, CURRENT_VERSION);
        }
    }

    function ensureTransaction() {
        let id = localStorage.getItem(STORAGE_TRX);
        if (!id) {
            id = 'POS-' + Date.now() + '-' + Math.floor(Math.random() * 999999);
            localStorage.setItem(STORAGE_TRX, id);
        }
        return id;
    }

    function clearTransaction() {
        localStorage.removeItem(STORAGE_TRX);
    }

    function loadState() {
        ensureCleanVersion();
        try {
            cart = JSON.parse(localStorage.getItem(STORAGE_CART) || '[]');
            if (!Array.isArray(cart)) cart = [];
        } catch (e) {
            cart = [];
        }
        try {
            activePromo = JSON.parse(localStorage.getItem(STORAGE_PROMO) || 'null');
        } catch (e) {
            activePromo = null;
        }
    }

    function saveCart() {
        localStorage.setItem(STORAGE_CART, JSON.stringify(cart));
        const cartJson = qs('#cartJson');
        if (cartJson) cartJson.value = JSON.stringify(cart.map(function (item) {
            return { id: item.id, qty: item.qty };
        }));
    }

    function savePromo() {
        if (activePromo) localStorage.setItem(STORAGE_PROMO, JSON.stringify(activePromo));
        else localStorage.removeItem(STORAGE_PROMO);
    }

    function clearCart() {
        cart = [];
        activePromo = null;
        localStorage.removeItem(STORAGE_CART);
        localStorage.removeItem(STORAGE_PROMO);
        clearTransaction();
        saveCart();
        savePromo();
        renderCart();
        updateTotals();
        renderPromo();
    }

    function productFromCard(card) {
        if (!card) return null;
        const info = qs('.pos-product-info', card);
        const img = qs('.pos-product-img img', card);
        const metaText = qs('.pos-product-info span', card)?.textContent || '';
        const category = metaText.split('•')[0].trim() || 'Tanpa kategori';
        return {
            id: toNumber(card.dataset.id),
            name: card.dataset.name || qs('.pos-product-info b', card)?.textContent?.trim() || 'Produk',
            code: card.dataset.code || '',
            barcode: card.dataset.barcode || '',
            price: toNumber(card.dataset.price),
            stock: toNumber(card.dataset.stock),
            unit: card.dataset.unit || 'pcs',
            category: category,
            image: img ? img.getAttribute('src') : ''
        };
    }

    function addProduct(product, qty) {
        if (!product || !product.id) {
            toast('Produk tidak terbaca.', 'error');
            return;
        }

        qty = Math.max(1, toNumber(qty || 1));
        const now = Date.now();
        const key = String(product.id);

        // Anti double event / double tap: 1 klik tidak boleh masuk 2 kali.
        if (lastAddKey === key && (now - lastAddAt) < 450) {
            return;
        }
        lastAddKey = key;
        lastAddAt = now;

        if (product.stock <= 0) {
            toast('Stok produk kosong.', 'warning');
            return;
        }

        ensureTransaction();

        let item = cart.find(function (row) {
            return String(row.id) === key;
        });

        if (item) {
            const nextQty = toNumber(item.qty) + qty;
            if (nextQty > product.stock) {
                toast('Qty melebihi stok tersedia.', 'warning');
                return;
            }
            item.qty = nextQty;
            item.subtotal = item.qty * item.price;
        } else {
            cart.push({
                id: product.id,
                name: product.name,
                code: product.code,
                barcode: product.barcode,
                price: product.price,
                stock: product.stock,
                unit: product.unit,
                category: product.category,
                image: product.image,
                qty: qty,
                subtotal: product.price * qty
            });
        }

        saveCart();
        renderCart();
        updateTotals();
        autoRefreshPromo();
        toast(product.name + ' masuk keranjang', 'success');
    }

    function updateQty(id, qty) {
        const item = cart.find(function (row) { return String(row.id) === String(id); });
        if (!item) return;
        qty = Math.max(0, toNumber(qty));
        if (qty <= 0) {
            removeItem(id);
            return;
        }
        if (qty > item.stock) {
            toast('Qty melebihi stok tersedia.', 'warning');
            renderCart();
            return;
        }
        item.qty = qty;
        item.subtotal = item.qty * item.price;
        saveCart();
        renderCart();
        updateTotals();
        autoRefreshPromo();
    }

    function removeItem(id) {
        cart = cart.filter(function (row) { return String(row.id) !== String(id); });
        if (!cart.length) {
            activePromo = null;
            clearTransaction();
            savePromo();
        }
        saveCart();
        renderCart();
        updateTotals();
        renderPromo();
    }

    function subtotal() {
        return cart.reduce(function (sum, item) { return sum + (toNumber(item.price) * toNumber(item.qty)); }, 0);
    }

    function manualDiscount() {
        return Math.max(0, toNumber(qs('#manualDiscountInput')?.value || 0));
    }

    function promoDiscount() {
        return activePromo ? Math.max(0, toNumber(activePromo.discount || 0)) : 0;
    }

    function totalDiscount() {
        return Math.min(subtotal(), manualDiscount() + promoDiscount());
    }

    function grandTotal() {
        return Math.max(0, subtotal() - totalDiscount());
    }

    function paidAmount() {
        return Math.max(0, toNumber(qs('#paidInput')?.value || 0));
    }

    function renderCart() {
        const box = qs('#cartItems');
        if (!box) return;

        if (!cart.length) {
            box.innerHTML = '<div class="cart-empty"><i class="bi bi-cart-x"></i><b>Keranjang kosong</b><span>Pilih produk untuk mulai transaksi.</span></div>';
            qs('#cartCountText') && (qs('#cartCountText').textContent = 'Belum ada item');
            saveCart();
            return;
        }

        box.innerHTML = cart.map(function (item) {
            return `
                <div class="cart-item" data-cart-id="${esc(item.id)}">
                    <div class="cart-item-info">
                        <b>${esc(item.name)}</b>
                        <span>${esc(item.category)} • ${rupiah(item.price)} × ${toNumber(item.qty)} = ${rupiah(item.price * item.qty)}</span>
                    </div>
                    <div class="qty-control">
                        <button type="button" class="cart-minus" data-cart-minus="${esc(item.id)}">−</button>
                        <input type="number" min="1" value="${toNumber(item.qty)}" data-cart-qty="${esc(item.id)}">
                        <button type="button" class="cart-plus" data-cart-plus="${esc(item.id)}">+</button>
                    </div>
                    <button type="button" class="cart-remove" data-cart-remove="${esc(item.id)}"><i class="bi bi-trash"></i></button>
                </div>`;
        }).join('');

        const count = cart.reduce(function (sum, item) { return sum + toNumber(item.qty); }, 0);
        qs('#cartCountText') && (qs('#cartCountText').textContent = count + ' item');
        saveCart();
    }

    function updateTotals() {
        const sub = subtotal();
        const pDisc = promoDiscount();
        const disc = totalDiscount();
        const total = grandTotal();
        const change = Math.max(0, paidAmount() - total);

        if (qs('#subtotalText')) qs('#subtotalText').textContent = rupiah(sub);
        if (qs('#promoDiscountText')) qs('#promoDiscountText').textContent = rupiah(pDisc);
        if (qs('#discountTotalText')) qs('#discountTotalText').textContent = rupiah(disc);
        if (qs('#grandTotalText')) qs('#grandTotalText').textContent = rupiah(total);
        if (qs('#changeText')) qs('#changeText').textContent = rupiah(change);

        if (qs('#discountInput')) qs('#discountInput').value = disc;
        if (qs('#cartJson')) qs('#cartJson').value = JSON.stringify(cart.map(function (item) { return { id: item.id, qty: item.qty }; }));
        if (qs('#promoIdInput')) qs('#promoIdInput').value = activePromo ? (activePromo.id || '') : '';
        if (qs('#promoCodeHidden')) qs('#promoCodeHidden').value = activePromo ? (activePromo.code || activePromo.barcode || '') : '';
    }

    function renderPromo() {
        const box = qs('#promoAppliedBox');
        const text = qs('#promoAppliedText');
        if (!box || !text) return;
        if (!activePromo) {
            box.style.display = 'none';
            text.textContent = 'Promo diterapkan';
            return;
        }
        box.style.display = '';
        text.textContent = (activePromo.name || 'Promo') + ' - ' + rupiah(activePromo.discount || 0);
    }

    function optionPromoData(option) {
        if (!option || !option.value) return null;
        return {
            id: option.value,
            name: option.dataset.name || option.textContent.trim(),
            code: option.dataset.code || '',
            barcode: option.dataset.barcode || '',
            scope: option.dataset.scope || 'all',
            productIds: (option.dataset.productIds || '').split(',').map(function (id) { return parseInt(id, 10); }).filter(Boolean),
            type: option.dataset.type || 'fixed',
            value: toNumber(option.dataset.value),
            max: toNumber(option.dataset.max),
            min: toNumber(option.dataset.min)
        };
    }

    function calculatePromoLocal(promo) {
        if (!promo || !cart.length) return 0;
        let eligible = subtotal();
        if (promo.scope === 'specific_products') {
            eligible = cart.reduce(function (sum, item) {
                return promo.productIds.includes(parseInt(item.id, 10)) ? sum + (item.price * item.qty) : sum;
            }, 0);
        }
        if (eligible <= 0 || eligible < promo.min) return 0;
        if (promo.type === 'percent') {
            let d = eligible * (promo.value / 100);
            if (promo.max > 0) d = Math.min(d, promo.max);
            return Math.min(d, eligible);
        }
        return Math.min(promo.value, eligible);
    }

    function applyAutoPromo() {
        const select = qs('#autoPromoSelect');
        if (!select || !select.value) {
            activePromo = null;
            savePromo();
            renderPromo();
            updateTotals();
            return;
        }
        const promo = optionPromoData(select.options[select.selectedIndex]);
        const discount = calculatePromoLocal(promo);
        if (discount <= 0) {
            activePromo = null;
            savePromo();
            renderPromo();
            updateTotals();
            toast('Produk promo belum ada di keranjang atau minimal belanja belum terpenuhi.', 'warning');
            return;
        }
        activePromo = { id: promo.id, name: promo.name, code: promo.code, barcode: promo.barcode, discount: discount };
        savePromo();
        renderPromo();
        updateTotals();
    }

    function autoRefreshPromo() {
        if (activePromo && qs('#autoPromoSelect') && qs('#autoPromoSelect').value) {
            applyAutoPromo();
        }
    }

    function applyVoucher() {
        const input = qs('#promoCodeInput');
        const code = (input ? input.value : '').trim();
        if (!cart.length) {
            toast('Keranjang masih kosong.', 'warning');
            return;
        }
        if (!code) {
            toast('Masukkan kode/barcode voucher.', 'warning');
            return;
        }

        const fd = new FormData();
        fd.append('subtotal', subtotal());
        fd.append('code', code);
        fd.append('cart_json', JSON.stringify(cart.map(function (item) { return { id: item.id, qty: item.qty, price: item.price }; })));

        fetch(baseUrl('admin/promos/apply.php'), { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json.success) {
                    activePromo = null;
                    savePromo();
                    renderPromo();
                    updateTotals();
                    toast(json.message || 'Voucher tidak valid.', 'error');
                    return;
                }
                activePromo = {
                    id: json.promo.id,
                    name: json.promo.name,
                    code: json.promo.code || code,
                    barcode: json.promo.barcode || code,
                    discount: toNumber(json.discount)
                };
                if (qs('#autoPromoSelect')) qs('#autoPromoSelect').value = '';
                savePromo();
                renderPromo();
                updateTotals();
                toast('Promo berhasil diterapkan.', 'success');
            })
            .catch(function () {
                toast('Gagal mengecek promo.', 'error');
            });
    }

    function filterProducts(keyword) {
        keyword = String(keyword || '').toLowerCase().trim();
        qsa('.pos-product-card').forEach(function (card) {
            const text = (card.textContent + ' ' + (card.dataset.name || '') + ' ' + (card.dataset.code || '') + ' ' + (card.dataset.barcode || '')).toLowerCase();
            card.style.display = (!keyword || text.includes(keyword)) ? '' : 'none';
        });
    }

    function findProductByCode(code) {
        code = String(code || '').trim();
        if (!code) return null;
        let found = null;
        qsa('.pos-product-card').some(function (card) {
            if (String(card.dataset.barcode || '') === code || String(card.dataset.code || '') === code) {
                found = productFromCard(card);
                return true;
            }
            return false;
        });
        return found;
    }

    function openScanner(mode) {
        scanMode = mode || 'product';
        const modal = qs('#barcodeModal');
        if (!modal) return;
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        startScanner();
    }

    function closeScanner() {
        const modal = qs('#barcodeModal');
        if (modal) {
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
        }
        if (scanner && scannerRunning) {
            scanner.stop().catch(function () {}).finally(function () {
                scannerRunning = false;
                qs('#barcodeReader') && (qs('#barcodeReader').innerHTML = '');
            });
        }
    }

    function startScanner() {
        if (!window.Html5Qrcode || !qs('#barcodeReader')) {
            toast('Library scanner belum siap.', 'error');
            return;
        }
        if (scannerRunning) return;
        scanner = new Html5Qrcode('barcodeReader');
        scanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 240, height: 140 } },
            function (decodedText) {
                handleScannedCode(decodedText);
                closeScanner();
            }
        ).then(function () {
            scannerRunning = true;
        }).catch(function () {
            toast('Kamera tidak bisa dibuka. Gunakan HTTPS / localhost / WebView.', 'error');
        });
    }

    function handleScannedCode(code) {
        code = String(code || '').trim();
        if (!code) return;
        if (scanMode === 'promo') {
            if (qs('#promoCodeInput')) qs('#promoCodeInput').value = code;
            applyVoucher();
            return;
        }
        const product = findProductByCode(code);
        if (product) addProduct(product, 1);
        else {
            if (qs('#promoCodeInput')) qs('#promoCodeInput').value = code;
            applyVoucher();
        }
    }

    function bindEvents() {
        // Klik product card. Jangan pakai selector umum agar tidak double dari tombol lain.
        document.addEventListener('click', function (e) {
            const card = e.target.closest('.pos-product-card');
            if (card) {
                e.preventDefault();
                e.stopPropagation();
                addProduct(productFromCard(card), 1);
                return;
            }

            const plus = e.target.closest('[data-cart-plus], .cart-plus');
            if (plus) {
                e.preventDefault();
                const id = plus.dataset.cartPlus || plus.closest('[data-cart-id]')?.dataset.cartId;
                const item = cart.find(function (row) { return String(row.id) === String(id); });
                if (item) updateQty(id, toNumber(item.qty) + 1);
                return;
            }

            const minus = e.target.closest('[data-cart-minus], .cart-minus');
            if (minus) {
                e.preventDefault();
                const id = minus.dataset.cartMinus || minus.closest('[data-cart-id]')?.dataset.cartId;
                const item = cart.find(function (row) { return String(row.id) === String(id); });
                if (item) updateQty(id, toNumber(item.qty) - 1);
                return;
            }

            const remove = e.target.closest('[data-cart-remove], .cart-remove');
            if (remove) {
                e.preventDefault();
                removeItem(remove.dataset.cartRemove || remove.closest('[data-cart-id]')?.dataset.cartId);
                return;
            }

            if (e.target.closest('#clearCartBtn')) {
                e.preventDefault();
                if (window.Swal) {
                    Swal.fire({
                        title: 'Reset keranjang?',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Reset',
                        cancelButtonText: 'Batal',
                        confirmButtonColor: '#dc2626'
                    }).then(function (r) { if (r.isConfirmed) clearCart(); });
                } else if (confirm('Reset keranjang?')) clearCart();
                return;
            }

            if (e.target.closest('#applyVoucherBtn')) {
                e.preventDefault();
                applyVoucher();
                return;
            }

            if (e.target.closest('#clearPromoBtn')) {
                e.preventDefault();
                activePromo = null;
                if (qs('#autoPromoSelect')) qs('#autoPromoSelect').value = '';
                if (qs('#promoCodeInput')) qs('#promoCodeInput').value = '';
                savePromo();
                renderPromo();
                updateTotals();
                return;
            }

            if (e.target.closest('#openBarcodeScanner')) {
                e.preventDefault();
                openScanner('product');
                return;
            }

            if (e.target.closest('#scanPromoBtn')) {
                e.preventDefault();
                openScanner('promo');
                return;
            }

            if (e.target.closest('#closeBarcodeScanner, #closeBarcodeBackdrop')) {
                e.preventDefault();
                closeScanner();
                return;
            }
        });

        document.addEventListener('input', function (e) {
            if (e.target.matches('#posSearch')) filterProducts(e.target.value);
            if (e.target.matches('#manualDiscountInput, #paidInput')) updateTotals();
            if (e.target.matches('[data-cart-qty]')) updateQty(e.target.dataset.cartQty, e.target.value);
        });

        document.addEventListener('change', function (e) {
            if (e.target.matches('#autoPromoSelect')) applyAutoPromo();
        });

        document.addEventListener('submit', function (e) {
            const form = e.target.closest('#checkoutForm');
            if (!form) return;
            updateTotals();
            if (!cart.length) {
                e.preventDefault();
                toast('Keranjang masih kosong.', 'warning');
                return;
            }
            if (paidAmount() < grandTotal()) {
                e.preventDefault();
                toast('Uang diterima kurang dari total pembayaran.', 'warning');
                return;
            }
            saveCart();
        });
    }

    function init() {
        document.body.classList.add('pos-page');
        loadState();
        renderCart();
        renderPromo();
        updateTotals();
        bindEvents();
    }

    document.addEventListener('DOMContentLoaded', init);

    window.KasirPOS = {
        clearCart: clearCart,
        addProduct: addProduct,
        getCart: function () { return cart; },
        updateTotals: updateTotals
    };
})();
