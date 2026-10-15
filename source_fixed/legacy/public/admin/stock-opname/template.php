<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/DataExchange/Xlsx.php';
require_once __DIR__ . '/../../../app/Modules/Marketplace/Marketplace.php';
require_once __DIR__ . '/../../../app/Modules/CatalogFast.php';
require_once __DIR__ . '/../branches/_helpers.php';
Auth::requireRole(['super_admin','owner']);

$db=Database::connect();
marketplace_install($db);
branch_install($db);
$branchId=branch_current_id($db);
$branch=branch_current($db);

$stmt=$db->prepare("SELECT p.id,p.name,p.product_code,p.unit,p.stock,p.purchase_price,p.selling_price,c.name category_name,
    COALESCE(pbs.stock,p.stock,0) AS branch_stock
    FROM products p
    LEFT JOIN categories c ON c.id=p.category_id
    LEFT JOIN product_branch_stocks pbs ON pbs.product_id=p.id AND pbs.branch_id=?
    WHERE p.is_active=1
    ORDER BY p.name ASC,p.id ASC");
$stmt->execute([$branchId]);
$products=$stmt->fetchAll() ?: [];
$productIds=array_map(fn($row)=>(int)$row['id'],$products);
$variantsByProduct=catalog_fast_variants_by_product($db,$productIds,$branchId,true);
$brandNames=catalog_fast_brand_names($db,$productIds);

$data=[];
foreach($products as $p){
    $brand=(string)($brandNames[(int)$p['id']]??'');
    $vars=$variantsByProduct[(int)$p['id']]??[];
    if($vars){
        foreach($vars as $v){
            $stock=max(0,(int)($v['stock']??0));
            if($stock<=0) continue; // request: stok 0 tidak masuk template
            $label=trim((string)($v['display_name']??$v['name']??'Varian'));
            $purchase=(float)($v['purchase_price']??0)>0?(float)$v['purchase_price']:(float)$p['purchase_price'];
            $data[]=[
                'name'=>$p['name'].' - '.$label,'variant'=>$label,'brand'=>$brand,'category'=>$p['category_name']??'',
                'unit'=>$p['unit']?:'pcs','system_stock'=>$stock,'purchase_price'=>$purchase,
                'total_purchase_price'=>$stock*$purchase,'physical_stock'=>'','note'=>'','product_id'=>(int)$p['id'],'variant_id'=>(int)$v['id']
            ];
        }
    }else{
        $stock=max(0,(int)($p['branch_stock']??$p['stock']??0));
        if($stock<=0) continue; // request: stok 0 tidak masuk template
        $purchase=(float)$p['purchase_price'];
        $data[]=[
            'name'=>$p['name'],'variant'=>'','brand'=>$brand,'category'=>$p['category_name']??'','unit'=>$p['unit']?:'pcs',
            'system_stock'=>$stock,'purchase_price'=>$purchase,'total_purchase_price'=>$stock*$purchase,
            'physical_stock'=>'','note'=>'','product_id'=>(int)$p['id'],'variant_id'=>0
        ];
    }
}

$headers=['name','variant','brand','category','unit','system_stock','purchase_price','total_purchase_price','physical_stock','note','product_id','variant_id'];
$rows=[
    [[ 'value'=>'TEMPLATE STOCK OPNAME - '.($branch['name']??'Cabang'),'style'=>1,'height'=>28]],
    [[ 'value'=>'Hanya produk/varian dengan stok > 0. Harga beli dan total modal otomatis. Isi physical_stock; product_id/variant_id jangan diubah.','style'=>5]],
    array_map(fn($v)=>['value'=>$v,'style'=>2],$headers)
];
foreach($data as $r){
    $rows[]=[
        ['value'=>$r['name'],'style'=>5],['value'=>$r['variant'],'style'=>5],['value'=>$r['brand'],'style'=>5],['value'=>$r['category'],'style'=>5],['value'=>$r['unit'],'style'=>10],
        ['value'=>$r['system_stock'],'type'=>'number','style'=>4],['value'=>$r['purchase_price'],'type'=>'number','style'=>4],['value'=>$r['total_purchase_price'],'type'=>'number','style'=>4],
        ['value'=>'','style'=>4],['value'=>'','style'=>5],['value'=>$r['product_id'],'type'=>'number','style'=>4],['value'=>$r['variant_id'],'type'=>'number','style'=>4]
    ];
}
Auth::log('download_stock_opname_template','Download template stock opname stok>0 + harga beli: '.count($data).' baris');
KpXlsx::download('template-stock-opname-'.date('Ymd-His').'.xlsx',[[
    'name'=>'Stock Opname','rows'=>$rows,'widths'=>[48,22,20,22,10,15,18,20,16,34,12,12],
    'freeze_rows'=>3,'auto_filter'=>true,'filter_row'=>3,'merges'=>['A1:L1','A2:L2']
]]);
