<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/Marketplace/Marketplace.php';
require_once __DIR__ . '/../branches/_helpers.php';
Auth::requireRole(['super_admin','owner']);
$pageTitle = 'Produk';
$db = Database::connect();
marketplace_install($db);
branch_install($db);
$currentBranch = branch_current($db);
$currentBranchId = (int)$currentBranch['id'];
$sql = "SELECT p.*, c.name AS category_name, s.name AS supplier_name,COALESCE(vx.variant_count,0) AS variant_count,COALESCE(vx.variant_names,'') AS variant_names FROM products p LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN suppliers s ON s.id=p.supplier_id LEFT JOIN (SELECT product_id,COUNT(*) AS variant_count,GROUP_CONCAT(name ORDER BY sort_order ASC,name ASC SEPARATOR ', ') AS variant_names FROM marketplace_product_variants WHERE is_active=1 GROUP BY product_id) vx ON vx.product_id=p.id ORDER BY p.id DESC";
$products = $db->query($sql)->fetchAll();
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link href="<?= legacy_base_url('assets/css/product-barcode.css?v=' . time()); ?>" rel="stylesheet">
<style>
.product-bulk-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:0 0 14px;padding:12px 14px;border:1px solid #fecaca;background:#fffafa;border-radius:17px}.product-bulk-info{display:flex;align-items:center;gap:10px;color:#7f1d1d;font-weight:800}.product-bulk-info i{font-size:20px}.product-bulk-info b{color:#991b1b}.product-bulk-delete-btn{border:0;border-radius:14px;padding:10px 14px;background:linear-gradient(135deg,#ef4444,#991b1b);color:#fff;font-weight:900;display:inline-flex;align-items:center;gap:8px;box-shadow:0 10px 25px rgba(153,27,27,.16)}.product-bulk-delete-btn:disabled{opacity:.45;cursor:not-allowed;box-shadow:none}.product-check-wrap{display:grid;place-items:center;margin:0}.product-check{width:19px;height:19px;accent-color:#991b1b;cursor:pointer}.product-inline-delete{display:inline-flex;margin:0}.product-inline-delete button{border:0}.product-quick-edit{display:inline-flex;align-items:center;gap:5px;margin-top:5px;color:#7e22ce!important;font-size:11px;font-weight:900;text-decoration:none!important}.product-quick-edit:hover{text-decoration:underline!important}
.product-row-edit-actions{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-top:8px}.product-edit-link,.product-quick-edit-trigger{min-height:34px;border-radius:11px;padding:7px 10px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:6px!important;font-size:11px!important;font-weight:950!important;text-decoration:none!important;line-height:1.1!important;cursor:pointer}.product-edit-link{background:#f3e8ff!important;color:#7e22ce!important;border:1px solid #e9d5ff!important}.product-quick-edit-trigger{background:#fff1f2!important;color:#991b1b!important;border:1px solid #fecdd3!important;box-shadow:none!important}.product-edit-link:hover,.product-quick-edit-trigger:hover{transform:translateY(-1px)}
.product-quick-modal[hidden]{display:none!important}.product-quick-modal{position:fixed;inset:0;z-index:99999;display:grid;place-items:center;padding:18px;background:rgba(15,23,42,.58);backdrop-filter:blur(7px)}.product-quick-dialog{width:min(520px,100%);background:#fff;border:1px solid #fbcfe8;border-radius:26px;padding:20px;box-shadow:0 28px 80px rgba(76,29,149,.25)}.product-quick-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:16px}.product-quick-head h3{margin:0;color:#581c87;font-size:22px;font-weight:950}.product-quick-head p{margin:5px 0 0;color:#64748b;font-size:13px;line-height:1.45}.product-quick-close{width:42px;height:42px;min-width:42px;border:0;border-radius:14px;background:#fff1f2;color:#991b1b;font-size:20px;display:grid;place-items:center}.product-quick-fields{display:grid;gap:13px}.product-quick-field label{display:block;margin:0 0 7px;color:#7f1d1d;font-size:13px;font-weight:950}.product-quick-field input{width:100%;min-height:52px;border:1px solid #fecaca;border-radius:16px;padding:12px 14px;background:#fff;color:#0f172a;font-weight:850;outline:none}.product-quick-field input:focus{border-color:#c084fc;box-shadow:0 0 0 4px rgba(192,132,252,.16)}.product-quick-note{padding:11px 12px;border-radius:14px;background:#faf5ff;color:#6b21a8;font-size:12px;font-weight:800;line-height:1.45}.product-quick-actions{display:grid;grid-template-columns:1fr 1.4fr;gap:10px;margin-top:16px}.product-quick-actions button{min-height:48px;border-radius:15px;font-weight:950}.product-quick-cancel{border:1px solid #e2e8f0;background:#fff;color:#475569}.product-quick-save{border:0;background:linear-gradient(135deg,#9333ea,#6b21a8);color:#fff;box-shadow:0 12px 28px rgba(126,34,206,.22)}

@media(max-width:720px){.product-bulk-bar{align-items:stretch}.product-bulk-info{width:100%}.product-bulk-delete-btn{width:100%;justify-content:center}.product-row-edit-actions{display:grid;grid-template-columns:1fr 1fr;width:100%;gap:7px}.product-edit-link,.product-quick-edit-trigger{min-height:40px!important;font-size:12px!important}.product-quick-modal{place-items:end center;padding:0}.product-quick-dialog{border-radius:26px 26px 0 0;padding:20px 18px calc(110px + env(safe-area-inset-bottom));max-height:88vh;overflow:auto}.product-quick-actions{position:sticky;bottom:0;background:#fff;padding-top:10px}}
</style>
<main class="admin-main">
  <header class="admin-topbar">
    <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
    <div class="topbar-title"><h1>Produk</h1><p>Kelola produk, harga beli, harga jual, stok, gambar, barcode, dan status.</p></div>
    <div class="topbar-actions">
      <a href="<?= legacy_base_url('admin/import-products/template.php?format=xlsx'); ?>" class="btn-soft"><i class="bi bi-file-earmark-spreadsheet"></i> Template Excel</a>
      <a href="<?= legacy_base_url('admin/import-products/index.php'); ?>" class="btn-soft"><i class="bi bi-cloud-arrow-up"></i> Upload / Apply Excel</a>
      <a href="<?= legacy_base_url('admin/data-exchange/products-export.php?format=xlsx'); ?>" class="btn-soft"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
      <a href="<?= legacy_base_url('admin/data-exchange/products-export.php?format=pdf'); ?>" class="btn-soft"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
      <a href="<?= legacy_base_url('admin/products/create.php'); ?>" class="btn-premium"><i class="bi bi-plus-circle"></i> Tambah Produk</a>
    </div>
  </header>

  <section class="premium-card mt-4">
    <div class="card-head">
      <div>
        <h3>Data Produk</h3>
        <p>Produk tampil dengan search, pagination, sorting, barcode, label, dan hapus beberapa produk sekaligus.</p>
      </div>
      <span class="badge-soft danger"><?= count($products); ?> Produk</span>
    </div>

    <div class="table-toolbar-note">
      <div class="dt-hint-card">
        <div class="dt-hint-icon"><i class="bi bi-search-heart"></i></div>
        <div class="dt-hint-content">
          <span class="dt-hint-title">Pencarian Produk</span>
          <p class="dt-hint-text">Cari berdasarkan nama, kode, barcode, kategori, supplier, harga, stok, atau status. Centang beberapa baris untuk hapus massal.</p>
        </div>
      </div>
    </div>

    <form method="POST" action="<?= legacy_base_url('admin/products/bulk-delete.php'); ?>" id="productBulkDeleteForm">
      <?= CSRF::input(); ?>
      <div id="productBulkIds"></div>
      <div class="product-bulk-bar">
        <div class="product-bulk-info"><i class="bi bi-check2-square"></i><span><b id="productSelectedCount">0</b> produk dipilih</span></div>
        <button type="button" class="product-bulk-delete-btn" id="productBulkDeleteBtn" disabled><i class="bi bi-trash3"></i> Hapus Produk Terpilih</button>
      </div>
    </form>

    <div class="table-premium-card dt-premium-wrap">
      <table class="premium-table js-data-table nowrap product-table" data-title="produk" data-table-width="1460">
        <thead>
          <tr>
            <th class="no-sort" style="width:52px"><label class="product-check-wrap" title="Pilih semua pada halaman ini"><input type="checkbox" class="product-check product-select-all" aria-label="Pilih semua produk"></label></th>
            <th>Produk</th>
            <th>Kode / Barcode</th>
            <th>Kategori</th>
            <th>Varian</th>
            <th>Supplier</th>
            <th>Harga Beli</th>
            <th>Harga Jual</th>
            <th>Stok</th>
            <th>Status</th>
            <th class="text-end no-sort">Aksi</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($products as $p): ?>
          <tr data-product-id="<?= e($p['id']); ?>">
            <td><label class="product-check-wrap"><input type="checkbox" class="product-check product-select-check" value="<?= e($p['id']); ?>" aria-label="Pilih produk <?= e($p['name']); ?>"></label></td>
            <td class="product-name-cell">
              <div class="table-title">
                <span class="table-product-img">
                  <?php if($p['image']): ?><img loading="lazy" src="<?= legacy_base_url($p['image']); ?>" alt="produk"><?php else: ?><i class="bi bi-image"></i><?php endif; ?>
                </span>
                <div><b><?= e($p['name']); ?></b><small class="table-subtext"><?= e($p['unit']); ?> • Min stok <?= e($p['minimum_stock']); ?></small><div class="product-row-edit-actions"><a class="product-edit-link" href="<?= legacy_base_url('admin/products/edit.php?id='.$p['id']); ?>"><i class="bi bi-pencil-square"></i> Edit Produk</a><button type="button" class="product-quick-edit-trigger" data-product-id="<?= e($p['id']); ?>" data-product-name="<?= e($p['name']); ?>" data-product-price="<?= e((float)$p['selling_price']); ?>"><i class="bi bi-cash-coin"></i> Ganti Nama / Harga</button></div></div>
              </div>
            </td>
            <td><code><?= e($p['product_code']); ?></code><br><small class="table-subtext"><?= e($p['barcode'] ?: 'Tanpa barcode'); ?></small></td>
            <td><?= e($p['category_name'] ?: '-'); ?></td>
            <td><?php if((int)($p['variant_count']??0)>0): ?><span class="badge-soft success"><?= (int)$p['variant_count']; ?> varian</span><br><small class="table-subtext"><?= e($p['variant_names']??''); ?></small><?php else: ?><span class="table-subtext">Tanpa varian</span><?php endif; ?></td>
            <td><?= e($p['supplier_name'] ?: '-'); ?></td>
            <td><div class="price-mini"><small>Modal</small><strong><?= rupiah($p['purchase_price']); ?></strong></div></td>
            <td><div class="price-mini"><small>Jual</small><strong><?= rupiah($p['selling_price']); ?></strong></div></td>
            <td><span class="badge-stock <?= (int)$p['stock'] <= (int)$p['minimum_stock'] ? 'low' : ''; ?>"><i class="bi bi-box-seam"></i><?= e($p['stock']); ?> <?= e($p['unit']); ?></span></td>
            <td><?= $p['is_active'] ? '<span class="badge-soft success">Aktif</span>' : '<span class="badge-soft danger">Nonaktif</span>'; ?></td>
            <td class="text-end table-actions">
              <a class="btn-icon label-action-link" title="Cetak Label" target="_blank" href="<?= legacy_base_url('admin/products/label.php?id='.$p['id']); ?>"><i class="bi bi-upc-scan"></i></a>
              <a class="btn-icon edit" title="Edit Nama & Harga" aria-label="Edit nama dan harga produk" href="<?= legacy_base_url('admin/products/edit.php?id='.$p['id']); ?>"><i class="bi bi-pencil"></i></a>
              <button type="button" class="btn-icon edit product-quick-edit-trigger" title="Ganti Nama / Harga" aria-label="Ganti nama dan harga produk" data-product-id="<?= e($p['id']); ?>" data-product-name="<?= e($p['name']); ?>" data-product-price="<?= e((float)$p['selling_price']); ?>"><i class="bi bi-cash-coin"></i></button>
              <form method="POST" action="<?= legacy_base_url('admin/products/delete.php'); ?>" class="product-inline-delete" data-product-name="<?= e($p['name']); ?>">
                <?= CSRF::input(); ?>
                <input type="hidden" name="id" value="<?= e($p['id']); ?>">
                <button type="button" class="btn-icon delete product-single-delete-btn" title="Hapus"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>

<div class="product-quick-modal" id="productQuickEditModal" hidden aria-hidden="true">
  <div class="product-quick-dialog" role="dialog" aria-modal="true" aria-labelledby="productQuickEditTitle">
    <div class="product-quick-head">
      <div><h3 id="productQuickEditTitle">Ganti Nama & Harga</h3><p>Edit cepat langsung dari menu Produk tanpa perlu mencari kolom aksi di sisi kanan tabel.</p></div>
      <button type="button" class="product-quick-close" id="productQuickEditClose" aria-label="Tutup"><i class="bi bi-x-lg"></i></button>
    </div>
    <form method="POST" action="<?= legacy_base_url('admin/products/quick-edit.php'); ?>" id="productQuickEditForm">
      <?= CSRF::input(); ?>
      <input type="hidden" name="id" id="productQuickEditId" value="">
      <div class="product-quick-fields">
        <div class="product-quick-field"><label for="productQuickEditName">Nama Produk</label><input type="text" name="name" id="productQuickEditName" maxlength="255" required></div>
        <div class="product-quick-field"><label for="productQuickEditPrice">Harga Jual / Harga POS</label><input type="number" name="selling_price" id="productQuickEditPrice" min="0" step="1" inputmode="numeric" required></div>
        <div class="product-quick-note"><i class="bi bi-lightning-charge-fill"></i> Harga yang disimpan akan langsung disinkronkan ke harga Retail/Ecer agar POS, keranjang, dan nota transaksi baru memakai harga terbaru.</div>
      </div>
      <div class="product-quick-actions"><button type="button" class="product-quick-cancel" id="productQuickEditCancel">Batal</button><button type="submit" class="product-quick-save"><i class="bi bi-check2-circle"></i> Simpan Perubahan</button></div>
    </form>
  </div>
</div>
<script>
(function(){
  'use strict';
  var quickModal = document.getElementById('productQuickEditModal');
  var quickId = document.getElementById('productQuickEditId');
  var quickName = document.getElementById('productQuickEditName');
  var quickPrice = document.getElementById('productQuickEditPrice');
  var quickClose = document.getElementById('productQuickEditClose');
  var quickCancel = document.getElementById('productQuickEditCancel');
  function openQuickEdit(btn){
    if(!quickModal || !btn) return;
    quickId.value = btn.getAttribute('data-product-id') || '';
    quickName.value = btn.getAttribute('data-product-name') || '';
    quickPrice.value = btn.getAttribute('data-product-price') || '0';
    quickModal.hidden = false;
    quickModal.setAttribute('aria-hidden','false');
    document.documentElement.style.overflow = 'hidden';
    setTimeout(function(){ if(quickName) quickName.focus(); }, 40);
  }
  function closeQuickEdit(){
    if(!quickModal) return;
    quickModal.hidden = true;
    quickModal.setAttribute('aria-hidden','true');
    document.documentElement.style.overflow = '';
  }
  document.addEventListener('click', function(event){
    var quickBtn = event.target.closest('.product-quick-edit-trigger');
    if(quickBtn){ event.preventDefault(); event.stopPropagation(); openQuickEdit(quickBtn); return; }
    if(event.target === quickModal) closeQuickEdit();
  });
  if(quickClose) quickClose.addEventListener('click', closeQuickEdit);
  if(quickCancel) quickCancel.addEventListener('click', closeQuickEdit);
  document.addEventListener('keydown', function(event){ if(event.key === 'Escape' && quickModal && !quickModal.hidden) closeQuickEdit(); });

  var selected = new Set();
  var countEl = document.getElementById('productSelectedCount');
  var bulkBtn = document.getElementById('productBulkDeleteBtn');
  var bulkForm = document.getElementById('productBulkDeleteForm');
  var idsBox = document.getElementById('productBulkIds');

  function visibleChecks(){
    return Array.from(document.querySelectorAll('.product-select-check')).filter(function(cb){
      return cb.getClientRects().length > 0;
    });
  }

  function syncUi(){
    document.querySelectorAll('.product-select-check').forEach(function(cb){ cb.checked = selected.has(String(cb.value)); });
    var current = visibleChecks();
    var selectedVisible = current.filter(function(cb){ return selected.has(String(cb.value)); }).length;
    document.querySelectorAll('.product-select-all').forEach(function(master){
      master.checked = current.length > 0 && selectedVisible === current.length;
      master.indeterminate = selectedVisible > 0 && selectedVisible < current.length;
    });
    if(countEl) countEl.textContent = String(selected.size);
    if(bulkBtn) bulkBtn.disabled = selected.size === 0;
  }

  function esc(text){
    return String(text || '').replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});
  }

  document.addEventListener('change', function(event){
    if(event.target.matches('.product-select-check')){
      var id = String(event.target.value || '');
      if(!id) return;
      if(event.target.checked) selected.add(id); else selected.delete(id);
      syncUi();
      return;
    }
    if(event.target.matches('.product-select-all')){
      visibleChecks().forEach(function(cb){
        cb.checked = event.target.checked;
        var id = String(cb.value || '');
        if(event.target.checked) selected.add(id); else selected.delete(id);
      });
      syncUi();
    }
  });

  document.addEventListener('click', function(event){
    var singleBtn = event.target.closest('.product-single-delete-btn');
    if(singleBtn){
      event.preventDefault();
      var form = singleBtn.closest('.product-inline-delete');
      if(!form) return;
      var name = form.dataset.productName || 'produk ini';
      var doSubmit = function(){ form.submit(); };
      if(!window.Swal){ if(confirm('Hapus ' + name + '?')) doSubmit(); return; }
      Swal.fire({
        icon:'warning', title:'Hapus produk?',
        html:'<b>'+esc(name)+'</b><br><small style="display:block;margin-top:8px;color:#64748b">Produk yang sudah dipakai di transaksi/pembelian/retur/opname akan ditolak agar histori tetap aman.</small>',
        showCancelButton:true, confirmButtonText:'Ya, Hapus', cancelButtonText:'Batal', confirmButtonColor:'#dc2626', reverseButtons:true
      }).then(function(r){ if(r.isConfirmed) doSubmit(); });
      return;
    }

    if(event.target.closest('#productBulkDeleteBtn')){
      event.preventDefault();
      if(!selected.size || !bulkForm || !idsBox) return;
      var doBulk = function(){
        idsBox.innerHTML = '';
        selected.forEach(function(id){
          var input = document.createElement('input');
          input.type = 'hidden'; input.name = 'product_ids[]'; input.value = id;
          idsBox.appendChild(input);
        });
        bulkForm.submit();
      };
      if(!window.Swal){ if(confirm('Hapus ' + selected.size + ' produk terpilih?')) doBulk(); return; }
      Swal.fire({
        icon:'warning', title:'Hapus '+selected.size+' produk?',
        text:'Produk yang masih punya histori transaksi atau relasi penting akan dilewati dan tidak dihapus.',
        showCancelButton:true, confirmButtonText:'Hapus Terpilih', cancelButtonText:'Batal', confirmButtonColor:'#dc2626', reverseButtons:true
      }).then(function(r){ if(r.isConfirmed) doBulk(); });
    }
  });

  // DataTables dapat redraw saat search/pagination; sinkronkan kembali checkbox setelah draw.
  function bindDataTableDraw(){
    if(window.jQuery && jQuery.fn && jQuery.fn.dataTable){
      jQuery(document).on('draw.dt', function(){ setTimeout(syncUi, 0); });
    }
  }
  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function(){ syncUi(); setTimeout(bindDataTableDraw, 350); });
  else { syncUi(); setTimeout(bindDataTableDraw, 350); }
})();
</script>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
