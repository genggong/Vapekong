<?php

function import_products_normalize_header(string $value): string
{
    $value = trim(strtolower($value));
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
    $value = str_replace([' ', '-', '.', '/', '\\'], '_', $value);
    $value = preg_replace('/[^a-z0-9_]/', '', $value);
    return trim($value, '_');
}

function import_products_parse_number($value): float
{
    $value = trim((string)$value);
    if ($value === '') return 0;
    $value = str_ireplace(['rp', 'idr', ' '], '', $value);
    $value = preg_replace('/[^0-9,\.\-]/', '', $value);

    if (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $value)) {
        $value = str_replace('.', '', $value);
    } elseif (preg_match('/^-?\d{1,3}(,\d{3})+$/', $value)) {
        $value = str_replace(',', '', $value);
    } elseif (strpos($value, ',') !== false && strpos($value, '.') !== false) {
        if (strrpos($value, ',') > strrpos($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    } else {
        $value = str_replace(',', '.', $value);
    }
    return (float)$value;
}

function import_products_parse_bool($value): int
{
    $value = strtolower(trim((string)$value));
    if ($value === '') return 1;
    return in_array($value, ['1', 'true', 'aktif', 'active', 'yes', 'ya', 'y'], true) ? 1 : 0;
}

function import_products_generate_code(PDO $db, int $i = 0): string
{
    do {
        $code = 'PRD' . date('YmdHis') . str_pad((string)random_int(10, 999), 3, '0', STR_PAD_LEFT) . $i;
        $stmt = $db->prepare('SELECT COUNT(*) FROM products WHERE product_code=?');
        $stmt->execute([$code]);
    } while ((int)$stmt->fetchColumn() > 0);

    return $code;
}

function import_products_detect_delimiter(string $file): string
{
    $line = '';
    $handle = fopen($file, 'r');
    if ($handle) {
        while (!feof($handle)) {
            $candidate = fgets($handle, 65536);
            if ($candidate === false) break;
            if (trim($candidate) !== '') { $line = $candidate; break; }
        }
        fclose($handle);
    }
    $candidates = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
    arsort($candidates);
    $delimiter = array_key_first($candidates);
    return $candidates[$delimiter] > 0 ? $delimiter : ',';
}

function import_products_find_category(PDO $db, ?string $name, bool $createMissing): ?int
{
    $name = trim((string)$name);
    if ($name === '') return null;

    $stmt = $db->prepare('SELECT id FROM categories WHERE LOWER(name)=LOWER(?) LIMIT 1');
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;

    if (!$createMissing) return null;

    $slug = slugify($name);
    $baseSlug = $slug;
    $i = 2;
    while (true) {
        $check = $db->prepare('SELECT COUNT(*) FROM categories WHERE slug=?');
        $check->execute([$slug]);
        if ((int)$check->fetchColumn() === 0) break;
        $slug = $baseSlug . '-' . $i++;
    }

    $ins = $db->prepare("INSERT INTO categories (name, slug, icon, color, description, sort_order, is_active) VALUES (?, ?, 'bi-box-seam', '#dc2626', ?, 99, 1)");
    $ins->execute([$name, $slug, 'Dibuat otomatis dari import produk']);
    return (int)$db->lastInsertId();
}

function import_products_find_supplier(PDO $db, ?string $name, bool $createMissing): ?int
{
    $name = trim((string)$name);
    if ($name === '') return null;

    $stmt = $db->prepare('SELECT id FROM suppliers WHERE LOWER(name)=LOWER(?) LIMIT 1');
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;

    if (!$createMissing) return null;

    $ins = $db->prepare("INSERT INTO suppliers (name, note, is_active) VALUES (?, 'Dibuat otomatis dari import produk', 1)");
    $ins->execute([$name]);
    return (int)$db->lastInsertId();
}

function import_products_existing_value(PDO $db, string $column, string $value, ?int $ignoreId = null): bool
{
    $value = trim($value);
    if ($value === '') return false;
    $allowed = ['product_code', 'barcode'];
    if (!in_array($column, $allowed, true)) return false;

    $sql = "SELECT COUNT(*) FROM products WHERE {$column}=?";
    $params = [$value];
    if ($ignoreId) {
        $sql .= ' AND id != ?';
        $params[] = $ignoreId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

function import_products_read_uploaded_file(array $file, int $maxRows = 1001): array
{
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name']) && !is_file($file['tmp_name'])) {
        throw new Exception('File produk wajib dipilih.');
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new Exception('Upload file gagal dengan kode ' . (int)$file['error'] . '.');
    }
    $size = (int)($file['size'] ?? filesize($file['tmp_name']));
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        throw new Exception('Ukuran file maksimal 10MB.');
    }

    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'csv', 'txt', 'pdf'], true)) {
        throw new Exception('Format harus XLSX, CSV, atau PDF hasil export KasirPro.');
    }

    if ($ext === 'xlsx') {
        require_once __DIR__ . '/../../../app/Modules/DataExchange/Xlsx.php';
        $rows = KpXlsx::readFirstSheet($file['tmp_name'], $maxRows + 1);
        if (!$rows) throw new Exception('Sheet Excel kosong atau tidak terbaca.');
        return $rows;
    }

    if ($ext === 'pdf') {
        require_once __DIR__ . '/../../../app/Modules/DataExchange/Pdf.php';
        $json = KpPdfDocument::readEmbeddedComment($file['tmp_name'], 'PRODUCT-DATA');
        if ($json === null) {
            throw new Exception('PDF tidak memiliki data produk yang bisa diimpor. Gunakan PDF hasil export dari menu Produk KasirPro.');
        }
        $payload = json_decode($json, true);
        if (!is_array($payload) || empty($payload['headers']) || !isset($payload['rows']) || !is_array($payload['rows'])) {
            throw new Exception('Data produk di dalam PDF tidak valid.');
        }
        return array_merge([$payload['headers']], array_slice($payload['rows'], 0, $maxRows));
    }

    $delimiter = import_products_detect_delimiter($file['tmp_name']);
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) throw new Exception('File CSV tidak bisa dibaca.');
    $rows = [];
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $rows[] = $data;
        if (count($rows) > $maxRows) break;
    }
    fclose($handle);
    if (!$rows) throw new Exception('File CSV kosong.');
    return $rows;
}

function import_products_find_existing(PDO $db, string $productCode, string $barcode, string $name = ''): array
{
    $byCode = null;
    $byBarcode = null;
    $byName = null;

    if ($productCode !== '') {
        $stmt = $db->prepare('SELECT * FROM products WHERE product_code=? LIMIT 1');
        $stmt->execute([$productCode]);
        $byCode = $stmt->fetch() ?: null;
    }
    if ($barcode !== '') {
        $stmt = $db->prepare('SELECT * FROM products WHERE barcode=? LIMIT 1');
        $stmt->execute([$barcode]);
        $byBarcode = $stmt->fetch() ?: null;
    }

    if ($byCode && $byBarcode && (int)$byCode['id'] !== (int)$byBarcode['id']) {
        return ['row' => null, 'conflict' => true];
    }

    // Template 2026 tidak lagi menampilkan kode produk/barcode.
    // Bila keduanya kosong, pembaruan dicocokkan dengan nama produk persis (case-insensitive).
    if (!$byCode && !$byBarcode && trim($name) !== '') {
        $stmt = $db->prepare('SELECT * FROM products WHERE LOWER(TRIM(name))=LOWER(TRIM(?)) ORDER BY id ASC LIMIT 2');
        $stmt->execute([$name]);
        $matches = $stmt->fetchAll();
        if (count($matches) > 1) {
            return ['row' => null, 'conflict' => true, 'reason' => 'duplicate_name'];
        }
        $byName = $matches[0] ?? null;
    }

    return ['row' => $byCode ?: $byBarcode ?: $byName, 'conflict' => false];
}

function import_products_record_stock_movement(PDO $db, int $productId, int $before, int $after, string $note, ?int $branchId = null): void
{
    if ($before === $after) return;
    $type = $after > $before ? 'stock_in' : 'stock_out';
    $qty = abs($after - $before);
    try {
        $hasBranch = function_exists('branch_column_exists') && branch_column_exists($db, 'stock_movements', 'branch_id');
        if ($hasBranch && $branchId) {
            $stmt = $db->prepare('INSERT INTO stock_movements (product_id,user_id,branch_id,type,qty,stock_before,stock_after,note) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([$productId, Auth::id(), $branchId, $type, $qty, $before, $after, $note]);
        } else {
            $stmt = $db->prepare('INSERT INTO stock_movements (product_id,user_id,type,qty,stock_before,stock_after,note) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$productId, Auth::id(), $type, $qty, $before, $after, $note]);
        }
    } catch (Throwable $e) {
        // Riwayat stok tidak boleh membatalkan import utama.
    }
}

function import_products_sync_branch_stock(PDO $db, int $branchId, int $productId, int $stock, int $minimumStock): void
{
    try {
        $stmt = $db->prepare('INSERT IGNORE INTO product_branch_stocks (branch_id,product_id,stock,minimum_stock,created_at) VALUES (?,?,?,?,NOW())');
        $stmt->execute([$branchId, $productId, $stock, $minimumStock]);
        $stmt = $db->prepare('UPDATE product_branch_stocks SET stock=?, minimum_stock=?, updated_at=NOW() WHERE branch_id=? AND product_id=?');
        $stmt->execute([$stock, $minimumStock, $branchId, $productId]);
    } catch (Throwable $e) {
        // Tetap kompatibel bila patch multi cabang belum dipasang.
    }
}

function import_products_find_brand(PDO $db, ?string $name): int
{
    $name=trim((string)$name); if($name==='') return 0;
    if(in_array(mb_strtolower($name),['tanpa brand','tanpa merek','no brand','none','-'],true)) return 0;
    try{
        $s=$db->prepare('SELECT id FROM marketplace_brands WHERE LOWER(TRIM(name))=LOWER(TRIM(?)) LIMIT 1');$s->execute([$name]);$id=(int)$s->fetchColumn();if($id>0)return $id;
        $base=slugify($name)?:'brand';$slug=$base;$i=2;
        while(true){$c=$db->prepare('SELECT COUNT(*) FROM marketplace_brands WHERE slug=?');$c->execute([$slug]);if((int)$c->fetchColumn()===0)break;$slug=$base.'-'.$i++;}
        $db->prepare("INSERT INTO marketplace_brands(name,slug,is_active,sort_order,created_at) VALUES(?,?,1,0,NOW())")->execute([$name,$slug]);return (int)$db->lastInsertId();
    }catch(Throwable $e){return 0;}
}

function import_products_upsert_variant(PDO $db,int $productId,int $branchId,array $row): int
{
    $name=trim((string)($row['variant']??''));if($name==='')return 0;
    $sku=trim((string)($row['variant_sku']??''))?:null;$barcode=trim((string)($row['variant_barcode']??''))?:null;
    $id=0;
    // Saat file Excel di-upload ulang, SKU/Barcode varian menjadi identitas paling stabil.
    // Ini membuat nama varian boleh diedit tanpa menghasilkan varian duplikat.
    if($sku){$s=$db->prepare('SELECT id FROM marketplace_product_variants WHERE product_id=? AND sku=? ORDER BY id ASC LIMIT 1');$s->execute([$productId,$sku]);$id=(int)$s->fetchColumn();}
    if($id<=0 && $barcode){$s=$db->prepare('SELECT id FROM marketplace_product_variants WHERE product_id=? AND barcode=? ORDER BY id ASC LIMIT 1');$s->execute([$productId,$barcode]);$id=(int)$s->fetchColumn();}
    if($id<=0){$s=$db->prepare('SELECT id FROM marketplace_product_variants WHERE product_id=? AND LOWER(TRIM(name))=LOWER(TRIM(?)) ORDER BY id ASC LIMIT 1');$s->execute([$productId,$name]);$id=(int)$s->fetchColumn();}
    if($id>0){$db->prepare('UPDATE marketplace_product_variants SET name=?,sku=?,barcode=?,selling_price=?,purchase_price=?,is_active=?,updated_at=NOW() WHERE id=?')->execute([$name,$sku,$barcode,max(0,(float)$row['selling_price']),max(0,(float)$row['purchase_price']),(int)$row['is_active'],$id]);}
    else{$db->prepare('INSERT INTO marketplace_product_variants(product_id,name,sku,barcode,selling_price,purchase_price,weight_gram,is_active,sort_order,created_at) VALUES(?,?,?,?,?,?,100,?,0,NOW())')->execute([$productId,$name,$sku,$barcode,max(0,(float)$row['selling_price']),max(0,(float)$row['purchase_price']),(int)$row['is_active']]);$id=(int)$db->lastInsertId();}
    marketplace_v16_set_variant_stock($db,$id,$branchId,max(0,(int)$row['stock']),max(0,(int)$row['minimum_stock']));
    marketplace_v16_sync_parent_stock($db,$productId,$branchId);
    return $id;
}
