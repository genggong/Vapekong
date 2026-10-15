<?php
require_once __DIR__ . '/_data.php';
$db = storefront_db();
$orderNumber = trim((string) ($_GET['order'] ?? ''));
$token = trim((string) ($_GET['token'] ?? ''));
$order = ($db && $orderNumber !== '' && $token !== '') ? marketplace_get_order($db, $orderNumber, $token) : null;
if ($order) $order = marketplace_expire_if_needed($db, $order);
$items = $order ? marketplace_order_items($db, (int) $order['id']) : [];
$events = $order ? marketplace_order_events($db, (int) $order['id']) : [];
$storeCourierAssignment = ($order && (string)($order['courier_code'] ?? '') === 'store') ? marketplace_store_courier_assignment($db,(int)$order['id']) : null;
$pageTitle = $order ? ('Pesanan ' . $order['order_number']) : 'Pesanan Tidak Ditemukan';
$activePage = 'order';
require __DIR__ . '/_header.php';
?>
<main class="inner-page order-payment-page">
    <?php if (!$order): ?>
        <section class="store-container order-not-found">
            <span><i class="bi bi-receipt-cutoff"></i></span>
            <h1>Pesanan tidak ditemukan</h1>
            <p>Link pesanan tidak valid atau sudah berubah.</p>
            <a class="btn-primary-store" href="<?= legacy_base_url(); ?>">Kembali ke Marketplace</a>
        </section>
    <?php else: ?>
        <section class="store-container order-page-head">
            <div>
                <span class="hero-label"><i class="bi bi-receipt"></i> PESANAN ONLINE</span>
                <h1><?= e($order['order_number']); ?></h1>
                <p>Dibuat <?= e(date('d M Y H:i', strtotime((string) $order['created_at']))); ?> • <?= e($order['branch_name'] ?: 'Cabang'); ?></p>
            </div>
            <div class="order-status-pills">
                <span class="order-status-pill <?= e($order['payment_status']); ?>"><i class="bi bi-wallet2"></i><?= e(marketplace_payment_status_label((string) $order['payment_status'])); ?></span>
                <span class="order-status-pill <?= e($order['order_status']); ?>"><i class="bi bi-box-seam"></i><?= e(marketplace_order_status_label((string) $order['order_status'])); ?></span>
            </div>
        </section>

        <section class="store-container order-layout">
            <div class="order-main-column">
                <section class="checkout-box payment-instruction-card">
                    <div class="checkout-box-title"><span><i class="bi bi-credit-card"></i></span><div><small>PEMBAYARAN</small><h2>Instruksi Pembayaran</h2></div></div>
                    <?php if ($order['payment_status'] === 'paid'): ?>
                        <div class="payment-success-panel"><span><i class="bi bi-check2-circle"></i></span><div><h3>Pembayaran berhasil</h3><p>Pesanan Anda sudah masuk antrean proses toko.</p></div></div>
                    <?php elseif (in_array($order['payment_status'], ['expired','failed','cancelled'], true)): ?>
                        <div class="payment-expired-panel"><span><i class="bi bi-x-circle"></i></span><div><h3>Pembayaran tidak aktif</h3><p>Pesanan telah dibatalkan dan stok produk dikembalikan.</p></div></div>
                    <?php elseif ($order['payment_status'] === 'error'): ?>
                        <div class="payment-error-panel"><span><i class="bi bi-arrow-repeat"></i></span><div><h3>Instruksi pembayaran belum terbentuk</h3><p>Pesanan sudah tersimpan. Coba buat ulang instruksi pembayaran dengan tombol di bawah.</p></div></div>
                        <button class="btn-primary-store payment-retry-btn" type="button" id="retryPaymentBtn"><i class="bi bi-arrow-clockwise"></i> Coba Pembayaran Lagi</button>
                    <?php else: ?>
                        <div class="payment-amount-panel">
                            <span>Total yang harus dibayar</span>
                            <strong><?= rupiah((float) ($order['payment_total'] ?: $order['grand_total'])); ?></strong>
                            <?php if ((float) $order['payment_fee'] > 0): ?><small>Termasuk biaya kanal pembayaran <?= rupiah((float) $order['payment_fee']); ?></small><?php endif; ?>
                        </div>
                        <?php if ((string) $order['payment_method'] === 'qris' && !empty($order['payment_qr_string'])): ?>
                            <div class="qris-live-box">
                                <div id="marketplaceQr" data-qr="<?= e($order['payment_qr_string']); ?>"></div>
                                <div><b>Scan QRIS</b><p>Buka aplikasi bank/e-wallet lalu scan QR untuk menyelesaikan pembayaran.</p></div>
                            </div>
                        <?php elseif (!empty($order['payment_va_number'])): ?>
                            <div class="va-live-box">
                                <span>Nomor Virtual Account</span>
                                <div><strong id="vaNumberText"><?= e($order['payment_va_number']); ?></strong><button type="button" class="copy-payment-btn" data-copy="#vaNumberText"><i class="bi bi-copy"></i> Salin</button></div>
                                <small><?= e(marketplace_pakasir_methods()[(string) $order['payment_method']] ?? 'Virtual Account'); ?></small>
                            </div>
                        <?php else: ?>
                            <div class="payment-error-panel"><span><i class="bi bi-exclamation-triangle"></i></span><div><h3>Instruksi pembayaran belum tersedia</h3><p>QRIS atau nomor Virtual Account belum terbentuk. Gunakan tombol di bawah untuk membuat ulang instruksi pembayaran.</p></div></div>
                            <button class="btn-primary-store payment-retry-btn" type="button" id="retryPaymentBtn"><i class="bi bi-arrow-clockwise"></i> Buat Ulang Pembayaran</button>
                        <?php endif; ?>
                        <?php if (!empty($order['payment_expired_at'])): ?>
                            <div class="payment-expiry"><i class="bi bi-clock-history"></i><span>Selesaikan pembayaran sebelum <b><?= e(date('d M Y H:i', strtotime((string) $order['payment_expired_at']))); ?></b></span></div>
                        <?php endif; ?>
                        <div class="payment-auto-note"><i class="bi bi-broadcast"></i><span>Status halaman ini diperbarui otomatis setelah pembayaran terkonfirmasi.</span></div>
                    <?php endif; ?>
                </section>

                <?php if((string)$order['courier_code']==='pickup'): ?>
                <section class="checkout-box order-shipping-card store-pickup-order-card">
                    <div class="checkout-box-title"><span><i class="bi bi-shop-window"></i></span><div><small>CLICK & COLLECT</small><h2>Ambil di Toko</h2></div></div>
                    <div class="order-address-block"><i class="bi bi-shop"></i><div><b><?= e($order['branch_name'] ?: 'Toko Pengambilan'); ?></b><p><?= nl2br(e($order['branch_address'] ?: $order['address'])); ?></p><?php if(!empty($order['branch_phone'])): ?><small><i class="bi bi-telephone"></i> <?= e($order['branch_phone']); ?></small><?php endif; ?></div></div>
                    <div class="shipping-detail-grid"><div><span>Status</span><b><?= e(marketplace_store_pickup_status_label($order)); ?></b></div><div><span>Jam Pengambilan</span><b><?= e(marketplace_store_pickup_config()['hours']); ?></b></div><div><span>Ongkir</span><b>Rp 0</b></div><div><span>Berat Pesanan</span><b><?= e(number_format((int)$order['total_weight_gram'],0,',','.')); ?> gr</b></div></div>
                    <?php if($order['payment_status']==='paid' && !empty($order['pickup_code'])): ?><div class="pickup-code-customer"><small>KODE PENGAMBILAN</small><strong><?= e($order['pickup_code']); ?></strong><p>Tunjukkan kode ini kepada petugas toko saat mengambil pesanan. Jangan berikan kode sebelum Anda berada di toko.</p></div><?php endif; ?>
                    <div class="payment-auto-note" style="margin-top:12px"><i class="bi bi-info-circle"></i><span><?= e(marketplace_store_pickup_config()['instructions']); ?></span></div>
                    <?php if((string)$order['fulfillment_status']!=='ready_pickup' && (string)$order['order_status']!=='completed'): ?><div class="payment-auto-note" style="margin-top:10px"><i class="bi bi-hourglass-split"></i><span>Tunggu status <b>Siap Diambil</b> sebelum datang ke toko.</span></div><?php endif; ?>
                    <?php if((string)$order['order_status']==='completed'): ?><div class="payment-success-panel" style="margin-top:12px"><span><i class="bi bi-check2-circle"></i></span><div><h3>Pesanan sudah diambil</h3><p><?= !empty($order['pickup_recipient'])?'Diserahkan kepada '.e($order['pickup_recipient']).'.':'Pengambilan selesai.'; ?></p></div></div><?php endif; ?>
                </section>
                <?php else: ?>
                <section class="checkout-box order-shipping-card">
                    <div class="checkout-box-title"><span><i class="bi bi-truck"></i></span><div><small>PENGIRIMAN</small><h2>Detail Pengiriman</h2></div></div>
                    <div class="order-address-block"><i class="bi bi-geo-alt-fill"></i><div><b><?= e($order['recipient_name']); ?> • <?= e($order['recipient_phone']); ?></b><p><?= nl2br(e($order['address'])); ?></p><small><?= e($order['destination_label']); ?><?= $order['postal_code'] ? ' • ' . e($order['postal_code']) : ''; ?></small></div></div>
                    <div class="shipping-detail-grid"><div><span>Kurir</span><b><?= e((string)$order['courier_code']==='store' ? ($order['courier_name'] ?: 'Kurir Toko') : (strtoupper((string) $order['courier_code']) . ' ' . $order['courier_service'])); ?></b></div><div><span>Estimasi</span><b><?= e($order['shipping_etd'] ?: '-'); ?></b></div><div><span>Berat</span><b><?= e(number_format((int) $order['total_weight_gram'], 0, ',', '.')); ?> gr</b></div><div><span><?= (string)$order['courier_code']==='store'?'Status Kurir':'Resi'; ?></span><b><?= e((string)$order['courier_code']==='store' ? ($storeCourierAssignment ? marketplace_store_courier_status_label((string)$storeCourierAssignment['status']) : 'Menunggu penugasan kurir') : ($order['awb_number'] ?: 'Belum tersedia')); ?></b></div></div>
                    <?php if((string)$order['courier_code']==='store' && $storeCourierAssignment && !empty($storeCourierAssignment['delivery_proof'])): ?><div class="payment-auto-note" style="margin-top:12px"><i class="bi bi-check2-circle"></i><span>Paket diterima oleh <b><?= e($storeCourierAssignment['proof_recipient_name'] ?: $order['recipient_name']); ?></b>. Bukti pengantaran tersimpan di sistem.</span></div><?php endif; ?>
                </section>
                <?php endif; ?>

                <section class="checkout-box order-timeline-card">
                    <div class="checkout-box-title"><span><i class="bi bi-clock-history"></i></span><div><small>AKTIVITAS</small><h2>Riwayat Pesanan</h2></div></div>
                    <div class="order-event-list">
                        <?php foreach ($events as $event): ?>
                            <div class="order-event-item"><span><i class="bi bi-circle-fill"></i></span><div><b><?= e($event['title']); ?></b><p><?= e($event['description']); ?></p><small><?= e(date('d M Y H:i', strtotime((string) $event['created_at']))); ?></small></div></div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>

            <aside class="order-summary-card order-summary-live">
                <div class="checkout-order-head"><span class="eyebrow">RINGKASAN</span><h2>Detail Belanja</h2></div>
                <div class="order-item-list">
                    <?php foreach ($items as $item): ?>
                        <div class="order-summary-item"><div><b><?= e($item['product_name']); ?><?= !empty($item['variant_name']) ? ' • '.e($item['variant_name']) : ''; ?></b><small><?= e($item['qty']); ?> × <?= rupiah((float) $item['unit_price']); ?></small></div><strong><?= rupiah((float) $item['line_total']); ?></strong></div>
                    <?php endforeach; ?>
                </div>
                <div class="summary-lines"><div><span>Subtotal</span><b><?= rupiah((float) $order['subtotal']); ?></b></div><?php if ((float)($order['discount'] ?? 0) > 0): ?><div><span>Diskon<?= !empty($order['voucher_code']) ? ' • ' . e($order['voucher_code']) : ''; ?></span><b class="purple-text">- <?= rupiah((float)$order['discount']); ?></b></div><?php endif; ?><div><span>Ongkir</span><b><?= rupiah((float) $order['shipping_cost']); ?></b></div><?php if((float)($order['shipping_discount']??0)>0):?><div><span>Gratis Ongkir</span><b class="purple-text">- <?=rupiah((float)$order['shipping_discount'])?></b></div><?php endif;?><?php if((float)($order['points_discount']??0)>0):?><div><span>Poin (<?= (int)$order['points_redeemed']?>)</span><b class="purple-text">- <?=rupiah((float)$order['points_discount'])?></b></div><?php endif;?><div class="grand"><span>Total Pesanan</span><strong><?= rupiah((float) $order['grand_total']); ?></strong></div><?php if ((int)($order['points_earned'] ?? 0) > 0): ?><div><span>Poin Member</span><b>+ <?= (int)$order['points_earned']; ?> poin</b></div><?php endif; ?><?php if ((float) $order['payment_total'] > (float) $order['grand_total']): ?><div><span>Total Pembayaran</span><b><?= rupiah((float) $order['payment_total']); ?></b></div><?php endif; ?></div>
                <?php if ($storeCustomerId > 0 && (int)($order['customer_id'] ?? 0) === $storeCustomerId): ?>
                <div style="display:grid;gap:8px;margin-top:12px">
                    <?php if ((string)$order['order_status'] === 'shipped'): ?><form method="post" action="<?= legacy_base_url('store/order-customer-action.php'); ?>"><?= CSRF::input(); ?><input type="hidden" name="order_id" value="<?= (int)$order['id']; ?>"><input type="hidden" name="action" value="confirm_received"><button class="btn-primary-store full" type="submit"><i class="bi bi-check2-circle"></i> Konfirmasi Barang Diterima</button></form><?php endif; ?>
                    <?php if (!in_array((string)$order['order_status'], ['shipped','completed','cancelled','expired'], true)): ?><form method="post" action="<?= legacy_base_url('store/order-customer-action.php'); ?>" onsubmit="return confirm('Ajukan pembatalan pesanan ini?')"><?= CSRF::input(); ?><input type="hidden" name="order_id" value="<?= (int)$order['id']; ?>"><input type="hidden" name="action" value="cancel_request"><button class="btn-soft full" type="submit"><i class="bi bi-x-circle"></i> Batalkan / Ajukan Refund</button></form><?php endif; ?>
                    <a class="btn-soft full" href="<?= legacy_base_url('bantuan-pesanan'); ?>"><i class="bi bi-headset"></i> Komplain / Bantuan</a>
                </div>
                <?php endif; ?>
                <a class="btn-soft full" href="<?= legacy_base_url('tracking'); ?>?code=<?= urlencode((string) $order['order_number']); ?>"><i class="bi <?= (string)$order['courier_code']==='pickup'?'bi-shop-window':'bi-truck'; ?>"></i> <?= (string)$order['courier_code']==='pickup'?'Status Pengambilan':'Lacak Pesanan'; ?></a>
                <a class="btn-soft full" href="<?= legacy_base_url('store/invoice.php'); ?>?order=<?= urlencode((string)$order['order_number']); ?>&token=<?= urlencode((string)$order['public_token']); ?>"><i class="bi bi-file-earmark-pdf"></i> Download Invoice PDF</a>
                <a class="drawer-link" href="<?= legacy_base_url(); ?>">Kembali Belanja</a>
            </aside>
        </section>

        <div id="orderLiveConfig" data-order="<?= e($order['order_number']); ?>" data-token="<?= e($order['public_token']); ?>" data-status-url="<?= e(legacy_base_url('store/api/payment-status.php')); ?>" data-retry-url="<?= e(legacy_base_url('store/api/retry-payment.php')); ?>" data-csrf="<?= e(CSRF::token()); ?>"></div>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
        <script src="<?= legacy_base_url('assets/js/marketplace-order.js?v=15.2.0'); ?>"></script>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
