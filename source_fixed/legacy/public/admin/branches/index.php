<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireRole(['super_admin','owner']);
$pageTitle = 'Multi Cabang';
$db = Database::connect();
branch_install($db);
$currentBranch = branch_current($db);
$currentBranchId = (int) $currentBranch['id'];

$normalizeDate = static function ($value): string {
    $value = trim((string) $value);
    if ($value === '') return '';
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $value : '';
};

$omzetFrom = $normalizeDate($_GET['omzet_from'] ?? '');
$omzetTo = $normalizeDate($_GET['omzet_to'] ?? '');
if ($omzetFrom !== '' && $omzetTo !== '' && $omzetFrom > $omzetTo) {
    [$omzetFrom, $omzetTo] = [$omzetTo, $omzetFrom];
}

$trxWhere = ["status = 'success'"];
$trxParams = [];
if ($omzetFrom !== '') {
    $trxWhere[] = 'DATE(COALESCE(transaction_date, created_at)) >= ?';
    $trxParams[] = $omzetFrom;
}
if ($omzetTo !== '') {
    $trxWhere[] = 'DATE(COALESCE(transaction_date, created_at)) <= ?';
    $trxParams[] = $omzetTo;
}

// Stok dan transaksi WAJIB diagregasi terpisah. Jika langsung JOIN product_branch_stocks
// dengan transactions, jumlah stok/modal/omzet akan terduplikasi (fan-out join).
$sql = "
    SELECT
        b.*,
        COALESCE(bs.total_products, 0) AS total_products,
        COALESCE(bs.total_stock, 0) AS total_stock,
        COALESCE(bs.stock_value, 0) AS stock_value,
        COALESCE(tx.trx_count, 0) AS trx_count,
        COALESCE(tx.omzet, 0) AS omzet
    FROM branches b
    LEFT JOIN (
        SELECT
            pbs.branch_id,
            COUNT(DISTINCT pbs.product_id) AS total_products,
            COALESCE(SUM(pbs.stock), 0) AS total_stock,
            COALESCE(SUM(pbs.stock * COALESCE(p.purchase_price, 0)), 0) AS stock_value
        FROM product_branch_stocks pbs
        LEFT JOIN products p ON p.id = pbs.product_id
        GROUP BY pbs.branch_id
    ) bs ON bs.branch_id = b.id
    LEFT JOIN (
        SELECT
            branch_id,
            COUNT(*) AS trx_count,
            COALESCE(SUM(grand_total), 0) AS omzet
        FROM transactions
        WHERE " . implode(' AND ', $trxWhere) . "
        GROUP BY branch_id
    ) tx ON tx.branch_id = b.id
    ORDER BY b.is_main DESC, b.name ASC
";
$stmtBranches = $db->prepare($sql);
$stmtBranches->execute($trxParams);
$branches = $stmtBranches->fetchAll();

$totalBranch = count($branches);
$totalStock = array_sum(array_map(static fn($b) => (int) $b['total_stock'], $branches));
$totalStockValue = array_sum(array_map(static fn($b) => (float) $b['stock_value'], $branches));
$totalOmzet = array_sum(array_map(static fn($b) => (float) $b['omzet'], $branches));
$totalOmzetTrx = array_sum(array_map(static fn($b) => (int) $b['trx_count'], $branches));

if ($omzetFrom !== '' && $omzetTo !== '') {
    $omzetPeriodLabel = date('d/m/Y', strtotime($omzetFrom)) . ' – ' . date('d/m/Y', strtotime($omzetTo));
} elseif ($omzetFrom !== '') {
    $omzetPeriodLabel = 'Mulai ' . date('d/m/Y', strtotime($omzetFrom));
} elseif ($omzetTo !== '') {
    $omzetPeriodLabel = 'Sampai ' . date('d/m/Y', strtotime($omzetTo));
} else {
    $omzetPeriodLabel = 'Semua waktu';
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">
<style>
.branch-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin:18px 0 24px}.branch-card{background:#fff;border:1px solid #fee2e2;border-radius:24px;padding:20px;box-shadow:0 16px 45px rgba(153,27,27,.08)}.branch-card .icon{width:48px;height:48px;border-radius:18px;background:linear-gradient(135deg,#ef4444,#991b1b);color:#fff;display:flex;align-items:center;justify-content:center;font-size:22px;margin-bottom:12px}.branch-card span{color:#64748b;font-weight:700}.branch-card h3{margin:6px 0 0;color:#7f1d1d}.branch-card small{display:block;margin-top:6px;color:#64748b;line-height:1.4}.branch-table-wrap{overflow:auto}.branch-actions{display:flex;gap:8px;flex-wrap:wrap}.branch-badge{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:7px 12px;font-size:12px;font-weight:800}.branch-badge.main{background:#fee2e2;color:#991b1b}.branch-badge.active{background:#dcfce7;color:#166534}.branch-badge.off{background:#f1f5f9;color:#475569}.branch-active-row{outline:3px solid rgba(153,27,27,.16);background:#fff7f7}.branch-active-label{display:inline-flex;align-items:center;gap:6px;margin-top:6px;border-radius:999px;background:#991b1b;color:#fff;padding:6px 10px;font-size:12px;font-weight:900}
.branch-omzet-filter{margin:22px 0 0;padding:16px 18px;border:1px solid rgba(153,27,27,.12);border-radius:22px;background:linear-gradient(180deg,#fff,#fffafa);display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}.branch-omzet-copy{display:flex;gap:11px;align-items:flex-start;min-width:240px}.branch-omzet-copy .filter-icon{width:44px;height:44px;border-radius:15px;background:#fff1f2;color:#991b1b;display:flex;align-items:center;justify-content:center;font-size:20px;flex:0 0 auto}.branch-omzet-copy b{display:block;color:#7f1d1d;font-size:15px}.branch-omzet-copy small{display:block;color:#64748b;margin-top:3px;line-height:1.4}.branch-omzet-form{display:grid;grid-template-columns:repeat(2,minmax(145px,1fr)) auto;gap:9px;align-items:end;flex:1;max-width:720px}.branch-filter-field{display:grid;gap:5px}.branch-filter-field label{font-size:11px;font-weight:900;color:#7f1d1d}.branch-filter-field input{width:100%;border:1px solid #fecaca;border-radius:14px;background:#fff;padding:10px 12px;min-height:44px;font-weight:800;color:#111827;outline:0}.branch-filter-field input:focus{border-color:#a855f7;box-shadow:0 0 0 4px rgba(168,85,247,.1)}.branch-filter-actions{display:flex;gap:7px}.branch-filter-actions .system-btn{min-height:44px;white-space:nowrap}.branch-period-pill{display:inline-flex;align-items:center;gap:6px;margin-top:6px;padding:5px 9px;border-radius:999px;background:#fff1f2;color:#991b1b;font-size:11px;font-weight:900}
@media(max-width:900px){.branch-grid{grid-template-columns:1fr 1fr}.branch-omzet-form{max-width:none;width:100%}}
@media(max-width:620px){.branch-grid{grid-template-columns:1fr}.branch-omzet-filter{padding:14px}.branch-omzet-copy{width:100%}.branch-omzet-form{grid-template-columns:1fr 1fr}.branch-filter-actions{grid-column:1 / -1}.branch-filter-actions .system-btn{flex:1;justify-content:center}}
@media(max-width:430px){.branch-omzet-form{grid-template-columns:1fr}.branch-filter-actions{grid-column:auto;display:grid;grid-template-columns:1fr 1fr}}
</style>
<main class="admin-main system-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title"><h1>Multi Cabang</h1><p>Cabang aktif sekarang: <b><?= e($currentBranch['name'] ?? '-'); ?></b>. Klik <b>Pakai</b> untuk mengganti cabang aktif admin/owner.</p></div>
        <div class="topbar-actions"><a href="<?= legacy_base_url('admin/branches/transfer-stock.php'); ?>" class="system-btn soft"><i class="bi bi-arrow-left-right"></i> Transfer Stok</a><a href="<?= legacy_base_url('admin/branches/create.php'); ?>" class="system-btn primary"><i class="bi bi-plus-circle"></i> Tambah Cabang</a></div>
    </header>

    <section class="branch-omzet-filter">
        <div class="branch-omzet-copy">
            <div class="filter-icon"><i class="bi bi-calendar-range"></i></div>
            <div>
                <b>Filter Omzet Multi Cabang</b>
                <small>Filter ini hanya memengaruhi Omzet Sukses dan jumlah invoice. Total stok dan nilai modal tetap menampilkan kondisi stok saat ini.</small>
                <span class="branch-period-pill"><i class="bi bi-calendar-check"></i> <?= e($omzetPeriodLabel); ?></span>
            </div>
        </div>
        <form method="GET" class="branch-omzet-form">
            <div class="branch-filter-field"><label for="omzetFrom">Dari Tanggal</label><input type="date" id="omzetFrom" name="omzet_from" value="<?= e($omzetFrom); ?>"></div>
            <div class="branch-filter-field"><label for="omzetTo">Sampai Tanggal</label><input type="date" id="omzetTo" name="omzet_to" value="<?= e($omzetTo); ?>"></div>
            <div class="branch-filter-actions"><button type="submit" class="system-btn primary"><i class="bi bi-funnel"></i> Terapkan</button><a href="<?= legacy_base_url('admin/branches/index.php'); ?>" class="system-btn soft"><i class="bi bi-x-circle"></i> Reset</a></div>
        </form>
    </section>

    <section class="branch-grid">
        <div class="branch-card"><div class="icon"><i class="bi bi-shop"></i></div><span>Total Cabang</span><h3><?= e($totalBranch); ?></h3><small>Seluruh cabang terdaftar</small></div>
        <div class="branch-card"><div class="icon"><i class="bi bi-boxes"></i></div><span>Total Stok</span><h3><?= e($totalStock); ?></h3><small>Stok aktual seluruh cabang</small></div>
        <div class="branch-card"><div class="icon"><i class="bi bi-wallet2"></i></div><span>Nilai Modal Stok</span><h3><?= rupiah($totalStockValue); ?></h3><small>Modal stok aktual, tidak dipengaruhi filter tanggal</small></div>
        <div class="branch-card"><div class="icon"><i class="bi bi-cash-stack"></i></div><span>Omzet Sukses</span><h3><?= rupiah($totalOmzet); ?></h3><small><?= e($totalOmzetTrx); ?> invoice • <?= e($omzetPeriodLabel); ?></small></div>
    </section>

    <section class="system-form-card">
        <div class="system-card-head"><div><h3>Data Cabang</h3><p>Omzet per cabang mengikuti filter periode di atas. Cabang utama memakai stok awal produk lama; cabang baru mulai dari stok 0 dan bisa diisi lewat transfer stok.</p></div></div>
        <div class="branch-table-wrap">
            <table class="premium-table" data-title="multi-cabang">
                <thead><tr><th>Cabang</th><th>Kontak</th><th>Produk</th><th>Total Stok</th><th>Nilai Modal</th><th>Omzet</th><th>Status</th><th class="text-end no-sort">Aksi</th></tr></thead>
                <tbody>
                    <?php foreach($branches as $b): ?>
                    <tr class="<?= (int)$b['id'] === $currentBranchId ? 'branch-active-row' : ''; ?>">
                        <td><b><?= e($b['name']); ?></b><br><small><?= e($b['code']); ?><?= (int)$b['is_main'] ? ' • Cabang Utama' : ''; ?></small><?php if ((int)$b['id'] === $currentBranchId): ?><span class="branch-active-label"><i class="bi bi-check-circle-fill"></i> Sedang Dipakai</span><?php endif; ?></td>
                        <td><?= e($b['phone'] ?: '-'); ?><br><small><?= e($b['address'] ?: '-'); ?></small></td>
                        <td><?= e($b['total_products']); ?></td>
                        <td><b><?= e($b['total_stock']); ?></b></td>
                        <td><?= rupiah($b['stock_value']); ?></td>
                        <td data-order="<?= e((float)$b['omzet']); ?>"><?= rupiah($b['omzet']); ?><br><small><?= e($b['trx_count']); ?> invoice • <?= e($omzetPeriodLabel); ?></small></td>
                        <td><?= (int)$b['is_main'] ? '<span class="branch-badge main"><i class="bi bi-star-fill"></i> Utama</span>' : ''; ?> <?= (int)$b['is_active'] ? '<span class="branch-badge active">Aktif</span>' : '<span class="branch-badge off">Nonaktif</span>'; ?></td>
                        <td class="text-end"><div class="branch-actions"><a class="system-btn soft" href="<?= legacy_base_url('admin/branches/switch.php?id=' . $b['id'] . '&back=admin/branches/index.php'); ?>">Pakai</a><a class="system-btn soft" href="<?= legacy_base_url('admin/branches/edit.php?id=' . $b['id']); ?>">Edit</a><?php if (!(int)$b['is_main']): ?><a class="system-btn danger" onclick="return confirm('Nonaktifkan cabang ini?')" href="<?= legacy_base_url('admin/branches/delete.php?id=' . $b['id']); ?>">Nonaktif</a><?php endif; ?></div></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
