<?php
function bundle_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function bundle_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function bundle_install(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS product_bundles (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(80) NULL UNIQUE,
        name VARCHAR(180) NOT NULL,
        image VARCHAR(255) NULL,
        selling_price DECIMAL(15,2) NOT NULL DEFAULT 0,
        note TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_product_bundles_active (is_active),
        INDEX idx_product_bundles_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS product_bundle_items (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        bundle_id BIGINT NOT NULL,
        product_id BIGINT NOT NULL,
        qty INT NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_bundle_product (bundle_id, product_id),
        INDEX idx_bundle_items_bundle (bundle_id),
        INDEX idx_bundle_items_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!bundle_column_exists($db, 'transaction_items', 'item_type')) {
        $db->exec("ALTER TABLE transaction_items ADD COLUMN item_type VARCHAR(30) NOT NULL DEFAULT 'product' AFTER transaction_id");
    }
    if (!bundle_column_exists($db, 'transaction_items', 'bundle_id')) {
        $db->exec("ALTER TABLE transaction_items ADD COLUMN bundle_id BIGINT NULL AFTER product_id");
    }
    if (!bundle_column_exists($db, 'transaction_items', 'bundle_components')) {
        $db->exec("ALTER TABLE transaction_items ADD COLUMN bundle_components TEXT NULL AFTER product_name");
    }
}

function bundle_next_code(PDO $db): string
{
    $prefix = 'PKT-' . date('Ymd') . '-';
    $stmt = $db->prepare("SELECT code FROM product_bundles WHERE code LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last) {
        $parts = explode('-', (string)$last);
        $next = ((int)end($parts)) + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function bundle_items(PDO $db, int $bundleId): array
{
    $stmt = $db->prepare("SELECT bi.*, p.name AS product_name, p.product_code, p.unit, p.purchase_price, p.selling_price, p.stock, p.image
        FROM product_bundle_items bi
        JOIN products p ON p.id = bi.product_id
        WHERE bi.bundle_id = ?
        ORDER BY p.name ASC");
    $stmt->execute([$bundleId]);
    return $stmt->fetchAll();
}

function bundle_cost(PDO $db, int $bundleId): float
{
    $stmt = $db->prepare("SELECT COALESCE(SUM(bi.qty * p.purchase_price),0)
        FROM product_bundle_items bi
        JOIN products p ON p.id = bi.product_id
        WHERE bi.bundle_id = ?");
    $stmt->execute([$bundleId]);
    return (float)$stmt->fetchColumn();
}

function bundle_available_stock(PDO $db, int $bundleId, ?int $branchId = null): int
{
    $items = bundle_items($db, $bundleId);
    if (!$items) return 0;

    $min = null;
    foreach ($items as $it) {
        $stock = (int)$it['stock'];

        if ($branchId && bundle_table_exists($db, 'product_branch_stocks')) {
            $stmt = $db->prepare("SELECT stock FROM product_branch_stocks WHERE branch_id = ? AND product_id = ? LIMIT 1");
            $stmt->execute([$branchId, (int)$it['product_id']]);
            $branchStock = $stmt->fetchColumn();
            if ($branchStock !== false) {
                $stock = (int)$branchStock;
            }
        }

        $qty = max(1, (int)$it['qty']);
        $canMake = (int)floor($stock / $qty);
        $min = $min === null ? $canMake : min($min, $canMake);
    }

    return max(0, (int)($min ?? 0));
}

function bundle_upload_image(string $field = 'image'): ?string
{
    if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowed = ['jpg','jpeg','png','webp'];
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        throw new Exception('Format gambar paket harus jpg, jpeg, png, atau webp.');
    }

    $dir = __DIR__ . '/../../uploads/bundles';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $filename = 'bundle-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = $dir . '/' . $filename;

    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
        throw new Exception('Gagal upload gambar paket.');
    }

    return 'uploads/bundles/' . $filename;
}

function bundle_asset_url(?string $path): string
{
    $path = trim((string)$path);
    if ($path === '') return '';

    // Normalisasi path lama jika sempat tersimpan public/uploads/...
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#^public/#', '', $path);

    return legacy_base_url($path);
}

function bundle_image_exists(?string $path): bool
{
    $path = trim((string)$path);
    if ($path === '') return false;
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#^public/#', '', $path);

    $publicFile = __DIR__ . '/../../' . $path;
    $projectFile = __DIR__ . '/../../../' . $path;

    return is_file($publicFile) || is_file($projectFile);
}

function bundle_redirect(string $path): void
{
    legacy_redirect($path);
}
?>