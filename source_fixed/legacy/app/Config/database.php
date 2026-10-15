<?php

// Kredensial dibaca dari environment agar tidak tersimpan di dalam source.
// Contoh set: VAPEKONG_DB_HOST, VAPEKONG_DB_PORT, VAPEKONG_DB_NAME,
//               VAPEKONG_DB_USER, VAPEKONG_DB_PASS
// Isi default hanya untuk instalasi lokal; production wajib set env variable.
return [
    'host' => getenv('VAPEKONG_DB_HOST') ?: 'localhost',
    'port' => (int)(getenv('VAPEKONG_DB_PORT') ?: 3306),
    'database' => getenv('VAPEKONG_DB_NAME') ?: 'vapekong_pos',
    'username' => getenv('VAPEKONG_DB_USER') ?: 'vapekong_pospalasanga',
    'password' => getenv('VAPEKONG_DB_PASS') ?: '',
    'charset' => 'utf8mb4',
];
