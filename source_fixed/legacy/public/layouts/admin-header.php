<?php
require_once __DIR__ . '/../../app/Modules/Marketplace/Marketplace.php';
$marketplaceTheme = marketplace_theme_config();
$marketplaceThemeSync = !empty($marketplaceTheme['sync_internal']);
$user = Auth::user();
$pageTitle = $pageTitle ?? 'Dashboard';
$themeColor = strtolower((string) setting('theme_color', 'red'));
$allowedThemeColors = ['red', 'blue', 'green', 'purple', 'orange', 'pink', 'dark'];
if (!in_array($themeColor, $allowedThemeColors, true)) {
    $themeColor = 'red';
}
?>
<!DOCTYPE html>
<html lang="id" style="<?= e(marketplace_theme_style_vars($marketplaceTheme)); ?>">
<head>
    <meta charset="UTF-8">
    <title><?= e($pageTitle); ?> - <?= e(setting('app_name', 'KasirPro UMKM')); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="<?= e($marketplaceThemeSync ? $marketplaceTheme['primary'] : "#991b1b"); ?>">

    <!-- Bootstrap + Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">

    <!-- Bootstrap DataTables 1.13.8 -->
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">

    <!-- App CSS -->
    <link href="<?= legacy_base_url('assets/css/app.css?v=' . time()); ?>" rel="stylesheet">
    <link href="<?= legacy_base_url('assets/css/admin.css?v=' . time()); ?>" rel="stylesheet">
    <link href="<?= legacy_base_url('assets/css/transaction-detail.css?v=' . time()); ?>" rel="stylesheet">
    <link href="<?= legacy_base_url('assets/css/finance.css?v=' . time()); ?>" rel="stylesheet">
    <link href="<?= legacy_base_url('assets/css/pos.css?v=' . time()); ?>" rel="stylesheet">
    <link href="<?= legacy_base_url('assets/css/global-form-fix.css?v=' . time()); ?>" rel="stylesheet">
    <link href="<?= legacy_base_url('assets/css/ui-final-reset.css?v=' . time()); ?>" rel="stylesheet">

    <!-- DataTables Custom WAJIB paling bawah di header -->
    <link href="<?= legacy_base_url('assets/css/datatables-custom.css?v=' . time()); ?>" rel="stylesheet">

    <link rel="stylesheet" href="<?= legacy_base_url('assets/css/sweetalert-theme.css?v=' . time()); ?>">
    <link rel="stylesheet" href="<?= legacy_base_url('assets/css/datatables-spacing-fix.css?v=' . time()); ?>">
    <link rel="stylesheet" href="<?= legacy_base_url('assets/css/print-polish.css?v=' . time()); ?>" media="print">
    <link rel="stylesheet" href="<?= legacy_base_url('assets/css/global-ui-responsive-fix.css?v=' . time()); ?>">
    <link rel="stylesheet" href="<?= legacy_base_url('assets/css/datatables-actions-status.css?v=' . time()); ?>">
    <link rel="stylesheet" href="<?= legacy_base_url('assets/css/status-color-fixed.css?v=' . time()); ?>">
    <link rel="stylesheet" href="<?= legacy_base_url('assets/css/status-color-force.css?v=' . time()); ?>">
    <link rel="stylesheet" href="<?= legacy_base_url('assets/css/admin-ui-rescue.css?v=' . time()); ?>">
    <link rel="stylesheet" href="<?= legacy_base_url('assets/css/theme-color-settings.css?v=' . time()); ?>">
    <link rel="stylesheet" href="<?= legacy_base_url('assets/css/theme-color-full-override.css?v=' . time()); ?>">
    <link rel="stylesheet" href="<?= legacy_base_url('assets/css/marketplace-internal-theme.css?v=15.8.0'); ?>">
</head>
<body data-theme-color="<?= e($themeColor); ?>" data-marketplace-theme-sync="<?= $marketplaceThemeSync ? 'on' : 'off'; ?>">

<div id="pageLoader" class="page-loader">
    <div class="loader-card">
        <div class="loader-logo">
            <i class="bi bi-shop-window"></i>
        </div>
        <div class="loader-text">Memuat halaman...</div>
    </div>
</div>

<div class="admin-wrapper">