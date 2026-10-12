<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireRole(['super_admin','owner']);
$pageTitle = 'Buat Stock Opname';
$db = Database::connect();
opname_ensure_tables($db);

if (is_post()) {
    if (!CSRF::verify()) { flash('error','Token keamanan tidak valid.'); legacy_redirect('admin/stock-opname/create.php'); }
    $title = trim($_POST['title'] ?? '');
    $note = trim($_POST['note'] ?? '');
    if ($title === '') { flash('error','Nama sesi opname wajib diisi.'); legacy_redirect('admin/stock-opname/create.php'); }
    try {
        $db->beginTransaction();
        $code = opname_code($db);
        $branchId=branch_current_id($db);
        $stmt = $db->prepare("INSERT INTO stock_opnames (code, title, user_id, branch_id, status, note, created_at) VALUES (?, ?, ?, ?, 'draft', ?, NOW())");
        $stmt->execute([$code, $title, Auth::id(), $branchId, $note]);
        $id = (int) $db->lastInsertId();
        opname_seed_items($db, $id);
        Auth::log('create_stock_opname', 'Membuat stock opname ' . $code);
        $db->commit();
        flash('success','Sesi stock opname dibuat. Silakan input stok fisik.');
        legacy_redirect('admin/stock-opname/edit.php?id=' . $id);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error','Gagal membuat opname: ' . $e->getMessage());
        legacy_redirect('admin/stock-opname/create.php');
    }
}
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/stock-opname.css?v=' . time()); ?>">
<main class="admin-main stock-opname-page">
    <header class="admin-topbar page-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Buat Stock Opname</h1><p>Buat sesi audit stok. Data produk aktif akan disalin ke draft opname.</p></div></header>
    <section class="opname-card">
        <form method="POST"><?= CSRF::input(); ?>
            <div class="opname-form-grid">
                <div class="opname-field opname-wide"><label>Nama Sesi</label><input class="opname-input" name="title" required placeholder="Contoh: Opname Bulanan Juni 2026"></div>
                <div class="opname-field opname-wide"><label>Catatan</label><textarea class="opname-textarea" name="note" rows="4" placeholder="Catatan opsional"></textarea></div>
            </div>
            <div class="opname-bottom-actions"><a href="<?= legacy_base_url('admin/stock-opname/index.php'); ?>" class="opname-btn soft">Batal</a><button class="opname-btn primary"><i class="bi bi-plus-circle"></i> Buat Sesi</button></div>
        </form>
    </section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
