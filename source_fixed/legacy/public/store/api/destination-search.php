<?php
require_once __DIR__ . '/_api.php';

try {
    marketplace_api_throttle('destination', 250);
    $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
    if (mb_strlen($q) < 3) {
        marketplace_api_json(['success' => true, 'data' => []]);
    }
    $rows = marketplace_rajaongkir_search($q, 20);
    marketplace_api_json(['success' => true, 'data' => $rows]);
} catch (Throwable $e) {
    marketplace_api_error($e, 502);
}
