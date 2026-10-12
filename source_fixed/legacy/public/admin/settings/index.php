<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin', 'owner']);
$pageTitle = 'Pengaturan POS';
$db = Database::connect();

function pos_setting_save(PDO $db, string $key, string $value): void
{
    $stmt=$db->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $stmt->execute([$key,$value]);
}

function pos_qris_upload(array $file): ?string
{
    if (empty($file['name']) || empty($file['tmp_name'])) return null;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload gambar QRIS gagal.');
    if (($file['size'] ?? 0) > 5*1024*1024) throw new RuntimeException('Ukuran gambar QRIS maksimal 5MB.');
    $info=@getimagesize($file['tmp_name']);
    $extMap=[IMAGETYPE_JPEG=>'jpg',IMAGETYPE_PNG=>'png',IMAGETYPE_WEBP=>'webp'];
    $ext=$info && isset($extMap[$info[2]??0]) ? $extMap[$info[2]] : null;
    if(!$ext) throw new RuntimeException('Gambar QRIS harus JPG, PNG, atau WEBP.');
    $dir=__DIR__.'/../../uploads/qris';
    if(!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) throw new RuntimeException('Folder QRIS tidak dapat dibuat.');
    $name='pos-qris-'.date('YmdHis').'-'.bin2hex(random_bytes(3)).'.'.$ext;
    if(!move_uploaded_file($file['tmp_name'],$dir.'/'.$name)) throw new RuntimeException('Gagal menyimpan gambar QRIS.');
    return 'uploads/qris/'.$name;
}

$fields=['app_name','store_name','store_phone','store_email','store_address','receipt_footer','currency','timezone','low_stock_alert','maintenance_mode','maintenance_message','pakasir_project','pakasir_api_key','qris_manual_enabled','qris_manual_label','member_point_earn_rate','member_point_value','member_point_min_redeem','fonnte_token','fonnte_country_code','fonnte_auto_invoice','fonnte_owner_phone','theme_color'];
if(is_post()){
    if(!CSRF::verify()){ flash('error','Token keamanan tidak valid.'); legacy_redirect('admin/settings/index.php'); }
    try{
        $themes=['red','blue','green','purple','orange','pink','dark'];
        $_POST['theme_color']=in_array(strtolower((string)($_POST['theme_color']??'red')),$themes,true)?strtolower((string)$_POST['theme_color']):'red';
        $_POST['maintenance_mode']=(($_POST['maintenance_mode']??'off')==='on')?'on':'off';
        $_POST['qris_manual_enabled']=(($_POST['qris_manual_enabled']??'off')==='on')?'on':'off';
        $_POST['qris_manual_label']=trim((string)($_POST['qris_manual_label']??'QRIS Toko')) ?: 'QRIS Toko';
        $_POST['fonnte_auto_invoice']=in_array((string)($_POST['fonnte_auto_invoice']??'manual'),['manual','success'],true)?(string)$_POST['fonnte_auto_invoice']:'manual';
        $qris=trim((string)setting('qris_manual_image',''));
        if(!empty($_POST['qris_manual_remove'])) $qris='';
        if($up=pos_qris_upload($_FILES['qris_manual_image']??[])) $qris=$up;
        if($_POST['qris_manual_enabled']==='on' && $qris==='') throw new RuntimeException('Upload gambar QRIS sebelum mengaktifkan QRIS Manual.');
        foreach($fields as $key) pos_setting_save($db,$key,trim((string)($_POST[$key]??'')));
        pos_setting_save($db,'qris_manual_image',$qris);
        Auth::log('update_pos_settings','Memperbarui Pengaturan POS');
        flash('success','Pengaturan POS berhasil disimpan.');
    }catch(Throwable $e){ flash('error','Gagal menyimpan: '.$e->getMessage()); }
    legacy_redirect('admin/settings/index.php');
}
$settings=[];
try{foreach($db->query("SELECT setting_key,setting_value FROM settings")->fetchAll() as $r)$settings[$r['setting_key']]=$r['setting_value'];}catch(Throwable $e){}
$v=static fn(string $k,string $d=''): string => (string)($settings[$k]??$d);
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=12.0.0'); ?>">
<main class="admin-main system-wrap">
<header class="admin-topbar page-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Pengaturan POS</h1><p>Pengaturan kasir, toko fisik, QRIS POS, WhatsApp, poin member, maintenance, dan tema admin.</p></div><div class="topbar-actions"><a class="btn-soft" href="<?= legacy_base_url('admin/marketplace-settings/index.php'); ?>"><i class="bi bi-globe2"></i> Pengaturan Marketplace</a></div></header>
<section class="system-grid">
<div class="system-card"><div class="icon"><i class="bi bi-cart-check-fill"></i></div><span>Mode</span><h3>POS / Kasir</h3><p>Terpisah dari website marketplace</p></div>
<div class="system-card"><div class="icon"><i class="bi bi-qr-code"></i></div><span>Pakasir POS</span><h3><?= $v('pakasir_project')!==''?'Terhubung':'Belum Diisi'; ?></h3><p>Khusus pembayaran kasir POS</p></div>
<div class="system-card"><div class="icon"><i class="bi bi-whatsapp"></i></div><span>WhatsApp</span><h3><?= $v('fonnte_token')!==''?'Aktif':'Belum Diisi'; ?></h3><p>Notifikasi transaksi POS</p></div>
<div class="system-card"><div class="icon"><i class="bi bi-tools"></i></div><span>Maintenance</span><h3><?= $v('maintenance_mode','off')==='on'?'Aktif':'Nonaktif'; ?></h3><p>Kontrol sistem POS</p></div>
</section>
<form method="post" enctype="multipart/form-data" class="system-form-card"><?= CSRF::input(); ?><div class="system-form-grid">
<div class="marketplace-settings-heading"><h3><i class="bi bi-shop"></i> Identitas POS</h3><p>Nama aplikasi dan data toko fisik untuk admin, invoice, dan struk.</p></div>
<div class="system-field"><label>Nama Aplikasi POS</label><input name="app_name" value="<?= e($v('app_name','KasirPro UMKM')); ?>"></div>
<div class="system-field"><label>Nama Toko POS</label><input name="store_name" value="<?= e($v('store_name','Toko Demo')); ?>"></div>
<div class="system-field"><label>Telepon Toko</label><input name="store_phone" value="<?= e($v('store_phone')); ?>"></div>
<div class="system-field"><label>Email Toko</label><input type="email" name="store_email" value="<?= e($v('store_email')); ?>"></div>
<div class="system-field full"><label>Alamat Toko</label><textarea name="store_address"><?= e($v('store_address')); ?></textarea></div>
<div class="system-field full"><label>Footer Struk</label><textarea name="receipt_footer"><?= e($v('receipt_footer','Terima kasih sudah belanja.')); ?></textarea></div>

<div class="marketplace-settings-heading"><h3><i class="bi bi-credit-card"></i> Pembayaran POS</h3><p>Credential ini hanya dipakai transaksi kasir/POS. Marketplace mempunyai credential Pakasir sendiri.</p></div>
<div class="system-field"><label>Slug Project Pakasir POS</label><input name="pakasir_project" value="<?= e($v('pakasir_project')); ?>" autocomplete="off"></div>
<div class="system-field"><label>API Key Pakasir POS</label><input type="password" name="pakasir_api_key" value="<?= e($v('pakasir_api_key')); ?>" autocomplete="off"></div>
<div class="system-field full"><label>Webhook Pakasir POS</label><input readonly value="<?= e(legacy_base_url('pakasir-webhook.php')); ?>"><small>Gunakan URL ini hanya pada project Pakasir POS. Marketplace memakai webhook terpisah.</small></div>
<div class="system-field"><label>QRIS Manual POS</label><select name="qris_manual_enabled"><option value="off" <?= $v('qris_manual_enabled','off')==='off'?'selected':''; ?>>Nonaktif</option><option value="on" <?= $v('qris_manual_enabled')==='on'?'selected':''; ?>>Aktif</option></select></div>
<div class="system-field"><label>Nama QRIS Manual</label><input name="qris_manual_label" value="<?= e($v('qris_manual_label','QRIS Toko')); ?>"></div>
<div class="system-field full"><label>Gambar QRIS Manual</label><input type="file" name="qris_manual_image" accept="image/png,image/jpeg,image/webp"><?php if($v('qris_manual_image')!==''): ?><div style="display:flex;gap:16px;align-items:center;margin-top:12px"><img src="<?= legacy_base_url($v('qris_manual_image')); ?>" style="width:150px;aspect-ratio:1;object-fit:contain;border:1px solid #e2e8f0;border-radius:16px;padding:8px"><label><input type="checkbox" name="qris_manual_remove" value="1"> Lepaskan gambar</label></div><?php endif; ?></div>

<div class="marketplace-settings-heading"><h3><i class="bi bi-bell"></i> Operasional POS</h3><p>Alert stok, tema, maintenance, poin member, dan notifikasi WhatsApp.</p></div>
<div class="system-field"><label>Mata Uang</label><input name="currency" value="<?= e($v('currency','IDR')); ?>"></div>
<div class="system-field"><label>Timezone</label><input name="timezone" value="<?= e($v('timezone','Asia/Jakarta')); ?>"></div>
<div class="system-field"><label>Alert Stok Minimum</label><select name="low_stock_alert"><option value="Aktif" <?= $v('low_stock_alert','Aktif')==='Aktif'?'selected':''; ?>>Aktif</option><option value="Nonaktif" <?= $v('low_stock_alert')==='Nonaktif'?'selected':''; ?>>Nonaktif</option></select></div>
<div class="system-field"><label>Tema Admin</label><select name="theme_color"><?php foreach(['red'=>'Merah','blue'=>'Biru','green'=>'Hijau','purple'=>'Ungu','orange'=>'Oranye','pink'=>'Pink','dark'=>'Gelap'] as $k=>$n): ?><option value="<?= $k; ?>" <?= $v('theme_color','red')===$k?'selected':''; ?>><?= $n; ?></option><?php endforeach; ?></select></div>
<div class="system-field"><label>Maintenance POS</label><select name="maintenance_mode"><option value="off" <?= $v('maintenance_mode','off')==='off'?'selected':''; ?>>Nonaktif</option><option value="on" <?= $v('maintenance_mode')==='on'?'selected':''; ?>>Aktif</option></select></div>
<div class="system-field"><label>Pesan Maintenance</label><input name="maintenance_message" value="<?= e($v('maintenance_message','Mohon maaf, sistem sedang maintenance.')); ?>"></div>
<div class="system-field"><label>Reward: Rp per 1 poin</label><input type="number" min="0" name="member_point_earn_rate" value="<?= e($v('member_point_earn_rate','10000')); ?>"></div>
<div class="system-field"><label>Nilai 1 Poin</label><input type="number" min="1" name="member_point_value" value="<?= e($v('member_point_value','100')); ?>"></div>
<div class="system-field"><label>Minimal Tukar Poin</label><input type="number" min="1" name="member_point_min_redeem" value="<?= e($v('member_point_min_redeem','1')); ?>"></div>
<div class="system-field"><label>Token Fonnte</label><input type="password" name="fonnte_token" value="<?= e($v('fonnte_token')); ?>" autocomplete="off"></div>
<div class="system-field"><label>Country Code WA</label><input name="fonnte_country_code" value="<?= e($v('fonnte_country_code','62')); ?>"></div>
<div class="system-field"><label>Invoice WA</label><select name="fonnte_auto_invoice"><option value="manual" <?= $v('fonnte_auto_invoice','manual')==='manual'?'selected':''; ?>>Manual</option><option value="success" <?= $v('fonnte_auto_invoice')==='success'?'selected':''; ?>>Otomatis saat sukses</option></select></div>
<div class="system-field"><label>WhatsApp Owner</label><input name="fonnte_owner_phone" value="<?= e($v('fonnte_owner_phone')); ?>"></div>
</div><div class="system-form-actions"><a class="system-btn soft" href="<?= legacy_base_url('admin/dashboard.php'); ?>">Batal</a><button class="system-btn primary"><i class="bi bi-save"></i> Simpan Pengaturan POS</button></div></form>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
