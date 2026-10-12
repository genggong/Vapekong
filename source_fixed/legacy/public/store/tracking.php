<?php
require_once __DIR__ . '/_data.php';
$pageTitle = 'Tracking Pesanan';
$activePage = 'tracking';
$code = trim((string) ($_GET['code'] ?? ''));
$result = $code !== '' ? storefront_track($code) : null;
$deliveryTrack = null;
$storeCourierTrack = null;
if ($result && ($result['type'] ?? '') === 'online_order' && (string)($result['data']['courier_code'] ?? '') === 'store') {
    try { $trackDb = storefront_db(); if($trackDb) $storeCourierTrack = marketplace_store_courier_assignment($trackDb,(int)$result['data']['id']); } catch(Throwable $ignore) {}
}
if ($result && ($result['type'] ?? '') === 'online_order' && !empty($result['data']['awb_number'])) {
    try {
        $trackDb = storefront_db();
        if ($trackDb) {
            $deliveryTrack = marketplace_delivery_tracking($trackDb, $result['data']);
            $refreshed = storefront_track($code);
            if ($refreshed) $result = $refreshed;
        }
    } catch (Throwable $ignore) {}
}
require __DIR__ . '/_header.php';

function storefront_tracking_step(string $orderStatus, string $step): string
{
    $rank = ['pending_payment'=>0,'paid'=>1,'processing'=>2,'shipped'=>3,'completed'=>4];
    $need = ['created'=>0,'paid'=>1,'processing'=>2,'shipped'=>3,'completed'=>4];
    if (in_array($orderStatus, ['cancelled','expired'], true)) return $step === 'created' ? 'done' : '';
    $current = $rank[$orderStatus] ?? 0;
    $target = $need[$step] ?? 0;
    if ($current > $target || ($current === 4 && $target === 4)) return 'done';
    if ($current === $target) return 'active';
    return '';
}

function storefront_pickup_tracking_step(array $order, string $step): string
{
    $need=['created'=>0,'paid'=>1,'processing'=>2,'ready_pickup'=>3,'completed'=>4];
    if(in_array((string)($order['order_status']??''),['cancelled','expired'],true)) return $step==='created'?'done':'';
    $current=0;
    if((string)($order['payment_status']??'')==='paid') $current=1;
    if(in_array((string)($order['fulfillment_status']??''),['picking','packing','ready_ship'],true) || (string)($order['order_status']??'')==='processing') $current=max($current,2);
    if((string)($order['fulfillment_status']??'')==='ready_pickup') $current=3;
    if((string)($order['order_status']??'')==='completed') $current=4;
    $target=$need[$step]??0;
    if($current>$target || ($current===4&&$target===4)) return 'done';
    if($current===$target) return 'active';
    return '';
}
?>
<main class="inner-page tracking-page">
    <section class="store-container page-hero small"><div><span class="hero-label"><i class="bi bi-truck"></i> ORDER TRACKING</span><h1>Lacak Pesanan <span>Anda</span></h1><p>Masukkan nomor order marketplace, invoice POS, atau nomor resi pengiriman.</p></div><div class="page-hero-icon"><i class="bi bi-geo-alt-fill"></i></div></section>
    <section class="store-container tracking-search-panel"><form method="get"><i class="bi bi-search"></i><input name="code" value="<?= e($code); ?>" placeholder="Contoh: MKT-20260925-XXXX / nomor resi" required><button class="btn-primary-store" type="submit">Lacak Pesanan</button></form></section>
    <?php if ($code !== ''): ?>
        <section class="store-container tracking-result-card <?= $result ? 'found' : 'not-found'; ?>">
            <?php if (!$result): ?>
                <div class="tracking-empty"><span><i class="bi bi-search-heart"></i></span><h2>Pesanan belum ditemukan</h2><p>Periksa kembali nomor order, invoice, atau resi yang dimasukkan.</p></div>
            <?php else: $d = $result['data']; ?>
                <?php if ($result['type'] === 'online_order'): ?>
                    <?php $status = (string)($d['order_status'] ?? 'pending_payment'); ?>
                    <div class="tracking-result-head"><div><span class="eyebrow">STATUS MARKETPLACE</span><h2><?= e($d['order_number'] ?? $code); ?></h2></div><span class="tracking-status-badge"><?= e(strtoupper(marketplace_order_status_label($status))); ?></span></div>
                    <div class="tracking-meta">
                        <div><small>Penerima</small><b><?= e($d['recipient_name'] ?? '-'); ?></b></div>
                        <div><small>Metode</small><b><?= e((string)($d['courier_code']??'')==='pickup' ? 'Ambil di Toko' : ((string)($d['courier_code']??'')==='store' ? ($d['courier_name'] ?: 'Kurir Toko') : strtoupper((string)($d['courier_code'] ?? '')).' '.($d['courier_service'] ?? ''))); ?></b></div>
                        <div><small><?= (string)($d['courier_code']??'')==='pickup'?'Kode Pengambilan':((string)($d['courier_code']??'')==='store'?'Kurir Toko':'Resi'); ?></small><b><?= e((string)($d['courier_code']??'')==='pickup' ? (($d['payment_status']??'')==='paid' ? ($d['pickup_code'] ?: '-') : 'Tampil setelah lunas') : ((string)($d['courier_code']??'')==='store' ? ($storeCourierTrack['courier_name'] ?? 'Menunggu penugasan') : ($d['awb_number'] ?: 'Belum tersedia'))); ?></b></div>
                        <div><small>Status</small><b><?= e((string)($d['courier_code']??'')==='pickup' ? marketplace_store_pickup_status_label($d) : ((string)($d['courier_code']??'')==='store' && $storeCourierTrack ? marketplace_store_courier_status_label((string)$storeCourierTrack['status']) : ($d['delivery_status'] ?: '-'))); ?></b></div>
                        <div><small>Tanggal</small><b><?= !empty($d['created_at']) ? date('d/m/Y H:i', strtotime($d['created_at'])) : '-'; ?></b></div>
                    </div>
                    <?php if (in_array($status,['cancelled','expired'],true)): ?><div class="tracking-empty" style="padding:20px 0 0;"><p>Pesanan <?= $status === 'expired' ? 'kedaluwarsa karena pembayaran tidak selesai' : 'telah dibatalkan'; ?>.</p></div><?php endif; ?>
                    <?php if((string)($d['courier_code']??'')==='pickup'): ?>
                    <div class="tracking-timeline">
                        <div class="<?= storefront_pickup_tracking_step($d,'created'); ?>"><span><i class="bi bi-check-lg"></i></span><div><b>Pesanan tercatat</b><small>Order tersimpan dan stok di toko di-reserve.</small></div></div>
                        <div class="<?= storefront_pickup_tracking_step($d,'paid'); ?>"><span><i class="bi bi-credit-card-2-front"></i></span><div><b>Pembayaran</b><small><?= $d['payment_status']==='paid' ? 'Pembayaran sudah terkonfirmasi.' : 'Menunggu konfirmasi pembayaran.'; ?></small></div></div>
                        <div class="<?= storefront_pickup_tracking_step($d,'processing'); ?>"><span><i class="bi bi-box-seam"></i></span><div><b>Picking & Packing</b><small>Barang sedang disiapkan oleh toko pilihan Anda.</small></div></div>
                        <div class="<?= storefront_pickup_tracking_step($d,'ready_pickup'); ?>"><span><i class="bi bi-shop-window"></i></span><div><b>Siap Diambil</b><small><?= (string)($d['fulfillment_status']??'')==='ready_pickup' ? 'Silakan datang ke toko dan tunjukkan kode pengambilan.' : 'Tunggu sampai paket selesai dipacking.'; ?></small></div></div>
                        <div class="<?= storefront_pickup_tracking_step($d,'completed'); ?>"><span><i class="bi bi-bag-check"></i></span><div><b>Sudah Diambil</b><small>Pesanan telah diserahkan di toko.</small></div></div>
                    </div>
                    <?php else: ?>
                    <div class="tracking-timeline">
                        <div class="<?= storefront_tracking_step($status,'created'); ?>"><span><i class="bi bi-check-lg"></i></span><div><b>Pesanan tercatat</b><small>Order marketplace sudah tersimpan dan stok di-reserve.</small></div></div>
                        <div class="<?= storefront_tracking_step($status,'paid'); ?>"><span><i class="bi bi-credit-card-2-front"></i></span><div><b>Pembayaran</b><small><?= $d['payment_status']==='paid' ? 'Pembayaran sudah terkonfirmasi.' : 'Menunggu konfirmasi pembayaran.'; ?></small></div></div>
                        <div class="<?= storefront_tracking_step($status,'processing'); ?>"><span><i class="bi bi-box-seam"></i></span><div><b>Diproses</b><small>Barang disiapkan oleh cabang fulfillment.</small></div></div>
                        <div class="<?= storefront_tracking_step($status,'shipped'); ?>"><span><i class="bi bi-truck"></i></span><div><b>Pengiriman</b><small><?php if((string)($d['courier_code']??'')==='store'): ?><?= e($storeCourierTrack ? marketplace_store_courier_status_label((string)$storeCourierTrack['status']) : 'Menunggu penugasan kurir toko'); ?><?php else: ?><?= !empty($d['awb_number']) ? 'Resi: '.e($d['awb_number']) : 'Nomor resi akan muncul setelah barang dikirim.'; ?><?php endif; ?></small></div></div>
                        <div class="<?= storefront_tracking_step($status,'completed'); ?>"><span><i class="bi bi-house-check"></i></span><div><b>Selesai</b><small>Pesanan diterima dan diselesaikan.</small></div></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($d['delivery_live_tracking_url'])): ?><div style="margin-top:16px"><a class="btn-primary-store" target="_blank" rel="noopener" href="<?= e($d['delivery_live_tracking_url']); ?>"><i class="bi bi-geo-alt"></i> Live Tracking Kurir</a></div><?php endif; ?>
                    <?php if (is_array($deliveryTrack) && !empty($deliveryTrack['history'])): ?><div style="margin-top:22px"><h3>Riwayat Kurir</h3><div class="tracking-timeline"><?php foreach(array_slice(array_reverse($deliveryTrack['history']),0,12) as $h): ?><div class="done"><span><i class="bi bi-geo"></i></span><div><b><?= e((string)($h['status'] ?? $h['description'] ?? 'Update')); ?></b><small><?= e((string)($h['description'] ?? $h['note'] ?? '')); ?> <?= e((string)($h['date'] ?? $h['datetime'] ?? '')); ?></small></div></div><?php endforeach; ?></div></div><?php endif; ?>
                <?php else: ?>
                    <div class="tracking-result-head"><div><span class="eyebrow">STATUS PESANAN</span><h2><?= e($result['type'] === 'transaction' ? ($d['invoice_number'] ?? $code) : ($d['awb_number'] ?? $code)); ?></h2></div><span class="tracking-status-badge"><?= e(strtoupper((string) ($d['tracking_status'] ?? $d['status'] ?? 'PROCESS'))); ?></span></div>
                    <?php if ($result['type'] === 'transaction'): ?><div class="tracking-meta"><div><small>Pelanggan</small><b><?= e($d['customer_name'] ?: 'Umum'); ?></b></div><div><small>Cabang</small><b><?= e($d['branch_name'] ?: '-'); ?></b></div><div><small>Total</small><b><?= rupiah($d['grand_total']); ?></b></div><div><small>Tanggal</small><b><?= !empty($d['transaction_date']) ? date('d/m/Y H:i', strtotime($d['transaction_date'])) : '-'; ?></b></div></div><?php endif; ?>
                    <div class="tracking-timeline"><div class="done"><span><i class="bi bi-check-lg"></i></span><div><b>Pesanan tercatat</b><small>Pesanan sudah tersimpan di sistem.</small></div></div><div class="<?= (($d['status'] ?? '') === 'success') ? 'done' : 'active'; ?>"><span><i class="bi bi-box-seam"></i></span><div><b>Diproses</b><small>Barang disiapkan oleh toko.</small></div></div><div class="active"><span><i class="bi bi-truck"></i></span><div><b>Pengiriman</b><small>Status akan tampil setelah data pengiriman tersedia.</small></div></div><div><span><i class="bi bi-house-check"></i></span><div><b>Selesai</b><small>Pesanan diterima pelanggan.</small></div></div></div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
