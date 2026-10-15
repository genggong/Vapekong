<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireRole(['super_admin','owner']);
$pageTitle = 'Log WhatsApp';
$db = Database::connect();
wa_install($db);

$rows = $db->query("SELECT wl.*, t.invoice_number, u.name AS sender_name
    FROM whatsapp_logs wl
    LEFT JOIN transactions t ON t.id = wl.transaction_id
    LEFT JOIN users u ON u.id = wl.sent_by
    ORDER BY wl.id DESC
    LIMIT 300")->fetchAll();

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">
<style>.wa-status{display:inline-flex;border-radius:999px;padding:6px 10px;font-weight:900;font-size:12px}.wa-status.success{background:#dcfce7;color:#166534}.wa-status.failed{background:#fee2e2;color:#991b1b}.wa-response{max-width:360px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#64748b}.wa-table-wrap{overflow:auto}</style>
<main class="admin-main system-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title"><h1>Log WhatsApp</h1><p>Riwayat pengiriman invoice WhatsApp via Fonnte.</p></div>
        <div class="topbar-actions"><a class="system-btn soft" href="<?= legacy_base_url('admin/settings/index.php'); ?>"><i class="bi bi-gear"></i> Pengaturan Fonnte</a></div>
    </header>
    <section class="system-form-card">
        <div class="system-card-head"><div><h3>Riwayat Pengiriman</h3><p>Data terakhir maksimal 300 pesan.</p></div></div>
        <div class="wa-table-wrap">
            <table class="premium-table">
                <thead><tr><th>Waktu</th><th>Invoice</th><th>Target</th><th>Status</th><th>Pengirim</th><th>Response</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e(date('d/m/Y H:i', strtotime($r['created_at']))); ?></td>
                        <td><?= !empty($r['invoice_number']) ? '<a href="' . e(legacy_base_url('admin/transactions/detail.php?id=' . $r['transaction_id'])) . '"><b>' . e($r['invoice_number']) . '</b></a>' : '-'; ?></td>
                        <td><?= e($r['target']); ?></td>
                        <td><span class="wa-status <?= e($r['status']); ?>"><?= e(strtoupper($r['status'])); ?></span></td>
                        <td><?= e($r['sender_name'] ?: '-'); ?></td>
                        <td><div class="wa-response" title="<?= e($r['response']); ?>"><?= e($r['response']); ?></div></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="6" class="text-center">Belum ada log WhatsApp.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
