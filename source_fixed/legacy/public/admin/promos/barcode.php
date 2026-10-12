<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';

Auth::requireLogin();

$pageTitle = 'Barcode Promo';
$db = Database::connect();
ensure_promos_table($db);

$id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM promos WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$promo = $stmt->fetch();

if (!$promo) {
    flash('error', 'Promo tidak ditemukan.');
    legacy_redirect('admin/promos/index.php');
}

$promoCode = trim((string)($promo['code'] ?? ''));
$barcodeCode = trim((string)($promo['barcode_code'] ?? ''));

if ($barcodeCode === '') {
    $barcodeCode = $promoCode;
}

if ($barcodeCode === '') {
    $barcodeCode = 'PROMO-' . $promo['id'];
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/promos.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/promo-barcode-share.css?v=' . time()); ?>">

<main class="admin-main promo-barcode-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Barcode Promo</h1>
            <p>Lihat, download, print, atau share barcode promo ke customer.</p>
        </div>
        <div class="topbar-actions">
            <a href="<?= legacy_base_url('admin/promos/index.php'); ?>" class="promos-btn soft">
                <i class="bi bi-arrow-left"></i> Kembali
            </a>
        </div>
    </header>

    <section class="promo-barcode-card" id="promoBarcodeCard">
        <div class="promo-voucher-head">
            <div class="voucher-logo">
                <i class="bi bi-ticket-perforated-fill"></i>
            </div>
            <div>
                <span>Voucher Promo</span>
                <h2><?= e($promo['name']); ?></h2>
                <p><?= e($promo['note'] ?: 'Tunjukkan / scan barcode ini di kasir untuk memakai promo.'); ?></p>
            </div>
        </div>

        <div class="promo-discount-box">
            <span>Diskon</span>
            <strong><?= promo_discount_text($promo); ?></strong>
            <small>Minimal belanja <?= rupiah($promo['min_purchase'] ?? 0); ?></small>
        </div>

        <div class="barcode-preview-box">
            <svg id="promoBarcodeSvg"></svg>
            <div class="barcode-code-text"><?= e($barcodeCode); ?></div>
        </div>

        <div class="voucher-meta-grid">
            <div>
                <span>Kode Voucher</span>
                <b><?= e($promoCode ?: $barcodeCode); ?></b>
            </div>
            <div>
                <span>Barcode</span>
                <b><?= e($barcodeCode); ?></b>
            </div>
            <div>
                <span>Periode</span>
                <b><?= e(($promo['start_date'] ?: '-') . ' s/d ' . ($promo['end_date'] ?: '-')); ?></b>
            </div>
            <div>
                <span>Status</span>
                <b><?= strip_tags(promo_status_badge($promo)); ?></b>
            </div>
        </div>

        <div class="barcode-action-grid no-print">
            <button type="button" class="barcode-btn primary" id="downloadPngBtn">
                <i class="bi bi-file-earmark-image"></i> Download PNG
            </button>
            <button type="button" class="barcode-btn soft" id="downloadSvgBtn">
                <i class="bi bi-filetype-svg"></i> Download SVG
            </button>
            <button type="button" class="barcode-btn soft" onclick="window.print()">
                <i class="bi bi-printer"></i> Print
            </button>
            <button type="button" class="barcode-btn soft" id="sharePromoBtn">
                <i class="bi bi-share"></i> Share
            </button>
            <button type="button" class="barcode-btn soft" id="copyCodeBtn">
                <i class="bi bi-clipboard"></i> Copy Kode
            </button>
        </div>
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
(function () {
    const promoName = <?= json_encode($promo['name']); ?>;
    const promoCode = <?= json_encode($barcodeCode); ?>;
    const shareText = 'Promo: ' + promoName + '\nKode/Barcode: ' + promoCode;

    function renderBarcode() {
        if (typeof JsBarcode === 'undefined') {
            return;
        }

        JsBarcode('#promoBarcodeSvg', promoCode, {
            format: 'CODE128',
            width: 2,
            height: 92,
            displayValue: false,
            margin: 12,
            background: '#ffffff',
            lineColor: '#111827'
        });
    }

    function fileName(ext) {
        return 'barcode-promo-' + promoCode.replace(/[^a-zA-Z0-9_-]/g, '-') + '.' + ext;
    }

    function downloadSvg() {
        const svg = document.getElementById('promoBarcodeSvg');
        const serializer = new XMLSerializer();
        const source = serializer.serializeToString(svg);
        const blob = new Blob([source], { type: 'image/svg+xml;charset=utf-8' });
        const url = URL.createObjectURL(blob);

        const a = document.createElement('a');
        a.href = url;
        a.download = fileName('svg');
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }

    function downloadPng() {
        const svg = document.getElementById('promoBarcodeSvg');
        const serializer = new XMLSerializer();
        const source = serializer.serializeToString(svg);
        const img = new Image();
        const svgBlob = new Blob([source], { type: 'image/svg+xml;charset=utf-8' });
        const url = URL.createObjectURL(svgBlob);

        img.onload = function () {
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(720, img.width + 120);
            canvas.height = Math.max(360, img.height + 190);

            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            ctx.fillStyle = '#991b1b';
            ctx.font = 'bold 28px Arial';
            ctx.textAlign = 'center';
            ctx.fillText(promoName, canvas.width / 2, 50);

            ctx.drawImage(img, (canvas.width - img.width) / 2, 82);

            ctx.fillStyle = '#111827';
            ctx.font = 'bold 24px Arial';
            ctx.fillText(promoCode, canvas.width / 2, 220);

            ctx.fillStyle = '#64748b';
            ctx.font = '16px Arial';
            ctx.fillText('Scan di POS Kasir untuk memakai promo', canvas.width / 2, 252);

            const pngUrl = canvas.toDataURL('image/png');
            const a = document.createElement('a');
            a.href = pngUrl;
            a.download = fileName('png');
            document.body.appendChild(a);
            a.click();
            a.remove();

            URL.revokeObjectURL(url);
        };

        img.src = url;
    }

    async function sharePromo() {
        if (navigator.share) {
            try {
                await navigator.share({
                    title: 'Barcode Promo',
                    text: shareText
                });
                return;
            } catch (e) {}
        }

        copyCode();
    }

    function copyCode() {
        navigator.clipboard.writeText(promoCode).then(function () {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'Kode promo disalin',
                    showConfirmButton: false,
                    timer: 2200,
                    timerProgressBar: true
                });
            } else {
                alert('Kode promo disalin: ' + promoCode);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        renderBarcode();

        document.getElementById('downloadSvgBtn')?.addEventListener('click', downloadSvg);
        document.getElementById('downloadPngBtn')?.addEventListener('click', downloadPng);
        document.getElementById('sharePromoBtn')?.addEventListener('click', sharePromo);
        document.getElementById('copyCodeBtn')?.addEventListener('click', copyCode);
    });
})();
</script>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
