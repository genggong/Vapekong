<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../prices/_helpers.php';
Auth::requireLogin();
$pageTitle = 'Tambah Pelanggan';
$db = Database::connect();
price_install($db);

$customer = [
    'name' => '',
    'phone' => '',
    'email' => '',
    'address' => '',
    'member_code' => 'MBR' . date('ymdHis'),
    'member_level' => 'regular',
    'price_level' => 'retail',
    'points' => 0,
    'credit_limit' => 0,
    'note' => '',
    'status' => 'active',
];

$id = (int)($_GET['id'] ?? 0);


if (is_post()) {
    if (!CSRF::verify()) {
        flash('error', 'Token keamanan tidak valid.');
        legacy_redirect('admin/customers/create.php');
    }

    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $memberCode = trim($_POST['member_code'] ?? '');
    $memberLevel = $_POST['member_level'] ?? 'regular';
    $priceLevel = trim($_POST['price_level'] ?? 'retail');
    if (!array_key_exists($priceLevel, price_levels())) {
        $priceLevel = 'retail';
    }
    $points = (int)($_POST['points'] ?? 0);
    $creditLimit = (float)($_POST['credit_limit'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    $status = $_POST['status'] ?? 'active';

    if ($name === '') {
        flash('error', 'Nama pelanggan wajib diisi.');
        legacy_redirect('admin/customers/create.php');
    }

    try {

        $stmt = $db->prepare("INSERT INTO customers (name, phone, email, address, member_code, member_level, price_level, points, credit_limit, note, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $phone ?: null, $email ?: null, $address ?: null, $memberCode ?: null, $memberLevel, $priceLevel, $points, $creditLimit, $note ?: null, $status]);
        Auth::log('create_customer', 'Tambah pelanggan ' . $name);
        flash('success', 'Pelanggan berhasil ditambahkan.');

        legacy_redirect('admin/customers/index.php');
    } catch (Throwable $e) {
        flash('error', 'Gagal menyimpan pelanggan: ' . $e->getMessage());
        legacy_redirect('admin/customers/create.php');
    }
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/customers.css?v=' . time()); ?>">

<main class="admin-main customer-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Tambah Pelanggan</h1>
            <p>Lengkapi data pelanggan untuk member, poin, dan piutang.</p>
        </div>
    </header>

    <form method="POST" class="customer-form-card">
        <?= CSRF::input(); ?>
        <div class="customer-form-grid">
            <div class="customer-field"><label>Nama Pelanggan</label><input name="name" value="<?= e($customer['name']); ?>" required></div>
            <div class="customer-field"><label>No HP</label><input name="phone" value="<?= e($customer['phone']); ?>" placeholder="08xxxxxxxxxx"></div>
            <div class="customer-field"><label>Email</label><input name="email" type="email" value="<?= e($customer['email']); ?>"></div>
            <div class="customer-field"><label>Kode Member</label><input name="member_code" value="<?= e($customer['member_code']); ?>"></div>
            <div class="customer-field">
                <label>Level Member</label>
                <select name="member_level">
                    <option value="regular" <?= $customer['member_level'] === 'regular' ? 'selected' : ''; ?>>Regular</option>
                    <option value="silver" <?= $customer['member_level'] === 'silver' ? 'selected' : ''; ?>>Silver</option>
                    <option value="gold" <?= $customer['member_level'] === 'gold' ? 'selected' : ''; ?>>Gold</option>
                    <option value="platinum" <?= $customer['member_level'] === 'platinum' ? 'selected' : ''; ?>>Platinum</option>
                </select>
            </div>
            <div class="customer-field">
                <label>Level Harga</label>
                <select name="price_level">
                    <?php foreach (price_levels() as $levelCode => $levelLabel): ?>
                        <option value="<?= e($levelCode); ?>" <?= $customer['price_level'] === $levelCode ? 'selected' : ''; ?>><?= e($levelLabel); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="customer-field"><label>Poin</label><input name="points" type="number" value="<?= e($customer['points']); ?>"></div>
            <div class="customer-field"><label>Limit Piutang</label><input name="credit_limit" type="number" value="<?= e($customer['credit_limit']); ?>"></div>
            <div class="customer-field">
                <label>Status</label>
                <select name="status">
                    <option value="active" <?= $customer['status'] === 'active' ? 'selected' : ''; ?>>Aktif</option>
                    <option value="inactive" <?= $customer['status'] === 'inactive' ? 'selected' : ''; ?>>Nonaktif</option>
                </select>
            </div>
            <div class="customer-field full"><label>Alamat</label><textarea name="address"><?= e($customer['address']); ?></textarea></div>
            <div class="customer-field full"><label>Catatan</label><textarea name="note"><?= e($customer['note']); ?></textarea></div>
        </div>

        <div class="customer-form-actions">
            <a class="customer-btn soft" href="<?= legacy_base_url('admin/customers/index.php'); ?>">Batal</a>
            <button class="customer-btn primary" type="submit"><i class="bi bi-save"></i> Simpan</button>
        </div>
    </form>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
