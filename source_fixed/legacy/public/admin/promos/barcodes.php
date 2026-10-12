<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';

Auth::requireLogin();

$pageTitle = 'Semua Barcode Promo';
$db = Database::connect();
ensure_promos_table($db);

$promos = $db->query("SELECT * FROM promos ORDER BY id DESC")->fetchAll();

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/promos.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/promo-barcode-share.css?v=' . time()); ?>">

<main class="admin-main promo-barcode-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Semua Barcode Promo</h1>
            <p>Kumpulan barcode promo yang bisa diprint dan dibagikan.</p>
        </div>
        <div class="topbar-actions">
            <button type="button" onclick="window.print()" class="promos-btn primary">
                <i class="bi bi-printer"></i> Print Semua
            </button>
            <a href="<?= legacy_base_url('admin/promos/index.php'); ?>" class="promos-btn soft">
                <i class="bi bi-arrow-left"></i> Kembali
            </a>
        </div>
    </header>

    <section class="promo-barcode-grid">
        <?php foreach ($promos as $promo): ?>
            <?php
                $code = trim((string)($promo['barcode_code'] ?? ''));
                if ($code === '') $code = trim((string)($promo['code'] ?? ''));
                if ($code === '') $code = 'PROMO-' . $promo['id'];
            ?>
            <article class="barcode-mini-card">
                <div class="mini-head">
                    <i class="bi bi-ticket-perforated-fill"></i>
                    <div>
                        <b><?= e($promo['name']); ?></b>
                        <small><?= promo_discount_text($promo); ?></small>
                    </div>
                </div>
                <svg class="barcode-mini-svg" data-code="<?= e($code); ?>"></svg>
                <div class="barcode-code-text"><?= e($code); ?></div>
                <a class="barcode-mini-link no-print" href="<?= legacy_base_url('admin/promos/barcode.php?id=' . $promo['id']); ?>">
                    Detail / Download
                </a>
            </article>
        <?php endforeach; ?>
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof JsBarcode === 'undefined') return;

    document.querySelectorAll('.barcode-mini-svg').forEach(function (svg) {
        JsBarcode(svg, svg.dataset.code, {
            format: 'CODE128',
            width: 1.5,
            height: 64,
            displayValue: false,
            margin: 8,
            background: '#ffffff',
            lineColor: '#111827'
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
