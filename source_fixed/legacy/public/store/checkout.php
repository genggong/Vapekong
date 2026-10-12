<?php
require_once __DIR__ . '/_data.php';
$pageTitle = 'Checkout';
$activePage = 'checkout';
$db = storefront_db();
$branch = storefront_primary_branch();
$raja = marketplace_rajaongkir_config();
$payConfig = $db ? marketplace_pakasir_config($db) : ['project'=>'','api_key'=>''];
$pickupConfig = marketplace_store_pickup_config();
$deliveryReady = $db && $raja['api_key'] !== '' && $raja['origin_id'] > 0;
$pickupReady = $db && !empty($pickupConfig['enabled']);
$paymentReady = $db && $payConfig['project'] !== '' && $payConfig['api_key'] !== '';
$checkoutReady = $paymentReady && ($deliveryReady || $pickupReady);
$paymentMethods = marketplace_pakasir_methods();
$customer = $db ? marketplace_customer($db) : null;
$savedAddresses = ($db && $customer) ? marketplace_customer_addresses($db, (int)$customer['id']) : [];
$pointsAvailable = (int)($customer['points'] ?? 0);
$pointValue = max(1,(float)setting('member_point_value','100'));
$pointMinRedeem = max(1,(int)setting('member_point_min_redeem','1'));
$pointsEnabled = $customer && setting('marketplace_points_redeem_enabled','on')==='on';
$bodyClass = 'checkout-page-body';
require __DIR__ . '/_header.php';
?>
<main class="inner-page checkout-live-page">
    <section class="store-container page-hero small">
        <div>
            <span class="hero-label"><i class="bi bi-shield-lock-fill"></i> CHECKOUT AMAN</span>
            <h1>Selesaikan <span>Pesananmu</span></h1>
            <p>Alamat, ongkir, stok, dan pembayaran divalidasi ulang oleh server sebelum pesanan dibuat.</p>
        </div>
        <div class="page-hero-icon"><i class="bi bi-credit-card-2-front-fill"></i></div>
    </section>

    <?php if (!$checkoutReady): ?>
        <section class="store-container checkout-system-warning">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div><b>Checkout online belum siap digunakan.</b><span>Admin perlu melengkapi konfigurasi lokasi pengiriman dan pembayaran terlebih dahulu.</span></div>
        </section>
    <?php endif; ?>

    <form id="marketplaceCheckoutForm" class="store-container checkout-page-grid" autocomplete="on"
          data-region-url="<?= e(legacy_base_url('store/api/region-options.php')); ?>"
          data-resolve-url="<?= e(legacy_base_url('store/api/resolve-destination.php')); ?>"
          data-shipping-url="<?= e(legacy_base_url('store/api/shipping-cost.php')); ?>"
          data-pickup-url="<?= e(legacy_base_url('store/api/pickup-options.php')); ?>"
          data-delivery-ready="<?= $deliveryReady ? '1' : '0'; ?>" data-pickup-ready="<?= $pickupReady ? '1' : '0'; ?>"
          data-order-url="<?= e(legacy_base_url('store/api/create-order.php')); ?>"
          data-voucher-url="<?= e(legacy_base_url('store/api/voucher-check.php')); ?>"
          data-point-value="<?= e((string)$pointValue); ?>" data-point-min="<?= (int)$pointMinRedeem; ?>" data-point-available="<?= (int)$pointsAvailable; ?>"
          data-ready="<?= $checkoutReady ? '1' : '0'; ?>">
        <?= CSRF::input(); ?>
        <input type="hidden" name="checkout_key" id="checkoutKey" value="<?= e(bin2hex(random_bytes(16))); ?>">
        <input type="hidden" name="destination_id" id="destinationId" value="">
        <input type="hidden" name="destination_label" id="destinationLabel" value="">
        <input type="hidden" name="province" id="destinationProvince" value="">
        <input type="hidden" name="city" id="destinationCity" value="">
        <input type="hidden" name="district" id="destinationDistrict" value="">
        <input type="hidden" name="subdistrict" id="destinationSubdistrict" value="">
        <input type="hidden" name="courier_code" id="courierCode" value="">
        <input type="hidden" name="courier_service" id="courierService" value="">
        <input type="hidden" name="shipping_cost" id="shippingCostValue" value="0">
        <input type="hidden" name="pickup_branch_id" id="pickupBranchId" value="">
        <input type="hidden" name="fulfillment_method" id="fulfillmentMethod" value="delivery">

        <div class="checkout-main-col">
            <section class="checkout-box checkout-fulfillment-choice">
                <div class="checkout-box-title"><span><i class="bi bi-signpost-split-fill"></i></span><div><small>LANGKAH 1</small><h2>Cara Menerima Pesanan</h2></div></div>
                <div class="fulfillment-method-grid">
                    <label class="fulfillment-method-card <?= $deliveryReady ? 'active' : 'disabled'; ?>" data-method="delivery">
                        <input type="radio" name="fulfillment_choice" value="delivery" <?= $deliveryReady ? 'checked' : 'disabled'; ?>>
                        <span class="fulfillment-method-icon"><i class="bi bi-truck"></i></span><span><b>Dikirim ke Alamat</b><small><?= $deliveryReady ? 'RajaOngkir / Kurir Toko' : 'Konfigurasi pengiriman belum siap'; ?></small></span><i class="bi bi-check-circle-fill fulfillment-method-check"></i>
                    </label>
                    <label class="fulfillment-method-card <?= !$deliveryReady && $pickupReady ? 'active' : ''; ?> <?= $pickupReady ? '' : 'disabled'; ?>" data-method="pickup">
                        <input type="radio" name="fulfillment_choice" value="pickup" <?= !$deliveryReady && $pickupReady ? 'checked' : ''; ?> <?= $pickupReady ? '' : 'disabled'; ?>>
                        <span class="fulfillment-method-icon"><i class="bi bi-shop-window"></i></span><span><b><?= e($pickupConfig['name'] ?? 'Ambil di Toko'); ?></b><small><?= $pickupReady ? 'Ongkir Rp0 • ambil setelah siap' : 'Belum diaktifkan admin'; ?></small></span><i class="bi bi-check-circle-fill fulfillment-method-check"></i>
                    </label>
                </div>
            </section>

            <section class="checkout-box">
                <div class="checkout-box-title"><span><i class="bi bi-person-lines-fill"></i></span><div><small>LANGKAH 2</small><h2>Data Penerima</h2></div></div>
                <div class="form-grid">
                    <?php if ($savedAddresses): ?>
                    <div class="span-2 checkout-saved-addresses delivery-only"><div class="saved-address-title"><b><i class="bi bi-bookmark-check-fill"></i> Gunakan Alamat Tersimpan</b><a href="<?= legacy_base_url('alamat-saya'); ?>">Kelola</a></div><div class="saved-address-choice-row"><?php foreach ($savedAddresses as $sa): ?><button type="button" class="saved-address-choice <?= $sa['is_default'] ? 'default' : ''; ?>" data-address='<?= e(json_encode($sa, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?>'><span><?= e($sa['label']); ?><?= $sa['is_default'] ? ' • Utama' : ''; ?></span><small><?= e($sa['recipient_name']); ?> • <?= e($sa['destination_label']); ?></small></button><?php endforeach; ?></div></div>
                    <?php endif; ?>
                    <label>Nama Penerima<input type="text" name="recipient_name" required minlength="2" maxlength="160" placeholder="Nama lengkap" value="<?= e($customer['name'] ?? ''); ?>"></label>
                    <label>Nomor WhatsApp<input type="tel" name="recipient_phone" required inputmode="tel" maxlength="30" placeholder="08xxxxxxxxxx" value="<?= e($customer['phone'] ?? ''); ?>"></label>
                    <label>Email <small>(opsional)</small><input type="email" name="recipient_email" maxlength="190" placeholder="nama@email.com" value="<?= e($customer['email'] ?? ''); ?>"></label>
                    <label class="delivery-only">Kode Pos<input type="text" name="postal_code" id="postalCode" inputmode="numeric" maxlength="10" placeholder="Otomatis / isi manual"></label>
                    <label class="span-2 delivery-only">Alamat Lengkap<textarea name="address" required minlength="8" rows="3" placeholder="Nama jalan, nomor rumah, RT/RW, patokan..."></textarea></label>
                    <div class="span-2 checkout-region-block delivery-only">
                        <div class="checkout-region-head"><div><b><i class="bi bi-pin-map-fill"></i> Wilayah Pengiriman</b><small>Pilih berurutan agar ongkir dihitung otomatis.</small></div><span id="regionLoading" hidden><i class="bi bi-arrow-repeat"></i> Memuat</span></div>
                        <div class="checkout-region-grid">
                            <label>Provinsi<select id="destinationProvinceSelect" required><option value="">Pilih Provinsi</option></select></label>
                            <label>Kota / Kabupaten<select id="destinationCitySelect" required disabled><option value="">Pilih Kota/Kabupaten</option></select></label>
                            <label>Kecamatan<select id="destinationDistrictSelect" required disabled><option value="">Pilih Kecamatan</option></select></label>
                            <label>Kelurahan / Desa<select id="destinationSubdistrictSelect" required disabled><option value="">Pilih Kelurahan/Desa</option></select></label>
                        </div>
                        <div class="checkout-region-help" id="regionStatus"><i class="bi bi-info-circle"></i><span>Pilih Provinsi terlebih dahulu.</span></div>
                    </div>
                    <div class="span-2 destination-selected delivery-only" id="destinationSelected" hidden>
                        <i class="bi bi-geo-alt-fill"></i><div><b id="destinationSelectedLabel"></b><small id="destinationSelectedMeta"></small></div><button type="button" id="destinationChange">Ganti Wilayah</button>
                    </div>
                    <div class="span-2 checkout-pickup-fields" id="pickupStoreFields" hidden>
                        <div class="checkout-region-head"><div><b><i class="bi bi-shop-window"></i> Pilih Toko Pengambilan</b><small>Hanya toko dengan stok lengkap untuk seluruh keranjang yang dapat dipilih.</small></div><span id="pickupLoading" hidden><i class="bi bi-arrow-repeat"></i> Memuat</span></div>
                        <div class="pickup-store-options" id="pickupStoreOptions"></div>
                        <div class="checkout-region-help" id="pickupStatus"><i class="bi bi-info-circle"></i><span>Pilih toko tempat pesanan akan diambil.</span></div>
                    </div>
                    <label class="span-2">Catatan Pesanan <small>(opsional)</small><textarea name="customer_note" rows="2" maxlength="500" placeholder="Contoh: bungkus aman, nama pengambil berbeda, dll."></textarea></label>
                </div>
            </section>

            <section class="checkout-box" id="shippingSection">
                <div class="checkout-box-title"><span><i class="bi bi-truck"></i></span><div><small>LANGKAH 3</small><h2>Pilih Pengiriman</h2></div></div>
                <div class="shipping-provider-note"><i class="bi bi-info-circle-fill"></i><span>Tarif diambil real-time berdasarkan alamat tujuan dan total berat produk.</span></div>
                <div class="shipping-status-box" id="shippingStatusBox">
                    <i class="bi bi-geo-alt"></i><span>Pilih alamat tujuan untuk melihat layanan kurir.</span>
                </div>
                <div class="checkout-couriers live-couriers" id="shippingOptions"></div>
            </section>

            <section class="checkout-box">
                <div class="checkout-box-title"><span><i class="bi bi-wallet2"></i></span><div><small>LANGKAH 4</small><h2>Metode Pembayaran</h2></div></div>
                <div class="payment-method-grid">
                    <label class="payment-method-card active">
                        <input type="radio" name="payment_method" value="qris" checked>
                        <span class="payment-icon"><i class="bi bi-qr-code"></i></span>
                        <span><b>QRIS</b><small>Scan QR, konfirmasi otomatis</small></span>
                        <i class="bi bi-check-circle-fill payment-check"></i>
                    </label>
                    <label class="payment-method-card">
                        <input type="radio" name="payment_method" value="bri_va">
                        <span class="payment-icon"><i class="bi bi-bank"></i></span>
                        <span><b>BRI Virtual Account</b><small>Nomor VA otomatis</small></span>
                        <i class="bi bi-check-circle-fill payment-check"></i>
                    </label>
                    <label class="payment-method-card">
                        <input type="radio" name="payment_method" value="bni_va">
                        <span class="payment-icon"><i class="bi bi-bank2"></i></span>
                        <span><b>BNI Virtual Account</b><small>Nomor VA otomatis</small></span>
                        <i class="bi bi-check-circle-fill payment-check"></i>
                    </label>
                    <label class="payment-method-card payment-method-select-card">
                        <input type="radio" name="payment_method" value="cimb_niaga_va" id="otherVaRadio">
                        <span class="payment-icon"><i class="bi bi-credit-card-2-front"></i></span>
                        <span class="va-select-copy"><b>Virtual Account Lain</b><select id="otherVaSelect" aria-label="Pilih bank virtual account">
                            <?php foreach ($paymentMethods as $method => $label): if (in_array($method, ['qris','bri_va','bni_va'], true)) continue; ?>
                                <option value="<?= e($method); ?>"><?= e($label); ?></option>
                            <?php endforeach; ?>
                        </select></span>
                        <i class="bi bi-check-circle-fill payment-check"></i>
                    </label>
                </div>
            </section>
        </div>

        <aside class="checkout-order-card">
            <div class="checkout-order-head"><span class="eyebrow">PESANAN ANDA</span><h2>Ringkasan Checkout</h2></div>
            <div id="checkoutItems" class="checkout-items"></div>
            <div class="checkout-weight-row"><span><i class="bi bi-box-seam"></i> Berat Kiriman</span><b id="checkoutWeight">-</b></div>
            <div class="checkout-voucher-box"><label for="voucherCode">Voucher Marketplace</label><div><input id="voucherCode" name="voucher_code" maxlength="80" placeholder="Masukkan kode voucher"><button type="button" id="applyVoucherBtn">Pakai</button></div><small id="voucherMessage">Voucher akan divalidasi dari server.</small></div>
            <?php if($pointsEnabled): ?><div class="checkout-voucher-box checkout-points-box"><label for="pointsToUse">Tukar Poin Member</label><div><input id="pointsToUse" name="points_to_use" type="number" min="0" max="<?= (int)$pointsAvailable; ?>" step="1" value="0" placeholder="0"><button type="button" id="useMaxPointsBtn">Maks</button></div><small>Tersedia <b><?= number_format($pointsAvailable,0,',','.'); ?> poin</b> • 1 poin = <?= rupiah($pointValue); ?> • minimal <?= (int)$pointMinRedeem; ?> poin.</small></div><?php else: ?><input type="hidden" name="points_to_use" value="0"><?php endif; ?>
            <div class="summary-lines">
                <div><span>Subtotal</span><b data-cart-subtotal>Rp 0</b></div>
                <div><span>Ongkir</span><b id="checkoutShippingText">Rp 0</b></div>
                <div><span>Diskon Voucher</span><b class="purple-text" id="checkoutDiscountText">- Rp 0</b></div><div><span>Poin Digunakan</span><b class="purple-text" id="checkoutPointsText">- Rp 0</b></div>
                <div class="grand"><span>Total</span><strong id="checkoutGrandTotal">Rp 0</strong></div>
            </div>
            <button type="submit" class="btn-primary-store full checkout-submit-live" id="checkoutSubmit" <?= $checkoutReady ? '' : 'disabled'; ?>><span class="btn-label"><i class="bi bi-lock-fill"></i> Buat Pesanan & Bayar</span><span class="btn-progress"><i class="bi bi-arrow-repeat"></i> Memproses...</span></button>
            <div class="checkout-safe"><i class="bi bi-shield-check"></i><span>Harga, stok, berat, ongkir, dan total dihitung ulang oleh server saat Anda menekan tombol bayar.</span></div>
            <?php if ($branch): ?><div class="checkout-fulfillment" id="checkoutFulfillmentText" data-default-branch="<?= e($branch['name']); ?>"><i class="bi bi-shop"></i><span>Dikirim dari <b><?= e($branch['name']); ?></b></span></div><?php endif; ?>
        </aside>
    </form>
</main>
<script src="<?= legacy_base_url('assets/js/marketplace-checkout.js?v=17.0.2'); ?>"></script>
<?php require __DIR__ . '/_footer.php'; ?>
