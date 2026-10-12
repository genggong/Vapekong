<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/Marketplace/Marketplace.php';
Auth::requireRole(['super_admin','owner']);
header('Content-Type: application/json; charset=utf-8');
try {
    $isPost = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
    if ($isPost && !CSRF::verify()) throw new RuntimeException('Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.');
    $src = $isPost ? $_POST : $_GET;
    $level = trim((string)($src['level'] ?? 'province'));
    $parent = (int)($src['parent_id'] ?? 0);
    // API key dari form hanya digunakan server-side untuk memuat dropdown sebelum pengaturan disimpan.
    $apiKey = trim((string)($src['api_key'] ?? ''));
    $rows = marketplace_rajaongkir_regions($level, $parent, $apiKey);
    echo json_encode(['success'=>true,'data'=>$rows], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>$e->getMessage(),'data'=>[]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
