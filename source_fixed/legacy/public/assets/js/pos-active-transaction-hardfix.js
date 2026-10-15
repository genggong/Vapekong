/* =========================================================
   KASIRPRO POS ACTIVE TRANSACTION HARD FIX
   Dipasang SETELAH pos.js.
   Fungsi:
   - Mencegah error "There is no active transaction"
   - Membuat transaction_id otomatis jika keranjang ada
   - Mengisi hidden input transaksi sebelum promo/bayar
   - Kompatibel dengan form #checkoutForm, #cartJson, promo dropdown/input
   ========================================================= */

(function () {
    'use strict';

    var KEY_TRX = 'kasirpro_pos_active_transaction_id';
    var KEY_CART_A = 'kasirpro_pos_cart';
    var KEY_CART_B = 'pos_cart';
    var KEY_CART_C = 'kasir_cart';

    function qs(selector, parent) {
        return (parent || document).querySelector(selector);
    }

    function qsa(selector, parent) {
        return Array.prototype.slice.call((parent || document).querySelectorAll(selector));
    }

    function makeTrxId() {
        return 'POS-' + Date.now() + '-' + Math.floor(Math.random() * 999999);
    }

    function getCartFromStorage() {
        var keys = [KEY_CART_A, KEY_CART_B, KEY_CART_C];

        for (var i = 0; i < keys.length; i++) {
            try {
                var raw = localStorage.getItem(keys[i]);
                if (!raw) continue;

                var parsed = JSON.parse(raw);
                if (Array.isArray(parsed) && parsed.length) {
                    return parsed;
                }
            } catch (e) {}
        }

        if (window.KasirPOS && typeof window.KasirPOS.getCart === 'function') {
            try {
                var cart = window.KasirPOS.getCart();
                if (Array.isArray(cart) && cart.length) {
                    return cart;
                }
            } catch (e) {}
        }

        return [];
    }

    function getOrCreateTransactionId() {
        var id = localStorage.getItem(KEY_TRX);

        if (!id) {
            id = makeTrxId();
            localStorage.setItem(KEY_TRX, id);
        }

        window.activeTransactionId = id;
        window.currentTransactionId = id;

        window.activeTransaction = window.activeTransaction || {};
        window.activeTransaction.id = id;
        window.activeTransaction.transaction_id = id;

        window.currentTransaction = window.currentTransaction || {};
        window.currentTransaction.id = id;
        window.currentTransaction.transaction_id = id;

        return id;
    }

    function ensureHidden(form, name, value) {
        if (!form) return;

        var input = form.querySelector('[name="' + name + '"]');

        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            form.appendChild(input);
        }

        input.value = value;
    }

    function ensureCartJson(form, cart) {
        if (!form) return;

        var json = JSON.stringify(cart || []);

        var cartJson = qs('#cartJson') || form.querySelector('[name="cart_json"]') || form.querySelector('[name="items"]');
        if (cartJson) {
            cartJson.value = json;
        }

        ensureHidden(form, 'cart_json', json);
        ensureHidden(form, 'items', json);
    }

    function ensureActiveTransactionForPOS() {
        var cart = getCartFromStorage();

        if (!cart.length) {
            return null;
        }

        var trxId = getOrCreateTransactionId();

        for (var i = 0; i < cart.length; i++) {
            cart[i].transaction_id = trxId;
        }

        try {
            localStorage.setItem(KEY_CART_A, JSON.stringify(cart));
        } catch (e) {}

        var form = qs('#checkoutForm') || qs('#posCheckoutForm') || qs('form[data-pos-checkout-form]') || qs('form[action*="pos"]');

        if (form) {
            ensureHidden(form, 'transaction_id', trxId);
            ensureHidden(form, 'active_transaction_id', trxId);
            ensureHidden(form, 'invoice_ref', trxId);
            ensureCartJson(form, cart);
        }

        return trxId;
    }

    function showToast(msg, icon) {
        if (window.Swal) {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: icon || 'info',
                title: msg,
                showConfirmButton: false,
                timer: 2200,
                timerProgressBar: true
            });
            return;
        }

        console.log(msg);
    }

    function patchApplyPromo() {
        if (!window.KasirPOS || typeof window.KasirPOS.applyPromo !== 'function') {
            return;
        }

        if (window.KasirPOS.__promoHardFixed) {
            return;
        }

        var originalApplyPromo = window.KasirPOS.applyPromo;

        window.KasirPOS.applyPromo = function (code) {
            var trxId = ensureActiveTransactionForPOS();

            if (!trxId) {
                showToast('Keranjang masih kosong.', 'warning');
                return;
            }

            return originalApplyPromo.call(window.KasirPOS, code);
        };

        window.KasirPOS.__promoHardFixed = true;
    }

    function patchSubmitTransaction() {
        if (!window.KasirPOS || typeof window.KasirPOS.submitTransaction !== 'function') {
            return;
        }

        if (window.KasirPOS.__submitHardFixed) {
            return;
        }

        var originalSubmit = window.KasirPOS.submitTransaction;

        window.KasirPOS.submitTransaction = function () {
            var trxId = ensureActiveTransactionForPOS();

            if (!trxId) {
                showToast('Keranjang masih kosong.', 'warning');
                return;
            }

            return originalSubmit.call(window.KasirPOS);
        };

        window.KasirPOS.__submitHardFixed = true;
    }

    function interceptPromoAndCheckoutClicks() {
        document.addEventListener('click', function (e) {
            var target = e.target;

            var isPromo =
                target.closest('#applyPromoBtn') ||
                target.closest('[data-apply-promo]') ||
                target.closest('.btn-apply-promo') ||
                target.closest('.promo-apply-btn') ||
                target.closest('.btn-pakai-promo') ||
                target.closest('[data-use-promo]');

            var isCheckout =
                target.closest('#checkoutBtn') ||
                target.closest('[data-checkout]') ||
                target.closest('.btn-checkout') ||
                target.closest('.process-payment-btn') ||
                target.closest('[data-process-payment]') ||
                target.closest('.btn-bayar') ||
                target.closest('.btn-payment');

            if (isPromo || isCheckout) {
                ensureActiveTransactionForPOS();
            }
        }, true);

        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form) return;

            if (
                form.matches('#checkoutForm') ||
                form.matches('#posCheckoutForm') ||
                form.matches('form[data-pos-checkout-form]') ||
                String(form.getAttribute('action') || '').indexOf('pos') !== -1
            ) {
                ensureActiveTransactionForPOS();
            }
        }, true);

        document.addEventListener('change', function (e) {
            if (
                e.target.matches('#autoPromoSelect') ||
                e.target.matches('[name="promo_id"]') ||
                e.target.matches('[data-promo-select]')
            ) {
                ensureActiveTransactionForPOS();
            }
        }, true);

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;

            if (
                e.target.matches('#promoCodeInput') ||
                e.target.matches('[name="promo_code"]') ||
                e.target.matches('.promo-code-input') ||
                e.target.matches('[data-promo-code-input]')
            ) {
                ensureActiveTransactionForPOS();
            }
        }, true);
    }

    function suppressWrongOldAlert() {
        if (!window.Swal || window.Swal.__activeTrxSuppressFixed) {
            return;
        }

        var originalFire = window.Swal.fire;

        window.Swal.fire = function () {
            var args = Array.prototype.slice.call(arguments);
            var first = args[0];

            var title = '';
            if (typeof first === 'string') {
                title = first;
            } else if (first && typeof first === 'object') {
                title = String(first.title || first.text || '');
            }

            if (title.indexOf('There is no active transaction') !== -1) {
                var trxId = ensureActiveTransactionForPOS();

                if (trxId) {
                    return Promise.resolve({ isConfirmed: true, isDismissed: false });
                }
            }

            return originalFire.apply(window.Swal, args);
        };

        window.Swal.__activeTrxSuppressFixed = true;
    }

    function initHardFix() {
        ensureActiveTransactionForPOS();
        patchApplyPromo();
        patchSubmitTransaction();
        interceptPromoAndCheckoutClicks();
        suppressWrongOldAlert();

        setInterval(function () {
            ensureActiveTransactionForPOS();
            patchApplyPromo();
            patchSubmitTransaction();
            suppressWrongOldAlert();
        }, 700);
    }

    document.addEventListener('DOMContentLoaded', initHardFix);

    window.KasirPOSActiveTransactionFix = {
        ensure: ensureActiveTransactionForPOS,
        getOrCreate: getOrCreateTransactionId
    };
})();
