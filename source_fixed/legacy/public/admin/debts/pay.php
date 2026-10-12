<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireRole(['super_admin','owner','admin','cashier']);

$pageTitle = 'Bayar Piutang';
$db = Database::connect();
debt_install($db);

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));

$stmt = $db->prepare("SELECT d.*, c.name AS customer_name, c.phone AS customer_phone
    FROM customer_debts d
    JOIN customers c ON c.id=d.customer_id
    WHERE d.id=? LIMIT 1");
$stmt->execute([$id]);
$debt = $stmt->fetch();

if (!$debt) {
    flash('error','Data piutang tidak ditemukan.');
    legacy_redirect('admin/debts/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verify()) {
        flash('error','Token keamanan tidak valid.');
        legacy_redirect('admin/debts/pay.php?id=' . $id);
    }

    $amount = (float)($_POST['amount'] ?? 0);
    $method = trim($_POST['payment_method'] ?? 'cash');
    $note = trim($_POST['note'] ?? '');

    try {
        if ($method === 'qris') {
            $qrisId = debt_create_qris_payment($db, $id, $amount, $note);
            Auth::log('debt_qris_create','Membuat QRIS pembayaran piutang ID ' . $id . ' sebesar ' . rupiah($amount));

            flash('success','QRIS piutang berhasil dibuat. Silakan scan untuk membayar.');
            legacy_redirect('admin/debts/qris.php?id=' . $qrisId);
        }

        $db->beginTransaction();
        debt_record_payment($db, $id, $amount, $method, $note);
        Auth::log('debt_payment','Pembayaran piutang ID ' . $id . ' sebesar ' . rupiah($amount));
        $db->commit();

        flash('success','Pembayaran piutang berhasil disimpan.');
        legacy_redirect('admin/debts/detail.php?id=' . $id);
    } catch(Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();

        flash('error','Gagal simpan pembayaran: ' . $e->getMessage());
        legacy_redirect('admin/debts/pay.php?id=' . $id);
    }
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/debts.css?v=' . time()); ?>">

<main class="admin-main system-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Bayar Piutang</h1>
            <p><?= e($debt['invoice_number'] ?: '-'); ?> • <?= e($debt['customer_name']); ?></p>
        </div>
        <div class="topbar-actions">
            <a class="system-btn soft" href="<?= legacy_base_url('admin/debts/detail.php?id=' . $id); ?>">
                <i class="bi bi-arrow-left"></i> Kembali
            </a>
        </div>
    </header>

    <section class="debt-pay-layout">
        <div class="debt-pay-card">
            <h3>Ringkasan</h3>

            <div class="debt-pay-row">
                <span>Total</span>
                <b><?= rupiah($debt['total_amount']); ?></b>
            </div>
            <div class="debt-pay-row">
                <span>Sudah Dibayar</span>
                <b><?= rupiah($debt['paid_amount']); ?></b>
            </div>
            <div class="debt-pay-row">
                <span>Sisa</span>
                <b><?= rupiah($debt['remaining_amount']); ?></b>
            </div>
        </div>

        <form method="POST" class="debt-pay-card debt-pay-form" id="debtPayForm">
            <?= CSRF::input(); ?>
            <input type="hidden" name="id" value="<?= e($id); ?>">

            <div class="system-field">
                <label>Nominal Bayar</label>
                <input
                    type="number"
                    name="amount"
                    min="1"
                    max="<?= e((int)$debt['remaining_amount']); ?>"
                    value="<?= e((int)$debt['remaining_amount']); ?>"
                    required
                >
            </div>

            <div class="system-field">
                <label>Metode</label>
                <select name="payment_method" id="debtPayMethod">
                    <option value="cash">Cash</option>
                    <option value="transfer">Transfer</option>
                    <option value="qris">QRIS</option>
                    <option value="lainnya">Lainnya</option>
                </select>
                <small>Jika pilih QRIS, sistem membuat QRIS dari nominal bayar piutang ini + kode unik.</small>
            </div>

            <div class="system-field">
                <label>Catatan</label>
                <textarea name="note" rows="3" placeholder="Catatan pembayaran"></textarea>
            </div>

            <div class="debt-pay-actions">
                <a class="system-btn soft" href="<?= legacy_base_url('admin/debts/detail.php?id=' . $id); ?>">
                    Batal
                </a>
                <button type="submit" class="system-btn primary" id="debtPaySubmit">
                    <i class="bi bi-save"></i> Simpan Pembayaran
                </button>
            </div>
        </form>
    </section>
</main>


<script>
(function(){
    const form = document.getElementById('debtPayForm');
    const btn = document.getElementById('debtPaySubmit');
    const method = document.getElementById('debtPayMethod');

    if (!form || !btn) return;

    form.addEventListener('submit', function(){
        const isQris = (method?.value || '') === 'qris';
        btn.disabled = true;
        btn.innerHTML = isQris
            ? '<span class="spinner-border spinner-border-sm"></span> Membuat QRIS...'
            : '<span class="spinner-border spinner-border-sm"></span> Menyimpan...';

        if (window.Swal) {
            Swal.fire({
                title: isQris ? 'Membuat QRIS...' : 'Menyimpan pembayaran...',
                text: isQris ? 'Mohon tunggu, sistem sedang membuat QRIS Pakasir.' : 'Mohon tunggu sebentar.',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: () => Swal.showLoading()
            });
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
