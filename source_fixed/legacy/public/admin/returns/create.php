<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireLogin();
$pageTitle = 'Buat Retur';
$db = Database::connect();
sales_returns_install($db);

$trx = null;
$items = [];
$invoice = trim($_GET['invoice'] ?? '');
$transactionId = (int)($_GET['transaction_id'] ?? ($_POST['transaction_id'] ?? 0));

if ($transactionId > 0) {
    $trx = sales_returns_fetch_transaction($db, $transactionId);
} elseif ($invoice !== '') {
    $trx = sales_returns_fetch_transaction_by_invoice($db, $invoice);
}

if ($trx) {
    $items = sales_returns_fetch_items_with_available($db, (int)$trx['id']);
}

if (is_post()) {
    if (!CSRF::verify()) {
        flash('error', 'Token keamanan tidak valid.');
        legacy_redirect('admin/returns/create.php');
    }

    $transactionId = (int)($_POST['transaction_id'] ?? 0);
    $returnType = $_POST['type'] ?? 'refund';
    $reason = trim($_POST['reason'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $qtyPost = $_POST['return_qty'] ?? [];
    $restockPost = $_POST['restock'] ?? [];

    $trx = sales_returns_fetch_transaction($db, $transactionId);
    if (!$trx) {
        flash('error', 'Transaksi tidak ditemukan.');
        legacy_redirect('admin/returns/create.php');
    }

    $items = sales_returns_fetch_items_with_available($db, $transactionId);
    $selected = [];

    foreach ($items as $item) {
        $itemId = (int)$item['id'];
        $qty = max(0, (int)($qtyPost[$itemId] ?? 0));
        $available = (int)$item['available_qty'];

        if ($qty <= 0) {
            continue;
        }

        if ($qty > $available) {
            flash('error', 'Qty retur untuk produk ' . $item['product_name'] . ' melebihi sisa yang bisa diretur.');
            legacy_redirect('admin/returns/create.php?transaction_id=' . $transactionId);
        }

        $selected[] = [
            'item' => $item,
            'qty' => $qty,
            'restock' => isset($restockPost[$itemId]) ? 1 : 0,
            'subtotal' => $qty * (float)$item['unit_price'],
        ];
    }

    if (!$selected) {
        flash('error', 'Minimal pilih 1 barang dan isi qty retur.');
        legacy_redirect('admin/returns/create.php?transaction_id=' . $transactionId);
    }

    try {
        $db->beginTransaction();

        $returnNumber = sales_returns_next_number($db);
        $totalRefund = array_sum(array_map(fn($row) => (float)$row['subtotal'], $selected));
        $returnDate = now_datetime();

        $stmt = $db->prepare("INSERT INTO sales_returns (return_number, transaction_id, customer_id, user_id, type, status, total_refund, reason, note, return_date, created_at)
            VALUES (?, ?, ?, ?, ?, 'completed', ?, ?, ?, ?, ?)");
        $stmt->execute([
            $returnNumber,
            $transactionId,
            $trx['customer_id'] ?: null,
            Auth::id(),
            in_array($returnType, ['refund','exchange','store_credit'], true) ? $returnType : 'refund',
            $totalRefund,
            $reason ?: null,
            $note ?: null,
            $returnDate,
            $returnDate,
        ]);
        $returnId = (int)$db->lastInsertId();

        foreach ($selected as $row) {
            $item = $row['item'];
            $qty = (int)$row['qty'];
            $restock = (int)$row['restock'];
            $subtotal = (float)$row['subtotal'];

            $stmt = $db->prepare("INSERT INTO sales_return_items (return_id, transaction_item_id, product_id, product_name, qty, unit_price, subtotal, restock, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $returnId,
                $item['id'],
                $item['product_id'] ?: null,
                $item['product_name'],
                $qty,
                $item['unit_price'],
                $subtotal,
                $restock,
                $returnDate,
            ]);

            if ($restock === 1 && !empty($item['product_id'])) {
                $stmt = $db->prepare("SELECT stock FROM products WHERE id = ? FOR UPDATE");
                $stmt->execute([(int)$item['product_id']]);
                $stockBefore = (int)($stmt->fetchColumn() ?: 0);
                $stockAfter = $stockBefore + $qty;

                $up = $db->prepare("UPDATE products SET stock = ? WHERE id = ?");
                $up->execute([$stockAfter, (int)$item['product_id']]);

                $sm = $db->prepare("INSERT INTO stock_movements (product_id, user_id, type, qty, stock_before, stock_after, reference_type, reference_id, note, created_at)
                    VALUES (?, ?, 'return_in', ?, ?, ?, 'sales_return', ?, ?, ?)");
                $sm->execute([
                    (int)$item['product_id'],
                    Auth::id(),
                    $qty,
                    $stockBefore,
                    $stockAfter,
                    $returnId,
                    'Retur penjualan ' . $returnNumber,
                    $returnDate,
                ]);
            }
        }

        sales_returns_update_transaction_status($db, $transactionId);
        sales_returns_insert_finance_expense($db, $returnId, $returnNumber, $totalRefund);
        sales_returns_notify($db, 'Retur penjualan dibuat', 'Retur ' . $returnNumber . ' dari invoice ' . $trx['invoice_number'] . ' berhasil dibuat.');
        Auth::log('create_sales_return', 'Membuat retur ' . $returnNumber);

        $db->commit();
        flash('success', 'Retur berhasil dibuat.');
        legacy_redirect('admin/returns/detail.php?id=' . $returnId);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        flash('error', 'Gagal membuat retur: ' . $e->getMessage());
        legacy_redirect('admin/returns/create.php?transaction_id=' . $transactionId);
    }
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/returns.css?v=' . time()); ?>">

<main class="admin-main returns-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Buat Retur</h1>
            <p>Pilih invoice transaksi, tentukan produk yang diretur, lalu simpan bukti retur.</p>
        </div>
        <div class="topbar-actions">
            <a href="<?= legacy_base_url('admin/returns/index.php'); ?>" class="btn-soft"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>
    </header>

    <section class="returns-form-card">
        <div class="returns-card-head">
            <div>
                <h3>Cari Invoice</h3>
                <p>Masukkan nomor invoice transaksi yang ingin diretur.</p>
            </div>
        </div>

        <form method="GET" class="return-search-box">
            <div>
                <label>Nomor Invoice</label>
                <input type="text" name="invoice" value="<?= e($invoice ?: ($trx['invoice_number'] ?? '')); ?>" placeholder="Contoh: INV-20260607-0001">
            </div>
            <button class="return-btn primary" type="submit"><i class="bi bi-search"></i> Cari Invoice</button>
        </form>

        <?php if (!$trx && ($invoice !== '' || $transactionId > 0)): ?>
            <div class="return-alert"><i class="bi bi-exclamation-circle"></i> Invoice tidak ditemukan.</div>
        <?php endif; ?>

        <?php if ($trx): ?>
            <div class="return-selected-transaction">
                <div class="return-meta-box"><span>Invoice</span><b><?= e($trx['invoice_number']); ?></b></div>
                <div class="return-meta-box"><span>Tanggal</span><b><?= e(date('d/m/Y H:i', strtotime($trx['transaction_date'] ?? $trx['created_at']))); ?></b></div>
                <div class="return-meta-box"><span>Kasir</span><b><?= e($trx['cashier_name'] ?? '-'); ?></b></div>
                <div class="return-meta-box"><span>Total</span><b><?= rupiah($trx['grand_total'] ?? 0); ?></b></div>
            </div>

            <form method="POST">
                <?= CSRF::input(); ?>
                <input type="hidden" name="transaction_id" value="<?= e($trx['id']); ?>">

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label>Tipe Retur</label>
                        <select name="type">
                            <option value="refund">Refund uang</option>
                            <option value="exchange">Tukar barang</option>
                            <option value="store_credit">Saldo toko / store credit</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label>Alasan Retur</label>
                        <input type="text" name="reason" placeholder="Contoh: Barang rusak / salah beli / pelanggan batal" required>
                    </div>
                    <div class="col-12">
                        <label>Catatan Tambahan</label>
                        <textarea name="note" placeholder="Catatan internal opsional"></textarea>
                    </div>
                </div>

                <div class="returns-table-wrap">
                    <table class="returns-table">
                        <thead>
                            <tr>
                                <th>Produk</th>
                                <th>Terjual</th>
                                <th>Sudah Retur</th>
                                <th>Sisa Bisa Retur</th>
                                <th>Qty Retur</th>
                                <th>Harga</th>
                                <th>Restock</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><div class="return-product"><b><?= e($item['product_name']); ?></b><small>ID item #<?= e($item['id']); ?></small></div></td>
                                    <td><?= e($item['qty']); ?></td>
                                    <td><?= e($item['returned_qty']); ?></td>
                                    <td><b><?= e($item['available_qty']); ?></b></td>
                                    <td><input class="return-qty-input" type="number" name="return_qty[<?= e($item['id']); ?>]" min="0" max="<?= e($item['available_qty']); ?>" value="0" <?= (int)$item['available_qty'] <= 0 ? 'disabled' : ''; ?>></td>
                                    <td><?= rupiah($item['unit_price']); ?></td>
                                    <td>
                                        <label class="return-check-line">
                                            <input type="checkbox" name="restock[<?= e($item['id']); ?>]" value="1" checked <?= empty($item['product_id']) ? 'disabled' : ''; ?>> Masuk stok
                                        </label>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="return-alert"><i class="bi bi-info-circle-fill"></i> Jika opsi restock aktif, stok produk otomatis bertambah dan riwayat stok akan tercatat.</div>

                <div class="return-form-actions">
                    <a class="return-btn soft" href="<?= legacy_base_url('admin/returns/index.php'); ?>">Batal</a>
                    <button class="return-btn primary" type="submit"><i class="bi bi-save"></i> Simpan Retur</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
