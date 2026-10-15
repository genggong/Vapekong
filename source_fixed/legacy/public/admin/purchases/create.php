<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/Marketplace/Marketplace.php';
Auth::requireRole(['super_admin','owner']);
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../branches/_helpers.php';
require_once __DIR__ . '/../warehouse/_helpers.php';
require_once __DIR__ . '/../../../app/Modules/CatalogFast.php';
$pageTitle = 'Buat Pembelian';
$db = Database::connect();
branch_install($db);
purchases_install($db);
warehouse_install($db);

$suppliers = $db->query("SELECT id,name FROM suppliers WHERE is_active=1 ORDER BY name ASC")->fetchAll();
$warehouses = warehouse_all($db, true);
$branches = branch_all($db, true);
$defaultWarehouseId = warehouse_main_id($db);
$defaultDestination = (($_GET['destination'] ?? '') === 'branch') ? ('branch:' . branch_current_id($db)) : ('warehouse:' . $defaultWarehouseId);
$products = $db->query("SELECT id,name,product_code,barcode,stock,unit,purchase_price FROM products WHERE is_active=1 ORDER BY name ASC")->fetchAll() ?: [];
$productIds = array_map(fn($row)=>(int)$row['id'], $products);
$variantsByProduct = catalog_fast_variants_by_product($db, $productIds, branch_current_id($db), true);
$productGroups = [];
foreach ($products as $p) {
    $group = ['parent'=>$p,'options'=>[]];
    $vars = $variantsByProduct[(int)$p['id']] ?? [];
    if ($vars) {
        foreach ($vars as $v) {
            $group['options'][] = [
                'product_id'=>(int)$p['id'],
                'variant_id'=>(int)$v['id'],
                'label'=>(string)($v['display_name'] ?? $v['name'] ?? 'Varian'),
                'code'=>(string)(($v['sku'] ?? '') ?: $p['product_code']),
                'barcode'=>(string)(($v['barcode'] ?? '') ?: $p['barcode']),
                'stock'=>(int)($v['stock'] ?? 0),
                'unit'=>$p['unit'],
                'purchase_price'=>(float)((float)($v['purchase_price'] ?? 0) > 0 ? $v['purchase_price'] : $p['purchase_price']),
            ];
        }
    } else {
        $group['options'][] = [
            'product_id'=>(int)$p['id'],'variant_id'=>0,'label'=>'Produk induk / tanpa varian',
            'code'=>$p['product_code'],'barcode'=>$p['barcode'],'stock'=>(int)$p['stock'],
            'unit'=>$p['unit'],'purchase_price'=>(float)$p['purchase_price']
        ];
    }
    $productGroups[] = $group;
}


if (is_post()) {
    if (!CSRF::verify()) {
        flash('error','Token keamanan tidak valid.');
        legacy_redirect('admin/purchases/create.php');
    }

    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $purchaseDate = $_POST['purchase_date'] ?? date('Y-m-d');
    $supplierInvoice = trim($_POST['supplier_invoice'] ?? '');
    $paymentStatus = $_POST['payment_status'] ?? 'paid';
    $discount = max(0, (float)($_POST['discount'] ?? 0));
    $shippingCost = max(0, (float)($_POST['shipping_cost'] ?? 0));
    $paidAmountInput = max(0, (float)($_POST['paid_amount'] ?? 0));
    $note = trim($_POST['note'] ?? '');
    $stockDestination = (string)($_POST['stock_destination'] ?? ('warehouse:' . $defaultWarehouseId));

    [$destinationType, $destinationIdRaw] = array_pad(explode(':', $stockDestination, 2), 2, '0');
    $destinationId = (int)$destinationIdRaw;
    if (!in_array($destinationType, ['warehouse','branch'], true) || $destinationId <= 0) {
        flash('error','Tujuan stok pembelian tidak valid.');
        legacy_redirect('admin/purchases/create.php');
    }
    if ($destinationType === 'warehouse') {
        $check = $db->prepare("SELECT id FROM warehouses WHERE id=? AND is_active=1 LIMIT 1");
    } else {
        $check = $db->prepare("SELECT id FROM branches WHERE id=? AND is_active=1 LIMIT 1");
    }
    $check->execute([$destinationId]);
    if (!$check->fetchColumn()) {
        flash('error','Tujuan stok tidak ditemukan / tidak aktif.');
        legacy_redirect('admin/purchases/create.php');
    }

    $itemKeys = $_POST['item_key'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $prices = $_POST['purchase_price'] ?? [];

    if (!in_array($paymentStatus, ['paid','debt'], true)) $paymentStatus = 'paid';

    $items = [];
    foreach ($itemKeys as $i => $key) {
        [$pidRaw,$variantRaw]=array_pad(explode(':',(string)$key,2),2,'0');
        $pid = (int)$pidRaw;
        $variantId=(int)$variantRaw;
        $qty = (int)($qtys[$i] ?? 0);
        $price = (float)($prices[$i] ?? 0);
        if ($pid > 0 && $qty > 0 && $price >= 0) {
            $items[] = ['product_id'=>$pid,'variant_id'=>$variantId,'qty'=>$qty,'price'=>$price];
        }
    }

    if (!$items) {
        flash('error','Minimal masukkan 1 produk pembelian.');
        legacy_redirect('admin/purchases/create.php');
    }

    try {
        $db->beginTransaction();
        $purchaseNumber = purchases_number($db);
        $subtotal = 0;
        $productCache = [];

        foreach ($items as $item) {
            $stmt = $db->prepare("SELECT * FROM products WHERE id=? FOR UPDATE");
            $stmt->execute([$item['product_id']]);
            $product = $stmt->fetch();
            if (!$product) throw new Exception('Produk tidak ditemukan.');
            if((int)$item['variant_id']>0){
                $vs=$db->prepare("SELECT * FROM marketplace_product_variants WHERE id=? AND product_id=? LIMIT 1 FOR UPDATE");$vs->execute([(int)$item['variant_id'],(int)$item['product_id']]);$variant=$vs->fetch();
                if(!$variant) throw new Exception('Varian produk tidak ditemukan.');
                $item['variant']=$variant;
            }
            $lineTotal = $item['qty'] * $item['price'];
            $subtotal += $lineTotal;
            $productCache[$item['product_id'].':'.$item['variant_id']] = ['product'=>$product,'variant'=>$item['variant']??null];
        }

        $grandTotal = max(0, $subtotal - $discount + $shippingCost);
        $paidAmount = $paymentStatus === 'paid' ? $grandTotal : min($paidAmountInput, $grandTotal);
        $debtAmount = max(0, $grandTotal - $paidAmount);
        $branchId = $destinationType === 'branch' ? $destinationId : null;
        $warehouseId = $destinationType === 'warehouse' ? $destinationId : null;

        $stmt = $db->prepare("INSERT INTO purchases (purchase_number,supplier_id,user_id,stock_destination_type,warehouse_id,branch_id,purchase_date,supplier_invoice,payment_status,subtotal,discount,shipping_cost,grand_total,paid_amount,debt_amount,status,note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'completed', ?)");
        $stmt->execute([$purchaseNumber, $supplierId ?: null, Auth::id(), $destinationType, $warehouseId, $branchId, $purchaseDate, $supplierInvoice ?: null, $paymentStatus, $subtotal, $discount, $shippingCost, $grandTotal, $paidAmount, $debtAmount, $note]);
        $purchaseId = (int)$db->lastInsertId();

        foreach ($items as $item) {
            $cache=$productCache[$item['product_id'].':'.$item['variant_id']];$product=$cache['product'];$variant=$cache['variant'];$variantId=(int)$item['variant_id'];$variantName=$variant['name']??null;$variantSku=$variant['sku']??null;
            $lineTotal = (int)$item['qty'] * (float)$item['price'];
            $before = 0;
            $after = 0;

            // Harga modal produk selalu mengikuti harga beli supplier terakhir.
            $db->prepare("UPDATE products SET purchase_price=?, updated_at=NOW() WHERE id=?")->execute([$item['price'], $item['product_id']]);

            if ($destinationType === 'warehouse') {
                if($variantId>0){
                    $ws=warehouse_variant_stock($db,$destinationId,(int)$item['product_id'],$variantId,true);$before=(int)$ws['stock'];$after=$before+(int)$item['qty'];
                    warehouse_set_variant_stock($db,$destinationId,(int)$item['product_id'],$variantId,$after,(float)$item['price'],(int)($ws['minimum_stock']??0));
                } else {
                    $ws = warehouse_stock($db, $destinationId, (int)$item['product_id'], true);$before=(int)$ws['stock'];$after=$before+(int)$item['qty'];
                    $db->prepare("UPDATE warehouse_stocks SET stock=?,purchase_price=?,minimum_stock=?,updated_at=NOW() WHERE warehouse_id=? AND product_id=?")->execute([$after,$item['price'],(int)$product['minimum_stock'],$destinationId,$item['product_id']]);
                }
                warehouse_record_movement($db,$destinationId,(int)$item['product_id'],'supplier_purchase_in',(int)$item['qty'],$before,$after,(float)$item['price'],'purchase',$purchaseId,'Pembelian supplier '.$purchaseNumber.($variantName?' • '.$variantName:''));
            } else {
                if($variantId>0){
                    $before=marketplace_v16_variant_stock($db,$variantId,$destinationId,true);$after=$before+(int)$item['qty'];marketplace_v16_set_variant_stock($db,$variantId,$destinationId,$after,0);marketplace_v16_sync_parent_stock($db,(int)$item['product_id'],$destinationId);
                } else {
                    branch_sync_product($db,(int)$item['product_id']);$bs=branch_stock($db,$destinationId,(int)$item['product_id']);$before=(int)$bs['stock'];$after=$before+(int)$item['qty'];
                    $db->prepare("UPDATE product_branch_stocks SET stock=?,minimum_stock=?,updated_at=NOW() WHERE branch_id=? AND product_id=?")->execute([$after,(int)$product['minimum_stock'],$destinationId,$item['product_id']]);
                    $mainStmt=$db->prepare("SELECT is_main FROM branches WHERE id=? LIMIT 1");$mainStmt->execute([$destinationId]);if((int)$mainStmt->fetchColumn()===1)$db->prepare("UPDATE products SET stock=?,updated_at=NOW() WHERE id=?")->execute([$after,$item['product_id']]);
                }
                $db->prepare("INSERT INTO stock_movements (product_id,user_id,branch_id,type,qty,stock_before,stock_after,reference_type,reference_id,note) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([$item['product_id'],Auth::id(),$destinationId,'stock_in',$item['qty'],$before,$after,'purchase',$purchaseId,'Pembelian supplier '.$purchaseNumber.($variantName?' • '.$variantName:'')]);
            }

            $db->prepare("INSERT INTO purchase_items (purchase_id,product_id,variant_id,product_name,variant_name,variant_sku,product_code,unit,qty,purchase_price,line_total,stock_before,stock_after) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$purchaseId,$item['product_id'],$variantId?:null,$product['name'],$variantName,$variantSku,$variantSku?:$product['product_code'],$product['unit'],$item['qty'],$item['price'],$lineTotal,$before,$after]);
        }

        if ($paidAmount > 0) {
            purchases_create_finance_expense($db, $purchaseId, $paidAmount, $purchaseNumber, 'Pembayaran pembelian stok' . ($note ? ': ' . $note : ''));
        }
        if ($debtAmount > 0) {
            purchases_create_debt($db, $purchaseId, $supplierId ?: null, $debtAmount, $purchaseNumber, $note);
        }
        purchases_create_notification($db, 'Pembelian tersimpan', 'Pembelian ' . $purchaseNumber . ' berhasil dibuat.', 'success');
        Auth::log('create_purchase', 'Membuat pembelian ' . $purchaseNumber . ' ke ' . $destinationType);
        $db->commit();
        flash('success','Pembelian berhasil dibuat dan stok tujuan otomatis bertambah.');
        legacy_redirect('admin/purchases/detail.php?id=' . $purchaseId);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error','Gagal membuat pembelian: ' . $e->getMessage());
        legacy_redirect('admin/purchases/create.php');
    }
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/purchases.css?v=' . time()); ?>">
<main class="admin-main purchases-wrap">
<header class="admin-topbar page-topbar">
    <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
    <div class="topbar-title"><h1>Buat Pembelian</h1><p>Input pembelian supplier. Default masuk Gudang, lalu cabang mengambil stok melalui Request Stock.</p></div>
</header>
<section class="premium-card mt-4 purchase-form-card">
<form method="POST" class="premium-form" id="purchaseForm">
<?= CSRF::input(); ?>
<div class="purchase-form-grid">
    <div class="form-group"><label>Supplier</label><select name="supplier_id"><option value="">Supplier Umum</option><?php foreach($suppliers as $s): ?><option value="<?= e($s['id']); ?>"><?= e($s['name']); ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>Tujuan Stok</label><select name="stock_destination" required><?php foreach($warehouses as $w): $v='warehouse:'.$w['id']; ?><option value="<?= e($v); ?>" <?= $defaultDestination===$v?'selected':''; ?>>Gudang — <?= e($w['name']); ?></option><?php endforeach; ?><?php foreach($branches as $b): $v='branch:'.$b['id']; ?><option value="<?= e($v); ?>" <?= $defaultDestination===$v?'selected':''; ?>>Cabang — <?= e($b['name']); ?></option><?php endforeach; ?></select><small>Disarankan supplier → Gudang. Cabang mengambil lewat Request Stock.</small></div>
    <div class="form-group"><label>Tanggal Pembelian</label><input type="date" name="purchase_date" value="<?= e(date('Y-m-d')); ?>" required></div>
    <div class="form-group"><label>Invoice Supplier</label><input name="supplier_invoice" placeholder="Contoh: INV-SUP-001"></div>
    <div class="form-group"><label>Status Pembayaran</label><select name="payment_status" id="paymentStatus"><option value="paid">Lunas</option><option value="debt">Hutang / Tempo</option></select></div>
</div>
<div class="purchase-items-head"><div><h3>Item Pembelian</h3><p>Pilih produk induk lalu variannya, masukkan qty dan harga beli supplier.</p></div><button type="button" class="btn-soft" id="addPurchaseItem"><i class="bi bi-plus-circle"></i> Tambah Item</button></div>
<div class="purchase-items" id="purchaseItems"><div class="purchase-item-row"><div class="form-group item-product"><label>Produk</label><select name="item_key[]" class="purchase-product" required><option value="">Pilih produk induk + varian</option><?php foreach($productGroups as $group): $parent=$group['parent']; ?><optgroup label="<?= e($parent['name']); ?>"><?php foreach($group['options'] as $p): ?><option value="<?= e($p['product_id'].':'.$p['variant_id']); ?>" data-price="<?= e($p['purchase_price']); ?>" data-stock="<?= e($p['stock']); ?>" data-unit="<?= e($p['unit']); ?>" data-code="<?= e($p['code'] ?? ''); ?>" data-barcode="<?= e($p['barcode'] ?? ''); ?>"><?= e('↳ '.$p['label']); ?> — <?= e($p['code']); ?><?= !empty($p['barcode']) ? ' • ' . e($p['barcode']) : ''; ?> | Modal <?= rupiah($p['purchase_price']); ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div><div class="form-group"><label>Qty</label><input type="number" min="1" name="qty[]" class="purchase-qty" value="1" required></div><div class="form-group"><label>Harga Beli</label><input type="number" min="0" step="0.01" name="purchase_price[]" class="purchase-price" value="0" required></div><button type="button" class="remove-purchase-item"><i class="bi bi-trash"></i></button></div></div>
<div class="purchase-total-panel"><div class="form-group"><label>Diskon</label><input type="number" min="0" step="0.01" name="discount" id="discount" value="0"></div><div class="form-group"><label>Ongkir / Biaya</label><input type="number" min="0" step="0.01" name="shipping_cost" id="shippingCost" value="0"></div><div class="form-group paid-field"><label>Dibayar</label><input type="number" min="0" step="0.01" name="paid_amount" id="paidAmount" value="0"><small>Jika status hutang, isi nominal yang sudah dibayar.</small></div><div class="purchase-total-box"><span>Grand Total</span><strong id="grandTotalText">Rp 0</strong><small id="debtText">Sisa hutang: Rp 0</small></div></div>
<div class="form-group form-wide"><label>Catatan</label><textarea name="note" rows="4" placeholder="Catatan pembelian / tempo / supplier"></textarea></div>
<div class="form-actions"><a class="btn-soft" href="<?= legacy_base_url('admin/purchases/index.php'); ?>">Batal</a><button class="btn-premium"><i class="bi bi-save"></i> Simpan Pembelian</button></div>
</form></section></main>
<script src="<?= legacy_base_url('assets/js/purchases.js?v=' . time()); ?>"></script>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
