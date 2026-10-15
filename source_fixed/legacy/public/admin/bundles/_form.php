<?php
// expects $bundle, $items, $products, $isEdit
?>
<form method="POST" enctype="multipart/form-data" class="system-form-card">
    <?= CSRF::input(); ?>
    <?php if($isEdit): ?><input type="hidden" name="id" value="<?= e($bundle['id']); ?>"><?php endif; ?>
    <div class="bundle-form-grid">
        <div class="system-field">
            <label>Kode Paket</label>
            <input name="code" value="<?= e($bundle['code'] ?? bundle_next_code($db)); ?>" placeholder="PKT-001">
        </div>
        <div class="system-field">
            <label>Nama Paket</label>
            <input name="name" required value="<?= e($bundle['name'] ?? ''); ?>" placeholder="Paket Hemat A">
        </div>
        <div class="system-field">
            <label>Harga Jual Paket</label>
            <input type="number" min="0" name="selling_price" required value="<?= e((float)($bundle['selling_price'] ?? 0)); ?>">
        </div>
        <div class="system-field">
            <label>Status</label>
            <select name="is_active">
                <option value="1" <?= (int)($bundle['is_active'] ?? 1) === 1 ? 'selected' : ''; ?>>Aktif</option>
                <option value="0" <?= (int)($bundle['is_active'] ?? 1) === 0 ? 'selected' : ''; ?>>Nonaktif</option>
            </select>
        </div>
        <div class="system-field">
            <label>Gambar Paket</label>
            <input type="file" name="image" accept="image/*">
            <small>Opsional. Jika kosong, gambar lama tetap dipakai.</small>
        </div>
        <div class="system-field">
            <label>Preview</label>
            <div class="bundle-preview"><?php if(!empty($bundle['image']) && bundle_image_exists($bundle['image'])): ?><img loading="lazy" src="<?= bundle_asset_url($bundle['image']); ?>"><?php else: ?><i class="bi bi-box2-heart"></i><?php endif; ?></div>
        </div>
        <div class="system-field bundle-wide">
            <label>Catatan Paket</label>
            <textarea name="note" rows="3" placeholder="Contoh: Paket promo weekend"><?= e($bundle['note'] ?? ''); ?></textarea>
        </div>
        <div class="system-field bundle-wide">
            <label>Isi Paket</label>
            <div class="bundle-components" id="bundleComponents">
                <?php
                $formItems = $items ?: [['product_id'=>'','qty'=>1]];
                foreach($formItems as $it):
                ?>
                    <div class="bundle-component-row">
                        <select name="product_id[]" required>
                            <option value="">Pilih produk</option>
                            <?php foreach($products as $p): ?>
                                <option value="<?= e($p['id']); ?>" <?= (int)($it['product_id'] ?? 0)===(int)$p['id']?'selected':''; ?>><?= e($p['name']); ?> — <?= e($p['product_code']); ?> | Modal <?= rupiah($p['purchase_price']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" min="1" name="qty[]" value="<?= e((int)($it['qty'] ?? 1)); ?>" required>
                        <button type="button" class="bundle-remove">×</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="system-btn soft" id="addBundleItem"><i class="bi bi-plus-circle"></i> Tambah Produk Isi Paket</button>
        </div>
    </div>
    <div class="system-form-actions">
        <a href="<?= legacy_base_url('admin/bundles/index.php'); ?>" class="system-btn soft">Batal</a>
        <button class="system-btn primary"><i class="bi bi-save"></i> Simpan Paket</button>
    </div>
</form>
<script>
document.getElementById('addBundleItem')?.addEventListener('click', function(){
    const wrap = document.getElementById('bundleComponents');
    const first = wrap.querySelector('.bundle-component-row');
    const clone = first.cloneNode(true);
    clone.querySelector('select').value = '';
    clone.querySelector('input').value = '1';
    wrap.appendChild(clone);
});
document.addEventListener('click', function(e){
    if(!e.target.classList.contains('bundle-remove')) return;
    const rows = document.querySelectorAll('.bundle-component-row');
    if(rows.length <= 1) return;
    e.target.closest('.bundle-component-row').remove();
});
</script>