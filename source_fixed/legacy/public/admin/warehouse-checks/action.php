<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
whqc_require_view();

if (!is_post() || !CSRF::verify()) {
    flash('error', 'Token keamanan tidak valid.');
    legacy_redirect('admin/warehouse-checks/index.php');
}

$db = Database::connect();
whqc_ensure_tables($db);
$itemId = (int)($_POST['item_id'] ?? 0);
$action = $_POST['action'] ?? '';
$row = whqc_fetch_one($db, $itemId);
if (!$row) {
    flash('error', 'Data barang SO tidak ditemukan.');
    legacy_redirect('admin/warehouse-checks/index.php');
}

try {
    $db->beginTransaction();
    $checkId = whqc_ensure_check($db, $row);

    if ($action === 'operator_check') {
        if (!whqc_can_operator()) throw new Exception('Role tidak boleh melakukan cek operator.');
        $note = trim((string)($_POST['operator_note'] ?? ''));
        $stmt = $db->prepare("UPDATE warehouse_quality_checks SET operator_user_id=?, operator_checked_at=NOW(), operator_note=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([Auth::id(), $note, $checkId]);
        Auth::log('warehouse_operator_check', 'Operator gudang cek item SO #' . $itemId);
        $msg = 'Barang berhasil ditandai sudah dicek operator gudang.';
    } elseif ($action === 'admin_check') {
        if (!whqc_can_admin()) throw new Exception('Role tidak boleh melakukan cek admin gudang.');
        $fresh = whqc_fetch_one($db, $itemId);
        if (empty($fresh['operator_checked_at'])) throw new Exception('Barang harus dicek operator gudang dulu.');
        $note = trim((string)($_POST['admin_note'] ?? ''));
        $stmt = $db->prepare("UPDATE warehouse_quality_checks SET admin_user_id=?, admin_checked_at=NOW(), admin_note=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([Auth::id(), $note, $checkId]);
        Auth::log('warehouse_admin_check', 'Admin gudang verifikasi item SO #' . $itemId);
        $msg = 'Barang berhasil diverifikasi admin gudang.';
    } elseif ($action === 'qc_approve' || $action === 'qc_reject') {
        if (!whqc_can_qc()) throw new Exception('Role tidak boleh melakukan quality control.');
        $fresh = whqc_fetch_one($db, $itemId);
        if (empty($fresh['admin_checked_at'])) throw new Exception('Barang harus diverifikasi admin gudang dulu.');
        $note = trim((string)($_POST['qc_note'] ?? ''));
        $status = $action === 'qc_approve' ? 'approved' : 'rejected';
        $stmt = $db->prepare("UPDATE warehouse_quality_checks SET qc_user_id=?, qc_status=?, qc_checked_at=NOW(), qc_note=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([Auth::id(), $status, $note, $checkId]);
        Auth::log('warehouse_qc_' . $status, 'Quality control ' . $status . ' item SO #' . $itemId);
        $msg = $status === 'approved' ? 'Barang berhasil di-approve Quality Control.' : 'Barang berhasil di-reject Quality Control.';
    } else {
        throw new Exception('Aksi tidak dikenal.');
    }

    $db->commit();
    flash('success', $msg);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    flash('error', 'Gagal memproses: ' . $e->getMessage());
}

legacy_redirect('admin/warehouse-checks/detail.php?item_id=' . $itemId);
