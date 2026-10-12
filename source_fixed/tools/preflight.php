<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
$min = '8.2.0';
$required = ['intl','mbstring','json','openssl','fileinfo','PDO','pdo_mysql'];
$ok = true;
echo "Vapekong POS CI4 v17.0 - Preflight\n";
echo "PHP: ".PHP_VERSION.(version_compare(PHP_VERSION,$min,'>=')?" [OK]":" [BUTUH >= {$min}]")."\n";
if (version_compare(PHP_VERSION,$min,'<')) $ok=false;
foreach ($required as $ext) {
    $exists = extension_loaded($ext);
    echo str_pad($ext, 14).($exists?"[OK]":"[MISSING]")."\n";
    if (!$exists) $ok=false;
}
$root=dirname(__DIR__);
foreach (['writable','legacy/storage'] as $rel) {
    $path=$root.DIRECTORY_SEPARATOR.$rel;
    $exists=is_dir($path);
    $write=$exists && is_writable($path);
    echo str_pad($rel, 14).($exists&&$write?"[OK writable]":($exists?"[NOT WRITABLE]":"[MISSING]"))."\n";
    if(!$exists||!$write)$ok=false;
}
echo $ok?"\nREADY\n":"\nBELUM READY - aktifkan item MISSING/NOT WRITABLE dahulu.\n";
exit($ok?0:1);
