<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../locations/_helpers.php';
Auth::requireRole(['super_admin','owner']);
$pageTitle = 'Input Stock Opname';
$db = Database::connect();
location_install($db);
opname_ensure_tables($db);
$id = (int) ($_GET['id'] ?? ($_POST['id'] ?? 0));
$opname = opname_get($db, $id);
if (!$opname) { flash('error','Sesi opname tidak ditemukan.'); legacy_redirect('admin/stock-opname/index.php'); }
if ($opname['status'] !== 'draft') { flash('error','Sesi opname sudah final/batal dan tidak bisa diedit.'); legacy_redirect('admin/stock-opname/detail.php?id=' . $id); }
opname_seed_items($db, $id);

if (is_post()) {
    if (!CSRF::verify()) { flash('error','Token keamanan tidak valid.'); legacy_redirect('admin/stock-opname/edit.php?id=' . $id); }
    $physical = $_POST['physical_stock'] ?? [];
    $reasons = $_POST['reason'] ?? [];
    $notes = $_POST['item_note'] ?? [];
    $title = trim($_POST['title'] ?? $opname['title']);
    $note = trim($_POST['note'] ?? $opname['note']);
    try {
        $db->beginTransaction();
        $db->prepare("UPDATE stock_opnames SET title=?, note=?, updated_at=NOW() WHERE id=? AND status='draft'")->execute([$title ?: $opname['title'], $note, $id]);
        $stmt = $db->prepare("SELECT id, system_stock, purchase_price FROM stock_opname_items WHERE opname_id=?");
        $stmt->execute([$id]);
        $items = $stmt->fetchAll();
        $upd = $db->prepare("UPDATE stock_opname_items SET physical_stock=?, difference=?, difference_value=?, reason=?, note=?, updated_at=NOW() WHERE id=? AND opname_id=?");
        foreach ($items as $item) {
            $itemId = (int) $item['id'];
            if (!array_key_exists($itemId, $physical) || trim((string)$physical[$itemId]) === '') {
                $upd->execute([null, 0, 0, null, trim($notes[$itemId] ?? ''), $itemId, $id]);
                continue;
            }
            $phys = max(0, (int) $physical[$itemId]);
            $diff = $phys - (int) $item['system_stock'];
            $reason = trim($reasons[$itemId] ?? '');
            $diffValue = abs($diff) * (float) ($item['purchase_price'] ?? 0);
            if ($diff === 0) $reason = 'match';
            $upd->execute([$phys, $diff, $diffValue, $reason ?: 'other', trim($notes[$itemId] ?? ''), $itemId, $id]);
        }
        opname_sync_totals($db, $id);
        Auth::log('update_stock_opname', 'Update draft stock opname ' . $opname['code']);
        $db->commit();
        flash('success','Draft opname berhasil disimpan.');
        legacy_redirect('admin/stock-opname/edit.php?id=' . $id);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error','Gagal menyimpan opname: ' . $e->getMessage());
        legacy_redirect('admin/stock-opname/edit.php?id=' . $id);
    }
}
$opname = opname_get($db, $id);
$items = opname_items($db, $id);
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/stock-opname.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/locations.css?v=' . time()); ?>">
<main class="admin-main stock-opname-page">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title"><h1>Input Stock Opname</h1><p><?= e($opname['code']); ?> • Isi stok fisik produk yang sudah dicek.</p></div>
        <div class="topbar-actions"><a href="<?= legacy_base_url('admin/stock-opname/detail.php?id=' . $id); ?>" class="opname-btn soft"><i class="bi bi-eye"></i> Detail</a></div>
    </header>
    <form method="POST" id="opnameForm"><?= CSRF::input(); ?><input type="hidden" name="id" value="<?= e($id); ?>">
        <section class="opname-card">
            <div class="opname-form-grid">
                <div class="opname-field"><label>Nama Sesi</label><input class="opname-input" name="title" value="<?= e($opname['title']); ?>" required></div>
                <div class="opname-field"><label>Status</label><div><?= opname_status_badge($opname['status']); ?></div></div>
                <div class="opname-field opname-wide"><label>Catatan Sesi</label><textarea class="opname-textarea" name="note" rows="3"><?= e($opname['note']); ?></textarea></div>
            </div>
        </section>
        <section class="opname-card">
            <div class="opname-card-head"><div><h3>Daftar Produk</h3><p>Kolom stok fisik boleh dikosongkan untuk produk yang belum dicek.</p></div><div class="opname-actions"><button type="button" class="opname-btn soft" id="fillSameStock"><i class="bi bi-check2-all"></i> Samakan Semua</button></div></div>
            <div class="opname-note"><i class="bi bi-info-circle-fill"></i><span>Finalisasi akan mengubah stok produk sesuai stok fisik dan mencatat pergerakan stok otomatis.</span></div>
            <div class="opname-table-wrap">
                <table class="opname-table">
                    <thead><tr><th>Produk</th><th>Stok Sistem</th><th>Stok Fisik</th><th>Selisih Qty</th><th>Nilai Selisih</th><th>Alasan</th><th>Catatan</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $it): $diff=(int)$it['difference']; ?>
                        <tr>
                            <td class="opname-name-cell"><div class="opname-product"><span class="opname-product-img"><?php if(!empty($it['image'])): ?><img loading="lazy" src="<?= legacy_base_url($it['image']); ?>" alt="produk"><?php else: ?><i class="bi bi-box-seam"></i><?php endif; ?></span><div><b><?= e($it['product_name']); ?></b><small><?= e($it['unit']); ?></small></div></div></td>
                            <td><b data-system-stock="<?= e($it['system_stock']); ?>"><?= e($it['system_stock']); ?></b> <?= e($it['unit']); ?><br><small>Modal: <?= rupiah($it['purchase_price'] ?? 0); ?></small></td>
                            <td><input class="opname-input physical-input" type="number" min="0" name="physical_stock[<?= e($it['id']); ?>]" value="<?= $it['physical_stock'] !== null ? e($it['physical_stock']) : ''; ?>" data-system="<?= e($it['system_stock']); ?>" data-price="<?= e((float)($it['purchase_price'] ?? 0)); ?>"></td>
                            <td class="diff-cell <?= $diff>0?'diff-plus':($diff<0?'diff-minus':'diff-zero'); ?>"><?= $it['physical_stock']===null ? '-' : e($diff); ?></td><td class="diff-value-cell <?= $diff>0?'diff-plus':($diff<0?'diff-minus':'diff-zero'); ?>"><?= $it['physical_stock']===null ? '-' : (($diff>0?'+':($diff<0?'-':'')) . rupiah(abs((float)($it['difference_value'] ?? 0)))); ?></td>
                            <td><select class="opname-select" name="reason[<?= e($it['id']); ?>]"><option value="">Pilih</option><?php foreach(['match'=>'Sesuai','missing'=>'Hilang','damaged'=>'Rusak','expired'=>'Expired','wrong_input'=>'Salah Input','found'=>'Stok Lebih','other'=>'Lainnya'] as $k=>$v): ?><option value="<?= e($k); ?>" <?= ($it['reason']===$k)?'selected':''; ?>><?= e($v); ?></option><?php endforeach; ?></select></td>
                            <td><input class="opname-input" name="item_note[<?= e($it['id']); ?>]" value="<?= e($it['note']); ?>" placeholder="Opsional"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="opname-bottom-actions">
                <a href="<?= legacy_base_url('admin/stock-opname/index.php'); ?>" class="opname-btn soft">Kembali</a>
                <button class="opname-btn primary"><i class="bi bi-save"></i> Simpan Draft</button>
                <button type="submit" formaction="<?= legacy_base_url('admin/stock-opname/finalize.php'); ?>" class="opname-btn success" onclick="return confirm('Finalisasi opname? Stok produk akan disesuaikan.');"><i class="bi bi-check-circle"></i> Finalisasi</button>
            </div>
        </section>
    </form>
</main>
<script>
document.addEventListener('input', function(e){
  if(!e.target.classList.contains('physical-input')) return;
  const tr=e.target.closest('tr'); const sys=Number(e.target.dataset.system||0); const price=Number(e.target.dataset.price||0); const val=e.target.value===''?null:Number(e.target.value||0); const cell=tr.querySelector('.diff-cell'); const valueCell=tr.querySelector('.diff-value-cell');
  if(val===null){cell.textContent='-'; cell.className='diff-cell diff-zero'; if(valueCell){valueCell.textContent='-'; valueCell.className='diff-value-cell diff-zero';} return;}
  const diff=val-sys; const cls=(diff>0?'diff-plus':(diff<0?'diff-minus':'diff-zero')); cell.textContent=diff; cell.className='diff-cell '+cls; if(valueCell){const valRp=Math.abs(diff*price); valueCell.textContent=(diff>0?'+':(diff<0?'-':'')) + new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(valRp); valueCell.className='diff-value-cell '+cls;}
  const sel=tr.querySelector('select'); if(diff===0) sel.value='match'; else if(diff>0 && !sel.value) sel.value='found'; else if(diff<0 && !sel.value) sel.value='missing';
});
document.getElementById('fillSameStock')?.addEventListener('click', function(){document.querySelectorAll('.physical-input').forEach(function(inp){inp.value=inp.dataset.system; inp.dispatchEvent(new Event('input',{bubbles:true}));});});
</script>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
