<?php
require_once __DIR__ . '/_api.php';

try {
    $level = strtolower(trim((string)($_GET['level'] ?? 'province')));
    $parent = (int)($_GET['parent_id'] ?? 0);
    if (!in_array($level, ['province','city','district','subdistrict','sub-district'], true)) {
        throw new InvalidArgumentException('Level wilayah tidak valid.');
    }
    if ($level !== 'province' && $parent <= 0) {
        marketplace_api_json(['success'=>true,'data'=>[]]);
    }
    $rows = marketplace_rajaongkir_regions($level, $parent);
    marketplace_api_json(['success'=>true,'data'=>$rows]);
} catch (Throwable $e) {
    marketplace_api_error($e, 502);
}
