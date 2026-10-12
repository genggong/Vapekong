(function () {
    'use strict';

    function rupiah(n) {
        n = parseFloat(n || 0);
        return 'Rp ' + n.toLocaleString('id-ID', { maximumFractionDigits: 0 });
    }

    function normalize(value) {
        return String(value || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim();
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function recalc() {
        var subtotal = 0;
        document.querySelectorAll('.purchase-item-row').forEach(function (row) {
            var q = parseFloat(row.querySelector('.purchase-qty')?.value || 0);
            var p = parseFloat(row.querySelector('.purchase-price')?.value || 0);
            subtotal += q * p;
        });

        var d = parseFloat(document.getElementById('discount')?.value || 0);
        var s = parseFloat(document.getElementById('shippingCost')?.value || 0);
        var grand = Math.max(0, subtotal - d + s);
        var paymentStatus = document.getElementById('paymentStatus')?.value || 'paid';
        var paid = paymentStatus === 'paid'
            ? grand
            : parseFloat(document.getElementById('paidAmount')?.value || 0);
        var debt = Math.max(0, grand - paid);

        var gt = document.getElementById('grandTotalText');
        var dt = document.getElementById('debtText');
        if (gt) gt.textContent = rupiah(grand);
        if (dt) dt.textContent = 'Sisa hutang: ' + rupiah(debt);

        if (paymentStatus === 'paid' && document.getElementById('paidAmount')) {
            document.getElementById('paidAmount').value = grand;
        }
    }

    function productOptions(select) {
        return Array.from(select.options).filter(function (opt) {
            return opt.value;
        });
    }

    function optionSearchText(opt) {
        return normalize([
            opt.textContent,
            opt.dataset.code,
            opt.dataset.barcode,
            opt.dataset.stock,
            opt.dataset.unit
        ].join(' '));
    }

    function pickerLabel(opt) {
        var text = String(opt.textContent || '').trim();
        return text || 'Produk';
    }

    function closeOtherPickers(activePicker) {
        document.querySelectorAll('.purchase-picker').forEach(function (picker) {
            if (picker !== activePicker) {
                var box = picker.querySelector('.purchase-picker-results');
                if (box) box.hidden = true;
            }
        });
    }

    function buildPicker(row) {
        var select = row.querySelector('.purchase-product');
        if (!select || select.dataset.searchPickerReady === '1') return;
        select.dataset.searchPickerReady = '1';

        // Validasi final tetap dilakukan server. Select dibuat hidden agar keyboard
        // bisa dipakai tanpa membuka native dropdown yang panjang di HP.
        select.required = false;
        select.classList.add('purchase-product-native');

        var picker = document.createElement('div');
        picker.className = 'purchase-picker';
        picker.innerHTML = '' +
            '<div class="purchase-picker-input-wrap">' +
                '<i class="bi bi-search"></i>' +
                '<input type="search" class="purchase-picker-input" autocomplete="off" placeholder="Ketik nama / kode / barcode produk..." aria-label="Cari produk pembelian">' +
                '<button type="button" class="purchase-picker-clear" title="Bersihkan" aria-label="Bersihkan pilihan"><i class="bi bi-x-lg"></i></button>' +
            '</div>' +
            '<div class="purchase-picker-results" hidden></div>';

        select.insertAdjacentElement('beforebegin', picker);

        var input = picker.querySelector('.purchase-picker-input');
        var results = picker.querySelector('.purchase-picker-results');
        var clearBtn = picker.querySelector('.purchase-picker-clear');
        var activeIndex = -1;

        function renderResults(keyword) {
            var key = normalize(keyword);
            var matches = productOptions(select).filter(function (opt) {
                return !key || optionSearchText(opt).includes(key);
            });

            // Supaya picker tetap ringan walaupun produk ratusan/ribuan.
            var visible = matches.slice(0, 60);
            activeIndex = -1;

            if (!visible.length) {
                results.innerHTML = '<div class="purchase-picker-empty">Produk tidak ditemukan. Coba nama, kode, atau barcode lain.</div>';
            } else {
                results.innerHTML = visible.map(function (opt) {
                    var stock = opt.dataset.stock || '0';
                    var unit = opt.dataset.unit || '';
                    var code = opt.dataset.code || '-';
                    var barcode = opt.dataset.barcode || '';
                    return '<button type="button" class="purchase-picker-option" data-product-value="' + escapeHtml(opt.value) + '">' +
                        '<span><b>' + escapeHtml(String(opt.textContent || '').split('|')[0].trim()) + '</b>' +
                        '<small>Kode: ' + escapeHtml(code) + (barcode ? ' • Barcode: ' + escapeHtml(barcode) : '') + '</small></span>' +
                        '<em>Stok ' + escapeHtml(stock) + ' ' + escapeHtml(unit) + '</em>' +
                    '</button>';
                }).join('') + (matches.length > visible.length
                    ? '<div class="purchase-picker-more">Menampilkan 60 dari ' + matches.length + ' hasil. Ketik lebih spesifik untuk mempersempit.</div>'
                    : '');
            }
            results.hidden = false;
            closeOtherPickers(picker);
        }

        function syncActive() {
            var buttons = Array.from(results.querySelectorAll('.purchase-picker-option'));
            buttons.forEach(function (btn, idx) {
                btn.classList.toggle('active', idx === activeIndex);
            });
            if (activeIndex >= 0 && buttons[activeIndex]) {
                buttons[activeIndex].scrollIntoView({ block: 'nearest' });
            }
        }

        function choose(value) {
            var opt = productOptions(select).find(function (item) {
                return String(item.value) === String(value);
            });
            if (!opt) return;

            select.value = opt.value;
            input.value = pickerLabel(opt);
            input.dataset.selectedValue = opt.value;
            results.hidden = true;
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        input.addEventListener('focus', function () {
            renderResults(input.dataset.selectedValue ? '' : input.value);
        });

        input.addEventListener('input', function () {
            if (input.dataset.selectedValue) {
                select.value = '';
                input.dataset.selectedValue = '';
            }
            renderResults(input.value);
        });

        input.addEventListener('keydown', function (event) {
            if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && results.hidden) {
                renderResults(input.value);
            }
            var buttons = Array.from(results.querySelectorAll('.purchase-picker-option'));
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                if (!buttons.length) return;
                activeIndex = Math.min(buttons.length - 1, activeIndex + 1);
                syncActive();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                if (!buttons.length) return;
                activeIndex = activeIndex <= 0 ? 0 : activeIndex - 1;
                syncActive();
            } else if (event.key === 'Enter') {
                if (!results.hidden && buttons.length) {
                    event.preventDefault();
                    var target = buttons[Math.max(0, activeIndex)];
                    if (target) choose(target.dataset.productValue);
                }
            } else if (event.key === 'Escape') {
                results.hidden = true;
            }
        });

        results.addEventListener('click', function (event) {
            var btn = event.target.closest('.purchase-picker-option');
            if (!btn) return;
            event.preventDefault();
            choose(btn.dataset.productValue);
        });

        clearBtn.addEventListener('click', function () {
            select.value = '';
            input.value = '';
            input.dataset.selectedValue = '';
            results.hidden = true;
            input.focus();
            recalc();
        });

        if (select.value) {
            var selected = select.options[select.selectedIndex];
            if (selected) {
                input.value = pickerLabel(selected);
                input.dataset.selectedValue = selected.value;
            }
        }
    }

    function bindRow(row) {
        if (!row || row.dataset.purchaseBound === '1') return;
        row.dataset.purchaseBound = '1';

        var select = row.querySelector('.purchase-product');
        if (select) {
            select.addEventListener('change', function () {
                var opt = this.options[this.selectedIndex];
                var price = opt ? opt.getAttribute('data-price') : 0;
                var input = row.querySelector('.purchase-price');
                if (input && (!input.value || parseFloat(input.value) <= 0)) {
                    input.value = price || 0;
                }
                recalc();
            });
        }

        row.querySelectorAll('input,select').forEach(function (el) {
            el.addEventListener('input', recalc);
            el.addEventListener('change', recalc);
        });

        row.querySelector('.remove-purchase-item')?.addEventListener('click', function () {
            if (document.querySelectorAll('.purchase-item-row').length > 1) {
                row.remove();
                recalc();
            }
        });

        buildPicker(row);
    }

    function showValidation(message, input) {
        if (window.Swal) {
            Swal.fire({ icon: 'warning', title: 'Produk belum dipilih', text: message });
        } else {
            alert(message);
        }
        if (input) setTimeout(function () { input.focus(); }, 50);
    }

    document.addEventListener('click', function (event) {
        if (!event.target.closest('.purchase-picker')) {
            document.querySelectorAll('.purchase-picker-results').forEach(function (box) {
                box.hidden = true;
            });
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        var firstSelect = document.querySelector('.purchase-product');
        var productOptionsTemplate = firstSelect ? firstSelect.innerHTML : '<option value="">Pilih produk</option>';

        document.querySelectorAll('.purchase-item-row').forEach(bindRow);

        document.getElementById('addPurchaseItem')?.addEventListener('click', function () {
            var wrap = document.getElementById('purchaseItems');
            if (!wrap) return;

            var div = document.createElement('div');
            div.className = 'purchase-item-row';
            div.innerHTML = '' +
                '<div class="form-group item-product"><label>Produk</label><select name="item_key[]" class="purchase-product" required>' + productOptionsTemplate + '</select></div>' +
                '<div class="form-group"><label>Qty</label><input type="number" min="1" name="qty[]" class="purchase-qty" value="1" required></div>' +
                '<div class="form-group"><label>Harga Beli</label><input type="number" min="0" step="0.01" name="purchase_price[]" class="purchase-price" value="0" required></div>' +
                '<button type="button" class="remove-purchase-item"><i class="bi bi-trash"></i></button>';
            wrap.appendChild(div);
            bindRow(div);
            recalc();
            setTimeout(function () {
                div.querySelector('.purchase-picker-input')?.focus();
            }, 30);
        });

        ['discount', 'shippingCost', 'paidAmount', 'paymentStatus'].forEach(function (id) {
            document.getElementById(id)?.addEventListener('input', recalc);
            document.getElementById(id)?.addEventListener('change', recalc);
        });

        document.getElementById('purchaseForm')?.addEventListener('submit', function (event) {
            var invalidRow = Array.from(document.querySelectorAll('.purchase-item-row')).find(function (row) {
                return !(row.querySelector('.purchase-product')?.value || '');
            });
            if (invalidRow) {
                event.preventDefault();
                showValidation('Ketik nama/kode/barcode lalu pilih produk dari hasil pencarian.', invalidRow.querySelector('.purchase-picker-input'));
            }
        });

        recalc();
    });
})();
