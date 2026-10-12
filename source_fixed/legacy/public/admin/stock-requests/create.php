<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/Marketplace/Marketplace.php';
require_once __DIR__ . '/../branches/_helpers.php';
require_once __DIR__ . '/../warehouse/_helpers.php';
require_once __DIR__ . '/../../../app/Modules/CatalogFast.php';
warehouse_require_request_access();
$pageTitle = 'Buat Request Stock';
$db = Database::connect();
branch_install($db);
warehouse_install($db);
$currentBranch = branch_current($db);
$branchId = (int)$currentBranch['id'];
$warehouseId = warehouse_main_id($db);
$warehouseName = warehouse_name($db, $warehouseId);

if (is_post()) {
    if (!CSRF::verify()) {
        flash('error', 'Token keamanan tidak valid.');
        legacy_redirect('admin/stock-requests/create.php');
    }
    $note = trim((string)($_POST['note'] ?? ''));
    $rawItems = (string)($_POST['items_json'] ?? '');
    $decoded = json_decode($rawItems, true);
    if (!is_array($decoded) || !$decoded) {
        flash('error', 'Pilih minimal 1 produk untuk request stok.');
        legacy_redirect('admin/stock-requests/create.php');
    }

    $items = [];
    foreach ($decoded as $row) {
        $pid=(int)($row['product_id']??0);$variantId=(int)($row['variant_id']??0);$qty=max(0,(int)($row['qty']??0));
        $key=$pid.':'.$variantId;
        if($pid>0&&$qty>0){if(!isset($items[$key]))$items[$key]=['product_id'=>$pid,'variant_id'=>$variantId,'qty'=>0];$items[$key]['qty']+=$qty;}
    }
    if (!$items) {
        flash('error', 'Item request tidak valid.');
        legacy_redirect('admin/stock-requests/create.php');
    }

    try {
        $db->beginTransaction();
        $requestNumber = warehouse_request_number($db);
        $detailRows = [];
        $total = 0.0;

        foreach ($items as $itemReq) {
            $productId=(int)$itemReq['product_id'];$variantId=(int)$itemReq['variant_id'];$qty=(int)$itemReq['qty'];
            $stmt = $db->prepare("SELECT id,name,product_code,unit,purchase_price FROM products WHERE id=? AND is_active=1 LIMIT 1 FOR UPDATE");
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if (!$product) throw new Exception('Ada produk yang tidak ditemukan / tidak aktif.');

            $variant=null;if($variantId>0){$vs=$db->prepare("SELECT * FROM marketplace_product_variants WHERE id=? AND product_id=? LIMIT 1");$vs->execute([$variantId,$productId]);$variant=$vs->fetch();if(!$variant)throw new Exception('Varian produk tidak ditemukan.');$ws=warehouse_variant_stock($db,$warehouseId,$productId,$variantId,true);}else{$ws=warehouse_stock($db,$warehouseId,$productId,true);}
            $available = (int)$ws['stock'];
            if ($available < $qty) {
                throw new Exception('Stok gudang untuk ' . $product['name'] . ' tidak cukup. Tersedia ' . $available . ' ' . $product['unit'] . '.');
            }
            $unitCost = (float)$ws['purchase_price'];
            if ($unitCost <= 0) $unitCost = (float)$product['purchase_price'];
            $lineTotal = $qty * $unitCost;
            $total += $lineTotal;
            $detailRows[] = [$product,$variant,$qty,$unitCost,$lineTotal];
        }

        $stmt = $db->prepare("INSERT INTO branch_stock_requests (request_number,branch_id,warehouse_id,requested_by,status,total_cost,note,created_at) VALUES (?,?,?,?, 'pending', ?, ?, NOW())");
        $stmt->execute([$requestNumber,$branchId,$warehouseId,Auth::id(),$total,$note ?: null]);
        $requestId = (int)$db->lastInsertId();

        $insert=$db->prepare("INSERT INTO branch_stock_request_items (request_id,product_id,variant_id,product_name,variant_name,variant_sku,product_code,unit,qty_requested,unit_cost,line_total,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())");
        foreach($detailRows as [$product,$variant,$qty,$unitCost,$lineTotal]){
            $insert->execute([$requestId,$product['id'],$variant['id']??null,$product['name'],$variant['name']??null,$variant['sku']??null,($variant['sku']??'')?:$product['product_code'],$product['unit'],$qty,$unitCost,$lineTotal]);
        }

        Auth::log('create_stock_request', 'Membuat request stok ' . $requestNumber . ' untuk ' . $currentBranch['name']);
        $db->commit();
        flash('success', 'Request stok berhasil dikirim ke gudang.');
        legacy_redirect('admin/stock-requests/detail.php?id=' . $requestId);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', 'Gagal membuat request: ' . $e->getMessage());
        legacy_redirect('admin/stock-requests/create.php');
    }
}

$stmt=$db->prepare("SELECT p.id,p.name,p.product_code,p.barcode,p.unit,c.name category_name,COALESCE(ws.stock,0) warehouse_stock,COALESCE(NULLIF(ws.purchase_price,0),p.purchase_price,0) warehouse_cost,p.purchase_price FROM products p LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN warehouse_stocks ws ON ws.product_id=p.id AND ws.warehouse_id=? WHERE p.is_active=1 ORDER BY p.name ASC");
$stmt->execute([$warehouseId]);
$baseProducts=$stmt->fetchAll() ?: [];
$productIds=array_map(fn($row)=>(int)$row['id'],$baseProducts);
$variantsByProduct=catalog_fast_variants_by_product($db,$productIds,branch_current_id($db),true);
$warehouseVariantMap=catalog_fast_warehouse_variant_stocks($db,$warehouseId,$productIds);
$productGroups=[];
foreach($baseProducts as $p){
    $items=[];
    $vars=$variantsByProduct[(int)$p['id']] ?? [];
    if($vars){
        foreach($vars as $v){
            $wvs=$warehouseVariantMap[(int)$p['id']][(int)$v['id']] ?? ['stock'=>0,'purchase_price'=>0];
            $items[]=[
                'id'=>(int)$p['id'],'parent_name'=>$p['name'],'variant_id'=>(int)$v['id'],'variant_name'=>(string)($v['display_name']??$v['name']),
                'name'=>$p['name'].' - '.($v['display_name']??$v['name']),'product_code'=>(($v['sku']??'')?:$p['product_code']),
                'barcode'=>(($v['barcode']??'')?:$p['barcode']),'unit'=>$p['unit'],'category_name'=>$p['category_name'],
                'warehouse_stock'=>(int)($wvs['stock']??0),
                'warehouse_cost'=>(float)((float)($wvs['purchase_price']??0)>0?$wvs['purchase_price']:((float)($v['purchase_price']??0)>0?$v['purchase_price']:$p['warehouse_cost']))
            ];
        }
    }else{
        $items[]=['id'=>(int)$p['id'],'parent_name'=>$p['name'],'variant_id'=>0,'variant_name'=>'','name'=>$p['name'],'product_code'=>$p['product_code'],'barcode'=>$p['barcode'],'unit'=>$p['unit'],'category_name'=>$p['category_name'],'warehouse_stock'=>(int)$p['warehouse_stock'],'warehouse_cost'=>(float)$p['warehouse_cost']];
    }
    usort($items,fn($a,$b)=>(((int)$a['warehouse_stock']<=0)<=>((int)$b['warehouse_stock']<=0))?:strnatcasecmp((string)$a['name'],(string)$b['name']));
    $productGroups[]=['parent'=>$p,'items'=>$items];
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/warehouse.css?v=' . time()); ?>">
<style>.request-product-group{border:1px solid #eef2f7;border-radius:18px;overflow:hidden;margin-bottom:12px;background:#fff}.request-parent-heading{display:flex;align-items:center;gap:10px;padding:10px 12px;background:#f8fafc;border-bottom:1px solid #eef2f7}.request-parent-heading>i{font-size:20px}.request-parent-heading div{display:grid;gap:2px;flex:1}.request-parent-heading small{font-size:10px;font-weight:900;letter-spacing:.08em;color:#94a3b8}.request-parent-heading b{font-size:13px;color:#0f172a}.request-parent-heading span{font-size:11px;font-weight:900;color:#64748b}.request-product-group .request-product-row{border-radius:0;border:0;border-bottom:1px solid #f1f5f9}.request-product-group .request-product-row:last-child{border-bottom:0}</style>
<main class="admin-main warehouse-wrap">
<header class="admin-topbar page-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Buat Request Stock</h1><p>Pilih barang seperti POS. Harga otomatis mengikuti modal gudang dari supplier tanpa margin.</p></div><div class="topbar-actions"><a class="btn-premium outline" href="<?= legacy_base_url('admin/stock-requests/index.php'); ?>"><i class="bi bi-arrow-left"></i> Kembali</a></div></header>

<section class="request-source-bar"><div><i class="bi bi-buildings"></i><span><small>Dari Gudang</small><b><?= e($warehouseName); ?></b></span></div><i class="bi bi-arrow-right"></i><div><i class="bi bi-shop"></i><span><small>Ke Cabang</small><b><?= e($currentBranch['name']); ?></b></span></div></section>

<form method="POST" id="stockRequestForm"><?= CSRF::input(); ?><input type="hidden" name="items_json" id="requestItemsJson">
<div class="request-pos-grid">
    <section class="request-products-panel">
        <div class="request-search"><i class="bi bi-search"></i><input type="search" id="requestProductSearch" placeholder="Cari nama, kode, barcode, kategori..."></div>
        <div class="request-product-list" id="requestProductList">
        <?php foreach($productGroups as $group): $parent=$group['parent']; ?>
            <div class="request-product-group" data-product-group>
                <div class="request-parent-heading"><i class="bi bi-box"></i><div><small>PRODUK INDUK</small><b><?= e($parent['name']); ?></b></div><span><?= count($group['items']); ?> <?= count($group['items'])>1?'varian':'item'; ?></span></div>
                <?php foreach($group['items'] as $p): $stock=(int)$p['warehouse_stock']; $cost=(float)$p['warehouse_cost']; ?>
                <article class="request-product-row" data-search="<?= e(strtolower($p['name'].' '.$p['product_code'].' '.$p['barcode'].' '.$p['category_name'])); ?>" data-product-id="<?= e($p['id']); ?>" data-variant-id="<?= e($p['variant_id']??0); ?>" data-name="<?= e($p['name']); ?>" data-parent-name="<?= e($p['parent_name']); ?>" data-code="<?= e($p['product_code']); ?>" data-unit="<?= e($p['unit']); ?>" data-stock="<?= $stock; ?>" data-cost="<?= e($cost); ?>">
                    <div class="request-product-icon"><i class="bi bi-box-seam"></i></div>
                    <div class="request-product-copy"><small><?= e($p['variant_name'] ? 'VARIAN • '.$p['variant_name'] : ($p['category_name'] ?: 'Produk induk')); ?></small><h3><?= e($p['name']); ?></h3><p><?= e($p['product_code']); ?><?= $p['barcode'] ? ' • '.e($p['barcode']) : ''; ?></p></div>
                    <div class="request-product-meta"><span class="stock-pill <?= $stock<=0?'out':'ok'; ?>">Gudang <?= $stock; ?> <?= e($p['unit']); ?></span><b><?= rupiah($cost); ?></b><small>modal / <?= e($p['unit']); ?></small></div>
                    <button type="button" class="request-add-btn" <?= $stock<=0?'disabled':''; ?>><i class="bi bi-plus-lg"></i></button>
                </article>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </section>

    <aside class="request-cart-panel">
        <div class="request-cart-head"><div><small>DAFTAR REQUEST</small><h2>Barang Cabang</h2></div><span id="requestCartCount">0 item</span></div>
        <div id="requestCartItems" class="request-cart-items"><div class="request-empty"><i class="bi bi-cart-plus"></i><b>Belum ada barang</b><span>Pilih produk dari daftar gudang.</span></div></div>
        <div class="request-cart-total"><span>Total Modal</span><strong id="requestCartTotal">Rp 0</strong><small>Harga supplier, tanpa margin gudang.</small></div>
        <label class="request-note"><span>Catatan Request</span><textarea name="note" rows="3" placeholder="Contoh: kebutuhan restock cabang minggu ini"></textarea></label>
        <button class="btn-premium request-submit" type="submit" id="requestSubmit" disabled><i class="bi bi-send-check"></i> Kirim Request ke Gudang</button>
    </aside>
</div>
</form>
</main>
<script src="<?= legacy_base_url('assets/js/stock-request.js?v=' . time()); ?>"></script>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
