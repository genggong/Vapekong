<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/Marketplace/Marketplace.php';
Auth::requirePermission('transactions.view');
$pageTitle = 'Detail Pesanan Marketplace';
$db = Database::connect();
marketplace_install($db);
$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT o.*,b.name AS branch_name,b.address AS branch_address,b.phone AS branch_phone,c.name AS customer_name,c.member_code AS customer_member_code FROM online_orders o LEFT JOIN branches b ON b.id=o.branch_id LEFT JOIN customers c ON c.id=o.customer_id WHERE o.id=? LIMIT 1");
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) { flash('error','Pesanan marketplace tidak ditemukan.'); legacy_redirect('admin/marketplace-orders/index.php'); }
$order = marketplace_expire_if_needed($db, $order);
$items = marketplace_order_items($db, $id);
$events = marketplace_order_events($db, $id);
$paymentLabel = marketplace_pakasir_methods()[$order['payment_method']] ?? strtoupper((string)$order['payment_method']);
$deliveryConfig = marketplace_delivery_config();
$storeCourierConfig = marketplace_store_courier_config();
$storePickupConfig = marketplace_store_pickup_config();
$storeCourierAssignment = ((string)$order['courier_code']==='store') ? marketplace_store_courier_assignment($db,$id) : null;
$storeCouriers = ((string)$order['courier_code']==='store') ? marketplace_store_courier_active($db,(int)$order['branch_id']) : [];
$pickupTs = time() + 2 * 3600;
$pickupDateDefault = date('Y-m-d', $pickupTs);
$pickupTimeDefault = date('H:i', $pickupTs);
$deliveryLabelUrl = '';
if (!empty($order['delivery_label_file'])) {
    $deliveryLabelUrl = preg_match('~^https?://~i', (string)$order['delivery_label_file']) ? (string)$order['delivery_label_file'] : legacy_base_url((string)$order['delivery_label_file']);
}
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<style>
.market-order-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(320px,.65fr);gap:18px;margin-top:18px}.market-order-kv{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.market-order-kv>div{padding:14px;border:1px solid #e2e8f0;border-radius:16px;background:#fff}.market-order-kv small{display:block;color:#64748b;margin-bottom:5px}.market-order-kv b{color:#0f172a}.market-order-events{display:grid;gap:10px}.market-order-event{border-left:3px solid #7c3aed;padding:8px 0 8px 14px}.market-order-event small{color:#64748b;display:block}.market-order-actions{display:grid;gap:12px}.market-order-actions input{width:100%}@media(max-width:900px){.market-order-grid{grid-template-columns:1fr}.market-order-kv{grid-template-columns:1fr}}
</style>
<main class="admin-main">
    <header class="admin-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title"><h1><?= e($order['order_number']); ?></h1><p>Detail order, pembayaran, alamat, stok fulfillment, dan pengiriman.</p></div>
        <div class="topbar-actions"><a class="btn-soft" href="<?= legacy_base_url('admin/marketplace-service/index.php'); ?>?q=<?= urlencode((string)$order['order_number']); ?>"><i class="bi bi-headset"></i> Layanan</a><a class="btn-soft" href="<?= legacy_base_url('admin/marketplace-orders/index.php'); ?>"><i class="bi bi-arrow-left"></i> Kembali</a></div>
    </header>

    <div class="market-order-grid">
        <div>
            <section class="premium-card">
                <div class="card-head"><div><h3>Informasi Pesanan</h3><p><?= e($order['recipient_name']); ?> • <?= e($order['recipient_phone']); ?></p></div><span class="badge-soft <?= $order['payment_status']==='paid'?'success':'warning'; ?>"><?= e(marketplace_payment_status_label((string)$order['payment_status'])); ?></span></div>
                <div class="market-order-kv">
                    <div><small>Status Order</small><b><?= e(marketplace_order_status_label((string)$order['order_status'])); ?></b></div>
                    <div><small>Cabang Fulfillment</small><b><?= e($order['branch_name'] ?: ('#'.$order['branch_id'])); ?></b></div>
                    <div><small>Pembayaran</small><b><?= e($paymentLabel); ?></b></div>
                    <div><small>Total Dibayar</small><b><?= rupiah((float)($order['payment_total'] ?: $order['grand_total'])); ?></b></div>
                    <div><small>Metode Penerimaan</small><b><?= e((string)$order['courier_code']==='pickup' ? ($order['courier_name'] ?: 'Ambil di Toko') : ((string)$order['courier_code']==='store' ? ($order['courier_name'] ?: 'Kurir Toko') : strtoupper((string)$order['courier_code']).' '.$order['courier_service'])); ?></b><br><small><?= e($order['shipping_etd'] ?: '-'); ?></small></div>
                    <?php if((string)$order['courier_code']==='store'): ?><div><small>Kurir Toko</small><b><?= e($storeCourierAssignment['courier_name'] ?? 'Belum ditugaskan'); ?></b><?php if($storeCourierAssignment): ?><br><small><?= e(marketplace_store_courier_status_label((string)$storeCourierAssignment['status'])); ?></small><?php endif; ?></div><?php endif; ?>
                    <?php if((string)$order['courier_code']==='pickup'): ?>
                    <div><small>Status Pengambilan</small><b><?= e(marketplace_store_pickup_status_label($order)); ?></b></div>
                    <div><small>Kode Pengambilan</small><b><?= $order['payment_status']==='paid' ? e($order['pickup_code'] ?: '-') : 'Tampil setelah lunas'; ?></b></div>
                    <?php else: ?>
                    <div><small>Resi</small><b><?= e($order['awb_number'] ?: 'Belum tersedia'); ?></b></div>
                    <div><small>Shipping Delivery</small><b><?= e($order['delivery_order_no'] ?: 'Belum dibuat'); ?></b></div>
                    <div><small>Status Kurir</small><b><?= e($order['delivery_status'] ?: '-'); ?></b></div>
                    <?php endif; ?>
                    <div><small>Berat</small><b><?= number_format((int)$order['total_weight_gram'],0,',','.'); ?> gram</b></div>
                    <div><small>Akun Pelanggan</small><b><?= e($order['customer_name'] ?: 'Guest'); ?></b><?php if (!empty($order['customer_member_code'])): ?><br><small><?= e($order['customer_member_code']); ?></small><?php endif; ?></div>
                    <div><small>Voucher</small><b><?= e($order['voucher_code'] ?: '-'); ?></b><?php if ((float)($order['discount'] ?? 0)>0): ?><br><small>- <?= rupiah((float)$order['discount']); ?></small><?php endif; ?></div>
                    <div><small>Poin Member</small><b><?= (int)($order['points_earned'] ?? 0); ?> poin</b></div>
                    <div><small>Dibuat</small><b><?= date('d/m/Y H:i', strtotime($order['created_at'])); ?></b></div>
                </div>
            </section>

            <section class="premium-card mt-4">
                <div class="card-head"><div><h3>Barang</h3><p>Harga dan modal disimpan sebagai snapshot saat checkout.</p></div></div>
                <div class="table-premium-card"><table class="premium-table"><thead><tr><th>Produk</th><th>Qty</th><th>Berat</th><th>Harga</th><th>Total</th></tr></thead><tbody>
                <?php foreach ($items as $item): ?><tr><td><b><?= e($item['product_name']); ?></b><br><small><?= e($item['product_code']); ?></small></td><td><?= (int)$item['qty']; ?> <?= e($item['unit']); ?></td><td><?= number_format((int)$item['weight_gram']*(int)$item['qty'],0,',','.'); ?> g</td><td><?= rupiah((float)$item['unit_price']); ?></td><td><b><?= rupiah((float)$item['line_total']); ?></b></td></tr><?php endforeach; ?>
                </tbody><tfoot><tr><th colspan="4">Subtotal</th><th><?= rupiah((float)$order['subtotal']); ?></th></tr><?php if((float)($order['discount']??0)>0): ?><tr><th colspan="4">Diskon<?= !empty($order['voucher_code']) ? ' • '.e($order['voucher_code']) : ''; ?></th><th>- <?= rupiah((float)$order['discount']); ?></th></tr><?php endif; ?><tr><th colspan="4">Ongkir</th><th><?= rupiah((float)$order['shipping_cost']); ?></th></tr><tr><th colspan="4">Grand Total</th><th><?= rupiah((float)$order['grand_total']); ?></th></tr></tfoot></table></div>
            </section>

            <section class="premium-card mt-4">
                <?php if((string)$order['courier_code']==='pickup'): ?>
                <div class="card-head"><div><h3>Lokasi Pengambilan</h3><p><?= e($order['branch_name'] ?: $order['destination_label']); ?></p></div></div>
                <p><b><?= nl2br(e($order['branch_address'] ?: $order['address'])); ?></b></p>
                <?php if(!empty($order['branch_phone'])): ?><p><i class="bi bi-telephone"></i> <?= e($order['branch_phone']); ?></p><?php endif; ?>
                <div class="maintenance-preview"><strong>Jam pengambilan:</strong> <?= e($storePickupConfig['hours']); ?><br><strong>Instruksi:</strong> <?= e($storePickupConfig['instructions']); ?></div>
                <?php else: ?>
                <div class="card-head"><div><h3>Alamat Pengiriman</h3><p><?= e($order['destination_label']); ?></p></div></div>
                <p><b><?= nl2br(e($order['address'])); ?></b></p>
                <p><?= e(implode(', ', array_filter([$order['subdistrict'],$order['district'],$order['city'],$order['province'],$order['postal_code']]))); ?></p>
                <?php endif; ?>
                <?php if (!empty($order['customer_note'])): ?><div class="maintenance-preview"><strong>Catatan:</strong> <?= e($order['customer_note']); ?></div><?php endif; ?>
            </section>
        </div>

        <div>
            <section class="premium-card">
                <div class="card-head"><div><h3>Proses Pesanan</h3><p>Urutan fulfillment marketplace.</p></div></div>
                <div class="market-order-actions">
                    <?php if ($order['payment_status']==='paid' && $order['order_status']==='paid'): ?>
                    <form method="post" action="<?= legacy_base_url('admin/marketplace-orders/action.php'); ?>"><?= CSRF::input(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="processing"><button class="btn-premium" style="width:100%;"><i class="bi bi-box-seam"></i> Mulai Proses Pesanan</button></form>
                    <?php endif; ?>
                    <?php if ((string)$order['courier_code']==='store' && $order['payment_status']==='paid' && !in_array($order['order_status'],['completed','cancelled','expired'],true)): ?>
                        <div style="padding:14px;border:1px solid #ddd6fe;border-radius:16px;background:#faf5ff;display:grid;gap:10px">
                            <b><i class="bi bi-bicycle"></i> Kurir Toko</b>
                            <?php if($storeCourierAssignment): ?><small>Ditugaskan ke <b><?= e($storeCourierAssignment['courier_name']); ?></b> • <?= e(marketplace_store_courier_status_label((string)$storeCourierAssignment['status'])); ?></small><?php else: ?><small>Belum ada kurir yang ditugaskan.</small><?php endif; ?>
                            <?php if(!$storeCourierAssignment || !in_array((string)$storeCourierAssignment['status'],['picked_up','on_the_way','delivered'],true)): ?>
                            <form method="post" action="<?= legacy_base_url('admin/marketplace-orders/action.php'); ?>" style="display:grid;gap:8px"><?= CSRF::input(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="assign_store_courier"><label>Pilih Kurir</label><select name="courier_id" required><option value="">Pilih kurir toko</option><?php foreach($storeCouriers as $courier): ?><option value="<?= (int)$courier['id']; ?>" <?= (int)($storeCourierAssignment['courier_id']??0)===(int)$courier['id']?'selected':''; ?>><?= e($courier['name'].' • '.$courier['vehicle_type'].(!empty($courier['vehicle_number'])?' '.$courier['vehicle_number']:'')); ?></option><?php endforeach; ?></select><button class="btn-premium"><i class="bi bi-person-check"></i> <?= $storeCourierAssignment?'Ganti Penugasan':'Tugaskan Kurir'; ?></button></form>
                            <?php endif; ?>
                            <?php if((string)$order['fulfillment_status']!=='ready_ship' && empty($storeCourierAssignment['picked_up_at'])): ?><small style="color:#92400e">Kurir boleh menerima tugas, tetapi tombol ambil paket baru aktif setelah Picking & Packing berstatus <b>Siap Dikirim</b>.</small><?php endif; ?>
                            <?php if($storeCourierAssignment && !empty($storeCourierAssignment['failure_reason'])): ?><div style="padding:10px 12px;border-radius:12px;background:#fef2f2;color:#991b1b"><b>Alasan gagal:</b> <?= e($storeCourierAssignment['failure_reason']); ?></div><?php endif; ?>
                            <?php if($storeCourierAssignment && !empty($storeCourierAssignment['delivery_proof'])): ?><div><small style="display:block;margin-bottom:6px">Bukti pengantaran</small><a target="_blank" rel="noopener" href="<?= e(legacy_base_url($storeCourierAssignment['delivery_proof'])); ?>"><img src="<?= e(legacy_base_url($storeCourierAssignment['delivery_proof'])); ?>" alt="Bukti pengantaran" style="width:100%;max-height:220px;object-fit:cover;border-radius:14px;border:1px solid #e2e8f0"></a></div><?php endif; ?>
                            <a class="btn-soft" href="<?= legacy_base_url('admin/marketplace-courier-deliveries/index.php'); ?>"><i class="bi bi-activity"></i> Monitor Pengiriman</a>
                        </div>
                    <?php endif; ?>
                    <?php if ((string)$order['courier_code']==='pickup' && $order['payment_status']==='paid' && !in_array($order['order_status'],['cancelled','expired'],true)): ?>
                        <div style="padding:14px;border:1px solid #d8b4fe;border-radius:16px;background:#faf5ff;display:grid;gap:10px">
                            <b><i class="bi bi-shop-window"></i> Ambil di Toko</b>
                            <small>Toko: <b><?= e($order['branch_name'] ?: ('Cabang #'.$order['branch_id'])); ?></b></small>
                            <small>Status: <b><?= e(marketplace_store_pickup_status_label($order)); ?></b></small>
                            <?php if(!empty($order['pickup_code'])): ?><div style="padding:12px;border-radius:12px;background:#fff;border:1px dashed #c084fc;text-align:center"><small>Kode Pengambilan</small><div style="font-size:28px;font-weight:900;letter-spacing:6px;color:#6d28d9"><?= e($order['pickup_code']); ?></div></div><?php endif; ?>
                            <?php if((string)$order['fulfillment_status']==='ready_ship'): ?><form method="post" action="<?= legacy_base_url('admin/marketplace-orders/action.php'); ?>"><?= CSRF::input(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="pickup_ready"><button class="btn-premium" style="width:100%"><i class="bi bi-bag-check"></i> Tandai Siap Diambil</button></form><?php endif; ?>
                            <?php if((string)$order['fulfillment_status']==='ready_pickup' && (string)$order['order_status']!=='completed'): ?>
                            <form method="post" action="<?= legacy_base_url('admin/marketplace-orders/action.php'); ?>" style="display:grid;gap:8px"><?= CSRF::input(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="pickup_complete"><label>Kode dari Customer<input name="pickup_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="6 digit" required></label><label>Diserahkan Kepada<input name="pickup_recipient" value="<?= e($order['recipient_name']); ?>" required></label><label>Catatan <small>(opsional)</small><input name="pickup_note" placeholder="Contoh: menunjukkan KTP / diwakilkan"></label><button class="btn-premium"><i class="bi bi-check2-circle"></i> Verifikasi & Selesaikan</button></form>
                            <?php elseif((string)$order['order_status']==='completed'): ?><div style="padding:10px 12px;border-radius:12px;background:#ecfdf5;color:#047857"><b>Sudah diambil</b><?= !empty($order['pickup_recipient'])?' oleh '.e($order['pickup_recipient']):''; ?><?= !empty($order['picked_up_at'])?' • '.e(date('d/m/Y H:i',strtotime($order['picked_up_at']))):''; ?></div><?php else: ?><small style="color:#92400e">Selesaikan Picking & Packing sampai status Siap Diambil. Customer akan menerima kode pengambilan setelah pembayaran.</small><?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($deliveryConfig['enabled'] && !in_array((string)$order['courier_code'],['store','pickup'],true) && $order['payment_status']==='paid' && in_array($order['order_status'],['paid','processing'],true)): ?>
                        <div style="padding:14px;border:1px solid #e2e8f0;border-radius:16px;background:#f8fafc;display:grid;gap:10px">
                            <b><i class="bi bi-truck-front-fill"></i> Shipping Delivery Otomatis</b>
                            <?php if (empty($order['delivery_order_no'])): ?>
                                <form method="post" action="<?= legacy_base_url('admin/marketplace-orders/action.php'); ?>"><?= CSRF::input(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="delivery_create"><button class="btn-premium" style="width:100%;"><i class="bi bi-cloud-arrow-up"></i> Buat Pengiriman Otomatis</button></form>
                            <?php elseif (empty($order['delivery_pickup_at'])): ?>
                                <small>Order kurir: <b><?= e($order['delivery_order_no']); ?></b></small>
                                <form method="post" action="<?= legacy_base_url('admin/marketplace-orders/action.php'); ?>" style="display:grid;gap:8px"><?= CSRF::input(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="delivery_pickup"><label>Tanggal Pickup</label><input type="date" name="pickup_date" min="<?= date('Y-m-d'); ?>" value="<?= e($pickupDateDefault); ?>" required><label>Jam Pickup</label><input type="time" name="pickup_time" value="<?= e($pickupTimeDefault); ?>" required><label>Kendaraan</label><select name="pickup_vehicle"><?php foreach(['Motor','Mobil','Truk'] as $vehicle): ?><option <?= $deliveryConfig['pickup_vehicle']===$vehicle?'selected':''; ?>><?= e($vehicle); ?></option><?php endforeach; ?></select><button class="btn-premium"><i class="bi bi-calendar2-check"></i> Request Pickup</button></form>
                            <?php else: ?>
                                <small>Pickup: <b><?= e($order['delivery_pickup_date'].' '.$order['delivery_pickup_time']); ?></b> • <?= e($order['delivery_pickup_vehicle']); ?></small>
                                <?php if ($deliveryLabelUrl === ''): ?><form method="post" action="<?= legacy_base_url('admin/marketplace-orders/action.php'); ?>"><?= CSRF::input(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="delivery_label"><button class="btn-premium" style="width:100%;"><i class="bi bi-printer"></i> Buat Label Pengiriman</button></form><?php else: ?><a class="btn-premium" target="_blank" rel="noopener" href="<?= e($deliveryLabelUrl); ?>"><i class="bi bi-file-earmark-pdf"></i> Buka / Cetak Label</a><?php endif; ?>
                                <?php if (!empty($order['awb_number'])): ?><form method="post" action="<?= legacy_base_url('admin/marketplace-orders/action.php'); ?>"><?= CSRF::input(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="delivery_sync"><button class="btn-soft" style="width:100%;"><i class="bi bi-arrow-repeat"></i> Sinkron Tracking</button></form><?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!in_array((string)$order['courier_code'],['store','pickup'],true) && in_array($order['order_status'],['paid','processing'],true) && $order['payment_status']==='paid'): ?>
                    <form method="post" action="<?= legacy_base_url('admin/marketplace-orders/action.php'); ?>" style="padding-top:4px;border-top:1px dashed #cbd5e1"><?= CSRF::input(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="shipped"><label>Resi Manual / Fallback</label><input name="awb_number" value="<?= e($order['awb_number']); ?>" placeholder="Masukkan nomor resi" required><button class="btn-soft" style="width:100%;margin-top:10px;"><i class="bi bi-truck"></i> Tandai Dikirim Manual</button></form>
                    <?php endif; ?>
                    <?php if (!in_array((string)$order['courier_code'],['store','pickup'],true) && $order['order_status']==='shipped'): ?>
                    <form method="post" action="<?= legacy_base_url('admin/marketplace-orders/action.php'); ?>"><?= CSRF::input(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="completed"><button class="btn-premium" style="width:100%;"><i class="bi bi-check2-circle"></i> Tandai Selesai</button></form>
                    <?php endif; ?>
                    <?php if ($order['payment_status']!=='paid' && !in_array($order['order_status'],['cancelled','expired','completed'],true)): ?>
                    <form method="post" action="<?= legacy_base_url('admin/marketplace-orders/action.php'); ?>" class="js-confirm-form" data-confirm="Batalkan order ini? Stok yang di-reserve akan dikembalikan ke cabang."><?= CSRF::input(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="cancel"><button class="btn-soft" style="width:100%;"><i class="bi bi-x-circle"></i> Batalkan Order</button></form>
                    <?php endif; ?>
                    <?php if ($order['payment_status']==='paid'): ?><small style="color:#64748b;">Order yang sudah dibayar memakai alur Pembatalan/Refund di menu Layanan Marketplace agar refund, stok, poin, dan referensi pengembalian dana tercatat.</small><?php endif; ?>
                </div>
            </section>

            <?php if (!empty($order['delivery_live_tracking_url'])): ?><section class="premium-card mt-4"><div class="card-head"><div><h3>Live Tracking Kurir</h3><p>Status langsung dari Shipping Delivery.</p></div></div><a class="btn-soft" target="_blank" rel="noopener" href="<?= e($order['delivery_live_tracking_url']); ?>"><i class="bi bi-geo-alt"></i> Buka Live Tracking</a></section><?php endif; ?>

            <section class="premium-card mt-4">
                <div class="card-head"><div><h3>Timeline</h3><p>Riwayat status order.</p></div></div>
                <div class="market-order-events"><?php foreach ($events as $event): ?><div class="market-order-event"><b><?= e($event['title']); ?></b><small><?= e($event['description']); ?></small><small><?= date('d/m/Y H:i', strtotime($event['created_at'])); ?></small></div><?php endforeach; ?></div>
            </section>
        </div>
    </div>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
