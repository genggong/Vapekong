<?php
require_once __DIR__ . '/../app/bootstrap.php';
$message = setting('maintenance_message', 'Mohon maaf, sistem sedang maintenance. Silakan coba lagi beberapa saat lagi.');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Maintenance - <?= e(setting('app_name', 'KasirPro UMKM')); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= legacy_base_url('assets/css/maintenance.css?v=' . time()); ?>">
</head>
<body>
    <main class="maintenance-card">
        <section class="maintenance-box">
            <div class="maintenance-icon"><i class="bi bi-tools"></i></div>
            <h1>Maintenance</h1>
            <p><?= nl2br(e($message)); ?></p>
            <div class="maintenance-actions">
                <a class="primary" href="<?= legacy_base_url('login'); ?>">Login Admin</a>
            </div>
        </section>
    </main>
</body>
</html>
