<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/Marketplace/Marketplace.php';
Auth::requireLogin();

$db = Database::connect();
marketplace_install($db);
$pageTitle = 'Ulasan Marketplace';
$tableReady = marketplace_table_exists($db, 'marketplace_reviews');
$schemaWarning = $tableReady ? '' : 'Tabel ulasan belum dapat dibuat pada database. Patch Repair V15.1 memperbaiki tipe product_id agar kompatibel dengan products.id POS.';

if (is_post() && CSRF::verify()) {
    if (!$tableReady) {
        flash('error', 'Tabel ulasan belum tersedia. Overwrite patch Repair V15.1 lalu muat ulang halaman.');
        legacy_redirect('admin/marketplace-reviews/index.php');
    }
    $id = (int) ($_POST['id'] ?? 0);
    $status = (string) ($_POST['status'] ?? 'pending');
    if ($id > 0 && in_array($status, ['approved','pending','rejected'], true)) {
        $db->prepare("UPDATE marketplace_reviews SET status=?,updated_at=NOW() WHERE id=?")->execute([$status,$id]);
        flash('success', 'Status ulasan diperbarui.');
    }
    legacy_redirect('admin/marketplace-reviews/index.php');
}

$filter = (string) ($_GET['status'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 20;
$rows = [];
$meta = pagination_meta(0, $page, $per);

if ($tableReady) {
    try {
        $where = '1=1';
        $params = [];
        if (in_array($filter, ['pending','approved','rejected'], true)) {
            $where = 'r.status=?';
            $params[] = $filter;
        }
        $ct = $db->prepare("SELECT COUNT(*) FROM marketplace_reviews r WHERE $where");
        $ct->execute($params);
        $meta = pagination_meta((int) $ct->fetchColumn(), $page, $per);
        $stmt = $db->prepare("SELECT r.*,c.name customer_name,p.name product_name,o.order_number FROM marketplace_reviews r JOIN customers c ON c.id=r.customer_id JOIN products p ON p.id=r.product_id LEFT JOIN online_orders o ON o.id=r.order_id WHERE $where ORDER BY r.id DESC LIMIT {$meta['perPage']} OFFSET {$meta['offset']}");
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        $schemaWarning = 'Struktur ulasan belum kompatibel sepenuhnya. Halaman dibuat aman agar tidak lagi menghasilkan HTTP 500.';
        $rows = [];
    }
}

$success = flash('success');
$error = flash('error');
require __DIR__ . '/../../layouts/admin-header.php';
require __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/marketplace-customer-admin.css?v=15.1') ?>">
<main class="admin-main mp-admin">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title"><h1>Ulasan Marketplace</h1><p>Moderasi review pembelian terverifikasi sebelum tampil ke publik.</p></div>
    </header>
    <?php if ($success): ?><div class="mp-alert success"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="mp-alert error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($schemaWarning): ?><div class="mp-alert error"><?= e($schemaWarning) ?></div><?php endif; ?>

    <section class="mp-panel">
        <div class="mp-panel-head">
            <div><h3>Review Produk</h3><p>Filter dan setujui ulasan pelanggan.</p></div>
            <div class="mp-filter">
                <a class="<?= !$filter ? 'active' : '' ?>" href="?">Semua</a>
                <a class="<?= $filter === 'pending' ? 'active' : '' ?>" href="?status=pending">Menunggu</a>
                <a class="<?= $filter === 'approved' ? 'active' : '' ?>" href="?status=approved">Tayang</a>
                <a class="<?= $filter === 'rejected' ? 'active' : '' ?>" href="?status=rejected">Ditolak</a>
            </div>
        </div>
        <div class="mp-review-list">
            <?php if ($rows): foreach ($rows as $r): ?>
                <article class="mp-review-card">
                    <div class="mp-review-rating"><?= str_repeat('★',(int)$r['rating']) . str_repeat('☆',5-(int)$r['rating']) ?></div>
                    <div class="mp-review-copy">
                        <div><b><?= e($r['product_name']) ?></b><span class="mp-badge <?= $r['status']==='approved'?'green':($r['status']==='rejected'?'red':'orange') ?>"><?= e(strtoupper($r['status'])) ?></span></div>
                        <h4><?= e($r['title'] ?: 'Ulasan Produk') ?></h4>
                        <p><?= nl2br(e($r['review'])) ?></p>
                        <small><?= e($r['customer_name']) ?> • <?= e($r['order_number'] ?: '-') ?> • <?= e(date('d M Y H:i', strtotime($r['created_at']))) ?></small>
                    </div>
                    <form method="post" class="mp-review-actions"><?= CSRF::input() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button name="status" value="approved" class="mp-btn success" title="Tayangkan"><i class="bi bi-check-lg"></i></button><button name="status" value="pending" class="mp-btn soft" title="Pending"><i class="bi bi-clock"></i></button><button name="status" value="rejected" class="mp-btn danger" title="Tolak"><i class="bi bi-x-lg"></i></button></form>
                </article>
            <?php endforeach; else: ?>
                <div class="empty"><?= $tableReady ? 'Belum ada ulasan pada filter ini.' : 'Struktur ulasan sedang diperbaiki. Setelah patch terpasang, tabel akan dibuat otomatis saat halaman dibuka.' ?></div>
            <?php endif; ?>
        </div>
        <?php if ($meta['lastPage'] > 1): ?><nav class="mp-pagination"><?php for($i=1;$i<=$meta['lastPage'];$i++):?><a class="<?= $i===$meta['page']?'active':'' ?>" href="?status=<?= urlencode($filter) ?>&page=<?= $i ?>"><?= $i ?></a><?php endfor;?></nav><?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/../../layouts/admin-footer.php'; ?>
