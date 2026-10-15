<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';

Auth::requireLogin();
$db = Database::connect();
ensure_promos_table($db);

$isEdit = basename($_SERVER['SCRIPT_NAME']) === 'edit.php';
$pageTitle = $isEdit ? 'Edit Promo' : 'Tambah Promo';

$autoPromoCode = 'PRM' . date('ymdHis');

$promo = [
    'name' => '', 'code' => $autoPromoCode, 'barcode' => $autoPromoCode, 'promo_type' => 'voucher',
    'discount_type' => 'fixed', 'discount_value' => 0, 'max_discount' => 0,
    'min_purchase' => 0, 'usage_limit' => 0, 'used_count' => 0,
    'promo_scope' => 'all', 'start_date' => date('Y-m-d'),
    'end_date' => date('Y-m-d', strtotime('+30 days')), 'note' => '', 'status' => 'active',
];

$id = (int)($_GET['id'] ?? 0);
$selectedProducts = [];

if ($isEdit) {
    $stmt = $db->prepare("SELECT * FROM promos WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $promo = $stmt->fetch();
    if (!$promo) {
        flash('error', 'Promo tidak ditemukan.');
        legacy_redirect('admin/promos/index.php');
    }
    if (empty($promo['code'])) {
        $promo['code'] = 'PRM' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
    }
    if (empty($promo['barcode'])) {
        $promo['barcode'] = $promo['code'];
    }
    $selectedProducts = promo_product_ids($db, $id);
}

$products = $db->query("SELECT id, name, product_code, barcode, selling_price, stock FROM products WHERE is_active=1 ORDER BY name ASC")->fetchAll();

if (is_post()) {
    if (!CSRF::verify()) {
        flash('error', 'Token keamanan tidak valid.');
        legacy_redirect($isEdit ? 'admin/promos/edit.php?id=' . $id : 'admin/promos/create.php');
    }

    $name = trim($_POST['name'] ?? '');
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $code = preg_replace('/[^A-Z0-9\-_]/', '', $code);
    if ($code === '') {
        $code = 'PRM' . date('ymdHis');
    }
    // Barcode promo otomatis sama dengan kode voucher supaya bisa discan di POS.
    $barcode = $code;
    $promoType = $_POST['promo_type'] ?? 'voucher';
    $discountType = $_POST['discount_type'] ?? 'fixed';
    $discountValue = (float)($_POST['discount_value'] ?? 0);
    $maxDiscount = (float)($_POST['max_discount'] ?? 0);
    $minPurchase = (float)($_POST['min_purchase'] ?? 0);
    $usageLimit = (int)($_POST['usage_limit'] ?? 0);
    $promoScope = $_POST['promo_scope'] ?? 'all';
    $productIds = $_POST['product_ids'] ?? [];
    $startDate = $_POST['start_date'] ?: null;
    $endDate = $_POST['end_date'] ?: null;
    $note = trim($_POST['note'] ?? '');
    $status = $_POST['status'] ?? 'active';

    if (!is_array($productIds)) $productIds = [];
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));

    if ($name === '' || $discountValue <= 0) {
        flash('error', 'Nama promo dan nilai diskon wajib diisi.');
        legacy_redirect($isEdit ? 'admin/promos/edit.php?id=' . $id : 'admin/promos/create.php');
    }

    if ($promoScope === 'specific_products' && !$productIds) {
        flash('error', 'Pilih minimal 1 produk untuk promo khusus produk.');
        legacy_redirect($isEdit ? 'admin/promos/edit.php?id=' . $id : 'admin/promos/create.php');
    }

    if (!in_array($promoType, ['voucher', 'auto_discount'], true)) $promoType = 'voucher';
    if (!in_array($discountType, ['fixed', 'percent'], true)) $discountType = 'fixed';
    if (!in_array($promoScope, ['all', 'specific_products'], true)) $promoScope = 'all';
    if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';

    try {
        $db->beginTransaction();

        if ($isEdit) {
            $stmt = $db->prepare("\n                UPDATE promos SET name=?, code=?, barcode=?, promo_type=?, discount_type=?, discount_value=?, max_discount=?,\n                    min_purchase=?, usage_limit=?, promo_scope=?, start_date=?, end_date=?, note=?, status=?\n                WHERE id=?\n            ");
            $stmt->execute([$name, $code ?: null, $barcode ?: null, $promoType, $discountType, $discountValue, $maxDiscount, $minPurchase, $usageLimit, $promoScope, $startDate, $endDate, $note ?: null, $status, $id]);
            $promoId = $id;
        } else {
            $stmt = $db->prepare("\n                INSERT INTO promos (name, code, barcode, promo_type, discount_type, discount_value, max_discount, min_purchase, usage_limit, promo_scope, start_date, end_date, note, status)\n                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n            ");
            $stmt->execute([$name, $code ?: null, $barcode ?: null, $promoType, $discountType, $discountValue, $maxDiscount, $minPurchase, $usageLimit, $promoScope, $startDate, $endDate, $note ?: null, $status]);
            $promoId = (int)$db->lastInsertId();
        }

        $db->prepare("DELETE FROM promo_products WHERE promo_id=?")->execute([$promoId]);
        if ($promoScope === 'specific_products') {
            $ins = $db->prepare("INSERT IGNORE INTO promo_products (promo_id, product_id) VALUES (?, ?)");
            foreach ($productIds as $pid) {
                $ins->execute([$promoId, $pid]);
            }
        }

        $db->commit();
        Auth::log($isEdit ? 'update_promo' : 'create_promo', ($isEdit ? 'Update promo ' : 'Tambah promo ') . $name);
        flash('success', $isEdit ? 'Promo berhasil diperbarui.' : 'Promo berhasil ditambahkan.');
        legacy_redirect('admin/promos/index.php');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', 'Gagal menyimpan promo: ' . $e->getMessage());
        legacy_redirect($isEdit ? 'admin/promos/edit.php?id=' . $id : 'admin/promos/create.php');
    }
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/promos.css?v=' . time()); ?>">

<main class="admin-main promos-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title"><h1><?= e($pageTitle); ?></h1><p>Atur kode, barcode, nilai diskon, dan produk yang boleh terkena promo.</p></div>
    </header>

    <form method="POST" class="promos-form-card">
        <?= CSRF::input(); ?>
        <div class="promos-form-grid">
            <div class="promo-field"><label>Nama Promo</label><input type="text" name="name" value="<?= e($promo['name']); ?>" required placeholder="Contoh: Diskon Minuman"></div>
            <div class="promo-field">
                <label>Kode Voucher</label>
                <div class="promo-code-row">
                    <input type="text" name="code" id="promoCodeInput" value="<?= e($promo['code']); ?>" placeholder="Contoh: MINUM10" autocomplete="off">
                    <button type="button" class="promo-mini-btn" id="generatePromoCodeBtn"><i class="bi bi-magic"></i></button>
                </div>
                <small class="promo-field-help">Kode ini otomatis menjadi kode barcode promo.</small>
            </div>
            <div class="promo-field">
                <label>Barcode Promo Otomatis</label>
                <input type="text" name="barcode" id="promoBarcodeInput" value="<?= e($promo['barcode'] ?: $promo['code']); ?>" readonly>
                <small class="promo-field-help">Barcode selalu mengikuti kode voucher supaya bisa discan di POS.</small>
            </div>
            <div class="promo-barcode-preview full">
                <div class="promo-barcode-card">
                    <div>
                        <span>Preview Barcode Promo</span>
                        <p>Barcode ini otomatis berubah mengikuti kode voucher.</p>
                    </div>
                    <div class="promo-barcode-box">
                        <svg id="promoBarcodeSvg"></svg>
                    </div>
                </div>
            </div>
            <div class="promo-field"><label>Tipe Promo</label><select name="promo_type"><option value="voucher" <?= $promo['promo_type'] === 'voucher' ? 'selected' : ''; ?>>Voucher Kode / Barcode</option><option value="auto_discount" <?= $promo['promo_type'] === 'auto_discount' ? 'selected' : ''; ?>>Diskon Otomatis</option></select></div>
            <div class="promo-field"><label>Tipe Diskon</label><select name="discount_type"><option value="fixed" <?= $promo['discount_type'] === 'fixed' ? 'selected' : ''; ?>>Nominal Rupiah</option><option value="percent" <?= $promo['discount_type'] === 'percent' ? 'selected' : ''; ?>>Persen</option></select></div>
            <div class="promo-field"><label>Nilai Diskon</label><input type="number" step="0.01" name="discount_value" value="<?= e($promo['discount_value']); ?>" required></div>
            <div class="promo-field"><label>Maksimal Diskon</label><input type="number" step="0.01" name="max_discount" value="<?= e($promo['max_discount']); ?>" placeholder="Untuk diskon persen"></div>
            <div class="promo-field"><label>Minimal Belanja Produk Promo</label><input type="number" step="0.01" name="min_purchase" value="<?= e($promo['min_purchase']); ?>"></div>
            <div class="promo-field"><label>Limit Penggunaan</label><input type="number" name="usage_limit" value="<?= e($promo['usage_limit']); ?>" placeholder="0 = tanpa limit"></div>
            <div class="promo-field"><label>Berlaku Untuk</label><select name="promo_scope" id="promoScopeSelect"><option value="all" <?= ($promo['promo_scope'] ?? 'all') === 'all' ? 'selected' : ''; ?>>Semua Produk</option><option value="specific_products" <?= ($promo['promo_scope'] ?? 'all') === 'specific_products' ? 'selected' : ''; ?>>Produk Tertentu</option></select></div>
            <div class="promo-field"><label>Tanggal Mulai</label><input type="date" name="start_date" value="<?= e($promo['start_date']); ?>"></div>
            <div class="promo-field"><label>Tanggal Selesai</label><input type="date" name="end_date" value="<?= e($promo['end_date']); ?>"></div>
            <div class="promo-field"><label>Status</label><select name="status"><option value="active" <?= $promo['status'] === 'active' ? 'selected' : ''; ?>>Aktif</option><option value="inactive" <?= $promo['status'] === 'inactive' ? 'selected' : ''; ?>>Nonaktif</option></select></div>
            <div class="promo-field full"><label>Catatan</label><textarea name="note" placeholder="Catatan promo optional"><?= e($promo['note']); ?></textarea></div>

            <div class="promo-products-box" id="promoProductsBox">
                <div class="promo-products-head">
                    <div><h4>Produk yang Mendapat Promo</h4><p>Dipakai jika pilihan “Berlaku Untuk” adalah Produk Tertentu.</p></div>
                </div>
                <div class="promo-product-grid">
                    <?php foreach ($products as $product): ?>
                        <label class="promo-product-check">
                            <input type="checkbox" name="product_ids[]" value="<?= e($product['id']); ?>" <?= in_array((int)$product['id'], $selectedProducts, true) ? 'checked' : ''; ?>>
                            <span><b><?= e($product['name']); ?></b><small><?= e($product['product_code'] ?: '-'); ?> • <?= rupiah($product['selling_price']); ?> • Stok <?= e($product['stock']); ?></small></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="promos-form-actions"><a href="<?= legacy_base_url('admin/promos/index.php'); ?>" class="promos-btn soft">Batal</a><button type="submit" class="promos-btn primary"><i class="bi bi-save"></i> Simpan Promo</button></div>
    </form>
</main>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
    var select = document.getElementById('promoScopeSelect');
    var box = document.getElementById('promoProductsBox');
    var codeInput = document.getElementById('promoCodeInput');
    var barcodeInput = document.getElementById('promoBarcodeInput');
    var barcodeSvg = document.getElementById('promoBarcodeSvg');
    var generateBtn = document.getElementById('generatePromoCodeBtn');

    function syncProductBox(){
        if(box && select) box.style.display = select.value === 'specific_products' ? '' : 'none';
    }

    function cleanCode(value) {
        value = (value || '').toUpperCase();
        return value.replace(/[^A-Z0-9\-_]/g, '');
    }

    function generateCode() {
        var now = new Date();
        var y = String(now.getFullYear()).slice(-2);
        var m = String(now.getMonth() + 1).padStart(2, '0');
        var d = String(now.getDate()).padStart(2, '0');
        var h = String(now.getHours()).padStart(2, '0');
        var i = String(now.getMinutes()).padStart(2, '0');
        var s = String(now.getSeconds()).padStart(2, '0');
        var rand = Math.floor(Math.random() * 90 + 10);
        return 'PRM' + y + m + d + h + i + s + rand;
    }

    function renderBarcode() {
        if (!codeInput || !barcodeInput) return;
        var code = cleanCode(codeInput.value);
        if (!code) {
            code = generateCode();
        }
        codeInput.value = code;
        barcodeInput.value = code;

        if (barcodeSvg && typeof JsBarcode !== 'undefined') {
            try {
                JsBarcode(barcodeSvg, code, {
                    format: 'CODE128',
                    width: 2,
                    height: 64,
                    displayValue: true,
                    fontSize: 14,
                    margin: 8,
                    background: '#ffffff',
                    lineColor: '#111827'
                });
            } catch (e) {
                console.warn('Gagal render barcode promo:', e);
            }
        }
    }

    select && select.addEventListener('change', syncProductBox);
    codeInput && codeInput.addEventListener('input', renderBarcode);
    codeInput && codeInput.addEventListener('blur', renderBarcode);
    generateBtn && generateBtn.addEventListener('click', function(){
        codeInput.value = generateCode();
        renderBarcode();
    });

    syncProductBox();
    renderBarcode();
});
</script>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
