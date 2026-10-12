<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireRole(['super_admin','owner','admin','cashier']);
$pageTitle = 'QRIS Piutang';
$db = Database::connect();
debt_install($db);

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT q.*, d.invoice_number, d.remaining_amount, c.name AS customer_name
    FROM customer_debt_qris q
    JOIN customer_debts d ON d.id=q.debt_id
    JOIN customers c ON c.id=q.customer_id
    WHERE q.id=? LIMIT 1");
$stmt->execute([$id]);
$qris = $stmt->fetch();

if (!$qris) {
    flash('error', 'QRIS piutang tidak ditemukan.');
    legacy_redirect('admin/debts/index.php');
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/debts.css?v=' . time()); ?>">
<style>
.debt-qris-card{background:#fff;border:1px solid #fee2e2;border-radius:28px;padding:22px;box-shadow:0 16px 45px rgba(153,27,27,.08);display:grid;gap:18px}.debt-qris-head h2{margin:0;color:#7f1d1d;font-weight:900}.debt-qris-head p{margin:6px 0 0;color:#64748b;font-weight:700}.debt-qris-box{background:#fff7f7;border:1px solid #fecaca;border-radius:24px;padding:18px;display:flex;justify-content:center;align-items:center;min-height:260px}.debt-qris-lines{display:grid;gap:10px}.debt-qris-line{display:flex;justify-content:space-between;gap:12px;border-bottom:1px dashed #fecaca;padding:10px 0}.debt-qris-line b{color:#7f1d1d}.debt-qris-status{border-radius:999px;padding:10px 14px;font-weight:900;width:max-content}.debt-qris-status.pending{background:#fef3c7;color:#92400e}.debt-qris-status.success{background:#dcfce7;color:#166534}.debt-qris-status.failed{background:#fee2e2;color:#991b1b}@media(max-width:600px){.debt-qris-line{display:grid}.debt-qris-card{padding:18px}}
</style>
<main class="admin-main system-wrap">
<header class="admin-topbar page-topbar">
    <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
    <div class="topbar-title"><h1>QRIS Piutang</h1><p><?= e($qris['invoice_number'] ?: '-'); ?> • <?= e($qris['customer_name']); ?></p></div>
    <div class="topbar-actions"><a class="system-btn soft" href="<?= legacy_base_url('admin/debts/detail.php?id=' . $qris['debt_id']); ?>">Kembali</a></div>
</header>

<section class="debt-qris-card">
    <div class="debt-qris-head">
        <h2>Scan QRIS untuk Bayar Piutang</h2>
        <p>Status akan dicek otomatis. Nominal QRIS mengikuti sisa/nominal bayar piutang + kode unik, lalu pembayaran baru dicatat setelah QRIS sukses.</p>
    </div>

    <div class="debt-qris-box">
        <img loading="lazy" src="https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=<?= urlencode($qris['payment_number']); ?>" alt="QRIS">
    </div>

    <div class="debt-qris-lines">
        <div class="debt-qris-line"><span>Nominal Piutang</span><b><?= rupiah($qris['base_amount']); ?></b></div>
        <div class="debt-qris-line"><span>Kode Unik</span><b><?= rupiah($qris['unique_code']); ?></b></div>
        <div class="debt-qris-line"><span>Total Bayar QRIS</span><b><?= rupiah($qris['amount']); ?></b></div>
        <div class="debt-qris-line"><span>Expired</span><b><?= $qris['expired_at'] ? e(date('d/m/Y H:i', strtotime($qris['expired_at']))) : '-'; ?></b></div>
        <div class="debt-qris-line"><span>Status</span><b><span id="debtQrisStatus" class="debt-qris-status <?= e($qris['status']); ?>"><?= e(strtoupper($qris['status'])); ?></span></b></div>
    </div>

    <div class="debt-detail-actions">
        <button type="button" class="system-btn primary" id="checkQrisBtn"><i class="bi bi-arrow-clockwise"></i> Cek Status</button>
        <a class="system-btn soft" href="<?= legacy_base_url('admin/debts/detail.php?id=' . $qris['debt_id']); ?>">Detail Piutang</a>
    </div>
</section>
</main>

<script>
(function(){
    const statusEl = document.getElementById('debtQrisStatus');
    const btn = document.getElementById('checkQrisBtn');
    const url = <?= json_encode(legacy_base_url('admin/debts/qris-status.php?id=' . $qris['id'])); ?>;

    function setStatus(status){
        statusEl.className = 'debt-qris-status ' + status;
        statusEl.textContent = status.toUpperCase();
    }

    async function checkStatus(manual){
        try {
            if(btn) btn.disabled = true;
            const res = await fetch(url, {headers:{'Accept':'application/json'}});
            const data = await res.json();
            if(!data.success) throw new Error(data.message || 'Gagal cek status');
            setStatus(data.status);
            if(data.status === 'success'){
                if(window.Swal) Swal.fire({icon:'success', title:'Pembayaran QRIS sukses', text:'Piutang sudah otomatis tercatat.'});
                setTimeout(()=>{ window.location.href = <?= json_encode(legacy_base_url('admin/debts/detail.php?id=' . $qris['debt_id'])); ?>; }, 1300);
            } else if(data.status === 'failed'){
                if(window.Swal) Swal.fire({icon:'error', title:'QRIS gagal / expired', text:'Silakan buat pembayaran QRIS baru.'});
            } else if(manual && window.Swal) {
                Swal.fire({icon:'info', title:'Masih pending', text:'Pembayaran belum terdeteksi.'});
            }
        } catch(e) {
            if(window.Swal) Swal.fire({icon:'error', title:'Gagal cek status', text:e.message});
        } finally {
            if(btn) btn.disabled = false;
        }
    }

    btn?.addEventListener('click', ()=>checkStatus(true));
    setInterval(()=>checkStatus(false), 5000);
})();
</script>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
