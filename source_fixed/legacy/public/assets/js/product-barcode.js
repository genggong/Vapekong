(function () {
    'use strict';

    let scannerInstance = null;
    let scannerRunning = false;
    let scannerStarting = false;
    let scannerSession = 0;
    let activeInput = null;
    let modalEl = null;
    let scanLocked = false;

    function q(selector, root = document) {
        return root.querySelector(selector);
    }

    function notify(type, message) {
        const fn = window[type];
        if (typeof fn === 'function') {
            fn(message);
            return;
        }
        if (window.Swal) {
            const icon = type === 'toastSuccess' ? 'success' : (type === 'toastWarning' ? 'warning' : 'error');
            window.Swal.fire({ icon: icon, title: message });
            return;
        }
        window.alert(message);
    }

    function makeProductCode() {
        const now = new Date();
        const pad = n => String(n).padStart(2, '0');
        const y = now.getFullYear();
        const m = pad(now.getMonth() + 1);
        const d = pad(now.getDate());
        const h = pad(now.getHours());
        const i = pad(now.getMinutes());
        const s = pad(now.getSeconds());
        const rand = Math.floor(Math.random() * 90 + 10);
        return `PRD${y}${m}${d}${h}${i}${s}${rand}`;
    }

    function createModal() {
        if (modalEl) return modalEl;

        modalEl = document.createElement('div');
        modalEl.className = 'barcode-camera-modal';
        modalEl.setAttribute('aria-hidden', 'true');
        modalEl.innerHTML = `
            <div class="barcode-camera-card" role="dialog" aria-modal="true" aria-labelledby="productBarcodeTitle">
                <div class="barcode-camera-head">
                    <div>
                        <h3 id="productBarcodeTitle">Scan Barcode Produk</h3>
                        <p>Arahkan kamera ke barcode. Hasil scan akan masuk otomatis.</p>
                    </div>
                    <button type="button" class="barcode-camera-close" aria-label="Tutup"><i class="bi bi-x-lg"></i></button>
                </div>
                <div id="productBarcodeReader" class="barcode-reader-box"></div>
                <div class="barcode-camera-help">
                    <i class="bi bi-info-circle"></i>
                    Gunakan HTTPS dan izinkan akses kamera pada browser.
                </div>
            </div>
        `;
        document.body.appendChild(modalEl);
        q('.barcode-camera-close', modalEl).addEventListener('click', stopScanner);
        modalEl.addEventListener('click', function (e) {
            if (e.target === modalEl) stopScanner();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modalEl && modalEl.classList.contains('show')) stopScanner();
        });
        return modalEl;
    }

    function scannerPreflight() {
        if (typeof window.Html5Qrcode === 'undefined') {
            return 'Library scanner gagal dimuat. Periksa koneksi internet atau konfigurasi firewall hosting.';
        }
        if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
            return 'Kamera browser hanya dapat digunakan melalui HTTPS. Buka website memakai https://.';
        }
        if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
            return 'Browser atau WebView ini tidak menyediakan akses kamera. Coba Chrome terbaru atau aktifkan izin kamera WebView.';
        }
        return '';
    }

    function cameraErrorMessage(err) {
        const text = String((err && (err.name || err.message)) || err || '').toLowerCase();
        if (text.includes('notallowed') || text.includes('permission') || text.includes('denied')) {
            return 'Izin kamera ditolak. Buka pengaturan situs browser lalu izinkan Kamera.';
        }
        if (text.includes('notfound') || text.includes('devicesnotfound') || text.includes('no cameras')) {
            return 'Kamera tidak ditemukan pada perangkat ini.';
        }
        if (text.includes('notreadable') || text.includes('trackstart') || text.includes('could not start')) {
            return 'Kamera sedang dipakai aplikasi lain atau tidak dapat dibuka. Tutup aplikasi kamera lalu coba lagi.';
        }
        if (text.includes('security') || text.includes('secure')) {
            return 'Akses kamera diblokir karena halaman tidak memakai HTTPS.';
        }
        return 'Kamera tidak bisa dibuka. Pastikan HTTPS aktif dan izin kamera diberikan.';
    }

    function clearReader() {
        const reader = document.getElementById('productBarcodeReader');
        if (reader) reader.innerHTML = '';
    }

    function startScanner(input) {
        const preflightError = scannerPreflight();
        if (preflightError) {
            notify('toastError', preflightError);
            return;
        }

        activeInput = input;
        const modal = createModal();
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('barcode-camera-open');
        scanLocked = false;

        const session = ++scannerSession;
        setTimeout(function () {
            if (session !== scannerSession || scannerRunning || scannerStarting) return;

            const readerId = 'productBarcodeReader';
            clearReader();
            const instance = new window.Html5Qrcode(readerId);
            scannerInstance = instance;
            scannerStarting = true;

            instance.start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: { width: 260, height: 160 }, aspectRatio: 1.777778 },
                function (decodedText) {
                    if (scanLocked) return;
                    scanLocked = true;
                    if (activeInput) {
                        activeInput.value = String(decodedText || '').trim();
                        activeInput.dispatchEvent(new Event('input', { bubbles: true }));
                        activeInput.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    notify('toastSuccess', 'Barcode berhasil discan.');
                    stopScanner();
                },
                function () {}
            ).then(function () {
                scannerStarting = false;
                if (session !== scannerSession) {
                    return instance.stop().catch(function () {}).then(function () {
                        try { instance.clear(); } catch (e) {}
                    });
                }
                scannerRunning = true;
            }).catch(function (err) {
                scannerStarting = false;
                scannerRunning = false;
                if (scannerInstance === instance) scannerInstance = null;
                if (session !== scannerSession) {
                    try { instance.clear(); } catch (e) {}
                    clearReader();
                    return;
                }
                console.error('Product barcode camera error:', err);
                try { instance.clear(); } catch (e) {}
                clearReader();
                if (modalEl) {
                    modalEl.classList.remove('show');
                    modalEl.setAttribute('aria-hidden', 'true');
                }
                document.body.classList.remove('barcode-camera-open');
                notify('toastError', cameraErrorMessage(err));
            });
        }, 150);
    }

    function stopScanner() {
        scannerSession++;
        const instance = scannerInstance;
        const wasRunning = scannerRunning;
        const wasStarting = scannerStarting;
        scannerInstance = null;
        scannerRunning = false;
        scannerStarting = false;
        activeInput = null;

        if (modalEl) {
            modalEl.classList.remove('show');
            modalEl.setAttribute('aria-hidden', 'true');
        }
        document.body.classList.remove('barcode-camera-open');

        if (!instance) {
            clearReader();
            return;
        }

        const finish = function () {
            try { instance.clear(); } catch (e) {}
            clearReader();
        };

        if (wasRunning) {
            instance.stop().then(finish).catch(finish);
        } else if (!wasStarting) {
            finish();
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.js-generate-code').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const target = document.querySelector(btn.dataset.target || '');
                if (!target) return;
                target.value = makeProductCode();
                target.dispatchEvent(new Event('input', { bubbles: true }));
                notify('toastSuccess', 'Kode produk otomatis dibuat.');
            });
        });

        document.querySelectorAll('.js-copy-code-to-barcode').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const from = document.querySelector(btn.dataset.from || '');
                const to = document.querySelector(btn.dataset.to || '');
                if (!from || !to) return;
                if (!from.value.trim()) {
                    notify('toastWarning', 'Kode produk masih kosong.');
                    return;
                }
                to.value = from.value.trim();
                to.dispatchEvent(new Event('input', { bubbles: true }));
                notify('toastSuccess', 'Kode produk disalin ke barcode.');
            });
        });

        document.querySelectorAll('.js-scan-barcode').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const input = document.querySelector(btn.dataset.target || '');
                if (!input) return;
                startScanner(input);
            });
        });
    });
})();
