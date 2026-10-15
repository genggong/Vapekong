<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/Marketplace/Marketplace.php';
Auth::requirePermission('transactions.view');
$pageTitle = 'Pesanan Marketplace';
$db = Database::connect();
marketplace_install($db);

$orders = $db->query("SELECT o.*, b.name AS branch_name
    FROM online_orders o
    LEFT JOIN branches b ON b.id=o.branch_id
    ORDER BY o.id DESC")->fetchAll() ?: [];

function marketplace_admin_badge(string $status): string
{
    $map = [
        'paid'=>'success','completed'=>'success','shipped'=>'info','processing'=>'info',
        'pending'=>'warning','pending_payment'=>'warning','error'=>'danger','failed'=>'danger',
        'cancelled'=>'danger','expired'=>'danger'
    ];
    return $map[$status] ?? 'info';
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<main class="admin-main">
    <header class="admin-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Pesanan Marketplace</h1>
            <p>Order online dari katalog, ongkir live, pembayaran, fulfillment cabang, dan resi pengiriman.</p>
        </div>
        <div class="topbar-actions"><a class="btn-premium" href="<?= legacy_base_url(); ?>" target="_blank"><i class="bi bi-box-arrow-up-right"></i> Buka Marketplace</a></div>
    </header>

    <section class="premium-card mt-4">
        <div class="card-head">
            <div><h3>Order Online</h3><p>Status pembayaran berasal dari webhook; stok sudah di-reserve sejak checkout dibuat.</p></div>
            <span class="badge-soft danger"><?= count($orders); ?> Data</span>
        </div>
        <div class="table-premium-card dt-premium-wrap">
            <table class="premium-table js-data-table nowrap" data-title="pesanan-marketplace" data-table-width="1250">
                <thead><tr><th>Order</th><th>Pelanggan</th><th>Cabang</th><th>Total</th><th>Pembayaran</th><th>Status Order</th><th>Pengiriman</th><th>Tanggal</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><b><?= e($order['order_number']); ?></b><br><small><?= e(strtoupper((string)$order['payment_method'])); ?></small></td>
                        <td><b><?= e($order['recipient_name']); ?></b><br><small><?= e($order['recipient_phone']); ?></small></td>
                        <td><?= e($order['branch_name'] ?: ('#'.$order['branch_id'])); ?></td>
                        <td><b><?= rupiah((float)$order['grand_total']); ?></b><br><small>Ongkir <?= rupiah((float)$order['shipping_cost']); ?></small></td>
                        <td><span class="badge-soft <?= marketplace_admin_badge((string)$order['payment_status']); ?>"><?= e(marketplace_payment_status_label((string)$order['payment_status'])); ?></span></td>
                        <td><span class="badge-soft <?= marketplace_admin_badge((string)$order['order_status']); ?>"><?= e(marketplace_order_status_label((string)$order['order_status'])); ?></span></td>
                        <td><b><?= e(strtoupper((string)$order['courier_code']).' '.$order['courier_service']); ?></b><br><small><?= e($order['awb_number'] ?: 'Belum ada resi'); ?></small></td>
                        <td><?= !empty($order['created_at']) ? date('d/m/Y H:i', strtotime($order['created_at'])) : '-'; ?></td>
                        <td class="text-end table-actions"><a class="btn-icon edit" href="<?= legacy_base_url('admin/marketplace-orders/detail.php?id='.(int)$order['id']); ?>" title="Detail"><i class="bi bi-eye"></i></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
