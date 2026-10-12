<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
if (file_exists(__DIR__ . '/../branches/_helpers.php')) require_once __DIR__ . '/../branches/_helpers.php';
Auth::requireRole(['super_admin','owner','admin']);
$pageTitle = 'Buat Retur Supplier';
$db = Database::connect();
supplier_returns_install($db);
if (function_exists('branch_install')) branch_install($db);

$suppliers = $db->query("SELECT id,name FROM suppliers ORDER BY name ASC")->fetchAll();
$products = $db->query("SELECT id,name,product_code,stock,purchase_price,unit FROM products WHERE is_active=1 ORDER BY name ASC")->fetchAll();
$purchases = [];
if (supplier_return_table_exists($db, 'purchases')) {
    $purchaseLabelExpr = supplier_return_purchase_label_expr($db);
    $purchaseDateExpr = supplier_return_purchase_date_expr($db);
    $purchaseTotalExpr = supplier_return_purchase_total_expr($db);
    $supplierSelect = supplier_return_column_exists($db, 'purchases', 'supplier_id') ? 'supplier_id' : 'NULL AS supplier_id';

    $purchases = $db->query("SELECT id, {$purchaseLabelExpr} AS purchase_label, {$supplierSelect}, {$purchaseTotalExpr} AS purchase_total FROM purchases ORDER BY {$purchaseDateExpr} DESC LIMIT 200")->fetchAll();
}
$branchId = supplier_return_branch_id($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verify()) { flash('error','Token keamanan tidak valid.'); legacy_redirect('admin/supplier-returns/create.php'); }

    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $purchaseId = (int)($_POST['purchase_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? 'lainnya');
    $note = trim($_POST['note'] ?? '');
    $productIds = $_POST['product_id'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $costs = $_POST['unit_cost'] ?? [];
    $itemReasons = $_POST['item_reason'] ?? [];
    $itemNotes = $_POST['item_note'] ?? [];

    if ($supplierId <= 0) { flash('error','Supplier wajib dipilih.'); legacy_redirect('admin/supplier-returns/create.php'); }

    try {
        $db->beginTransaction();

        $returnNumber = supplier_return_next_number($db);
        $stmt = $db->prepare("INSERT INTO supplier_returns (return_number,supplier_id,purchase_id,branch_id,user_id,status,reason,note,return_date,created_at)
            VALUES (?,?,?,?,?,'completed',?,?,NOW(),NOW())");
        $stmt->execute([$returnNumber, $supplierId, $purchaseId ?: null, $branchId, Auth::id(), $reason, $note ?: null]);
        $returnId = (int)$db->lastInsertId();

        $totalQty = 0;
        $totalValue = 0;
        $totalItems = 0;

        $insertItem = $db->prepare("INSERT INTO supplier_return_items (supplier_return_id,product_id,product_name,qty,unit_cost,subtotal,reason,note,created_at)
            VALUES (?,?,?,?,?,?,?,?,NOW())");

        foreach ($productIds as $i => $pid) {
            $pid = (int)$pid;
            $qty = max(0, (int)($qtys[$i] ?? 0));
            if ($pid <= 0 || $qty <= 0) continue;

            $stmt = $db->prepare("SELECT id,name,purchase_price FROM products WHERE id=? LIMIT 1");
            $stmt->execute([$pid]);
            $product = $stmt->fetch();
            if (!$product) continue;

            $cost = max(0, (float)($costs[$i] ?? $product['purchase_price']));
            $subtotal = $qty * $cost;
            $itemReason = trim($itemReasons[$i] ?? $reason);
            $itemNote = trim($itemNotes[$i] ?? '');

            $insertItem->execute([$returnId, $pid, $product['name'], $qty, $cost, $subtotal, $itemReason, $itemNote ?: null]);

            supplier_return_update_stock($db, $pid, $qty, $branchId, $returnId, 'Retur supplier ' . $returnNumber . ' - ' . supplier_return_reason_label($itemReason));

            $totalQty += $qty;
            $totalValue += $subtotal;
            $totalItems++;
        }

        if ($totalItems < 1) {
            throw new Exception('Minimal pilih 1 produk retur dengan qty lebih dari 0.');
        }

        $stmt = $db->prepare("UPDATE supplier_returns SET total_items=?, total_qty=?, total_value=? WHERE id=?");
        $stmt->execute([$totalItems, $totalQty, $totalValue, $returnId]);

        Auth::log('supplier_return_create','Membuat retur supplier ' . $returnNumber);
        $db->commit();

        flash('success','Retur supplier berhasil dibuat dan stok sudah dikurangi.');
        legacy_redirect('admin/supplier-returns/detail.php?id=' . $returnId);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error','Gagal membuat retur supplier: ' . $e->getMessage());
        legacy_redirect('admin/supplier-returns/create.php');
    }
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/supplier-returns.css?v=' . time()); ?>">
<main class="admin-main system-wrap">
<header class="admin-topbar page-topbar">
    <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
    <div class="topbar-title"><h1>Buat Retur Supplier</h1><p>Pilih supplier dan produk yang akan dikembalikan. Stok otomatis berkurang.</p></div>
</header>

<form method="POST" class="system-form-card">
<?= CSRF::input(); ?>
<div class="system-form-grid">
    <div class="system-field">
        <label>Supplier</label>
        <select name="supplier_id" required>
            <option value="">Pilih supplier</option>
            <?php foreach($suppliers as $s): ?><option value="<?= e($s['id']); ?>"><?= e($s['name']); ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="system-field">
        <label>Referensi Pembelian</label>
        <select name="purchase_id">
            <option value="">Tanpa referensi</option>
            <?php foreach($purchases as $p): ?><option value="<?= e($p['id']); ?>"><?= e($p['purchase_label'] ?? ('PO-'.$p['id'])); ?> • <?= rupiah($p['purchase_total'] ?? 0); ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="system-field">
        <label>Alasan Utama</label>
        <select name="reason">
            <?php foreach(supplier_return_reasons() as $k=>$v): ?><option value="<?= e($k); ?>"><?= e($v); ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="system-field system-wide">
        <label>Catatan</label>
        <textarea name="note" rows="3" placeholder="Contoh: barang dikembalikan karena rusak saat diterima"></textarea>
    </div>
</div>

<div class="sr-items-head">
    <h3>Produk Retur</h3>
    <button type="button" class="system-btn soft" id="addReturnItem"><i class="bi bi-plus-circle"></i> Tambah Produk</button>
</div>

<div class="sr-items" id="returnItems">
    <div class="sr-item-row">
        <select name="product_id[]" required>
            <option value="">Pilih produk</option>
            <?php foreach($products as $p): ?><option value="<?= e($p['id']); ?>" data-cost="<?= e($p['purchase_price']); ?>" data-stock="<?= e($p['stock']); ?>"><?= e($p['name']); ?> — stok <?= e($p['stock']); ?> <?= e($p['unit']); ?></option><?php endforeach; ?>
        </select>
        <input type="number" min="1" name="qty[]" value="1" placeholder="Qty">
        <input type="number" min="0" name="unit_cost[]" value="0" placeholder="Modal">
        <select name="item_reason[]"><?php foreach(supplier_return_reasons() as $k=>$v): ?><option value="<?= e($k); ?>"><?= e($v); ?></option><?php endforeach; ?></select>
        <input name="item_note[]" placeholder="Catatan item">
        <button type="button" class="sr-remove">×</button>
    </div>
</div>

<div class="system-form-actions">
    <a href="<?= legacy_base_url('admin/supplier-returns/index.php'); ?>" class="system-btn soft">Batal</a>
    <button class="system-btn primary"><i class="bi bi-save"></i> Simpan Retur Supplier</button>
</div>
</form>
</main>

<script>
(function(){
    const wrap = document.getElementById('returnItems');
    const add = document.getElementById('addReturnItem');

    function bind(row){
        const sel = row.querySelector('select[name="product_id[]"]');
        const cost = row.querySelector('input[name="unit_cost[]"]');
        sel?.addEventListener('change', function(){
            const opt = sel.options[sel.selectedIndex];
            if(opt && cost) cost.value = opt.dataset.cost || 0;
        });
    }

    add?.addEventListener('click', function(){
        const first = wrap.querySelector('.sr-item-row');
        const clone = first.cloneNode(true);
        clone.querySelectorAll('input').forEach(input => {
            if(input.name === 'qty[]') input.value = 1;
            else input.value = '';
        });
        clone.querySelectorAll('select').forEach(select => select.selectedIndex = 0);
        wrap.appendChild(clone);
        bind(clone);
    });

    document.addEventListener('click', function(e){
        if(!e.target.classList.contains('sr-remove')) return;
        const rows = wrap.querySelectorAll('.sr-item-row');
        if(rows.length <= 1) return;
        e.target.closest('.sr-item-row').remove();
    });

    wrap.querySelectorAll('.sr-item-row').forEach(bind);
})();
</script>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>