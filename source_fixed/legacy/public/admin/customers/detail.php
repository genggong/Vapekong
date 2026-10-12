<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
$pageTitle = 'Detail Pelanggan';
$db = Database::connect();

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM customers WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$customer = $stmt->fetch();

if (!$customer) {
    flash('error', 'Pelanggan tidak ditemukan.');
    legacy_redirect('admin/customers/index.php');
}

$transactions = [];
try {
    $stmt = $db->prepare("SELECT * FROM transactions WHERE customer_id=? ORDER BY id DESC LIMIT 50");
    $stmt->execute([$id]);
    $transactions = $stmt->fetchAll();
} catch (Throwable $e) {
    $transactions = [];
}

$totalSales = array_sum(array_map(fn($t) => (float)($t['grand_total'] ?? 0), $transactions));

$pointHistory = [];
try {
    $stmt = $db->prepare("SELECT cp.*, t.invoice_number FROM customer_points cp LEFT JOIN transactions t ON t.id = cp.transaction_id WHERE cp.customer_id = ? ORDER BY cp.id DESC LIMIT 100");
    $stmt->execute([$id]);
    $pointHistory = $stmt->fetchAll();
} catch (Throwable $e) {
    $pointHistory = [];
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/customers.css?v=' . time()); ?>">

<main class="admin-main customer-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Detail Pelanggan</h1>
            <p>Profil, poin, limit piutang, dan histori transaksi pelanggan.</p>
        </div>
        <div class="topbar-actions">
            <a href="<?= legacy_base_url('admin/customers/edit.php?id=' . $customer['id']); ?>" class="customer-btn primary"><i class="bi bi-pencil"></i> Edit</a>
        </div>
    </header>

    <section class="customer-grid">
        <div class="customer-card"><div class="icon"><i class="bi bi-person"></i></div><span>Nama</span><h3><?= e($customer['name']); ?></h3><p><?= e($customer['phone'] ?: '-'); ?></p></div>
        <div class="customer-card"><div class="icon"><i class="bi bi-star"></i></div><span>Poin</span><h3><?= e($customer['points']); ?></h3><p>Member <?= e(strtoupper($customer['member_level'])); ?></p></div>
        <div class="customer-card"><div class="icon"><i class="bi bi-wallet2"></i></div><span>Limit Piutang</span><h3><?= rupiah($customer['credit_limit']); ?></h3><p>Batas hutang pelanggan</p></div>
        <div class="customer-card"><div class="icon"><i class="bi bi-cash"></i></div><span>Total Belanja</span><h3><?= rupiah($totalSales); ?></h3><p>Dari histori transaksi</p></div>
    </section>

    <section class="customer-panel">
        <div class="customer-panel-head">
            <div>
                <h3>Riwayat Poin Member</h3>
                <p>Catatan poin masuk dari belanja dan poin keluar dari penukaran diskon POS.</p>
            </div>
        </div>

        <div class="customer-table-wrap">
            <table class="customer-table">
                <thead><tr><th>Tanggal</th><th>Invoice</th><th>Tipe</th><th>Poin</th><th>Keterangan</th></tr></thead>
                <tbody>
                <?php foreach ($pointHistory as $poin): ?>
                    <tr>
                        <td><?= e(date('d/m/Y H:i', strtotime($poin['created_at']))); ?></td>
                        <td><b><?= e($poin['invoice_number'] ?? '-'); ?></b></td>
                        <td>
                            <?php if (($poin['type'] ?? '') === 'in'): ?>
                                <span class="customer-badge green">Masuk</span>
                            <?php else: ?>
                                <span class="customer-badge red">Keluar</span>
                            <?php endif; ?>
                        </td>
                        <td><b><?= (($poin['type'] ?? '') === 'in' ? '+' : '-') . e((int) $poin['points']); ?></b></td>
                        <td><?= e($poin['description'] ?? '-'); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$pointHistory): ?>
                    <tr><td colspan="5" class="text-center text-muted fw-bold py-4">Belum ada riwayat poin.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="customer-panel">
        <div class="customer-panel-head">
            <div>
                <h3>Histori Transaksi</h3>
                <p>Riwayat transaksi pelanggan jika transaksi sudah menyimpan customer_id.</p>
            </div>
        </div>

        <div class="customer-table-wrap">
            <table class="customer-table">
                <thead><tr><th>Invoice</th><th>Tanggal</th><th>Metode</th><th>Total</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($transactions as $t): ?>
                    <tr>
                        <td><b><?= e($t['invoice_number'] ?? '-'); ?></b></td>
                        <td><?= e(date('d/m/Y H:i', strtotime($t['created_at']))); ?></td>
                        <td><span class="customer-badge blue"><?= e(payment_method_label($t['payment_method'] ?? '-')); ?></span></td>
                        <td><b><?= rupiah($t['grand_total'] ?? 0); ?></b></td>
                        <td><span class="customer-badge green"><?= e($t['status'] ?? '-'); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$transactions): ?>
                    <tr><td colspan="5" class="text-center text-muted fw-bold py-4">Belum ada histori transaksi.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
