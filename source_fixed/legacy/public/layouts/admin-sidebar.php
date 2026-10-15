<?php
$currentPath = $_SERVER['REQUEST_URI'] ?? '';

function menu_active(string $path, string $currentPath): string
{
    return strpos($currentPath, $path) !== false ? 'active' : '';
}

function menu_can(string $permission): bool
{
    return !class_exists('Auth') || Auth::role() === 'super_admin' || !method_exists('Auth', 'can') || Auth::can($permission);
}

function menu_link(string $permission, string $url, string $icon, string $label, string $currentPath): void
{
    if (!menu_can($permission)) {
        return;
    }

    $activeClass = $url !== '' ? menu_active('/' . $url, $currentPath) : '';
    echo '<a href="' . legacy_base_url($url) . '" class="' . $activeClass . '">';
    echo '<i class="bi ' . e($icon) . '"></i><span>' . e($label) . '</span>';
    echo '</a>';
}
?>

<aside class="admin-sidebar" id="adminSidebar">
    <div class="sidebar-brand">
        <div class="sidebar-logo">
            <i class="bi bi-shop-window"></i>
        </div>
        <div>
            <h3><?= e(setting('app_name', 'KasirPro')); ?></h3>
            <span><?= e(setting('store_name', 'Toko Demo')); ?></span>
        </div>
    </div>

    <div class="sidebar-scroll">
        <div class="sidebar-menu-title">Operasional</div>
        <nav class="sidebar-menu">
            <?php
            menu_link('dashboard.view', 'admin/dashboard.php', 'bi-house-door-fill', 'Dashboard', $currentPath);
            menu_link('pos.access', 'admin/pos/index.php', 'bi-cart-check-fill', 'POS Kasir', $currentPath);
            menu_link('pos.access', 'admin/stock-requests/index.php', 'bi-arrow-down-up', 'Request Stock', $currentPath);
            if (menu_can('dashboard.view')) {
                echo '<a href="' . e(legacy_base_url()) . '" target="_blank" rel="noopener" class=""><i class="bi bi-globe2"></i><span>Website E-Commerce</span><i class="bi bi-box-arrow-up-right" style="margin-left:auto;font-size:.78rem"></i></a>';
            }
            menu_link('settings.view', 'admin/branches/index.php', 'bi-shop-window', 'Multi Cabang', $currentPath);
            menu_link('products.view', 'admin/products/index.php', 'bi-box-seam-fill', 'Produk', $currentPath);
            menu_link('products.view', 'admin/prices/index.php', 'bi-tags-fill', 'Harga Grosir', $currentPath);
                        menu_link('products.view', 'admin/bundles/index.php', 'bi-box2-heart', 'Paket Bundling', $currentPath);
menu_link('categories.view', 'admin/categories/index.php', 'bi-tags-fill', 'Kategori', $currentPath);
            menu_link('customers.view', 'admin/customers/index.php', 'bi-person-hearts', 'Pelanggan', $currentPath);
            menu_link('customers.view', 'admin/customers/points.php', 'bi-stars', 'Poin Member', $currentPath);
            menu_link('suppliers.view', 'admin/suppliers/index.php', 'bi-truck-front-fill', 'Supplier', $currentPath);
            menu_link('stock.view', 'admin/stock/index.php', 'bi-inboxes-fill', 'Stok Barang', $currentPath);
            menu_link('stock.view', 'admin/warehouse/index.php', 'bi-buildings-fill', 'Gudang', $currentPath);
            menu_link('settings.view', 'admin/marketplace-couriers/index.php', 'bi-bicycle', 'Kurir Toko', $currentPath);
            menu_link('settings.view', 'admin/marketplace-courier-rates/index.php', 'bi-geo-alt-fill', 'Tarif Kurir Toko', $currentPath);
                        menu_link('stock.view', 'admin/locations/index.php', 'bi-geo-alt-fill', 'Rak / Lokasi', $currentPath);
menu_link('stock.view', 'admin/stock-opname/index.php', 'bi-clipboard-check-fill', 'Stock Opname', $currentPath);
            menu_link('warehouse_checks.view', 'admin/warehouse-checks/index.php', 'bi-clipboard2-check-fill', 'Cek Gudang / QC', $currentPath);
            menu_link('import_products.view', 'admin/import-products/index.php', 'bi-file-earmark-spreadsheet', 'Import Produk', $currentPath);
            ?>
        </nav>

        <div class="sidebar-menu-title">Transaksi</div>
        <nav class="sidebar-menu">
            <?php
            menu_link('transactions.view', 'admin/transactions/index.php', 'bi-receipt-cutoff', 'Transaksi', $currentPath);
            menu_link('transactions.view', 'admin/marketplace-dashboard/index.php', 'bi-speedometer2', 'Dashboard Marketplace', $currentPath);
            menu_link('transactions.view', 'admin/marketplace-orders/index.php', 'bi-bag-check-fill', 'Pesanan Marketplace', $currentPath);
            menu_link('transactions.view', 'admin/marketplace-fulfillment/index.php', 'bi-box-seam-fill', 'Picking & Packing', $currentPath);
            menu_link('transactions.view', 'admin/marketplace-courier-deliveries/index.php', 'bi-truck', 'Pengiriman Kurir Toko', $currentPath);
            menu_link('stock.view', 'admin/marketplace-stock-alerts/index.php', 'bi-exclamation-triangle-fill', 'Stock Alert Marketplace', $currentPath);
            menu_link('products.view', 'admin/marketplace-products/index.php', 'bi-grid-3x3-gap-fill', 'Produk Marketplace', $currentPath);
            menu_link('products.view', 'admin/marketplace-brands/index.php', 'bi-patch-check-fill', 'Brand Marketplace', $currentPath);
            menu_link('promos.view', 'admin/marketplace-flash-sales/index.php', 'bi-lightning-charge-fill', 'Flash Sale', $currentPath);
            menu_link('settings.view', 'admin/marketplace-homepage/index.php', 'bi-layout-text-window-reverse', 'Homepage Marketplace', $currentPath);
            menu_link('promos.view', 'admin/marketplace-vouchers/index.php', 'bi-ticket-detailed-fill', 'Voucher Marketplace', $currentPath);
            menu_link('transactions.view', 'admin/marketplace-reviews/index.php', 'bi-star-fill', 'Ulasan Marketplace', $currentPath);
            menu_link('transactions.view', 'admin/marketplace-service/index.php', 'bi-headset', 'Layanan Marketplace', $currentPath);
            menu_link('transactions.view', 'admin/debts/index.php', 'bi-wallet2', 'Hutang / Piutang', $currentPath);
            menu_link('promos.view', 'admin/promos/index.php', 'bi-ticket-perforated-fill', 'Promo / Voucher', $currentPath);
            menu_link('returns.view', 'admin/returns/index.php', 'bi-arrow-counterclockwise', 'Retur / Refund', $currentPath);
            menu_link('shifts.view', 'admin/shifts/index.php', 'bi-clock-history', 'Shift Kasir', $currentPath);
            menu_link('purchases.view', 'admin/purchases/index.php', 'bi-bag-plus-fill', 'Pembelian', $currentPath);
                        menu_link('purchases.view', 'admin/supplier-returns/index.php', 'bi-arrow-return-left', 'Retur Supplier', $currentPath);
?>
        </nav>

        <div class="sidebar-menu-title">Keuangan & Laporan</div>
        <nav class="sidebar-menu">
            <?php
            menu_link('finance.view', 'admin/accounting/index.php', 'bi-calculator-fill', 'Akuntansi', $currentPath);
            menu_link('finance.view', 'admin/finance/index.php', 'bi-wallet2', 'Keuangan', $currentPath);
            menu_link('reports.view', 'admin/reports/index.php', 'bi-bar-chart-line-fill', 'Laporan', $currentPath);
            menu_link('reports.view', 'admin/marketplace-courier-income/index.php', 'bi-cash-coin', 'Ongkir Kurir Toko', $currentPath);
            ?>
        </nav>

        <div class="sidebar-menu-title">Sistem</div>
        <nav class="sidebar-menu">
            <?php
            menu_link('notifications.view', 'admin/notifications/index.php', 'bi-bell-fill', 'Notifikasi', $currentPath);
            menu_link('logs.view', 'admin/logs/index.php', 'bi-clock-history', 'Activity Log', $currentPath);
            menu_link('backup.view', 'admin/backup/index.php', 'bi-database-down', 'Backup', $currentPath);
            menu_link('users.view', 'admin/users/index.php', 'bi-people-fill', 'User', $currentPath);
            menu_link('roles.view', 'admin/roles/index.php', 'bi-shield-lock-fill', 'Role & Akses', $currentPath);
            menu_link('settings.view', 'admin/whatsapp/logs.php', 'bi-whatsapp', 'Log WhatsApp', $currentPath);
            menu_link('settings.view', 'admin/settings/index.php', 'bi-sliders', 'Pengaturan POS', $currentPath);
            menu_link('settings.view', 'admin/marketplace-whatsapp/index.php', 'bi-whatsapp', 'WA Marketplace', $currentPath);
            menu_link('settings.view', 'admin/marketplace-settings/index.php', 'bi-globe2', 'Pengaturan Marketplace', $currentPath);
            menu_link('settings.view', 'admin/marketplace-logs/index.php', 'bi-activity', 'Monitor Marketplace', $currentPath);
            menu_link('settings.view', 'admin/marketplace-database/index.php', 'bi-database-check', 'Database & System Doctor', $currentPath);
            ?>
        </nav>
    </div>

    <div class="sidebar-bottom">
        <div class="sidebar-user-box">
            <div class="sidebar-user-avatar">
                <?= strtoupper(substr(Auth::user()['name'] ?? 'U', 0, 1)); ?>
            </div>
            <div>
                <b><?= e(Auth::user()['name'] ?? 'User'); ?></b>
                <span><?= e(Auth::user()['role_name'] ?? 'Admin'); ?></span>
            </div>
        </div>

        <a href="<?= legacy_base_url('logout.php'); ?>" class="logout-link">
            <i class="bi bi-box-arrow-right"></i><span>Logout</span>
        </a>
    </div>
</aside>
