<?php
require_once __DIR__ . '/_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    marketplace_api_json(['success'=>false,'message'=>'Method tidak diizinkan.'],405);
}
try {
    marketplace_api_throttle('pickup-options', 180);
    $payload=marketplace_api_payload();
    marketplace_api_require_csrf($payload);
    $db=Database::connect();
    marketplace_install($db);
    $items=is_array($payload['items']??null)?$payload['items']:[];
    $rows=marketplace_store_pickup_branches($db,$items);
    if(!$rows) throw new RuntimeException('Tidak ada toko pengambilan yang memiliki stok lengkap untuk keranjang ini.');
    marketplace_api_json(['success'=>true,'data'=>$rows]);
} catch(Throwable $e) {
    marketplace_api_error($e,422);
}
