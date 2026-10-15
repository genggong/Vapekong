<?php
require_once __DIR__ . '/_api.php';

try {
    marketplace_api_throttle('resolve_destination', 150);
    $subdistrict = trim((string)($_GET['subdistrict'] ?? ''));
    $district = trim((string)($_GET['district'] ?? ''));
    $city = trim((string)($_GET['city'] ?? ''));
    $province = trim((string)($_GET['province'] ?? ''));
    $zip = trim((string)($_GET['zip_code'] ?? ''));
    if ($subdistrict === '' || $district === '' || $city === '') {
        throw new InvalidArgumentException('Pilih wilayah sampai Kelurahan/Desa.');
    }
    $row = marketplace_rajaongkir_resolve_direct_destination($subdistrict, $district, $city, $zip);
    if (!empty($province) && empty($row['province'])) $row['province'] = $province;
    marketplace_api_json(['success'=>true,'data'=>$row]);
} catch (Throwable $e) {
    marketplace_api_error($e, 502);
}
