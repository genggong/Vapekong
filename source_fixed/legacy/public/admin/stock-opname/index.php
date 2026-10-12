<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireRole(['super_admin','owner']);
$pageTitle = 'Stock Opname';
$db = Database::connect();
opname_ensure_tables($db);

$status = $_GET['status'] ?? '';
$where = '';
$params = [];
if (in_array($status, ['draft','finalized','canceled'], true)) {
    $where = 'WHERE so.status = ?';
    $params[] = $status;
}
$stmt = $db->prepare("SELECT so.*, u.name AS user_name FROM stock_opnames so LEFT JOIN users u ON u.id = so.user_id {$where} ORDER BY so.id DESC LIMIT 300");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$summary = [
    'sessions' => (int) $db->query("SELECT COUNT(*) FROM stock_opnames")->fetchColumn(),
    'draft' => (int) $db->query("SELECT COUNT(*) FROM stock_opnames WHERE status='draft'")->fetchColumn(),
    'finalized' => (int) $db->query("SELECT COUNT(*) FROM stock_opnames WHERE status='finalized'")->fetchColumn(),
    'selisih' => (int) $db->query("SELECT COALESCE(SUM(total_plus + total_minus),0) FROM stock_opnames WHERE status='finalized'")->fetchColumn(),
    'plus_value' => (float) $db->query("SELECT COALESCE(SUM(total_plus_value),0) FROM stock_opnames WHERE status='finalized'")->fetchColumn(),
    'minus_value' => (float) $db->query("SELECT COALESCE(SUM(total_minus_value),0) FROM stock_opnames WHERE status='finalized'")->fetchColumn(),
];

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/stock-opname.css?v=' . time()); ?>">
<main class="admin-main stock-opname-page">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title"><h1>Stock Opname</h1><p>Audit stok fisik, cek selisih, lalu sesuaikan stok sistem dengan aman.</p></div>
        <div class="topbar-actions">
            <a href="<?= legacy_base_url('admin/stock-opname/template.php'); ?>" class="opname-btn soft"><i class="bi bi-file-earmark-excel"></i> Template Excel</a>
            <a href="<?= legacy_base_url('admin/stock-opname/import.php'); ?>" class="opname-btn soft"><i class="bi bi-upload"></i> Upload Excel</a>
            <a href="<?= legacy_base_url('admin/stock-opname/create.php'); ?>" class="opname-btn primary"><i class="bi bi-plus-circle"></i> Buat Opname</a>
        </div>
    </header>

    <section class="opname-grid">
        <div class="opname-stat"><div class="icon"><i class="bi bi-clipboard-data"></i></div><span>Total Sesi</span><h3><?= e($summary['sessions']); ?></h3></div>
        <div class="opname-stat"><div class="icon"><i class="bi bi-pencil-square"></i></div><span>Draft</span><h3><?= e($summary['draft']); ?></h3></div>
        <div class="opname-stat"><div class="icon"><i class="bi bi-check-circle"></i></div><span>Final</span><h3><?= e($summary['finalized']); ?></h3></div>
        <div class="opname-stat"><div class="icon"><i class="bi bi-arrow-left-right"></i></div><span>Total Selisih</span><h3><?= e($summary['selisih']); ?></h3><small>+<?= rupiah($summary['plus_value']); ?> / -<?= rupiah($summary['minus_value']); ?></small></div>
    </section>

    <section class="opname-card">
        <div class="opname-card-head">
            <div><h3>Riwayat Stock Opname</h3><p>Draft belum mengubah stok. Stok baru berubah setelah sesi difinalisasi.</p></div>
            <div class="opname-actions">
                <a class="opname-btn soft" href="<?= legacy_base_url('admin/stock-opname/index.php'); ?>">Semua</a>
                <a class="opname-btn soft" href="<?= legacy_base_url('admin/stock-opname/index.php?status=draft'); ?>">Draft</a>
                <a class="opname-btn soft" href="<?= legacy_base_url('admin/stock-opname/index.php?status=finalized'); ?>">Final</a>
            </div>
        </div>
        <div class="opname-table-wrap">
            <table class="opname-table">
                <thead><tr><th>Kode</th><th>Judul</th><th>Status</th><th>Progress</th><th>Selisih</th><th>Petugas</th><th>Tanggal</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row):
                    $progress = (int)$row['total_items'] > 0 ? round(((int)$row['total_checked'] / (int)$row['total_items']) * 100) : 0;
                ?>
                    <tr>
                        <td><b><?= e($row['code']); ?></b></td>
                        <td><b><?= e($row['title']); ?></b><br><small><?= e($row['note'] ?: '-'); ?></small></td>
                        <td><?= opname_status_badge($row['status']); ?></td>
                        <td><b><?= e($row['total_checked']); ?>/<?= e($row['total_items']); ?></b><div class="opname-progress"><span style="width:<?= e($progress); ?>%"></span></div></td>
                        <td><span class="diff-plus">+<?= e($row['total_plus']); ?> / <?= rupiah($row['total_plus_value'] ?? 0); ?></span><br><span class="diff-minus">-<?= e($row['total_minus']); ?> / <?= rupiah($row['total_minus_value'] ?? 0); ?></span></td>
                        <td><?= e($row['user_name'] ?: '-'); ?></td>
                        <td><?= e(date('d/m/Y H:i', strtotime($row['created_at']))); ?></td>
                        <td>
                            <div class="opname-actions">
                                <?php if ($row['status'] === 'draft'): ?><a class="opname-btn soft" href="<?= legacy_base_url('admin/stock-opname/edit.php?id=' . $row['id']); ?>"><i class="bi bi-pencil"></i> Input</a><?php endif; ?>
                                <a class="opname-btn soft" href="<?= legacy_base_url('admin/stock-opname/detail.php?id=' . $row['id']); ?>"><i class="bi bi-eye"></i> Detail</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="8" class="opname-empty">Belum ada sesi opname.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
