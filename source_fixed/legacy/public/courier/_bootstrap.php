<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/Modules/Marketplace/Marketplace.php';
$db=Database::connect();
marketplace_install($db);
