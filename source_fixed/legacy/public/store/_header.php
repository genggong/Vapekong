<?php
require_once __DIR__ . '/_data.php';
$store = storefront_settings();
$pageTitle = $pageTitle ?? $store['store_name'];
$siteTitle = trim((string) ($store['marketplace_site_title'] ?? '')) ?: $store['store_name'];
$metaDescription = trim((string) ($store['marketplace_meta_description'] ?? '')) ?: ('Belanja produk ' . $store['store_name'] . ' secara online. Stok terhubung langsung dengan POS.');
$activePage = $activePage ?? 'home';
$marketplaceTheme = marketplace_theme_config();
$marketplaceThemeVars = marketplace_theme_style_vars($marketplaceTheme);
if (($store['marketplace_enabled'] ?? 'on') !== 'on') {
    http_response_code(503);
    $closedName = trim((string) ($store['marketplace_site_title'] ?? '')) ?: (string) ($store['store_name'] ?? 'Marketplace');
    echo '<!doctype html><html lang="id" style="<?= e($marketplaceThemeVars); ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($closedName).'</title><style>body{font-family:Inter,Arial,sans-serif;margin:0;background:#f8fafc;color:#0f172a;min-height:100vh;display:grid;place-items:center;padding:24px}.c{max-width:560px;background:#fff;border:1px solid #e2e8f0;border-radius:24px;padding:32px;box-shadow:0 18px 50px rgba(15,23,42,.08);text-align:center}.i{width:64px;height:64px;border-radius:20px;background:#fff7ed;display:grid;place-items:center;margin:0 auto 18px;font-size:30px}.c h1{margin:0 0 10px}.c p{color:#64748b;line-height:1.7;margin:0}</style></head><body><div class="c"><div class="i">🛍️</div><h1>'.e($closedName).'</h1><p>Marketplace sedang dinonaktifkan sementara oleh pengelola. Silakan kembali lagi nanti.</p></div></body></html>';
    exit;
}
$searchValue = trim((string) ($_GET['q'] ?? ''));
$storeCustomer = storefront_customer();
$storeCustomerId = (int) ($storeCustomer['id'] ?? 0);
$storeUnread = 0;
if ($storeCustomerId > 0) { try { $sdb = storefront_db(); $st = $sdb->prepare("SELECT COUNT(*) FROM customer_notifications WHERE customer_id=? AND is_read=0"); $st->execute([$storeCustomerId]); $storeUnread=(int)$st->fetchColumn(); } catch (Throwable $ignore) {} }
$storeLogo = storefront_visual_url($store['marketplace_logo'] ?? '', 'logo-vapekong.svg');
$storeFavicon = storefront_visual_url($store['marketplace_favicon'] ?? '', 'favicon.svg');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="<?= e($marketplaceTheme['primary']); ?>">
    <title><?= e($activePage === 'home' ? $siteTitle : ($pageTitle . ' - ' . $store['store_name'])); ?></title>
    <meta name="description" content="<?= e($metaDescription); ?>">
    <link rel="icon" href="<?= e($storeFavicon); ?>">
    <link rel="apple-touch-icon" href="<?= e($storeFavicon); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= legacy_base_url('assets/css/storefront.css?v=15.14.0'); ?>" rel="stylesheet">
    <link href="<?= legacy_base_url('assets/css/marketplace-theme.css?v=15.8.0'); ?>" rel="stylesheet">
</head>
<body class="storefront-body theme-template-<?= e($marketplaceTheme['template']); ?> theme-preset-<?= e($marketplaceTheme['preset']); ?> <?= e(trim((string)($bodyClass ?? ''))); ?>" data-store-name="<?= e($store['store_name']); ?>" data-wishlist-url="<?= e(legacy_base_url('store/api/wishlist-toggle.php')); ?>" data-marketplace-auth="<?= $storeCustomerId > 0 ? '1' : '0'; ?>" data-login-url="<?= e(legacy_base_url('masuk')); ?>" data-csrf="<?= e(CSRF::token()); ?>">
<div class="store-page-loader" id="storePageLoader" aria-hidden="true">
    <div class="store-loader-orb"></div>
</div>
<script>
(function(){
  var done=false;
  function hideStoreLoader(){
    if(done) return;
    done=true;
    var el=document.getElementById('storePageLoader');
    if(!el) return;
    el.classList.add('hide');
    el.style.pointerEvents='none';
    window.setTimeout(function(){ if(el && el.parentNode) el.style.display='none'; }, 350);
  }
  window.VapekongHideStoreLoader=hideStoreLoader;
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', function(){ window.setTimeout(hideStoreLoader,120); }, {once:true});
  else window.setTimeout(hideStoreLoader,120);
  window.addEventListener('pageshow', hideStoreLoader, {once:true});
  window.setTimeout(hideStoreLoader,1800);
})();
</script>
<header class="store-header" id="storeHeader">
    <div class="store-container store-header-inner">
        <a class="store-brand" href="<?= legacy_base_url(); ?>" aria-label="<?= e($store['store_name']); ?>">
            <img class="store-logo-image" src="<?= e($storeLogo); ?>" alt="<?= e($store['store_name']); ?>" onerror="this.onerror=null;this.src='<?= e(storefront_asset_url('logo-vapekong.svg')); ?>';">
        </a>
        <button class="mobile-menu-btn" type="button" id="storeMenuToggle" aria-label="Buka menu" aria-controls="storeNav" aria-expanded="false"><i class="bi bi-list"></i></button>
        <nav class="store-nav" id="storeNav">
            <a class="<?= $activePage === 'home' ? 'active' : ''; ?>" href="<?= legacy_base_url(); ?>">Home</a>
            <a class="<?= $activePage === 'products' ? 'active' : ''; ?>" href="<?= legacy_base_url('produk'); ?>">Produk</a>
            <a href="<?= legacy_base_url() . '#categories'; ?>">Kategori</a>
            <a href="<?= legacy_base_url() . '#promo'; ?>">Promo</a>
            <a class="<?= $activePage === 'tracking' ? 'active' : ''; ?>" href="<?= legacy_base_url('tracking'); ?>">Tracking</a>
            <a class="<?= $activePage === 'account' ? 'active' : ''; ?>" href="<?= legacy_base_url($storeCustomer ? 'akun' : 'masuk'); ?>">Akun</a>
            <?php if ($storeCustomer): ?><a class="<?= $activePage === 'support' ? 'active' : ''; ?>" href="<?= legacy_base_url('bantuan-pesanan'); ?>">Bantuan Pesanan</a><?php else: ?><a href="<?= legacy_base_url() . '#faq'; ?>">Bantuan</a><?php endif; ?>
        </nav>
        <div class="store-header-actions">
            <form class="store-search" action="<?= legacy_base_url('produk'); ?>" method="get">
                <input name="q" value="<?= e($searchValue); ?>" placeholder="Cari produk, brand, atau kategori..." autocomplete="off">
                <button type="submit" aria-label="Cari"><i class="bi bi-search"></i></button>
            </form>
            <a class="icon-action account-icon-action" href="<?= legacy_base_url($storeCustomer ? 'akun' : 'masuk'); ?>" aria-label="<?= $storeCustomer ? 'Akun saya' : 'Masuk akun'; ?>">
                <i class="bi <?= $storeCustomer ? 'bi-person-check-fill' : 'bi-person'; ?>"></i><?php if ($storeUnread > 0): ?><span class="account-notif-count"><?= $storeUnread > 99 ? '99+' : $storeUnread; ?></span><?php endif; ?>
            </a>
            <button class="icon-action cart-open-btn" type="button" aria-label="Buka keranjang">
                <i class="bi bi-bag"></i><span class="cart-count" data-cart-count>0</span>
            </button>

        </div>
    </div>
</header>
<div class="mobile-nav-backdrop" id="mobileNavBackdrop"></div>
