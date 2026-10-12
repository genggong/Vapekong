<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../debts/_helpers.php';
Auth::requireLogin();
require_once __DIR__ . '/../whatsapp/_helpers.php';
$pageTitle = 'Detail Transaksi';
$db = Database::connect();
wa_install($db);

$id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT t.*, u.name AS cashier_name, c.name AS customer_name, c.phone AS customer_phone, c.member_code AS customer_member_code, c.points AS customer_points_total
    FROM transactions t
    LEFT JOIN users u ON u.id = t.cashier_id
    LEFT JOIN customers c ON c.id = t.customer_id
    WHERE t.id = ? LIMIT 1");
$stmt->execute([$id]);
$trx = $stmt->fetch();

if (!$trx) {
    flash('error', 'Transaksi tidak ditemukan.');
    legacy_redirect('admin/transactions/index.php');
}

$stmt = $db->prepare("SELECT * FROM transaction_items WHERE transaction_id = ? ORDER BY id ASC");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

$trxDate = $trx['transaction_date'] ?? $trx['created_at'] ?? date('Y-m-d H:i:s');
$printUrl = legacy_base_url('admin/transactions/print.php?id=' . $trx['id'] . '&autoprint=1');

$pakasirPayment = null;
if (!empty($trx['note']) && preg_match('/\[PAKASIR_PAYMENT\](.*?)\[\/PAKASIR_PAYMENT\]/s', (string) $trx['note'], $m)) {
    $decoded = json_decode($m[1], true);
    if (is_array($decoded)) {
        $pakasirPayment = $decoded;
    }
}

$memberPayment = null;
if (!empty($trx['note']) && preg_match('/\[MEMBER_POINTS\](.*?)\[\/MEMBER_POINTS\]/s', (string) $trx['note'], $m)) {
    $decoded = json_decode($m[1], true);
    if (is_array($decoded)) {
        $memberPayment = $decoded;
    }
}


$manualQrisPayment = null;
if (!empty($trx['note']) && preg_match('/\[MANUAL_QRIS\](.*?)\[\/MANUAL_QRIS\]/s', (string) $trx['note'], $m)) {
    $decoded = json_decode($m[1], true);
    if (is_array($decoded)) {
        $manualQrisPayment = $decoded;
    }
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>


<style>
.kp-swal-popup {
    border-radius: 28px !important;
    border: 1px solid rgba(153, 27, 27, .15) !important;
    box-shadow: 0 24px 70px rgba(153, 27, 27, .18) !important;
}
.kp-swal-title {
    color: #991b1b !important;
    font-weight: 900 !important;
}
.kp-swal-confirm {
    border-radius: 18px !important;
    padding: 12px 34px !important;
    font-weight: 800 !important;
    background: linear-gradient(135deg, #ef4444, #991b1b) !important;
    box-shadow: 0 12px 28px rgba(153, 27, 27, .25) !important;
}

.pakasir-card{margin:22px 0 0;border:1px solid rgba(153,27,27,.16);background:#fff7f7;border-radius:28px;padding:24px;box-shadow:0 18px 50px rgba(153,27,27,.10)}
.pakasir-card-head{display:flex;gap:12px;align-items:flex-start;margin-bottom:16px;color:#7f1d1d}.pakasir-card-head i{font-size:28px;color:#991b1b}.pakasir-card-head h3{margin:0;color:#991b1b;font-weight:900}.pakasir-card-head p{margin:4px 0 0;color:#7f1d1d}.pakasir-qr-wrap{display:grid;grid-template-columns:200px 1fr;gap:24px;align-items:center}.pakasir-qr-box{background:white;border-radius:24px;padding:18px;display:flex;justify-content:center;align-items:center;min-height:210px;border:1px solid #fecaca}.pakasir-pay-lines{display:grid;gap:12px}.pakasir-pay-line{display:flex;justify-content:space-between;gap:14px;border-bottom:1px dashed #fecaca;padding:0 0 10px}.pakasir-pay-line span{color:#7f1d1d}.pakasir-pay-line b{color:#991b1b;text-align:right}.pakasir-pay-line.total-qris{margin-top:4px;padding:12px 0;border-top:2px solid #fecaca;border-bottom:0}.pakasir-pay-line.total-qris span,.pakasir-pay-line.total-qris b{font-size:18px;font-weight:900}.pakasir-copy{margin-top:12px;width:100%;border:0;border-radius:18px;padding:12px 16px;background:linear-gradient(135deg,#ef4444,#991b1b);color:white;font-weight:900}.pakasir-qr-fallback{word-break:break-all;background:#fff;border:1px solid #fecaca;padding:10px;border-radius:14px;font-size:11px;color:#7f1d1d;max-height:110px;overflow:auto}@media(max-width:720px){.pakasir-card{padding:20px;margin-top:18px}.pakasir-card-head{margin-bottom:18px}.pakasir-qr-wrap{grid-template-columns:1fr;gap:20px}.pakasir-qr-box{min-height:230px}.pakasir-pay-line{font-size:14px}.pakasir-pay-line.total-qris span,.pakasir-pay-line.total-qris b{font-size:17px}}


.pakasir-live-status{margin:16px 0;padding:14px 16px;border-radius:20px;background:#fff;border:1px solid #fecaca;display:flex;align-items:center;gap:12px;color:#7f1d1d;font-weight:800}
.pakasir-live-dot{width:13px;height:13px;border-radius:50%;background:#f59e0b;box-shadow:0 0 0 6px rgba(245,158,11,.15)}
.pakasir-live-status.success{border-color:#bbf7d0;background:#f0fdf4;color:#166534}.pakasir-live-status.success .pakasir-live-dot{background:#16a34a;box-shadow:0 0 0 6px rgba(22,163,74,.15)}
.pakasir-live-status.failed{border-color:#fecaca;background:#fef2f2;color:#991b1b}.pakasir-live-status.failed .pakasir-live-dot{background:#dc2626;box-shadow:0 0 0 6px rgba(220,38,38,.15)}
.pakasir-check-btn{border:0;border-radius:16px;padding:10px 14px;background:#991b1b;color:#fff;font-weight:900;margin-left:auto;white-space:nowrap}
@media(max-width:720px){.pakasir-live-status{align-items:flex-start}.pakasir-check-btn{width:100%;margin:10px 0 0}.pakasir-live-status{flex-wrap:wrap}}
.manual-qris-detail{margin:22px 0 0;border:1px solid #d8b4fe;background:#faf5ff;border-radius:28px;padding:24px;box-shadow:0 18px 50px rgba(88,28,135,.10)}.manual-qris-detail-head{display:flex;gap:12px;align-items:flex-start;color:#581c87}.manual-qris-detail-head i{font-size:28px}.manual-qris-detail-head h3{margin:0;color:#581c87;font-weight:900}.manual-qris-detail-head p{margin:4px 0 0;color:#6b21a8}.manual-qris-detail-body{display:grid;grid-template-columns:220px 1fr;gap:24px;align-items:center;margin-top:18px}.manual-qris-detail img{width:220px;max-width:100%;aspect-ratio:1/1;object-fit:contain;background:#fff;border:1px solid #e9d5ff;border-radius:22px;padding:12px}.manual-qris-confirmed{border:1px solid #bbf7d0;background:#f0fdf4;color:#166534;border-radius:18px;padding:14px 16px;font-weight:800;line-height:1.55}.manual-qris-confirmed b{display:block;margin-bottom:4px}@media(max-width:720px){.manual-qris-detail{padding:20px}.manual-qris-detail-body{grid-template-columns:1fr}.manual-qris-detail img{margin:auto;width:min(260px,100%)}}

.wa-invoice-card{margin:0 0 20px;background:#fff7f7;border:1px solid #fecaca;border-radius:24px;padding:18px;box-shadow:0 14px 40px rgba(153,27,27,.08);display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}.wa-invoice-card h3{margin:0;color:#991b1b;font-weight:900}.wa-invoice-card p{margin:4px 0 0;color:#7f1d1d}.wa-invoice-form{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.wa-invoice-form input{border:1px solid #fecaca;border-radius:16px;padding:12px 14px;min-width:190px}.wa-send-btn{border:0;border-radius:16px;padding:12px 16px;background:linear-gradient(135deg,#22c55e,#15803d);color:white;font-weight:900}.wa-send-btn:disabled{opacity:.7}@media(max-width:720px){.wa-invoice-card{align-items:stretch}.wa-invoice-form,.wa-invoice-form input,.wa-send-btn{width:100%}}
</style>

<main class="admin-main transaction-page-space">
    <?php if (!empty($_GET['pos_success'])): ?>
<?php endif; ?>
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Detail Transaksi</h1>
            <p><?= e($trx['invoice_number']); ?> • <?= e(date('d/m/Y H:i', strtotime($trxDate))); ?></p>
        </div>
        <div class="topbar-actions d-none d-lg-flex">
            <a href="<?= legacy_base_url('admin/transactions/index.php'); ?>" class="btn-soft"><i class="bi bi-arrow-left"></i> Kembali</a>
            <a href="<?= legacy_base_url('admin/returns/create.php?transaction_id=' . $trx['id']); ?>" class="btn-soft"><i class="bi bi-arrow-counterclockwise"></i> Retur</a>
            <a href="<?= e($printUrl); ?>" target="_blank" class="btn-premium"><i class="bi bi-printer"></i> Cetak Struk</a>
        </div>
    </header>

    <section class="wa-invoice-card">
        <div>
            <h3><i class="bi bi-whatsapp"></i> Kirim Invoice WhatsApp</h3>
            <p>Kirim struk/invoice ke nomor pelanggan memakai API Fonnte.</p>
        </div>
        <form method="POST" action="<?= legacy_base_url('admin/whatsapp/send-invoice.php'); ?>" class="wa-invoice-form" id="waInvoiceForm">
            <?= CSRF::input(); ?>
            <input type="hidden" name="transaction_id" value="<?= e($trx['id']); ?>">
            <input name="target" value="<?= e($trx['customer_phone'] ?? ''); ?>" placeholder="Nomor WA, contoh 08123456789">
            <button type="submit" class="wa-send-btn"><i class="bi bi-whatsapp"></i> Kirim Invoice</button>
        </form>
    </section>

    <section class="trx-hero">
        <div class="trx-invoice-card">
            <span class="trx-label"><i class="bi bi-receipt-cutoff"></i> Invoice Penjualan</span>
            <h2 class="trx-invoice-no"><?= e($trx['invoice_number']); ?></h2>
            <p class="trx-muted">Detail transaksi sudah tersimpan. Kamu bisa cetak struk dari tombol cetak.</p>

            <div class="trx-meta-grid">
                <div class="trx-meta"><span>Tanggal</span><b><?= e(date('d/m/Y H:i', strtotime($trxDate))); ?></b></div>
                <div class="trx-meta"><span>Kasir</span><b><?= e($trx['cashier_name'] ?? '-'); ?></b></div>
                <div class="trx-meta"><span>Member</span><b><?= e($trx['customer_name'] ?? 'Umum'); ?></b></div>
                <div class="trx-meta"><span>Status</span><b id="trxStatusText"><?= e(strtoupper($trx['status'] ?? 'success')); ?></b></div>
            </div>
        </div>

        <aside class="trx-summary-card">
            <h3>Ringkasan Bayar</h3>
            <p class="trx-muted">Metode: <?= e(payment_method_label($trx['payment_method'] ?? '-')); ?></p>

            <div class="trx-summary-line"><span>Subtotal</span><b><?= rupiah($trx['subtotal'] ?? 0); ?></b></div>
            <div class="trx-summary-line"><span>Diskon</span><b><?= rupiah($trx['discount'] ?? 0); ?></b></div>
            <?php if ($memberPayment): ?>
                <?php $detailRedeemPoints = max(0, (int)($memberPayment['redeem_points'] ?? 0)); $detailEarnPoints = max(0, (int)($memberPayment['earn_points'] ?? 0)); $detailTotalPoints = max(0, (int)($trx['customer_points_total'] ?? 0)); ?>
                <?php if ($detailRedeemPoints > 0): ?><div class="trx-summary-line"><span>Tukar Poin</span><b>-<?= e($detailRedeemPoints); ?> poin</b></div><?php endif; ?>
                <?php if (strtolower((string)($trx['status'] ?? 'success')) === 'success'): ?>
                    <div class="trx-summary-line"><span>Poin Didapat</span><b>+<?= e($detailEarnPoints); ?> poin</b></div>
                    <div class="trx-summary-line"><span>Total Poin</span><b><?= e($detailTotalPoints); ?> poin</b></div>
                <?php else: ?>
                    <div class="trx-summary-line"><span>Poin Akan Didapat</span><b>+<?= e($detailEarnPoints); ?> poin</b></div>
                    <div class="trx-summary-line"><span>Total Poin Saat Ini</span><b><?= e($detailTotalPoints); ?> poin</b></div>
                <?php endif; ?>
            <?php endif; ?>
            <div class="trx-summary-line total"><span>Total</span><b><?= rupiah($trx['grand_total'] ?? 0); ?></b></div>
            <div class="trx-summary-line"><span>Dibayar</span><b><?= rupiah($trx['paid_amount'] ?? 0); ?></b></div>
            <div class="trx-summary-line change"><span>Kembali</span><b><?= rupiah($trx['change_amount'] ?? 0); ?></b></div>

            <div class="trx-actions">
                <a href="<?= legacy_base_url('admin/transactions/index.php'); ?>" class="trx-btn trx-btn-soft"><i class="bi bi-arrow-left"></i> Kembali</a>
                <a href="<?= legacy_base_url('admin/returns/create.php?transaction_id=' . $trx['id']); ?>" class="trx-btn trx-btn-soft"><i class="bi bi-arrow-counterclockwise"></i> Retur</a>
                <a href="<?= e($printUrl); ?>" target="_blank" class="trx-btn trx-btn-print"><i class="bi bi-printer-fill"></i> Cetak</a>
            </div>
        </aside>
    </section>



    <?php if ($pakasirPayment): ?>
    <section class="pakasir-card">
        <div class="pakasir-card-head">
            <i class="bi bi-qr-code-scan"></i>
            <div>
                <h3>Pembayaran QRIS</h3>
                <p>Scan QR ini untuk menyelesaikan pembayaran. Status invoice akan dicek otomatis.</p>
            </div>
        </div>
        <div class="pakasir-live-status <?= strtolower((string)($trx['status'] ?? 'pending')) === 'success' ? 'success' : (strtolower((string)($trx['status'] ?? 'pending')) === 'failed' ? 'failed' : '') ?>" id="pakasirLiveStatus" data-transaction-id="<?= e($trx['id']); ?>" data-current-status="<?= e(strtolower((string)($trx['status'] ?? 'pending'))); ?>">
            <span class="pakasir-live-dot"></span>
            <span id="pakasirLiveText">
                <?= strtolower((string)($trx['status'] ?? 'pending')) === 'success' ? 'Pembayaran QRIS sudah sukses.' : (strtolower((string)($trx['status'] ?? 'pending')) === 'failed' ? 'Pembayaran QRIS gagal / expired.' : 'Menunggu pembayaran QRIS. Status dicek otomatis setiap 5 detik.') ?>
            </span>
            <button type="button" class="pakasir-check-btn" id="pakasirManualCheck"><i class="bi bi-arrow-clockwise"></i> Cek Status</button>
        </div>

        <div class="pakasir-qr-wrap">
            <div class="pakasir-qr-box">
                <div id="pakasirQr" data-qr="<?= e($pakasirPayment['payment_number'] ?? ''); ?>"></div>
            </div>
            <div class="pakasir-pay-lines">
                <div class="pakasir-pay-line"><span>Order ID</span><b><?= e($pakasirPayment['order_id'] ?? $trx['invoice_number']); ?></b></div>
                <div class="pakasir-pay-line"><span>Nominal Belanja</span><b><?= rupiah($pakasirPayment['original_amount'] ?? (($pakasirPayment['amount'] ?? $trx['grand_total']) - ($pakasirPayment['unique_code'] ?? 0))); ?></b></div>
                <div class="pakasir-pay-line"><span>Kode Unik</span><b><?= str_pad((string) (int)($pakasirPayment['unique_code'] ?? 0), 3, '0', STR_PAD_LEFT); ?></b></div>
                <div class="pakasir-pay-line"><span>Biaya QRIS</span><b><?= rupiah($pakasirPayment['fee'] ?? 0); ?></b></div>
                <div class="pakasir-pay-line total-qris"><span>Total Bayar</span><b><?= rupiah($pakasirPayment['total_payment'] ?? $pakasirPayment['amount'] ?? $trx['grand_total']); ?></b></div>
                <div class="pakasir-pay-line"><span>Expired</span><b><?= e(!empty($pakasirPayment['expired_at']) ? date('d/m/Y H:i', strtotime($pakasirPayment['expired_at'])) : '-'); ?></b></div>

            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($manualQrisPayment && ($trx['payment_method'] ?? '') === 'qris_manual'): ?>
    <section class="manual-qris-detail">
        <div class="manual-qris-detail-head">
            <i class="bi bi-qr-code-scan"></i>
            <div>
                <h3><?= e($manualQrisPayment['label'] ?? 'QRIS Manual'); ?></h3>
                <p>Pembayaran QRIS manual dikonfirmasi langsung oleh kasir.</p>
            </div>
        </div>
        <div class="manual-qris-detail-body">
            <?php if (!empty($manualQrisPayment['image'])): ?>
                <img src="<?= legacy_base_url($manualQrisPayment['image']); ?>" alt="QRIS Manual">
            <?php endif; ?>
            <div class="manual-qris-confirmed">
                <b><i class="bi bi-check-circle-fill"></i> Pembayaran dikonfirmasi</b>
                Total <?= rupiah($trx['grand_total'] ?? 0); ?> telah dicatat sebagai pembayaran QRIS Manual.
                <?php if (!empty($manualQrisPayment['confirmed_at'])): ?><br>Dikonfirmasi: <?= e(date('d/m/Y H:i', strtotime($manualQrisPayment['confirmed_at']))); ?><?php endif; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section class="trx-items-card">
        <h3>Item Belanja</h3>
        <p class="trx-muted">Daftar produk pada transaksi ini.</p>

        <div class="desktop-table-wrap">
            <table class="trx-item-table">
                <thead>
                    <tr>
                        <th>Produk</th>
                        <th width="90">Qty</th>
                        <th>Harga</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <span class="trx-product-name"><?= e($item['product_name']); ?><?php if (($item['item_type'] ?? 'product') === 'bundle' && !empty($item['bundle_components'])): ?><br><small>Paket produk</small><?php endif; ?></span>
                            <span class="trx-product-sub"><?= e($item['qty']); ?> x <?= rupiah($item['unit_price']); ?></span>
                        </td>
                        <td><b><?= e($item['qty']); ?></b></td>
                        <td><?= rupiah($item['unit_price']); ?></td>
                        <td><b><?= rupiah($item['subtotal']); ?></b></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="trx-mobile-items">
            <?php foreach ($items as $item): ?>
                <div class="trx-mobile-item">
                    <div class="trx-mobile-item-head">
                        <h4><?= e($item['product_name']); ?><?php if (($item['item_type'] ?? 'product') === 'bundle' && !empty($item['bundle_components'])): ?><br><small>Paket produk</small><?php endif; ?></h4>
                        <span class="qty">x<?= e($item['qty']); ?></span>
                    </div>
                    <div class="trx-mobile-price"><span>Harga</span><b><?= rupiah($item['unit_price']); ?></b></div>
                    <div class="trx-mobile-price"><span>Subtotal</span><b><?= rupiah($item['subtotal']); ?></b></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="mobile-print-sticky">
        <a href="<?= legacy_base_url('admin/transactions/index.php'); ?>" class="trx-btn trx-btn-soft"><i class="bi bi-arrow-left"></i> Kembali</a>
        <a href="<?= legacy_base_url('admin/returns/create.php?transaction_id=' . $trx['id']); ?>" class="trx-btn trx-btn-soft"><i class="bi bi-arrow-counterclockwise"></i> Retur</a>
        <a href="<?= e($printUrl); ?>" target="_blank" class="trx-btn trx-btn-print"><i class="bi bi-printer-fill"></i> Cetak</a>
    </div>

<?php if (!empty($transaction['debt_amount']) && (float)$transaction['debt_amount'] > 0): ?>
<section class="system-form-card" style="margin-top:16px;">
    <h3>Piutang Pelanggan</h3>
    <p>Sisa tagihan: <b><?= rupiah($transaction['debt_amount']); ?></b></p>
    <p>Status: <b><?= e(debt_status_label($transaction['debt_status'] ?? 'unpaid')); ?></b><?php if(!empty($transaction['debt_due_date'])): ?> • Jatuh tempo <?= e(date('d/m/Y', strtotime($transaction['debt_due_date']))); ?><?php endif; ?></p>
    <a class="system-btn soft" href="<?= legacy_base_url('admin/debts/index.php?q=' . urlencode($transaction['invoice_number'] ?? '')); ?>">Lihat Piutang</a>
</section>
<?php endif; ?>

</main>


<?php if (!empty($_GET['pos_success'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.Swal) {
        Swal.fire({
            icon: 'success',
            title: 'Transaksi berhasil',
            text: 'Invoice berhasil dibuat. Kamu bisa cetak struk dari halaman ini.',
            confirmButtonText: 'Oke'
        });
    }
});
</script>
<?php endif; ?>


<?php if (isset($_GET['success']) && $_GET['success'] === '1'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    function showInvoiceSuccess() {
        if (window.Swal) {
            Swal.fire({
                icon: 'success',
                title: 'Transaksi Berhasil',
                text: 'Transaksi berhasil disimpan. Ini detail invoice-nya.',
                confirmButtonText: 'Oke',
                confirmButtonColor: '#991b1b',
                background: '#fff7f7',
                color: '#7f1d1d',
                customClass: {
                    popup: 'kp-swal-popup',
                    title: 'kp-swal-title',
                    confirmButton: 'kp-swal-confirm'
                }
            });
        } else {
            alert('Transaksi berhasil disimpan. Ini detail invoice-nya.');
        }

        if (window.history && window.history.replaceState) {
            const cleanUrl = window.location.href.replace(/[?&]success=1/, '').replace('?&', '?').replace(/&$/, '').replace(/\?$/, '');
            window.history.replaceState({}, document.title, cleanUrl);
        }
    }

    showInvoiceSuccess();
});
</script>
<?php endif; ?>


<?php if ($pakasirPayment): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
    const el = document.getElementById('pakasirQr');
    const qrText = el ? el.dataset.qr : '';
    if(el && qrText && window.QRCode){
        el.innerHTML = '';
        new QRCode(el, { text: qrText, width: 180, height: 180, correctLevel: QRCode.CorrectLevel.M });
    }
});
</script>
<?php endif; ?>


<?php if ($pakasirPayment): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const liveBox = document.getElementById('pakasirLiveStatus');
    const liveText = document.getElementById('pakasirLiveText');
    const checkBtn = document.getElementById('pakasirManualCheck');

    if (!liveBox || !liveText) return;

    const trxId = liveBox.dataset.transactionId;
    let currentStatus = (liveBox.dataset.currentStatus || 'pending').toLowerCase();
    let polling = null;
    let busy = false;

    function setLiveStatus(status, message, remoteStatus) {
        status = (status || 'pending').toLowerCase();
        currentStatus = status;
        liveBox.classList.remove('success', 'failed');

        if (status === 'success') {
            liveBox.classList.add('success');
            liveText.textContent = message || 'Pembayaran QRIS berhasil dikonfirmasi.';
            const statusMeta = document.getElementById('trxStatusText');
            if (statusMeta) statusMeta.textContent = 'SUCCESS';
            if (polling) clearInterval(polling);

            if (!sessionStorage.getItem('pakasir_paid_' + trxId)) {
                sessionStorage.setItem('pakasir_paid_' + trxId, '1');
                if (window.Swal) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Pembayaran Berhasil',
                        text: 'QRIS sudah dibayar dan invoice berubah menjadi sukses.',
                        confirmButtonText: 'Oke',
                        confirmButtonColor: '#991b1b',
                        background: '#fff7f7',
                        color: '#7f1d1d',
                        customClass: { popup: 'kp-swal-popup', title: 'kp-swal-title', confirmButton: 'kp-swal-confirm' }
                    });
                }
            }
            return;
        }

        if (status === 'failed') {
            liveBox.classList.add('failed');
            liveText.textContent = message || 'Pembayaran QRIS gagal atau expired.';
            const statusMeta = document.getElementById('trxStatusText');
            if (statusMeta) statusMeta.textContent = 'FAILED';
            if (polling) clearInterval(polling);

            if (!sessionStorage.getItem('pakasir_failed_' + trxId)) {
                sessionStorage.setItem('pakasir_failed_' + trxId, '1');
                if (window.Swal) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Pembayaran Gagal',
                        text: 'QRIS gagal atau sudah expired.',
                        confirmButtonText: 'Oke',
                        confirmButtonColor: '#991b1b',
                        background: '#fff7f7',
                        color: '#7f1d1d',
                        customClass: { popup: 'kp-swal-popup', title: 'kp-swal-title', confirmButton: 'kp-swal-confirm' }
                    });
                }
            }
            return;
        }

        liveText.textContent = message || ('Menunggu pembayaran QRIS' + (remoteStatus ? ' (' + remoteStatus + ')' : '') + '. Dicek otomatis setiap 5 detik.');
    }

    function checkStatus(manual) {
        if (busy || currentStatus === 'success' || currentStatus === 'failed') return;
        busy = true;

        if (manual && checkBtn) {
            checkBtn.disabled = true;
            checkBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Mengecek...';
        }

        fetch('<?= legacy_base_url('admin/pakasir/status.php'); ?>?id=' + encodeURIComponent(trxId) + '&_=' + Date.now(), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            if (!json.success) {
                if (manual && window.Swal) {
                    Swal.fire({ icon: 'error', title: 'Gagal cek status', text: json.message || 'Tidak bisa mengecek status QRIS.', confirmButtonColor: '#991b1b' });
                }
                return;
            }
            setLiveStatus(json.status, json.message, json.remote_status);
        })
        .catch(function () {
            if (manual && window.Swal) {
                Swal.fire({ icon: 'error', title: 'Gagal cek status', text: 'Koneksi ke server gagal.', confirmButtonColor: '#991b1b' });
            }
        })
        .finally(function () {
            busy = false;
            if (manual && checkBtn) {
                checkBtn.disabled = false;
                checkBtn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Cek Status';
            }
        });
    }

    if (checkBtn) {
        checkBtn.addEventListener('click', function () { checkStatus(true); });
    }

    if (currentStatus !== 'success' && currentStatus !== 'failed') {
        checkStatus(false);
        polling = setInterval(function () { checkStatus(false); }, 5000);
    }
});
</script>
<?php endif; ?>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('waInvoiceForm');
    if (!form) return;
    form.addEventListener('submit', function () {
        const btn = form.querySelector('button[type="submit"]');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Mengirim...';
        }
        if (window.Swal) {
            Swal.fire({
                title: 'Mengirim invoice WhatsApp',
                text: 'Mohon tunggu sebentar...',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: function () { Swal.showLoading(); }
            });
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
