<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin','owner']);
$db = Database::connect();
$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.id=? LIMIT 1");
$stmt->execute([$id]);
$product = $stmt->fetch();
if (!$product) {
    die('Produk tidak ditemukan.');
}
$barcodeValue = $product['barcode'] ?: $product['product_code'];
$qty = max(1, min(60, (int)($_GET['qty'] ?? 12)));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Label - <?= e($product['name']); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        *{box-sizing:border-box} body{margin:0;font-family:Arial,sans-serif;background:#f8fafc;color:#0f172a}.toolbar{position:sticky;top:0;z-index:10;background:#fff;padding:14px 18px;display:flex;gap:12px;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e8f0}.toolbar h1{font-size:18px;margin:0;color:#8b1a1a}.toolbar-actions{display:flex;gap:8px;align-items:center}.toolbar input{width:72px;padding:10px;border:1px solid #e2e8f0;border-radius:12px}.btn{border:0;border-radius:14px;padding:11px 14px;font-weight:800;cursor:pointer}.btn-print{background:linear-gradient(135deg,#ef4444,#991b1b);color:#fff}.btn-back{background:#fff1f2;color:#8b1a1a}.sheet{padding:18px;display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.label{background:#fff;border:1px dashed #cbd5e1;border-radius:14px;padding:10px;text-align:center;min-height:112px;display:flex;flex-direction:column;justify-content:center}.label b{font-size:12px;line-height:1.2}.label small{color:#64748b;font-size:10px}.price{font-weight:900;color:#8b1a1a;font-size:12px;margin-top:3px}.barcode-svg{width:100%;height:42px;margin:5px 0 1px}@media(max-width:640px){.sheet{grid-template-columns:repeat(2,1fr);padding:12px}.toolbar{align-items:flex-start;flex-direction:column}.toolbar-actions{width:100%;flex-wrap:wrap}.btn{flex:1}.label{min-height:105px}}@media print{body{background:#fff}.toolbar{display:none}.sheet{padding:0;gap:4mm;grid-template-columns:repeat(3,1fr)}.label{break-inside:avoid;border:1px dashed #999;border-radius:3mm}}
    </style>
</head>
<body>
    <div class="toolbar">
        <h1><i class="bi bi-upc-scan"></i> Cetak Label: <?= e($product['name']); ?></h1>
        <div class="toolbar-actions">
            <label>Jumlah <input type="number" id="qtyInput" value="<?= $qty; ?>" min="1" max="60"></label>
            <button class="btn btn-back" onclick="history.back()"><i class="bi bi-arrow-left"></i> Kembali</button>
            <button class="btn btn-print" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        </div>
    </div>
    <div class="sheet" id="labelSheet"></div>

    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <script>
        const product = {
            name: <?= json_encode($product['name']); ?>,
            code: <?= json_encode($barcodeValue); ?>,
            price: <?= json_encode(rupiah($product['selling_price'])); ?>,
            category: <?= json_encode($product['category_name'] ?: 'Produk'); ?>
        };
        const sheet = document.getElementById('labelSheet');
        const qtyInput = document.getElementById('qtyInput');
        function renderLabels(){
            const qty = Math.max(1, Math.min(60, parseInt(qtyInput.value || '1', 10)));
            sheet.innerHTML = '';
            for(let i=0;i<qty;i++){
                const item = document.createElement('div');
                item.className = 'label';
                item.innerHTML = `<b>${product.name}</b><small>${product.category}</small><svg class="barcode-svg"></svg><small>${product.code}</small><div class="price">${product.price}</div>`;
                sheet.appendChild(item);
            }
            document.querySelectorAll('.barcode-svg').forEach(svg => {
                JsBarcode(svg, product.code, {format:'CODE128', displayValue:false, height:38, margin:0, width:1.4});
            });
        }
        qtyInput.addEventListener('input', renderLabels);
        renderLabels();
    </script>
</body>
</html>
