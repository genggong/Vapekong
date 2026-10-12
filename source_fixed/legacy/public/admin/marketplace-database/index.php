<?php
require_once __DIR__.'/../../../app/bootstrap.php';
require_once __DIR__.'/../../../app/Modules/Marketplace/Marketplace.php';
Auth::requireLogin();
$db=Database::connect();
marketplace_install($db);
$pageTitle='Database & System Doctor';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$repairLog=[];$err='';

if(is_post()){
    if(!CSRF::verify())$err='Token keamanan tidak valid.';
    else try{
        $action=(string)($_POST['action']??'repair');
        if($action==='repair'){
            $repairLog=marketplace_doctor_repair($db);
            require_once __DIR__.'/../warehouse/_helpers.php';require_once __DIR__.'/../purchases/_helpers.php';
            warehouse_install($db);purchases_install($db);marketplace_v16_install($db);
            $repairLog[]='OK: schema Pembelian, Request Stock, stok varian gudang, transaction_items varian, dan Stock Opname diperiksa.';
            // Force the next status calculation to use fresh information_schema data.
            // PDO/MySQL schema detection in older V15.10 used prepared SHOW queries and
            // falsely reported every table as missing on some shared hosting.
            $verify=marketplace_doctor_status($db);
            $remaining=0;
            foreach($verify['product'] as $row) if(!$row['ok']) $remaining++;
            $coreRemaining=0;
            foreach($verify['core'] as $row) if(!$row['exists']) $coreRemaining++;
            if($remaining===0){
                flash('success','Upgrade selesai dan sudah diverifikasi ulang dari database aktif. Schema produk marketplace sudah siap.');
            } else {
                flash('success','Upgrade selesai. Scanner baru menemukan '.$remaining.' bagian schema produk yang masih perlu perhatian. Lihat kolom yang kurang dan log repair di bawah.');
            }
        }
    }catch(Throwable $e){$err=$e->getMessage();}
}
$status=marketplace_doctor_status($db);
$ok=flash('success');

function mp1510_read_db_config(string $path): ?array{
    if(!is_file($path))return null;
    try{$v=require $path;return is_array($v)?$v:null;}catch(Throwable $e){return null;}
}
$ctx=str_replace('\\','/',__DIR__);
$isPosContext=strpos($ctx,'/pos/public/')!==false;
$rootPath=$isPosContext?__DIR__.'/../../../../app/Config/database.php':__DIR__.'/../../../app/Config/database.php';
$posPath=$isPosContext?__DIR__.'/../../../app/Config/database.php':__DIR__.'/../../../pos/app/Config/database.php';
$rootCfg=mp1510_read_db_config($rootPath);
$altCfg=mp1510_read_db_config($posPath);
$rootDb=(string)($rootCfg['database']??'');$altDb=(string)($altCfg['database']??'');
$configMismatch=$rootDb!==''&&$altDb!==''&&$rootDb!==$altDb;

$bad=0;foreach($status['product'] as $r)if(!$r['ok'])$bad++;
$coreMissing=0;foreach($status['core'] as $r)if(!$r['exists'])$coreMissing++;
require __DIR__.'/../../layouts/admin-header.php';require __DIR__.'/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?=legacy_base_url('assets/css/marketplace-customer-admin.css?v=15.11')?>">
<style>
.db-head-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.db-stat{padding:16px;border:1px solid var(--mp-line,#e5e7eb);border-radius:18px;background:#fff}.db-stat small{display:block;color:#64748b;font-weight:800}.db-stat b{display:block;font-size:20px;margin-top:5px}.db-table{width:100%;border-collapse:collapse}.db-table th,.db-table td{padding:12px;border-bottom:1px solid #eef2f7;text-align:left;vertical-align:top}.db-pill{display:inline-flex;padding:5px 9px;border-radius:999px;font-weight:900;font-size:11px}.db-pill.ok{background:#dcfce7;color:#166534}.db-pill.bad{background:#fee2e2;color:#991b1b}.db-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px}.db-log{max-height:320px;overflow:auto;background:#0f172a;color:#e2e8f0;padding:14px;border-radius:16px;font:12px/1.55 ui-monospace,SFMono-Regular,Menlo,monospace}.db-log div+div{margin-top:5px}.db-config-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}@media(max-width:800px){.db-head-grid,.db-config-grid{grid-template-columns:1fr 1fr}.db-table{min-width:760px}.table-premium-card{overflow:auto}}@media(max-width:520px){.db-head-grid,.db-config-grid{grid-template-columns:1fr}}
</style>
<main class="admin-main mp-admin">
<header class="admin-topbar page-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Database & System Doctor</h1><p>Diagnosis tabel, kolom, data Brand/Varian, dan database aktif. Aman: repair tidak menghapus produk/order.</p></div></header>
<?php if($ok):?><div class="mp-alert success"><?=e($ok)?></div><?php endif;?><?php if($err):?><div class="mp-alert error"><?=e($err)?></div><?php endif;?>
<?php if($configMismatch):?><div class="mp-alert error"><b>Perhatian:</b> konfigurasi database root dan folder <code>/pos</code> berbeda. Ini bisa membuat domain utama dan subdomain melihat data Brand/Varian yang berbeda. Lihat bagian “Database Aktif” di bawah.</div><?php endif;?>
<section class="db-head-grid">
 <div class="db-stat"><small>Database aktif</small><b><?=e($status['database'])?></b></div>
 <div class="db-stat"><small>Schema produk bermasalah</small><b><?=$bad?></b></div>
 <div class="db-stat"><small>Tabel marketplace umum belum ada</small><b><?=$coreMissing?></b></div>
 <div class="db-stat"><small>Status</small><b><?=$bad===0?'Siap':'Perlu Upgrade'?></b></div>
</section>
<section class="mp-panel"><div class="mp-panel-head"><div><h3>Database Aktif & Konfigurasi</h3><p>Hanya nama database yang ditampilkan. Password/username tidak pernah ditampilkan.</p></div></div><div class="db-config-grid">
<div class="db-stat"><small>Koneksi yang sedang dipakai halaman ini</small><b><?=e($status['database'])?></b></div>
<div class="db-stat"><small>Config root /app</small><b><?=e($rootDb?:'-')?></b></div>
<div class="db-stat"><small>Config /pos/app</small><b><?=e($altDb?:'-')?></b></div>
<div class="db-stat"><small>Tes koneksi</small><b><?=!empty($status['self_test']['ok'])?'OK':'GAGAL'?></b></div>
<div class="db-stat"><small>Tabel products terbaca</small><b><?=!empty($status['self_test']['products_readable'])?'YA':'TIDAK'?></b></div>
</div></section>
<section class="mp-panel"><div class="mp-panel-head"><div><h3>Schema Produk Marketplace</h3><p>Bagian ini wajib sehat agar Brand, galeri, opsi varian, kombinasi varian dan stok varian tampil normal.</p></div><form method="post" onsubmit="return confirm('Jalankan repair/upgrade database marketplace? Proses hanya menambah tabel/kolom/index yang kurang dan tidak menghapus data produk/order.')"><?=CSRF::input()?><input type="hidden" name="action" value="repair"><button class="mp-btn"><i class="bi bi-tools"></i> Cek & Upgrade Database</button></form></div>
<div class="table-premium-card"><table class="db-table"><thead><tr><th>Modul</th><th>Tabel</th><th>Status</th><th>Data</th><th>Kolom Kurang</th></tr></thead><tbody><?php foreach($status['product'] as $r):?><tr><td><b><?=e($r['label'])?></b></td><td class="db-code"><?=e($r['table'])?></td><td><span class="db-pill <?=$r['ok']?'ok':'bad'?>"><?=$r['ok']?'OK':'PERLU UPGRADE'?></span></td><td><?=($r['count']===null?'-':(int)$r['count'])?></td><td class="db-code"><?=e($r['missing_columns']?implode(', ',$r['missing_columns']):'-')?></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="mp-panel"><div class="mp-panel-head"><div><h3>Sistem Inti + Marketplace</h3><p>Pemeriksaan tabel inti POS, pembelian, request stock, stok varian gudang, order marketplace, customer dan kurir.</p></div></div><div class="table-premium-card"><table class="db-table"><thead><tr><th>Tabel</th><th>Status</th><th>Jumlah Data</th></tr></thead><tbody><?php foreach($status['core'] as $name=>$r):?><tr><td class="db-code"><?=e($name)?></td><td><span class="db-pill <?=$r['exists']?'ok':'bad'?>"><?=$r['exists']?'ADA':'BELUM ADA'?></span></td><td><?=($r['count']===null?'-':(int)$r['count'])?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php if($repairLog):?><section class="mp-panel"><div class="mp-panel-head"><div><h3>Hasil Repair / Upgrade</h3><p>Baris GAGAL perlu diperhatikan; baris OK berarti sudah tersedia.</p></div></div><div class="db-log"><?php foreach($repairLog as $line):?><div><?=e($line)?></div><?php endforeach;?></div></section><?php endif;?>
<section class="mp-panel"><div class="mp-panel-head"><div><h3>Cara Membaca Hasil</h3></div></div><div class="mp-alert success"><b>Brand tersimpan tetapi dropdown kosong:</b> cek jumlah data <code>marketplace_brands</code>. Jika jumlah > 0 tetapi modul berstatus “Perlu Upgrade”, tekan Cek & Upgrade Database.</div><div class="mp-alert success"><b>Varian & stok:</b> mulai V15.16 stok diinput manual per varian dan per cabang. Stok induk POS otomatis dihitung dari total stok varian aktif, sehingga POS, Marketplace dan Stock Opname tetap sinkron.</div><div class="mp-alert success"><b>Domain utama dan subdomain beda data:</b> bandingkan “Database aktif”, “Config root /app”, dan “Config /pos/app”. Jika namanya berbeda, keduanya memang membaca database berbeda.</div></section>
</main>
<?php require __DIR__.'/../../layouts/admin-footer.php';?>
