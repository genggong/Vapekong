<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin','owner']);
require_once __DIR__ . '/../branches/_helpers.php';
$pageTitle = 'Stok Barang';
$db = Database::connect();
branch_install($db);
$currentBranch = branch_current($db);
$currentBranchId = (int) $currentBranch['id'];
$branchOptions = branch_is_admin() ? branch_all($db, true) : [];

$summary = [
    'total_products' => 0,
    'total_stock' => 0,
    'low_stock' => 0,
    'stock_value' => 0,
];

try {
    $summary['total_products'] = (int)($db->query("SELECT COUNT(*) total FROM products WHERE is_active=1")->fetch()['total'] ?? 0);
    $stmt = $db->prepare("SELECT COALESCE(SUM(stock),0) total FROM product_branch_stocks WHERE branch_id=?");
    $stmt->execute([$currentBranchId]);
    $summary['total_stock'] = (int)($stmt->fetch()['total'] ?? 0);
    $stmt = $db->prepare("SELECT COUNT(*) total FROM product_branch_stocks WHERE branch_id=? AND stock <= minimum_stock");
    $stmt->execute([$currentBranchId]);
    $summary['low_stock'] = (int)($stmt->fetch()['total'] ?? 0);
    $stmt = $db->prepare("SELECT COALESCE(SUM(pbs.stock * p.purchase_price),0) total FROM product_branch_stocks pbs JOIN products p ON p.id=pbs.product_id WHERE pbs.branch_id=?");
    $stmt->execute([$currentBranchId]);
    $summary['stock_value'] = (float)($stmt->fetch()['total'] ?? 0);
} catch (Throwable $e) {}

$stmtProducts = $db->prepare("SELECT p.*, c.name AS category_name, s.name AS supplier_name, COALESCE(pbs.stock, p.stock) AS stock, COALESCE(pbs.minimum_stock, p.minimum_stock) AS minimum_stock FROM products p LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN suppliers s ON s.id=p.supplier_id LEFT JOIN product_branch_stocks pbs ON pbs.product_id=p.id AND pbs.branch_id=? ORDER BY stock ASC, p.name ASC");
$stmtProducts->execute([$currentBranchId]);
$products = $stmtProducts->fetchAll();

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link href="<?= legacy_base_url('assets/css/stock.css?v=' . time()); ?>" rel="stylesheet">
<main class="admin-main stock-page">
  <header class="stock-hero premium-card">
    <button class="sidebar-toggle stock-menu-btn" id="sidebarToggle"><i class="bi bi-list"></i></button>

    <div class="stock-hero-copy">
      <span class="stock-kicker"><i class="bi bi-box-seam-fill"></i> Inventory Center</span>
      <h1>Stok Barang</h1>
      <p>Cabang aktif: <b><?= e($currentBranch['name'] ?? 'Cabang'); ?></b>. Data stok mengikuti cabang aktif.</p>
    </div>

    <div class="stock-hero-actions">
      <a href="<?= legacy_base_url('admin/stock/stock-in.php'); ?>" class="stock-action-main"><i class="bi bi-box-arrow-in-down"></i><span>Stok Masuk</span></a>
      <a href="<?= legacy_base_url('admin/stock/stock-out.php'); ?>" class="stock-action-outline"><i class="bi bi-box-arrow-up"></i><span>Stok Keluar</span></a>
    </div>
  </header>

  <section class="stock-mini-grid">
    <div class="stock-mini-card red">
      <div class="mini-icon"><i class="bi bi-grid-fill"></i></div>
      <div class="mini-body"><span>Total Produk</span><strong><?= e($summary['total_products']); ?></strong><small>Produk tercatat</small></div>
    </div>
    <div class="stock-mini-card blue">
      <div class="mini-icon"><i class="bi bi-boxes"></i></div>
      <div class="mini-body"><span>Total Stok</span><strong><?= e($summary['total_stock']); ?></strong><small>Semua satuan</small></div>
    </div>
    <div class="stock-mini-card orange">
      <div class="mini-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
      <div class="mini-body"><span>Stok Menipis</span><strong><?= e($summary['low_stock']); ?></strong><small>Butuh restock</small></div>
    </div>
    <div class="stock-mini-card green">
      <div class="mini-icon"><i class="bi bi-wallet2"></i></div>
      <div class="mini-body"><span>Nilai Modal</span><strong><?= rupiah($summary['stock_value']); ?></strong><small>Estimasi modal</small></div>
    </div>
  </section>

  <section class="premium-card mt-4 stock-card stock-data-card">
    <div class="stock-section-head">
      <div>
        <h3>Data Stok Produk</h3>
        <p>Cari produk, kode, kategori, supplier, status stok, atau nilai modal.</p>
      </div>
      <div class="stock-head-actions">
        <a href="<?= legacy_base_url('admin/stock-opname/template.php'); ?>" class="stock-pill-btn"><i class="bi bi-file-earmark-excel"></i> Template Opname</a>
        <a href="<?= legacy_base_url('admin/stock-opname/import.php'); ?>" class="stock-pill-btn"><i class="bi bi-upload"></i> Upload Excel</a>
        <a href="<?= legacy_base_url('admin/stock-opname/index.php'); ?>" class="stock-pill-btn"><i class="bi bi-clipboard-check"></i> Stock Opname</a>
        <a href="<?= legacy_base_url('admin/stock/adjustment.php'); ?>" class="stock-pill-btn"><i class="bi bi-sliders"></i> Adjustment</a>
        <a href="<?= legacy_base_url('admin/stock/history.php'); ?>" class="stock-pill-btn"><i class="bi bi-clock-history"></i> Riwayat</a>
      </div>
    </div>

    <div class="stock-note-clean"><i class="bi bi-info-circle-fill"></i><span>Stok otomatis berkurang saat transaksi POS sukses. Koreksi manual lewat stok masuk, stok keluar, atau adjustment.</span></div>

    <div class="table-premium-card dt-premium-wrap stock-table-wrap">
      <table class="premium-table js-data-table nowrap stock-table" data-title="stok">
        <thead>
          <tr>
            <th>Produk</th>
            <th>Kode</th>
            <th>Kategori</th>
            <th>Supplier</th>
            <th>Stok</th>
            <th>Minimum</th>
            <th>Nilai Modal</th>
            <th>Status</th>
            <th class="text-end">Aksi</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($products as $p):
          $isLow = (int)$p['stock'] <= (int)$p['minimum_stock'];
          $value = (float)$p['stock'] * (float)$p['purchase_price'];
        ?>
          <tr>
            <td class="stock-name-cell">
              <div class="table-title stock-product-title">
                <span class="table-product-img">
                  <?php if(!empty($p['image'])): ?>
                    <img loading="lazy" src="<?= legacy_base_url($p['image']); ?>" alt="produk">
                  <?php else: ?>
                    <i class="bi bi-box-seam"></i>
                  <?php endif; ?>
                </span>
                <div>
                  <b><?= e($p['name']); ?></b>
                  <small class="table-subtext"><?= e($p['unit']); ?> • <?= e($p['barcode'] ?: 'tanpa barcode'); ?></small>
                </div>
              </div>
            </td>
            <td><code><?= e($p['product_code']); ?></code></td>
            <td><?= e($p['category_name'] ?: '-'); ?></td>
            <td><?= e($p['supplier_name'] ?: '-'); ?></td>
            <td><span class="badge-stock <?= $isLow ? 'low' : ''; ?>"><i class="bi bi-box-seam"></i><?= e($p['stock']); ?> <?= e($p['unit']); ?></span></td>
            <td><?= e($p['minimum_stock']); ?> <?= e($p['unit']); ?></td>
            <td><strong><?= rupiah($value); ?></strong></td>
            <td><?= $isLow ? '<span class="badge-soft danger">Menipis</span>' : '<span class="badge-soft success">Aman</span>'; ?></td>
            <td class="text-end table-actions stock-row-actions">
              <a class="btn-icon stock-in" title="Stok Masuk" href="<?= legacy_base_url('admin/stock/stock-in.php?product_id='.$p['id']); ?>"><i class="bi bi-plus-lg"></i></a>
              <a class="btn-icon stock-out" title="Stok Keluar" href="<?= legacy_base_url('admin/stock/stock-out.php?product_id='.$p['id']); ?>"><i class="bi bi-dash-lg"></i></a>
              <a class="btn-icon edit" title="Adjustment" href="<?= legacy_base_url('admin/stock/adjustment.php?product_id='.$p['id']); ?>"><i class="bi bi-sliders"></i></a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
