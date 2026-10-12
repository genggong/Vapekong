<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin', 'owner']);
$pageTitle = 'Backup & Export';

$backupDir = __DIR__ . '/../../../storage/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0775, true);
}

$files = [];
foreach (glob($backupDir . '/*.sql') ?: [] as $file) {
    $files[] = [
        'name' => basename($file),
        'path' => $file,
        'size' => filesize($file),
        'time' => filemtime($file),
    ];
}
usort($files, fn($a, $b) => $b['time'] <=> $a['time']);

function human_size_backup($bytes): string {
    $bytes = (float)$bytes;
    if ($bytes >= 1024 * 1024) return number_format($bytes / 1024 / 1024, 2, ',', '.') . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2, ',', '.') . ' KB';
    return number_format($bytes, 0, ',', '.') . ' B';
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/backup.css?v=' . time()); ?>">

<main class="admin-main backup-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Backup & Export</h1>
            <p>Amankan database dan export data penting toko untuk arsip atau laporan.</p>
        </div>
        <div class="topbar-actions">
            <a href="<?= legacy_base_url('admin/backup/create.php'); ?>" class="backup-btn primary">
                <i class="bi bi-database-down"></i> Backup Database
            </a>
        </div>
    </header>

    <section class="backup-grid">
        <a class="backup-action-card" href="<?= legacy_base_url('admin/backup/create.php'); ?>">
            <div class="backup-icon"><i class="bi bi-database-down"></i></div>
            <h3>Backup Database</h3>
            <p>Simpan semua tabel database ke file SQL agar bisa dipulihkan saat dibutuhkan.</p>
            <span class="backup-btn primary"><i class="bi bi-download"></i> Buat Backup</span>
        </a>

        <a class="backup-action-card" href="<?= legacy_base_url('admin/backup/export-products.php'); ?>">
            <div class="backup-icon"><i class="bi bi-box-seam"></i></div>
            <h3>Export Produk</h3>
            <p>Download data produk, harga, kategori, supplier, barcode, dan stok ke CSV.</p>
            <span class="backup-btn soft"><i class="bi bi-filetype-csv"></i> Export CSV</span>
        </a>

        <a class="backup-action-card" href="<?= legacy_base_url('admin/backup/export-transactions.php'); ?>">
            <div class="backup-icon"><i class="bi bi-receipt-cutoff"></i></div>
            <h3>Export Transaksi</h3>
            <p>Download riwayat transaksi penjualan ke file CSV untuk laporan lanjutan.</p>
            <span class="backup-btn soft"><i class="bi bi-filetype-csv"></i> Export CSV</span>
        </a>
    </section>

    <section class="backup-panel">
        <div class="backup-panel-head">
            <div>
                <h3>File Backup Database</h3>
                <p>Daftar file backup SQL yang tersimpan di folder storage/backups.</p>
            </div>
        </div>

        <div class="backup-table-wrap">
            <table class="backup-table">
                <thead>
                    <tr>
                        <th>File</th>
                        <th>Ukuran</th>
                        <th>Dibuat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($files as $file): ?>
                        <tr>
                            <td>
                                <div class="backup-file">
                                    <div class="backup-file-icon"><i class="bi bi-database"></i></div>
                                    <div>
                                        <b><?= e($file['name']); ?></b>
                                        <small>storage/backups</small>
                                    </div>
                                </div>
                            </td>
                            <td><?= e(human_size_backup($file['size'])); ?></td>
                            <td><?= e(date('d/m/Y H:i', $file['time'])); ?></td>
                            <td>
                                <div class="backup-actions">
                                    <a class="backup-btn soft" href="<?= legacy_base_url('admin/backup/download.php?file=' . urlencode($file['name'])); ?>">
                                        <i class="bi bi-download"></i> Download
                                    </a>
                                    <a class="backup-btn danger btn-delete" href="<?= legacy_base_url('admin/backup/delete.php?file=' . urlencode($file['name'])); ?>">
                                        <i class="bi bi-trash"></i> Hapus
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$files): ?>
                        <tr><td colspan="4" class="backup-empty">Belum ada file backup.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="backup-note">
            <b>Catatan:</b> Simpan file backup di perangkat lain secara berkala. Jangan hanya simpan di hosting/server yang sama.
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
