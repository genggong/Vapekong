<?php
/**
 * Marketplace Store Courier portal (V15.4)
 * - Dedicated courier accounts/session (separate from admin/customer auth)
 * - Local/store-courier shipping option
 * - Admin assignment + courier delivery workflow
 * - Proof-of-delivery and delivery timeline
 */

function marketplace_store_courier_install(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS marketplace_couriers (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            branch_id BIGINT NULL,
            name VARCHAR(160) NOT NULL,
            phone VARCHAR(50) NOT NULL,
            email VARCHAR(190) NULL,
            username VARCHAR(100) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            vehicle_type VARCHAR(40) NOT NULL DEFAULT 'Motor',
            vehicle_number VARCHAR(80) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            last_login_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_marketplace_courier_username(username),
            INDEX idx_marketplace_courier_status(status),
            INDEX idx_marketplace_courier_branch(branch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $ignore) {}

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS marketplace_courier_assignments (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT NOT NULL,
            courier_id BIGINT NOT NULL,
            assigned_by BIGINT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'assigned',
            assigned_at DATETIME NOT NULL,
            accepted_at DATETIME NULL,
            picked_up_at DATETIME NULL,
            on_the_way_at DATETIME NULL,
            delivered_at DATETIME NULL,
            failed_at DATETIME NULL,
            returned_at DATETIME NULL,
            delivery_proof VARCHAR(255) NULL,
            recipient_name VARCHAR(160) NULL,
            delivery_note TEXT NULL,
            failure_reason TEXT NULL,
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(10,7) NULL,
            location_updated_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_store_courier_order(order_id),
            INDEX idx_store_courier_courier(courier_id,status),
            INDEX idx_store_courier_status(status,assigned_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $ignore) {}

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS marketplace_courier_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            assignment_id BIGINT NOT NULL,
            order_id BIGINT NOT NULL,
            courier_id BIGINT NULL,
            status VARCHAR(40) NOT NULL,
            title VARCHAR(160) NOT NULL,
            note TEXT NULL,
            proof_path VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_store_courier_event_assignment(assignment_id,created_at),
            INDEX idx_store_courier_event_order(order_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $ignore) {}

    $defaults = [
        'marketplace_store_courier_enabled' => 'off',
        'marketplace_store_courier_name' => 'Kurir Toko',
        'marketplace_store_courier_fee' => '10000',
        'marketplace_store_courier_eta' => '1-3 jam',
        'marketplace_store_courier_coverage' => 'same_city',
        'marketplace_store_courier_rate_mode' => 'flat',
    ];
    foreach ($defaults as $key => $value) {
        try {
            $stmt = $db->prepare("INSERT IGNORE INTO settings(setting_key,setting_value) VALUES(?,?)");
            $stmt->execute([$key,$value]);
        } catch (Throwable $ignore) {}
    }
}

function marketplace_store_courier_config(): array
{
    $coverage = strtolower(trim((string)setting('marketplace_store_courier_coverage','same_city')));
    if (!in_array($coverage,['same_district','same_city','same_province','all'],true)) $coverage='same_city';
    $rateMode=strtolower(trim((string)setting('marketplace_store_courier_rate_mode','flat')));
    if(!in_array($rateMode,['flat','subdistrict'],true))$rateMode='flat';
    return [
        'enabled' => setting('marketplace_store_courier_enabled','off') === 'on',
        'name' => trim((string)setting('marketplace_store_courier_name','Kurir Toko')) ?: 'Kurir Toko',
        'fee' => max(0,(float)setting('marketplace_store_courier_fee','10000')),
        'eta' => trim((string)setting('marketplace_store_courier_eta','1-3 jam')) ?: '1-3 jam',
        'coverage' => $coverage,
        'rate_mode' => $rateMode,
    ];
}

function marketplace_store_courier_norm(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/\b(KOTA|KABUPATEN|KAB\.?|KECAMATAN|KEC\.?|KELURAHAN|DESA)\b/u','',$value) ?: '';
    $value = preg_replace('/[^A-Z0-9]+/u',' ',$value) ?: '';
    return trim(preg_replace('/\s+/',' ',$value) ?: '');
}

function marketplace_store_courier_eligible_region(string $province, string $city, string $district): bool
{
    $cfg = marketplace_store_courier_config();
    if (!$cfg['enabled']) return false;
    if ($cfg['coverage'] === 'all') return true;

    $originProvince = marketplace_store_courier_norm((string)setting('rajaongkir_origin_province_name',''));
    $originCity = marketplace_store_courier_norm((string)setting('rajaongkir_origin_city_name',''));
    $originDistrict = marketplace_store_courier_norm((string)setting('rajaongkir_origin_district_name',''));
    $province = marketplace_store_courier_norm($province);
    $city = marketplace_store_courier_norm($city);
    $district = marketplace_store_courier_norm($district);

    if ($cfg['coverage'] === 'same_province') return $originProvince !== '' && $province === $originProvince;
    if ($cfg['coverage'] === 'same_city') return $originCity !== '' && $city === $originCity;
    return $originDistrict !== '' && $district === $originDistrict;
}

function marketplace_store_courier_option(string $province, string $city, string $district, string $subdistrict='', int $destinationId=0): ?array
{
    $cfg = marketplace_store_courier_config();
    if (!$cfg['enabled'] || !marketplace_store_courier_eligible_region($province,$city,$district)) return null;
    try {
        $db=Database::connect();marketplace_v16_install($db);
        if(!marketplace_store_courier_active($db,marketplace_branch_id($db))) return null;
        $rate=null;
        if(($cfg['rate_mode']??'flat')==='subdistrict'){
            $rate=marketplace_v16_store_courier_rate($db,$destinationId,$province,$city,$district,$subdistrict);
            if(!$rate) return null;
        }
        $fee=$rate?max(0,(float)$rate['fee']):(float)$cfg['fee'];
        $eta=$rate&&trim((string)($rate['eta']??''))!==''?trim((string)$rate['eta']):$cfg['eta'];
        return [
            'code' => 'store',
            'name' => $cfg['name'],
            'service' => 'LOCAL',
            'description' => $rate ? ('Kurir Toko • '.trim((string)$rate['subdistrict'])) : 'Diantar langsung oleh kurir toko',
            'cost' => $fee,
            'etd' => $eta,
            'is_store_courier' => true,
            'rate_id' => (int)($rate['id']??0),
        ];
    } catch(Throwable $e) { return null; }
}

function marketplace_store_courier_validate_destination(array $payload, int $destinationId): bool
{
    $province = trim((string)($payload['province'] ?? ''));
    $city = trim((string)($payload['city'] ?? ''));
    $district = trim((string)($payload['district'] ?? ''));
    $subdistrict = trim((string)($payload['subdistrict'] ?? ''));
    $postal = trim((string)($payload['postal_code'] ?? ''));
    if (!marketplace_store_courier_eligible_region($province,$city,$district)) return false;
    try {
        $resolved = marketplace_rajaongkir_resolve_direct_destination($subdistrict,$district,$city,$postal);
        if((int)($resolved['id'] ?? 0) !== $destinationId) return false;
        $cfg=marketplace_store_courier_config();
        if(($cfg['rate_mode']??'flat')==='subdistrict'){
            $db=Database::connect();
            return marketplace_v16_store_courier_rate($db,$destinationId,$province,$city,$district,$subdistrict)!==null;
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function marketplace_store_courier_active(PDO $db, ?int $branchId=null): array
{
    marketplace_store_courier_install($db);
    $params=[];
    $sql="SELECT * FROM marketplace_couriers WHERE status='active'";
    if ($branchId && $branchId>0) { $sql.=" AND (branch_id IS NULL OR branch_id=?)"; $params[]=$branchId; }
    $sql.=" ORDER BY name ASC,id ASC";
    $stmt=$db->prepare($sql);$stmt->execute($params);return $stmt->fetchAll() ?: [];
}

function marketplace_store_courier_get(PDO $db,int $id): ?array
{
    marketplace_store_courier_install($db);
    $s=$db->prepare("SELECT * FROM marketplace_couriers WHERE id=? LIMIT 1");$s->execute([$id]);return $s->fetch() ?: null;
}

function marketplace_store_courier_assignment(PDO $db,int $orderId): ?array
{
    marketplace_store_courier_install($db);
    $s=$db->prepare("SELECT a.*,a.recipient_name proof_recipient_name,a.delivery_note proof_note,a.delivery_proof proof_path,c.name courier_name,c.phone courier_phone,c.vehicle_type,c.vehicle_number,o.order_number,o.recipient_name,o.recipient_phone,o.address,o.destination_label,o.city,o.district,o.subdistrict,o.postal_code,o.customer_note,o.order_status,o.fulfillment_status,o.payment_status,o.branch_id,o.courier_code,o.courier_name shipping_name,o.courier_service,o.grand_total,o.shipping_cost FROM marketplace_courier_assignments a JOIN marketplace_couriers c ON c.id=a.courier_id JOIN online_orders o ON o.id=a.order_id WHERE a.order_id=? LIMIT 1");
    $s->execute([$orderId]);return $s->fetch() ?: null;
}

function marketplace_store_courier_assignment_by_id(PDO $db,int $assignmentId,?int $courierId=null): ?array
{
    marketplace_store_courier_install($db);
    $sql="SELECT a.*,a.recipient_name proof_recipient_name,a.delivery_note proof_note,a.delivery_proof proof_path,c.name assigned_courier_name,c.phone courier_phone,c.vehicle_type,c.vehicle_number,o.* FROM marketplace_courier_assignments a JOIN marketplace_couriers c ON c.id=a.courier_id JOIN online_orders o ON o.id=a.order_id WHERE a.id=?";
    $params=[$assignmentId];
    if ($courierId) { $sql.=" AND a.courier_id=?";$params[]=$courierId; }
    $sql.=" LIMIT 1";
    $s=$db->prepare($sql);$s->execute($params);return $s->fetch() ?: null;
}

function marketplace_store_courier_add_event(PDO $db,int $assignmentId,int $orderId,?int $courierId,string $status,string $title,string $note='',?string $proof=null): void
{
    try {
        $s=$db->prepare("INSERT INTO marketplace_courier_events(assignment_id,order_id,courier_id,status,title,note,proof_path,created_at) VALUES(?,?,?,?,?,?,?,NOW())");
        $s->execute([$assignmentId,$orderId,$courierId ?: null,$status,$title,$note !== '' ? $note : null,$proof]);
    } catch (Throwable $ignore) {}
}

function marketplace_store_courier_assign(PDO $db,int $orderId,int $courierId,?int $assignedBy=null): array
{
    marketplace_store_courier_install($db);
    $o=$db->prepare("SELECT * FROM online_orders WHERE id=? LIMIT 1");$o->execute([$orderId]);$order=$o->fetch();
    if(!$order) throw new RuntimeException('Pesanan tidak ditemukan.');
    if((string)$order['payment_status']!=='paid') throw new RuntimeException('Pesanan harus sudah dibayar sebelum ditugaskan ke kurir toko.');
    if((string)$order['courier_code']!=='store') throw new RuntimeException('Pesanan ini tidak memilih layanan Kurir Toko.');
    if(in_array((string)$order['order_status'],['completed','cancelled','expired'],true)) throw new RuntimeException('Status pesanan tidak dapat ditugaskan.');
    $courier=marketplace_store_courier_get($db,$courierId);
    if(!$courier || (string)$courier['status']!=='active') throw new RuntimeException('Kurir toko tidak aktif atau tidak ditemukan.');
    if(!empty($courier['branch_id']) && (int)$courier['branch_id']!==(int)$order['branch_id']) throw new RuntimeException('Kurir tidak terdaftar pada cabang fulfillment pesanan ini.');

    $existing=marketplace_store_courier_assignment($db,$orderId);
    if($existing && in_array((string)$existing['status'],['picked_up','on_the_way','delivered'],true)) throw new RuntimeException('Kurir tidak dapat diganti setelah paket diambil.');

    if($existing){
        $s=$db->prepare("UPDATE marketplace_courier_assignments SET courier_id=?,assigned_by=?,status='assigned',assigned_at=NOW(),accepted_at=NULL,picked_up_at=NULL,on_the_way_at=NULL,failed_at=NULL,returned_at=NULL,failure_reason=NULL,updated_at=NOW() WHERE id=?");
        $s->execute([$courierId,$assignedBy ?: null,(int)$existing['id']]);
        $assignmentId=(int)$existing['id'];
    } else {
        $s=$db->prepare("INSERT INTO marketplace_courier_assignments(order_id,courier_id,assigned_by,status,assigned_at,created_at) VALUES(?,?,?,'assigned',NOW(),NOW())");
        $s->execute([$orderId,$courierId,$assignedBy ?: null]);
        $assignmentId=(int)$db->lastInsertId();
    }
    $db->prepare("UPDATE online_orders SET delivery_status='Menunggu kurir toko',updated_at=NOW() WHERE id=?")->execute([$orderId]);
    marketplace_store_courier_add_event($db,$assignmentId,$orderId,$courierId,'assigned','Paket ditugaskan','Ditugaskan kepada '.$courier['name'].'.');
    if(function_exists('marketplace_add_event')) marketplace_add_event($db,$orderId,'courier_assigned','Kurir toko ditugaskan','Kurir: '.$courier['name']);
    if(function_exists('marketplace_v15_log')) { try{marketplace_v15_log($db,'store_courier_assign','online_order',$orderId,'Kurir '.$courier['name'].' ditugaskan ke '.$order['order_number']);}catch(Throwable $ignore){} }
    return marketplace_store_courier_assignment($db,$orderId) ?: [];
}

function marketplace_courier_session(): ?array
{
    return isset($_SESSION['marketplace_courier']) && is_array($_SESSION['marketplace_courier']) ? $_SESSION['marketplace_courier'] : null;
}

function marketplace_courier_id(): int
{
    return (int)(marketplace_courier_session()['id'] ?? 0);
}

function marketplace_courier_login(PDO $db,string $identity,string $password): bool
{
    marketplace_store_courier_install($db);
    $identity=trim($identity);
    $s=$db->prepare("SELECT * FROM marketplace_couriers WHERE (username=? OR phone=? OR email=?) AND status='active' LIMIT 1");
    $s->execute([$identity,$identity,$identity]);$courier=$s->fetch();
    if(!$courier || !password_verify($password,(string)$courier['password_hash'])) return false;
    session_regenerate_id(true);
    $_SESSION['marketplace_courier']=[
        'id'=>(int)$courier['id'],'name'=>(string)$courier['name'],'username'=>(string)$courier['username'],'phone'=>(string)$courier['phone'],
        'branch_id'=>(int)($courier['branch_id'] ?? 0),'vehicle_type'=>(string)$courier['vehicle_type'],'vehicle_number'=>(string)($courier['vehicle_number'] ?? '')
    ];
    $db->prepare("UPDATE marketplace_couriers SET last_login_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int)$courier['id']]);
    return true;
}

function marketplace_courier_logout(): void
{
    unset($_SESSION['marketplace_courier']);
    session_regenerate_id(true);
}

function marketplace_courier_require(): array
{
    $session=marketplace_courier_session();
    if(!$session){ legacy_redirect('kurir/login'); }
    return $session;
}

function marketplace_courier_refresh_session(PDO $db): ?array
{
    $id=marketplace_courier_id();if($id<=0)return null;
    $c=marketplace_store_courier_get($db,$id);
    if(!$c || (string)$c['status']!=='active'){marketplace_courier_logout();return null;}
    $_SESSION['marketplace_courier']=[
        'id'=>(int)$c['id'],'name'=>(string)$c['name'],'username'=>(string)$c['username'],'phone'=>(string)$c['phone'],
        'branch_id'=>(int)($c['branch_id'] ?? 0),'vehicle_type'=>(string)$c['vehicle_type'],'vehicle_number'=>(string)($c['vehicle_number'] ?? '')
    ];
    return $_SESSION['marketplace_courier'];
}

function marketplace_courier_jobs(PDO $db,int $courierId,string $filter='active',int $limit=100): array
{
    marketplace_store_courier_install($db);
    $limit=max(1,min(300,$limit));
    $where='a.courier_id=?';$params=[$courierId];
    if($filter==='active') $where.=" AND a.status IN('assigned','accepted','picked_up','on_the_way','failed')";
    elseif($filter==='done') $where.=" AND a.status IN('delivered','returned')";
    $sql="SELECT a.*,o.order_number,o.recipient_name,o.recipient_phone,o.address,o.destination_label,o.province,o.city,o.district,o.subdistrict,o.postal_code,o.customer_note,o.grand_total,o.shipping_cost,o.created_at order_created_at FROM marketplace_courier_assignments a JOIN online_orders o ON o.id=a.order_id WHERE {$where} ORDER BY CASE a.status WHEN 'on_the_way' THEN 1 WHEN 'picked_up' THEN 2 WHEN 'accepted' THEN 3 WHEN 'assigned' THEN 4 WHEN 'failed' THEN 5 ELSE 9 END,a.assigned_at ASC LIMIT {$limit}";
    $s=$db->prepare($sql);$s->execute($params);return $s->fetchAll() ?: [];
}

function marketplace_courier_events(PDO $db,int $assignmentId): array
{
    marketplace_store_courier_install($db);
    $s=$db->prepare("SELECT * FROM marketplace_courier_events WHERE assignment_id=? ORDER BY id DESC LIMIT 100");$s->execute([$assignmentId]);return $s->fetchAll() ?: [];
}

function marketplace_courier_upload_proof(array $file,int $orderId): ?string
{
    if(empty($file['name'])||empty($file['tmp_name'])) return null;
    if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK) throw new RuntimeException('Upload bukti pengantaran gagal.');
    if((int)($file['size']??0)>6*1024*1024) throw new RuntimeException('Ukuran foto bukti maksimal 6MB.');
    $info=@getimagesize($file['tmp_name']);$map=[IMAGETYPE_JPEG=>'jpg',IMAGETYPE_PNG=>'png',IMAGETYPE_WEBP=>'webp'];
    $ext=$info&&isset($map[$info[2]??0])?$map[$info[2]]:null;
    if(!$ext) throw new RuntimeException('Bukti pengantaran harus berupa JPG, PNG, atau WEBP.');
    $dir=dirname(__DIR__,3).'/public/uploads/courier-proof';
    if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir)) throw new RuntimeException('Folder bukti pengantaran tidak dapat dibuat.');
    $name='proof-'.$orderId.'-'.date('YmdHis').'-'.bin2hex(random_bytes(3)).'.'.$ext;
    if(!move_uploaded_file($file['tmp_name'],$dir.'/'.$name)) throw new RuntimeException('Gagal menyimpan foto bukti.');
    return 'uploads/courier-proof/'.$name;
}

function marketplace_courier_update_location(PDO $db,int $assignmentId,int $courierId,?float $lat,?float $lng): void
{
    if($lat===null||$lng===null||$lat<-90||$lat>90||$lng<-180||$lng>180)return;
    $s=$db->prepare("UPDATE marketplace_courier_assignments SET latitude=?,longitude=?,location_updated_at=NOW(),updated_at=NOW() WHERE id=? AND courier_id=?");
    $s->execute([$lat,$lng,$assignmentId,$courierId]);
}

function marketplace_courier_update_status(PDO $db,int $assignmentId,int $courierId,string $action,array $payload=[],?array $proofFile=null): array
{
    marketplace_store_courier_install($db);
    $assignment=marketplace_store_courier_assignment_by_id($db,$assignmentId,$courierId);
    if(!$assignment) throw new RuntimeException('Paket tidak ditemukan atau bukan tugas Anda.');
    $current=(string)$assignment['status'];
    if($action==='pickup' && (string)($assignment['fulfillment_status']??'')!=='ready_ship') throw new RuntimeException('Paket belum berstatus Siap Dikirim. Selesaikan picking & packing terlebih dahulu.');
    $transitions=[
        'accept'=>['from'=>['assigned'],'to'=>'accepted','title'=>'Tugas diterima'],
        'pickup'=>['from'=>['assigned','accepted'],'to'=>'picked_up','title'=>'Paket diambil dari toko'],
        'ontheway'=>['from'=>['picked_up'],'to'=>'on_the_way','title'=>'Kurir menuju alamat penerima'],
        'deliver'=>['from'=>['picked_up','on_the_way','failed'],'to'=>'delivered','title'=>'Paket berhasil diterima'],
        'fail'=>['from'=>['picked_up','on_the_way'],'to'=>'failed','title'=>'Pengantaran belum berhasil'],
        'return'=>['from'=>['failed','picked_up','on_the_way'],'to'=>'returned','title'=>'Paket dikembalikan ke toko'],
    ];
    if(!isset($transitions[$action])) throw new RuntimeException('Aksi kurir tidak dikenali.');
    $tr=$transitions[$action];
    if(!in_array($current,$tr['from'],true)) throw new RuntimeException('Status paket sudah berubah. Muat ulang halaman.');

    $note=trim((string)($payload['note']??''));
    $recipient=trim((string)($payload['recipient_name']??''));
    $proof=null;
    if($action==='deliver'){
        if($recipient==='') throw new RuntimeException('Nama penerima paket wajib diisi.');
        $proof=marketplace_courier_upload_proof($proofFile??[],(int)$assignment['order_id']);
        if($proof===null) throw new RuntimeException('Foto bukti pengantaran wajib diupload.');
    }
    if($action==='fail' && mb_strlen($note)<5) throw new RuntimeException('Tuliskan alasan pengantaran gagal.');

    $columns=['status=?','updated_at=NOW()'];$params=[$tr['to']];
    $stamp=['accepted'=>'accepted_at','picked_up'=>'picked_up_at','on_the_way'=>'on_the_way_at','delivered'=>'delivered_at','failed'=>'failed_at','returned'=>'returned_at'][$tr['to']]??null;
    if($stamp)$columns[]="{$stamp}=NOW()";
    if($proof!==null){$columns[]='delivery_proof=?';$params[]=$proof;}
    if($recipient!==''){$columns[]='recipient_name=?';$params[]=$recipient;}
    if($note!==''){$columns[]='delivery_note=?';$params[]=$note;}
    if($action==='fail'){$columns[]='failure_reason=?';$params[]=$note;}
    $params[]=$assignmentId;$params[]=$courierId;
    $s=$db->prepare("UPDATE marketplace_courier_assignments SET ".implode(',',$columns)." WHERE id=? AND courier_id=?");$s->execute($params);

    $orderId=(int)$assignment['order_id'];
    $deliveryStatus=[
        'accepted'=>'Kurir toko menerima tugas','picked_up'=>'Paket diambil kurir toko','on_the_way'=>'Kurir toko dalam perjalanan','delivered'=>'Paket diterima','failed'=>'Pengantaran kurir toko gagal','returned'=>'Paket kembali ke toko'
    ][$tr['to']] ?? $tr['title'];
    if(in_array($tr['to'],['picked_up','on_the_way'],true)){
        $db->prepare("UPDATE online_orders SET order_status='shipped',fulfillment_status='shipped',delivery_status=?,shipped_at=COALESCE(shipped_at,NOW()),updated_at=NOW() WHERE id=?")->execute([$deliveryStatus,$orderId]);
    } elseif($tr['to']==='delivered'){
        $db->prepare("UPDATE online_orders SET order_status='completed',fulfillment_status='completed',delivery_status=?,completed_at=COALESCE(completed_at,NOW()),updated_at=NOW() WHERE id=?")->execute([$deliveryStatus,$orderId]);
    } elseif($tr['to']==='returned') {
        $db->prepare("UPDATE online_orders SET order_status='processing',fulfillment_status='ready_ship',delivery_status=?,shipped_at=NULL,updated_at=NOW() WHERE id=?")->execute([$deliveryStatus,$orderId]);
    } else {
        $db->prepare("UPDATE online_orders SET delivery_status=?,updated_at=NOW() WHERE id=?")->execute([$deliveryStatus,$orderId]);
    }
    marketplace_store_courier_add_event($db,$assignmentId,$orderId,$courierId,$tr['to'],$tr['title'],$note,$proof);
    if(function_exists('marketplace_add_event')) marketplace_add_event($db,$orderId,'store_courier_'.$tr['to'],$tr['title'],$note ?: $deliveryStatus);
    if($tr['to']==='on_the_way' && function_exists('marketplace_wa_enqueue_order_event')) marketplace_wa_enqueue_order_event($db,$orderId,'shipped','Pesanan sedang diantar','Kurir toko sedang menuju alamat Anda.');
    if($tr['to']==='delivered' && function_exists('marketplace_wa_enqueue_order_event')) marketplace_wa_enqueue_order_event($db,$orderId,'completed','Pesanan telah diterima','Paket telah diterima oleh '.($recipient ?: 'penerima').'.');
    return marketplace_store_courier_assignment_by_id($db,$assignmentId,$courierId) ?: [];
}

function marketplace_store_courier_status_label(string $status): string
{
    return [
        'assigned'=>'Menunggu Kurir','accepted'=>'Tugas Diterima','picked_up'=>'Paket Diambil','on_the_way'=>'Dalam Perjalanan','delivered'=>'Terkirim','failed'=>'Gagal Antar','returned'=>'Kembali ke Toko'
    ][$status] ?? ucwords(str_replace('_',' ',$status));
}

function marketplace_store_courier_status_class(string $status): string
{
    return match($status){'delivered'=>'success','on_the_way','picked_up'=>'info','failed','returned'=>'danger',default=>'warning'};
}
