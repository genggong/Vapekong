<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/DataExchange/DataExchange.php';
Auth::requireRole(['super_admin', 'owner']);

$format = strtolower(trim($_GET['format'] ?? 'xlsx'));
$headers = kp_exchange_product_headers();
$labels = kp_exchange_product_label_row();

// Satu produk induk boleh memiliki beberapa baris varian. Kode produk induk sama agar upload ulang
// tetap meng-update produk POS yang sama, bukan membuat master baru.
$sample = [
    'Foom X Switch Prime','Foom','Pod System','White GT','FOOM-X-WHITE','',
    'FOOM-X-SWITCH','', 'Supplier Umum','pcs',260000,290000,2,1,'Rak A-01','aktif','Contoh produk varian White GT'
];
$sample2 = [
    'Foom X Switch Prime','Foom','Pod System','Beige','FOOM-X-BEIGE','',
    'FOOM-X-SWITCH','', 'Supplier Umum','pcs',260000,290000,2,1,'Rak A-01','aktif','Produk induk sama, varian Beige'
];
$sample3 = [
    'Kapas Vape Premium','Tanpa Brand','Accessories','','','',
    'KAPAS-PREMIUM','', 'Supplier Umum','pcs',10000,15000,12,3,'Rak B-02','aktif','Contoh produk tanpa varian'
];

if ($format === 'csv') {
    $filename='template-import-produk-brand-kategori-varian-'.date('Ymd').'.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    echo "\xEF\xBB\xBF";
    $out=fopen('php://output','w');
    fputcsv($out,$labels);
    fputcsv($out,$sample);
    fputcsv($out,$sample2);
    fputcsv($out,$sample3);
    fclose($out);
    exit;
}

$toRow=function(array $r):array{
    $out=[];
    foreach($r as $i=>$v){
        // Harga berada pada index 10/11, stok pada 12/13.
        $num=in_array($i,[10,11,12,13],true);
        $out[]=['value'=>$v,'type'=>$num?'number':'string','style'=>$num?($i<12?3:4):5];
    }
    return $out;
};
$dataRows=[array_map(static fn($v)=>['value'=>$v,'style'=>2],$labels),$toRow($sample),$toRow($sample2),$toRow($sample3)];
$instructions=[
 [['value'=>'PETUNJUK TEMPLATE PRODUK POS + BRAND + KATEGORI + VARIAN','style'=>1,'height'=>28],''],
 [['value'=>'1. Urutan utama: Nama Produk | Brand / Merek | Kategori | Varian. Produk tanpa varian boleh mengosongkan kolom Varian.','style'=>5],''],
 [['value'=>'2. Produk bervarian memakai Nama Produk dan Kode Produk induk yang sama pada setiap baris. Contoh White GT stok 2 dan Beige stok 2.','style'=>5],''],
 [['value'=>'3. Saat file di-upload ulang ke menu Import Produk, sistem meng-update produk POS yang sama dan menerapkan Brand, Kategori, Varian, Harga serta Stok.','style'=>5],''],
 [['value'=>'4. Brand / Merek boleh kosong atau diisi "Tanpa Brand". Brand baru akan dibuat otomatis. Kategori baru dapat dibuat otomatis saat proses import.','style'=>5],''],
 [['value'=>'5. SKU Varian dan Barcode Varian bersifat opsional tetapi sebaiknya unik agar scan dan pencarian varian lebih mudah.','style'=>5],''],
 [['value'=>'6. Kode Produk sangat disarankan tetap diisi dan jangan diubah saat ingin memperbarui produk lama.','style'=>5],''],
 [['value'=>'7. Stok pada baris varian adalah stok varian pada cabang POS aktif. Stok induk dihitung ulang dari total stok semua varian.','style'=>5],''],
 [['value'=>'8. Jangan mengubah nama header. Maksimal 1.000 baris sekali upload.','style'=>5],''],
];
KpXlsx::download('template-produk-pos-brand-kategori-varian-'.date('Ymd').'.xlsx',[
 ['name'=>'Data Produk','rows'=>$dataRows,'widths'=>[32,20,22,22,20,20,20,20,24,10,16,16,10,14,16,12,38],'freeze_rows'=>1,'auto_filter'=>true,'filter_row'=>1],
 ['name'=>'Petunjuk','rows'=>$instructions,'widths'=>[115,10],'merges'=>['A1:B1','A2:B2','A3:B3','A4:B4','A5:B5','A6:B6','A7:B7','A8:B8','A9:B9']]
]);
