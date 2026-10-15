<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireLogin();
$pageTitle = 'Detail Retur';
$db = Database::connect();
sales_returns_install($db);

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT sr.*, t.invoice_number, t.payment_method, u.name AS user_name, c.name AS customer_name, c.phone AS customer_phone
    FROM sales_returns sr
    LEFT JOIN transactions t ON t.id = sr.transaction_id
    LEFT JOIN users u ON u.id = sr.user_id
    LEFT JOIN customers c ON c.id = sr.customer_id
    WHERE sr.id = ? LIMIT 1");
$stmt->execute([$id]);
$return = $stmt->fetch();

if (!$return) {
    flash('error', 'Data retur tidak ditemukan.');
    legacy_redirect('admin/returns/index.php');
}

$stmt = $db->prepare("SELECT * FROM sales_return_items WHERE return_id = ? ORDER BY id ASC");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/returns.css?v=' . time()); ?>">

<main class="admin-main returns-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Detail Retur</h1>
            <p><?= e($return['return_number']); ?> • Invoice <?= e($return['invoice_number'] ?? '-'); ?></p>
        </div>
        <div class="topbar-actions">
            <a href="<?= legacy_base_url('admin/returns/index.php'); ?>" class="btn-soft"><i class="bi bi-arrow-left"></i> Kembali</a>
            <a href="<?= legacy_base_url('admin/returns/print.php?id=' . $return['id']); ?>" target="_blank" class="btn-premium"><i class="bi bi-printer"></i> Cetak Bukti</a>
        </div>
    </header>

    <section class="returns-detail-card">
        <div class="returns-card-head">
            <div>
                <h3><?= e($return['return_number']); ?></h3>
                <p>Detail retur dan refund penjualan.</p>
            </div>
            <?php if ($return['status'] === 'completed'): ?>
                <span class="return-badge green">Selesai</span>
            <?php else: ?>
                <span class="return-badge red">Dibatalkan</span>
            <?php endif; ?>
        </div>

        <div class="return-selected-transaction">
            <div class="return-meta-box"><span>Invoice</span><b><?= e($return['invoice_number'] ?? '-'); ?></b></div>
            <div class="return-meta-box"><span>Tanggal Retur</span><b><?= e(date('d/m/Y H:i', strtotime($return['return_date']))); ?></b></div>
            <div class="return-meta-box"><span>Pelanggan</span><b><?= e($return['customer_name'] ?? '-'); ?></b></div>
            <div class="return-meta-box"><span>Admin</span><b><?= e($return['user_name'] ?? '-'); ?></b></div>
        </div>

        <div class="return-selected-transaction">
            <div class="return-meta-box"><span>Tipe</span><b><?= e(strtoupper(str_replace('_', ' ', $return['type']))); ?></b></div>
            <div class="return-meta-box"><span>Metode Bayar Awal</span><b><?= e(payment_method_label($return['payment_method'] ?? '-')); ?></b></div>
            <div class="return-meta-box"><span>Alasan</span><b><?= e($return['reason'] ?: '-'); ?></b></div>
            <div class="return-meta-box"><span>Total Refund</span><b><?= rupiah($return['total_refund']); ?></b></div>
        </div>

        <?php if (!empty($return['note'])): ?>
            <div class="return-alert"><i class="bi bi-journal-text"></i> <?= e($return['note']); ?></div>
        <?php endif; ?>

        <div class="returns-table-wrap">
            <table class="returns-table">
                <thead>
                    <tr>
                        <th>Produk</th>
                        <th>Qty</th>
                        <th>Harga</th>
                        <th>Subtotal</th>
                        <th>Restock</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><div class="return-product"><b><?= e($item['product_name']); ?></b><small>Item transaksi #<?= e($item['transaction_item_id']); ?></small></div></td>
                            <td><b><?= e($item['qty']); ?></b></td>
                            <td><?= rupiah($item['unit_price']); ?></td>
                            <td><b><?= rupiah($item['subtotal']); ?></b></td>
                            <td><?= (int)$item['restock'] === 1 ? '<span class="return-badge green">Masuk stok</span>' : '<span class="return-badge gray">Tidak</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="return-total-box">
            <span>Total nilai retur/refund</span>
            <b><?= rupiah($return['total_refund']); ?></b>
        </div>

        <div class="return-form-actions">
            <a class="return-btn soft" href="<?= legacy_base_url('admin/transactions/detail.php?id=' . $return['transaction_id']); ?>"><i class="bi bi-receipt"></i> Detail Transaksi</a>
            <a class="return-btn primary" href="<?= legacy_base_url('admin/returns/print.php?id=' . $return['id']); ?>" target="_blank"><i class="bi bi-printer"></i> Cetak Bukti</a>
            <?php if ($return['status'] === 'completed'): ?>
                <a class="return-btn danger btn-delete" href="<?= legacy_base_url('admin/returns/cancel.php?id=' . $return['id']); ?>"><i class="bi bi-x-circle"></i> Batalkan Retur</a>
            <?php endif; ?>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
