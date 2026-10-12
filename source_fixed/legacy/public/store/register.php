<?php
require_once __DIR__ . '/_data.php';
$db=storefront_db(); if(!$db){http_response_code(503);exit('Database tidak tersedia.');}
if(marketplace_customer($db)) legacy_redirect('akun');
$error='';
if(is_post()){
 if(!CSRF::verify()) $error='Sesi formulir kedaluwarsa. Silakan coba lagi.';
 else {
  $name=trim((string)($_POST['name']??'')); $phone=preg_replace('/[^0-9+]/','',(string)($_POST['phone']??'')); $email=strtolower(trim((string)($_POST['email']??''))); $password=(string)($_POST['password']??''); $confirm=(string)($_POST['password_confirm']??'');
  try{
   if(mb_strlen($name)<2) throw new InvalidArgumentException('Nama lengkap wajib diisi.');
   if(strlen(preg_replace('/\D+/','',$phone))<9) throw new InvalidArgumentException('Nomor WhatsApp tidak valid.');
   if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Email tidak valid.');
   if(strlen($password)<6) throw new InvalidArgumentException('Password minimal 6 karakter.');
   if($password!==$confirm) throw new InvalidArgumentException('Konfirmasi password tidak sama.');
   $stmt=$db->prepare("SELECT * FROM customers WHERE LOWER(email)=LOWER(?) OR phone=? ORDER BY id ASC LIMIT 1"); $stmt->execute([$email,$phone]); $existing=$stmt->fetch();
   $hash=password_hash($password,PASSWORD_DEFAULT);
   if($existing){
      $sameEmail=strtolower((string)$existing['email'])===$email; $samePhone=(string)$existing['phone']===$phone;
      if(!empty($existing['marketplace_password_hash'])) throw new RuntimeException('Data ini sudah memiliki akun marketplace. Silakan masuk.');
      if(!$sameEmail || !$samePhone) throw new RuntimeException('Email atau nomor WhatsApp sudah terdaftar pada pelanggan lain. Hubungi admin untuk menyatukan data.');
      $db->prepare("UPDATE customers SET name=?,marketplace_password_hash=?,marketplace_registered_at=NOW(),marketplace_last_login_at=NOW(),status='active' WHERE id=?")->execute([$name,$hash,(int)$existing['id']]);
      $customerId=(int)$existing['id'];
   } else {
      $code='WEB'.date('ymd').strtoupper(bin2hex(random_bytes(2)));
      $db->prepare("INSERT INTO customers(name,phone,email,address,member_code,member_level,points,credit_limit,note,status,marketplace_password_hash,marketplace_registered_at,marketplace_last_login_at) VALUES(?,?,?,NULL,?,'regular',0,0,'Daftar dari marketplace','active',?,NOW(),NOW())")->execute([$name,$phone,$email,$code,$hash]);
      $customerId=(int)$db->lastInsertId();
   }
   session_regenerate_id(true); $_SESSION['marketplace_customer_id']=$customerId; legacy_redirect('akun');
  }catch(Throwable $e){$error=$e->getMessage();}
 }
}
$pageTitle='Daftar Akun';$activePage='account';require __DIR__.'/_header.php';
?>
<main class="inner-page customer-auth-page"><section class="store-container auth-shell"><div class="auth-copy"><span class="hero-label"><i class="bi bi-person-plus-fill"></i> MEMBER MARKETPLACE</span><h1>Buat Akun <span>Belanja</span></h1><p>Akun marketplace menggunakan data pelanggan yang sama dengan POS. Jika email dan nomor WhatsApp sudah tercatat sebagai member POS, data tersebut akan disambungkan saat keduanya cocok.</p></div><section class="checkout-box auth-card"><div class="checkout-box-title"><span><i class="bi bi-person-plus"></i></span><div><small>DAFTAR GRATIS</small><h2>Akun Baru</h2></div></div><?php if($error):?><div class="checkout-live-alert error"><i class="bi bi-exclamation-triangle-fill"></i><span><?=e($error)?></span></div><?php endif;?><form method="post" class="account-form"><?=CSRF::input()?><label>Nama Lengkap<input name="name" required minlength="2" value="<?=e($_POST['name']??'')?>"></label><label>Nomor WhatsApp<input name="phone" required inputmode="tel" value="<?=e($_POST['phone']??'')?>"></label><label>Email<input type="email" name="email" required value="<?=e($_POST['email']??'')?>"></label><label>Password<input type="password" name="password" required minlength="6"></label><label>Ulangi Password<input type="password" name="password_confirm" required minlength="6"></label><button class="btn-primary-store full" type="submit"><i class="bi bi-person-check-fill"></i> Daftar</button></form><div class="auth-foot">Sudah punya akun? <a href="<?=legacy_base_url('masuk')?>">Masuk</a></div></section></section></main>
<?php require __DIR__.'/_footer.php';?>
