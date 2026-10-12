<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
if (file_exists(__DIR__ . '/../whatsapp/_helpers.php')) require_once __DIR__ . '/../whatsapp/_helpers.php';
Auth::requireRole(['super_admin','owner','admin','cashier']);
$pageTitle = 'Detail Piutang';
$db = Database::connect();
debt_install($db);

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT d.*, c.name AS customer_name, c.phone AS customer_phone, c.member_code
    FROM customer_debts d
    JOIN customers c ON c.id=d.customer_id
    WHERE d.id=? LIMIT 1");
$stmt->execute([$id]);
$debt = $stmt->fetch();

if (!$debt) {
    flash('error','Data piutang tidak ditemukan.');
    legacy_redirect('admin/debts/index.php');
}

$status = debt_actual_status($debt);

$paymentsStmt = $db->prepare("SELECT p.*, u.name AS user_name
    FROM customer_debt_payments p
    LEFT JOIN users u ON u.id=p.created_by
    WHERE p.debt_id=?
    ORDER BY p.id DESC");
$paymentsStmt->execute([$id]);
$payments = $paymentsStmt->fetchAll();

$qrisStmt = $db->prepare("SELECT * FROM customer_debt_qris WHERE debt_id=? ORDER BY id DESC");
$qrisStmt->execute([$id]);
$qrisPayments = $qrisStmt->fetchAll();

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/debts.css?v=' . time()); ?>">

<main class="admin-main system-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Detail Piutang</h1>
            <p><?= e($debt['invoice_number'] ?: '-'); ?> • <?= e($debt['customer_name']); ?></p>
        </div>
        <div class="topbar-actions">
            <a class="system-btn soft" href="<?= legacy_base_url('admin/debts/index.php'); ?>"><i class="bi bi-arrow-left"></i> Kembali</a>
            <?php if ((float)$debt['remaining_amount'] > 0): ?>
                <a class="system-btn primary" href="<?= legacy_base_url('admin/debts/pay.php?id=' . $id); ?>"><i class="bi bi-cash-coin"></i> Bayar</a>
            <?php endif; ?>
        </div>
    </header>

    <section class="debt-detail-layout">
        <div class="debt-detail-card">
            <h3>Ringkasan Tagihan</h3>

            <div class="debt-detail-row">
                <span>Pelanggan</span>
                <b><?= e($debt['customer_name']); ?></b>
            </div>
            <div class="debt-detail-row">
                <span>No. HP</span>
                <b><?= e($debt['customer_phone'] ?: '-'); ?></b>
            </div>
            <div class="debt-detail-row">
                <span>Total</span>
                <b><?= rupiah($debt['total_amount']); ?></b>
            </div>
            <div class="debt-detail-row">
                <span>Dibayar</span>
                <b><?= rupiah($debt['paid_amount']); ?></b>
            </div>
            <div class="debt-detail-row">
                <span>Sisa</span>
                <b><?= rupiah($debt['remaining_amount']); ?></b>
            </div>
            <div class="debt-detail-row">
                <span>Jatuh Tempo</span>
                <b><?= $debt['due_date'] ? e(date('d/m/Y', strtotime($debt['due_date']))) : '-'; ?></b>
            </div>
            <div class="debt-detail-row">
                <span>Status</span>
                <b><span class="debt-status <?= e($status); ?>"><?= e(debt_status_label($status)); ?></span></b>
            </div>

            <div class="debt-detail-actions">
                <?php if ((float)$debt['remaining_amount'] > 0): ?>
                    <a class="system-btn primary" href="<?= legacy_base_url('admin/debts/pay.php?id=' . $id); ?>">
                        <i class="bi bi-cash-coin"></i> Bayar Piutang
                    </a>
                <?php endif; ?>

                <?php if (function_exists('wa_send_fonnte') && !empty($debt['customer_phone'])): ?>
                    <a class="system-btn debt-whatsapp-btn" href="<?= legacy_base_url('admin/debts/send-wa.php?id=' . $id); ?>" onclick="return confirm('Kirim tagihan WhatsApp?')">
                        <i class="bi bi-whatsapp"></i> Kirim Tagihan WhatsApp
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="debt-detail-card">
            <h3>Catatan</h3>
            <div class="debt-note-text"><?= nl2br(e($debt['note'] ?: '-')); ?></div>
        </div>


        <?php if (!empty($qrisPayments)): ?>
        <div class="debt-detail-card">
            <h3>Riwayat QRIS Piutang</h3>
            <div class="table-responsive">
                <table class="premium-table">
                    <thead><tr><th>Order ID</th><th>Nominal</th><th>Total QRIS</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
                    <tbody>
                    <?php foreach($qrisPayments as $q): ?>
                        <tr>
                            <td><b><?= e($q['order_id']); ?></b><br><small><?= e(date('d/m/Y H:i', strtotime($q['created_at']))); ?></small></td>
                            <td><?= rupiah($q['base_amount']); ?></td>
                            <td><b><?= rupiah($q['amount']); ?></b></td>
                            <td><span class="debt-status <?= e($q['status']==='success'?'paid':($q['status']==='failed'?'unpaid':'partial')); ?>"><?= e(strtoupper($q['status'])); ?></span></td>
                            <td class="text-end"><?php if($q['status']==='pending'): ?><a class="system-btn soft" href="<?= legacy_base_url('admin/debts/qris.php?id=' . $q['id']); ?>">Buka QRIS</a><?php else: ?>-<?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="debt-detail-card">
            <h3>Riwayat Pembayaran</h3>
            <div class="table-responsive">
                <table class="premium-table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Nominal</th>
                            <th>Metode</th>
                            <th>User</th>
                            <th>Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($payments as $p): ?>
                            <tr>
                                <td><?= e(date('d/m/Y H:i', strtotime($p['created_at']))); ?></td>
                                <td><b><?= rupiah($p['amount']); ?></b></td>
                                <td><?= e(strtoupper($p['payment_method'])); ?></td>
                                <td><?= e($p['user_name'] ?: '-'); ?></td>
                                <td><?= e($p['note'] ?: '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$payments): ?>
                            <tr><td colspan="5" class="text-center">Belum ada pembayaran.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
