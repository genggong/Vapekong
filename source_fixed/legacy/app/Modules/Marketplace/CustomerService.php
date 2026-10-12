<?php

/** Marketplace customer-service: cancellation requests, complaints, returns/refunds, and order chat. */

function marketplace_service_install(PDO $db): void
{
    try {
        if (!marketplace_column_exists($db,'online_orders','customer_confirmed_at')) $db->exec("ALTER TABLE online_orders ADD COLUMN customer_confirmed_at DATETIME NULL");
        if (!marketplace_column_exists($db,'online_orders','cancel_requested_at')) $db->exec("ALTER TABLE online_orders ADD COLUMN cancel_requested_at DATETIME NULL");
        if (!marketplace_column_exists($db,'online_orders','points_reversed_at')) $db->exec("ALTER TABLE online_orders ADD COLUMN points_reversed_at DATETIME NULL");
    } catch (Throwable $ignore) {}
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS marketplace_service_cases (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            case_number VARCHAR(80) NOT NULL,
            order_id BIGINT NOT NULL,
            customer_id BIGINT NOT NULL,
            case_type ENUM('cancel_refund','return_refund','complaint','support') NOT NULL DEFAULT 'support',
            status ENUM('open','waiting_admin','waiting_customer','approved','rejected','resolved','closed') NOT NULL DEFAULT 'open',
            subject VARCHAR(190) NOT NULL,
            reason VARCHAR(190) NULL,
            customer_note TEXT NULL,
            admin_note TEXT NULL,
            refund_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            refund_status ENUM('none','pending','processed','rejected') NOT NULL DEFAULT 'none',
            refund_method VARCHAR(120) NULL,
            refund_reference VARCHAR(190) NULL,
            refund_processed_at DATETIME NULL,
            resolved_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_marketplace_case_number(case_number),
            INDEX idx_marketplace_cases_order(order_id),
            INDEX idx_marketplace_cases_customer(customer_id),
            INDEX idx_marketplace_cases_status(status),
            CONSTRAINT fk_marketplace_cases_order FOREIGN KEY(order_id) REFERENCES online_orders(id) ON DELETE CASCADE,
            CONSTRAINT fk_marketplace_cases_customer FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $ignore) {}
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS marketplace_service_messages (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            case_id BIGINT NOT NULL,
            sender_type ENUM('customer','admin','system') NOT NULL,
            sender_id BIGINT NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_marketplace_service_messages_case(case_id,id),
            CONSTRAINT fk_marketplace_service_messages_case FOREIGN KEY(case_id) REFERENCES marketplace_service_cases(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $ignore) {}
}

function marketplace_service_case_number(): string
{
    return 'CASE-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(3)),0,6));
}

function marketplace_service_type_label(string $type): string
{
    return [
        'cancel_refund'=>'Pembatalan & Refund',
        'return_refund'=>'Retur & Refund',
        'complaint'=>'Komplain Pesanan',
        'support'=>'Bantuan Pesanan',
    ][$type] ?? ucwords(str_replace('_',' ',$type));
}

function marketplace_service_status_label(string $status): string
{
    return [
        'open'=>'Terbuka','waiting_admin'=>'Menunggu Admin','waiting_customer'=>'Menunggu Pelanggan',
        'approved'=>'Disetujui','rejected'=>'Ditolak','resolved'=>'Selesai','closed'=>'Ditutup'
    ][$status] ?? ucwords(str_replace('_',' ',$status));
}

function marketplace_service_get(PDO $db, int $id): ?array
{
    marketplace_service_install($db);
    $st=$db->prepare("SELECT c.*,o.order_number,o.public_token,o.order_status,o.payment_status,o.grand_total,o.recipient_name,o.recipient_phone,cu.name customer_name,cu.phone customer_phone FROM marketplace_service_cases c JOIN online_orders o ON o.id=c.order_id JOIN customers cu ON cu.id=c.customer_id WHERE c.id=? LIMIT 1");
    $st->execute([$id]); return $st->fetch() ?: null;
}

function marketplace_service_messages(PDO $db, int $caseId): array
{
    marketplace_service_install($db);
    $st=$db->prepare("SELECT * FROM marketplace_service_messages WHERE case_id=? ORDER BY id ASC");
    $st->execute([$caseId]); return $st->fetchAll() ?: [];
}

function marketplace_service_add_message(PDO $db, int $caseId, string $senderType, ?int $senderId, string $message): void
{
    $message=trim($message); if($message==='') return;
    $senderType=in_array($senderType,['customer','admin','system'],true)?$senderType:'system';
    $db->prepare("INSERT INTO marketplace_service_messages(case_id,sender_type,sender_id,message,created_at) VALUES(?,?,?,?,NOW())")->execute([$caseId,$senderType,$senderId,$message]);
}

function marketplace_service_notify_customer(PDO $db, array $case, string $title, string $message): void
{
    try {
        $db->prepare("INSERT INTO customer_notifications(customer_id,order_id,type,title,message,is_read,created_at) VALUES(?,?,'service',?,?,0,NOW())")
           ->execute([(int)$case['customer_id'],(int)$case['order_id'],$title,$message]);
    } catch (Throwable $ignore) {}
    if (function_exists('marketplace_wa_event_enabled') && marketplace_wa_event_enabled('service_case')) {
        $phone=(string)($case['customer_phone'] ?? $case['recipient_phone'] ?? '');
        $store=trim((string)setting('marketplace_site_title','')) ?: trim((string)setting('store_name','Toko'));
        $wa="*{$store}*\n{$title}\nKasus: {$case['case_number']}\nPesanan: {$case['order_number']}\n{$message}\n\nBuka bantuan: ".legacy_base_url('store/support-detail.php?id='.(int)$case['id']);
        marketplace_wa_enqueue($db,$phone,'service_case',$wa,'mkt-case-'.(int)$case['id'].'-'.substr(hash('sha256',$title.'|'.$message.'|'.microtime(true)),0,24),(int)$case['order_id'],(int)$case['customer_id']);
    }
}

function marketplace_service_create(PDO $db, int $customerId, int $orderId, string $type, string $reason, string $note): array
{
    marketplace_service_install($db);
    $type=in_array($type,['cancel_refund','return_refund','complaint','support'],true)?$type:'support';
    $st=$db->prepare("SELECT * FROM online_orders WHERE id=? AND customer_id=? LIMIT 1");$st->execute([$orderId,$customerId]);$order=$st->fetch();
    if(!$order) throw new RuntimeException('Pesanan tidak ditemukan pada akun Anda.');
    if(in_array($type,['cancel_refund','return_refund'],true)){
        $dup=$db->prepare("SELECT id FROM marketplace_service_cases WHERE order_id=? AND customer_id=? AND case_type=? AND status NOT IN('rejected','closed') LIMIT 1");
        $dup->execute([$orderId,$customerId,$type]); if($dup->fetchColumn()) throw new RuntimeException('Pengajuan sejenis untuk pesanan ini sudah ada.');
    }
    if($type==='cancel_refund' && in_array((string)$order['order_status'],['shipped','completed','cancelled','expired'],true)) throw new RuntimeException('Pesanan yang sudah dikirim/selesai tidak dapat diajukan pembatalan. Gunakan Retur/Komplain.');
    if($type==='return_refund' && !in_array((string)$order['order_status'],['shipped','completed'],true)) throw new RuntimeException('Retur dapat diajukan setelah pesanan dikirim.');
    $caseNumber=marketplace_service_case_number();
    $subject=marketplace_service_type_label($type).' - '.$order['order_number'];
    $refundStatus=in_array($type,['cancel_refund','return_refund'],true)&&$order['payment_status']==='paid'?'pending':'none';
    $refundAmount=$refundStatus==='pending'?(float)$order['grand_total']:0;
    $db->prepare("INSERT INTO marketplace_service_cases(case_number,order_id,customer_id,case_type,status,subject,reason,customer_note,refund_amount,refund_status,created_at) VALUES(?,?,?,?,'waiting_admin',?,?,?,?,?,NOW())")
       ->execute([$caseNumber,$orderId,$customerId,$type,$subject,$reason?:null,$note?:null,$refundAmount,$refundStatus]);
    $id=(int)$db->lastInsertId();
    marketplace_service_add_message($db,$id,'customer',$customerId,$note!==''?$note:($reason!==''?$reason:'Pengajuan dibuat.'));
    if($type==='cancel_refund') $db->prepare("UPDATE online_orders SET cancel_requested_at=COALESCE(cancel_requested_at,NOW()),updated_at=NOW() WHERE id=?")->execute([$orderId]);
    $case=marketplace_service_get($db,$id);
    if($case){
        marketplace_service_notify_customer($db,$case,'Pengajuan diterima','Tim toko akan meninjau pengajuan Anda.');
        if(function_exists('marketplace_wa_enqueue_admin')){
            marketplace_wa_enqueue_admin($db,'service_case','Pengajuan marketplace baru '.$caseNumber.' untuk order '.$order['order_number'].'. '.marketplace_service_type_label($type),'mkt-admin-case-'.$id,$orderId);
        }
    }
    return $case ?: ['id'=>$id,'case_number'=>$caseNumber];
}

function marketplace_confirm_received(PDO $db, int $customerId, int $orderId): bool
{
    marketplace_service_install($db);
    $st=$db->prepare("SELECT * FROM online_orders WHERE id=? AND customer_id=? LIMIT 1");$st->execute([$orderId,$customerId]);$o=$st->fetch();
    if(!$o) throw new RuntimeException('Pesanan tidak ditemukan.');
    if((string)$o['order_status']==='completed') return true;
    if((string)$o['order_status']!=='shipped') throw new RuntimeException('Pesanan belum berstatus dikirim.');
    $db->prepare("UPDATE online_orders SET order_status='completed',fulfillment_status='completed',customer_confirmed_at=NOW(),completed_at=COALESCE(completed_at,NOW()),updated_at=NOW() WHERE id=?")->execute([$orderId]);
    marketplace_add_event($db,$orderId,'completed','Pesanan diterima','Pelanggan mengonfirmasi barang telah diterima.');
    return true;
}

function marketplace_reverse_customer_points(PDO $db, int $orderId, string $reason='Refund marketplace'): int
{
    try {
        if (!marketplace_column_exists($db,'online_orders','points_reversed_at')) $db->exec("ALTER TABLE online_orders ADD COLUMN points_reversed_at DATETIME NULL");
        $started=!$db->inTransaction(); if($started)$db->beginTransaction();
        $st=$db->prepare("SELECT id,order_number,customer_id,points_earned,points_reversed_at FROM online_orders WHERE id=? FOR UPDATE");$st->execute([$orderId]);$o=$st->fetch();
        if(!$o||(int)$o['customer_id']<=0||(int)$o['points_earned']<=0||!empty($o['points_reversed_at'])){if($started)$db->commit();return 0;}
        $points=(int)$o['points_earned'];
        $db->prepare("UPDATE customers SET points=GREATEST(points-?,0),updated_at=NOW() WHERE id=?")->execute([$points,(int)$o['customer_id']]);
        $db->prepare("INSERT INTO customer_points(customer_id,transaction_id,type,points,description,created_at) VALUES(?,NULL,'out',?,?,NOW())")->execute([(int)$o['customer_id'],$points,$reason.' '.$o['order_number']]);
        $db->prepare("UPDATE online_orders SET points_reversed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$orderId]);
        if($started)$db->commit();
        return $points;
    } catch(Throwable $e){if(isset($started)&&$started&&$db->inTransaction())$db->rollBack();return 0;}
}
