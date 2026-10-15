<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireRole(['super_admin','owner','admin','cashier']);

header('Content-Type: application/json; charset=utf-8');

function debt_qris_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$db = Database::connect();
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    debt_qris_json(['success'=>false, 'message'=>'ID QRIS tidak valid.'], 400);
}

try {
    $result = debt_qris_check_and_apply($db, $id);
    debt_qris_json([
        'success' => true,
        'status' => $result['status'],
        'qris' => [
            'id' => (int)$id,
            'status' => $result['status'],
        ],
    ]);
} catch (Throwable $e) {
    debt_qris_json(['success'=>false, 'message'=>$e->getMessage()], 500);
}
