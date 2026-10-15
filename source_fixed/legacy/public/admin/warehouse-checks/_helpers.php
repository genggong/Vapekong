<?php
function whqc_role(): ?string
{
    return Auth::role();
}

function whqc_can_operator(): bool
{
    return in_array(whqc_role(), ['super_admin','owner','operator_gudang','admin_gudang'], true);
}

function whqc_can_admin(): bool
{
    return in_array(whqc_role(), ['super_admin','owner','admin_gudang'], true);
}

function whqc_can_qc(): bool
{
    return in_array(whqc_role(), ['super_admin','owner','quality_control'], true);
}

function whqc_can_view(): bool
{
    return in_array(whqc_role(), ['super_admin','owner','operator_gudang','admin_gudang','quality_control'], true);
}

function whqc_require_view(): void
{
    Auth::requireLogin();
    if (!whqc_can_view()) {
        flash('error', 'Kamu tidak punya akses ke menu cek gudang / QC.');
        legacy_redirect('admin/dashboard.php');
    }
}

function whqc_ensure_tables(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS warehouse_quality_checks (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        opname_id BIGINT NOT NULL,
        item_id BIGINT NOT NULL,
        product_id INT NULL,
        operator_user_id INT NULL,
        operator_checked_at DATETIME NULL,
        operator_note TEXT NULL,
        admin_user_id INT NULL,
        admin_checked_at DATETIME NULL,
        admin_note TEXT NULL,
        qc_user_id INT NULL,
        qc_status VARCHAR(30) NOT NULL DEFAULT 'pending',
        qc_checked_at DATETIME NULL,
        qc_note TEXT NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY unique_whqc_item (item_id),
        KEY idx_whqc_opname (opname_id),
        KEY idx_whqc_product (product_id),
        KEY idx_whqc_qc_status (qc_status),
        KEY idx_whqc_operator_checked (operator_checked_at),
        KEY idx_whqc_admin_checked (admin_checked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function whqc_status_label(array $row): string
{
    if (($row['qc_status'] ?? '') === 'approved') return 'QC Approved';
    if (($row['qc_status'] ?? '') === 'rejected') return 'QC Rejected';
    if (!empty($row['admin_checked_at'])) return 'Admin Gudang OK';
    if (!empty($row['operator_checked_at'])) return 'Operator Checked';
    return 'Pending';
}

function whqc_status_badge(array $row): string
{
    $status = whqc_status_label($row);
    $class = 'pending';
    if (($row['qc_status'] ?? '') === 'approved') $class = 'success';
    elseif (($row['qc_status'] ?? '') === 'rejected') $class = 'failed';
    elseif (!empty($row['admin_checked_at'])) $class = 'info';
    elseif (!empty($row['operator_checked_at'])) $class = 'warning';
    return '<span class="whqc-badge whqc-' . e($class) . '">' . e($status) . '</span>';
}

function whqc_qc_badge(?string $status): string
{
    $status = $status ?: 'pending';
    if ($status === 'approved') return '<span class="whqc-badge whqc-success">QC Approved</span>';
    if ($status === 'rejected') return '<span class="whqc-badge whqc-failed">QC Rejected</span>';
    return '<span class="whqc-badge whqc-pending">Pending QC</span>';
}

function whqc_fetch_rows(PDO $db, array $filters = []): array
{
    $where = ["so.status = 'finalized'"];
    $params = [];

    if (!empty($filters['q'])) {
        $where[] = "(soi.product_name LIKE ? OR soi.product_code LIKE ? OR so.code LIKE ? OR so.title LIKE ?)";
        $q = '%' . $filters['q'] . '%';
        array_push($params, $q, $q, $q, $q);
    }

    $status = $filters['status'] ?? '';
    if ($status === 'pending') {
        $where[] = "wqc.id IS NULL";
    } elseif ($status === 'operator_checked') {
        $where[] = "wqc.operator_checked_at IS NOT NULL AND wqc.admin_checked_at IS NULL AND COALESCE(wqc.qc_status,'pending')='pending'";
    } elseif ($status === 'admin_checked') {
        $where[] = "wqc.admin_checked_at IS NOT NULL AND COALESCE(wqc.qc_status,'pending')='pending'";
    } elseif ($status === 'approved') {
        $where[] = "wqc.qc_status='approved'";
    } elseif ($status === 'rejected') {
        $where[] = "wqc.qc_status='rejected'";
    }

    $sql = "SELECT
        soi.id AS item_id,
        soi.opname_id,
        soi.product_id,
        soi.product_name,
        soi.product_code,
        soi.unit,
        soi.system_stock,
        soi.physical_stock,
        soi.difference,
        soi.difference_value,
        soi.reason,
        soi.note AS opname_item_note,
        p.image,
        so.code AS opname_code,
        so.title AS opname_title,
        so.created_at AS opname_created_at,
        so.updated_at AS opname_updated_at,
        creator.name AS opname_user_name,
        wqc.id AS check_id,
        wqc.operator_user_id,
        wqc.operator_checked_at,
        wqc.operator_note,
        wqc.admin_user_id,
        wqc.admin_checked_at,
        wqc.admin_note,
        wqc.qc_user_id,
        wqc.qc_status,
        wqc.qc_checked_at,
        wqc.qc_note,
        op.name AS operator_name,
        adm.name AS admin_name,
        qc.name AS qc_name
        FROM stock_opname_items soi
        INNER JOIN stock_opnames so ON so.id = soi.opname_id
        LEFT JOIN products p ON p.id = soi.product_id
        LEFT JOIN users creator ON creator.id = so.user_id
        LEFT JOIN warehouse_quality_checks wqc ON wqc.item_id = soi.id
        LEFT JOIN users op ON op.id = wqc.operator_user_id
        LEFT JOIN users adm ON adm.id = wqc.admin_user_id
        LEFT JOIN users qc ON qc.id = wqc.qc_user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY so.id DESC, soi.product_name ASC
        LIMIT 500";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function whqc_fetch_one(PDO $db, int $itemId): ?array
{
    $stmt = $db->prepare("SELECT
        soi.id AS item_id,
        soi.opname_id,
        soi.product_id,
        soi.product_name,
        soi.product_code,
        soi.unit,
        soi.system_stock,
        soi.physical_stock,
        soi.difference,
        soi.difference_value,
        soi.reason,
        soi.note AS opname_item_note,
        p.image,
        so.code AS opname_code,
        so.title AS opname_title,
        so.status AS opname_status,
        so.created_at AS opname_created_at,
        so.updated_at AS opname_updated_at,
        creator.name AS opname_user_name,
        wqc.id AS check_id,
        wqc.operator_user_id,
        wqc.operator_checked_at,
        wqc.operator_note,
        wqc.admin_user_id,
        wqc.admin_checked_at,
        wqc.admin_note,
        wqc.qc_user_id,
        wqc.qc_status,
        wqc.qc_checked_at,
        wqc.qc_note,
        op.name AS operator_name,
        adm.name AS admin_name,
        qc.name AS qc_name
        FROM stock_opname_items soi
        INNER JOIN stock_opnames so ON so.id = soi.opname_id
        LEFT JOIN products p ON p.id = soi.product_id
        LEFT JOIN users creator ON creator.id = so.user_id
        LEFT JOIN warehouse_quality_checks wqc ON wqc.item_id = soi.id
        LEFT JOIN users op ON op.id = wqc.operator_user_id
        LEFT JOIN users adm ON adm.id = wqc.admin_user_id
        LEFT JOIN users qc ON qc.id = wqc.qc_user_id
        WHERE soi.id = ? AND so.status='finalized'
        LIMIT 1");
    $stmt->execute([$itemId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function whqc_ensure_check(PDO $db, array $row): int
{
    if (!empty($row['check_id'])) {
        return (int) $row['check_id'];
    }

    $stmt = $db->prepare("INSERT INTO warehouse_quality_checks
        (opname_id, item_id, product_id, qc_status, created_at, updated_at)
        VALUES (?, ?, ?, 'pending', NOW(), NOW())
        ON DUPLICATE KEY UPDATE updated_at=NOW()");
    $stmt->execute([(int)$row['opname_id'], (int)$row['item_id'], !empty($row['product_id']) ? (int)$row['product_id'] : null]);

    $id = (int) $db->lastInsertId();
    if ($id > 0) return $id;

    $stmt = $db->prepare("SELECT id FROM warehouse_quality_checks WHERE item_id=? LIMIT 1");
    $stmt->execute([(int)$row['item_id']]);
    return (int) $stmt->fetchColumn();
}

function whqc_summary(PDO $db): array
{
    $total = (int) $db->query("SELECT COUNT(*) FROM stock_opname_items soi INNER JOIN stock_opnames so ON so.id=soi.opname_id WHERE so.status='finalized'")->fetchColumn();
    $operator = (int) $db->query("SELECT COUNT(*) FROM warehouse_quality_checks WHERE operator_checked_at IS NOT NULL")->fetchColumn();
    $admin = (int) $db->query("SELECT COUNT(*) FROM warehouse_quality_checks WHERE admin_checked_at IS NOT NULL")->fetchColumn();
    $approved = (int) $db->query("SELECT COUNT(*) FROM warehouse_quality_checks WHERE qc_status='approved'")->fetchColumn();
    $rejected = (int) $db->query("SELECT COUNT(*) FROM warehouse_quality_checks WHERE qc_status='rejected'")->fetchColumn();
    return compact('total','operator','admin','approved','rejected');
}
?>
