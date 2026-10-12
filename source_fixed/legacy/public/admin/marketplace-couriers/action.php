<?php
require_once __DIR__.'/../../../app/bootstrap.php';require_once __DIR__.'/../../../app/Modules/Marketplace/Marketplace.php';Auth::requirePermission('settings.view');
$db=Database::connect();marketplace_install($db);
if(!is_post()||!CSRF::verify()){flash('error','Token keamanan tidak valid.');legacy_redirect('admin/marketplace-couriers/index.php');}
try{
    $action=trim((string)($_POST['action']??''));
    if($action!=='save') throw new RuntimeException('Aksi tidak dikenali.');
    $id=max(0,(int)($_POST['id']??0));$name=trim((string)($_POST['name']??''));$username=strtolower(trim((string)($_POST['username']??'')));$phone=trim((string)($_POST['phone']??''));$email=trim((string)($_POST['email']??''));$branch=max(0,(int)($_POST['branch_id']??0));$vehicle=trim((string)($_POST['vehicle_type']??'Motor'));$plate=strtoupper(trim((string)($_POST['vehicle_number']??'')));$status=(($_POST['status']??'active')==='inactive')?'inactive':'active';$password=(string)($_POST['password']??'');
    if(mb_strlen($name)<2) throw new RuntimeException('Nama kurir wajib diisi.');
    if(!preg_match('/^[a-z0-9._-]{3,100}$/',$username)) throw new RuntimeException('Username minimal 3 karakter dan hanya boleh huruf kecil, angka, titik, garis bawah, atau strip.');
    if(strlen(preg_replace('/\D+/','',$phone))<9) throw new RuntimeException('Nomor WhatsApp kurir tidak valid.');
    if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email kurir tidak valid.');
    if(!in_array($vehicle,['Motor','Mobil','Mobil Box','Sepeda','Lainnya'],true))$vehicle='Motor';
    if($branch>0){$b=$db->prepare("SELECT id FROM branches WHERE id=? AND is_active=1 LIMIT 1");$b->execute([$branch]);if(!(int)$b->fetchColumn())throw new RuntimeException('Cabang tidak valid.');}
    $dup=$db->prepare("SELECT id FROM marketplace_couriers WHERE username=? AND id<>? LIMIT 1");$dup->execute([$username,$id]);if($dup->fetchColumn())throw new RuntimeException('Username sudah digunakan kurir lain.');
    if($id>0){
        $current=marketplace_store_courier_get($db,$id);if(!$current)throw new RuntimeException('Kurir tidak ditemukan.');
        if($password!==''&&strlen($password)<6)throw new RuntimeException('Password baru minimal 6 karakter.');
        if($password!==''){$s=$db->prepare("UPDATE marketplace_couriers SET branch_id=?,name=?,phone=?,email=?,username=?,password_hash=?,vehicle_type=?,vehicle_number=?,status=?,updated_at=NOW() WHERE id=?");$s->execute([$branch?:null,$name,$phone,$email?:null,$username,password_hash($password,PASSWORD_DEFAULT),$vehicle,$plate?:null,$status,$id]);}
        else{$s=$db->prepare("UPDATE marketplace_couriers SET branch_id=?,name=?,phone=?,email=?,username=?,vehicle_type=?,vehicle_number=?,status=?,updated_at=NOW() WHERE id=?");$s->execute([$branch?:null,$name,$phone,$email?:null,$username,$vehicle,$plate?:null,$status,$id]);}
        Auth::log('update_store_courier','Kurir toko '.$name);flash('success','Data kurir berhasil diperbarui.');
    }else{
        if(strlen($password)<6)throw new RuntimeException('Password minimal 6 karakter.');
        $s=$db->prepare("INSERT INTO marketplace_couriers(branch_id,name,phone,email,username,password_hash,vehicle_type,vehicle_number,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())");$s->execute([$branch?:null,$name,$phone,$email?:null,$username,password_hash($password,PASSWORD_DEFAULT),$vehicle,$plate?:null,$status]);
        Auth::log('create_store_courier','Kurir toko '.$name);flash('success','Akun kurir berhasil dibuat.');
    }
}catch(Throwable $e){flash('error',$e->getMessage());}
legacy_redirect('admin/marketplace-couriers/index.php');
