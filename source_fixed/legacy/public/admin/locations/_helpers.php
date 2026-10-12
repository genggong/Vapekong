<?php
function location_table_exists(PDO $db, string $table): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]); return (int)$stmt->fetchColumn() > 0;
}
function location_column_exists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table,$column]); return (int)$stmt->fetchColumn() > 0;
}
function location_install(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS product_locations (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        product_id BIGINT NOT NULL,
        branch_id BIGINT NULL,
        area VARCHAR(120) NULL,
        rack VARCHAR(80) NULL,
        shelf VARCHAR(80) NULL,
        bin_box VARCHAR(80) NULL,
        note TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_product_branch_location (product_id, branch_id),
        INDEX idx_product_locations_product (product_id),
        INDEX idx_product_locations_branch (branch_id),
        INDEX idx_product_locations_rack (rack),
        INDEX idx_product_locations_area (area)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (location_table_exists($db,'products') && !location_column_exists($db,'products','rack_location')) {
        $db->exec("ALTER TABLE products ADD COLUMN rack_location VARCHAR(160) NULL AFTER unit");
    }
}
function location_label(?array $row): string {
    if (!$row) return '-';
    $parts=[]; foreach(['area','rack','shelf','bin_box'] as $k){ if(!empty($row[$k])) $parts[]=trim((string)$row[$k]); }
    return $parts ? implode(' / ', $parts) : '-';
}
function location_product(PDO $db, int $productId, ?int $branchId=null): ?array {
    location_install($db);
    if ($branchId) {
        $stmt=$db->prepare("SELECT * FROM product_locations WHERE product_id=? AND branch_id=? LIMIT 1");
        $stmt->execute([$productId,$branchId]); $row=$stmt->fetch(); if($row) return $row;
    }
    $stmt=$db->prepare("SELECT * FROM product_locations WHERE product_id=? AND branch_id IS NULL LIMIT 1");
    $stmt->execute([$productId]); $row=$stmt->fetch(); if($row) return $row;
    $stmt=$db->prepare("SELECT rack_location FROM products WHERE id=? LIMIT 1");
    $stmt->execute([$productId]); $legacy=trim((string)($stmt->fetchColumn() ?: ''));
    return $legacy!=='' ? ['area'=>null,'rack'=>$legacy,'shelf'=>null,'bin_box'=>null,'note'=>null] : null;
}
function location_product_label(PDO $db, int $productId, ?int $branchId=null): string {
    return location_label(location_product($db,$productId,$branchId));
}
function location_save(PDO $db, int $productId, ?int $branchId, string $area, string $rack, string $shelf, string $binBox, string $note=''): void {
    location_install($db);
    $area=trim($area); $rack=trim($rack); $shelf=trim($shelf); $binBox=trim($binBox); $note=trim($note);
    if ($branchId && $branchId>0) {
        $stmt=$db->prepare("SELECT id FROM product_locations WHERE product_id=? AND branch_id=? LIMIT 1");
        $stmt->execute([$productId,$branchId]); $id=(int)$stmt->fetchColumn();
    } else {
        $branchId=null; $stmt=$db->prepare("SELECT id FROM product_locations WHERE product_id=? AND branch_id IS NULL LIMIT 1");
        $stmt->execute([$productId]); $id=(int)$stmt->fetchColumn();
    }
    if ($id>0) {
        $stmt=$db->prepare("UPDATE product_locations SET area=?, rack=?, shelf=?, bin_box=?, note=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([$area?:null,$rack?:null,$shelf?:null,$binBox?:null,$note?:null,$id]);
    } else {
        $stmt=$db->prepare("INSERT INTO product_locations (product_id, branch_id, area, rack, shelf, bin_box, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$productId,$branchId,$area?:null,$rack?:null,$shelf?:null,$binBox?:null,$note?:null]);
    }
    if (!$branchId) {
        $label=location_label(['area'=>$area,'rack'=>$rack,'shelf'=>$shelf,'bin_box'=>$binBox]);
        $db->prepare("UPDATE products SET rack_location=?, updated_at=NOW() WHERE id=?")->execute([$label!=='-'?$label:null,$productId]);
    }
}
function location_branch_options(PDO $db): array {
    if (function_exists('branch_all')) { try { return branch_all($db,true); } catch(Throwable $e){} }
    return [];
}
?>