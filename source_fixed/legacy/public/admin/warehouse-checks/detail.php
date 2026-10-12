<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
whqc_require_view();
$pageTitle = 'Detail Cek Gudang';
$db = Database::connect();
whqc_ensure_tables($db);

$itemId = (int)($_GET['item_id'] ?? 0);
$row = whqc_fetch_one($db, $itemId);
if (!$row) {
    flash('error', 'Data barang SO tidak ditemukan atau sesi SO belum final.');
    legacy_redirect('admin/warehouse-checks/index.php');
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/warehouse-qc.css?v=' . time()); ?>">
<main class="admin-main whqc-page">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Detail Cek Gudang</h1>
            <p><?= e($row['opname_code']); ?> • <?= e($row['product_name']); ?></p>
        </div>
        <div class="topbar-actions"><a class="whqc-btn soft" href="<?= legacy_base_url('admin/warehouse-checks/index.php'); ?>"><i class="bi bi-arrow-left"></i> Kembali</a></div>
    </header>

    <section class="whqc-detail-grid">
        <div class="whqc-card">
            <div class="whqc-product big">
                <span class="whqc-img big">
                    <?php if(!empty($row['image'])): ?><img loading="lazy" src="<?= legacy_base_url($row['image']); ?>" alt="produk"><?php else: ?><i class="bi bi-box-seam"></i><?php endif; ?>
                </span>
                <div>
                    <h3><?= e($row['product_name']); ?></h3>
                    <p><?= e($row['product_code'] ?: '-'); ?> • <?= e($row['unit'] ?: 'pcs'); ?></p>
                    <?= whqc_status_badge($row); ?>
                </div>
            </div>

            <div class="whqc-info-grid">
                <div><span>Kode SO</span><b><?= e($row['opname_code']); ?></b></div>
                <div><span>Nama SO</span><b><?= e($row['opname_title']); ?></b></div>
                <div><span>Stok Sistem</span><b><?= e($row['system_stock']); ?></b></div>
                <div><span>Stok Fisik</span><b><?= $row['physical_stock'] !== null ? e($row['physical_stock']) : '-'; ?></b></div>
                <div><span>Selisih</span><b><?= e($row['difference']); ?></b></div>
                <div><span>Nilai Selisih</span><b><?= rupiah($row['difference_value'] ?? 0); ?></b></div>
            </div>

            <?php if (!empty($row['opname_item_note'])): ?>
                <div class="whqc-note"><b>Catatan SO:</b> <?= e($row['opname_item_note']); ?></div>
            <?php endif; ?>
        </div>

        <div class="whqc-card">
            <h3>Checklist Bertahap</h3>
            <div class="whqc-step <?= !empty($row['operator_checked_at']) ? 'done' : ''; ?>">
                <div class="num">1</div>
                <div><b>Operator Gudang</b><p><?= !empty($row['operator_checked_at']) ? 'Dicek oleh ' . e($row['operator_name'] ?: '-') . ' • ' . e(date('d/m/Y H:i', strtotime($row['operator_checked_at']))) : 'Belum dicek operator.'; ?></p></div>
            </div>
            <div class="whqc-step <?= !empty($row['admin_checked_at']) ? 'done' : ''; ?>">
                <div class="num">2</div>
                <div><b>Admin Gudang</b><p><?= !empty($row['admin_checked_at']) ? 'Disetujui oleh ' . e($row['admin_name'] ?: '-') . ' • ' . e(date('d/m/Y H:i', strtotime($row['admin_checked_at']))) : 'Menunggu admin gudang.'; ?></p></div>
            </div>
            <div class="whqc-step <?= ($row['qc_status'] ?? '') === 'approved' ? 'done' : (($row['qc_status'] ?? '') === 'rejected' ? 'reject' : ''); ?>">
                <div class="num">3</div>
                <div><b>Quality Control</b><p><?= whqc_qc_badge($row['qc_status'] ?? 'pending'); ?> <?= !empty($row['qc_checked_at']) ? ' • ' . e(date('d/m/Y H:i', strtotime($row['qc_checked_at']))) : ''; ?></p></div>
            </div>
        </div>
    </section>

    <section class="whqc-card">
        <h3>Aksi Sesuai Role</h3>
        <form method="POST" action="<?= legacy_base_url('admin/warehouse-checks/action.php'); ?>" class="whqc-action-form">
            <?= CSRF::input(); ?>
            <input type="hidden" name="item_id" value="<?= e($row['item_id']); ?>">

            <?php if (whqc_can_operator() && empty($row['operator_checked_at'])): ?>
                <div class="whqc-field">
                    <label>Catatan Operator Gudang</label>
                    <textarea name="operator_note" placeholder="Contoh: barang sudah dicek fisik di gudang"></textarea>
                </div>
                <button name="action" value="operator_check" class="whqc-btn primary"><i class="bi bi-check2-square"></i> Tandai Operator Checked</button>
            <?php endif; ?>

            <?php if (whqc_can_admin() && !empty($row['operator_checked_at']) && empty($row['admin_checked_at'])): ?>
                <div class="whqc-field">
                    <label>Catatan Admin Gudang</label>
                    <textarea name="admin_note" placeholder="Catatan verifikasi admin gudang"></textarea>
                </div>
                <button name="action" value="admin_check" class="whqc-btn info"><i class="bi bi-shield-check"></i> Setujui Admin Gudang</button>
            <?php endif; ?>

            <?php if (whqc_can_qc() && !empty($row['admin_checked_at']) && ($row['qc_status'] ?? 'pending') === 'pending'): ?>
                <div class="whqc-field">
                    <label>Catatan Quality Control</label>
                    <textarea name="qc_note" placeholder="Catatan hasil QC"></textarea>
                </div>
                <div class="whqc-actions">
                    <button name="action" value="qc_approve" class="whqc-btn success"><i class="bi bi-patch-check"></i> QC Approve</button>
                    <button name="action" value="qc_reject" class="whqc-btn danger" onclick="return confirm('Reject hasil cek barang ini?');"><i class="bi bi-x-circle"></i> QC Reject</button>
                </div>
            <?php endif; ?>

            <?php if ((!whqc_can_operator() || !empty($row['operator_checked_at'])) && (!whqc_can_admin() || empty($row['operator_checked_at']) || !empty($row['admin_checked_at'])) && (!whqc_can_qc() || empty($row['admin_checked_at']) || ($row['qc_status'] ?? 'pending') !== 'pending')): ?>
                <div class="whqc-empty-action"><i class="bi bi-info-circle"></i> Tidak ada aksi yang tersedia untuk role/status saat ini.</div>
            <?php endif; ?>
        </form>
    </section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
