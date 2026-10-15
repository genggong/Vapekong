<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../branches/_helpers.php';
Auth::requireRole(['super_admin', 'owner']);

if (!is_post()) {
    flash('error', 'Metode hapus transaksi tidak valid.');
    legacy_redirect('admin/transactions/index.php');
}

if (!CSRF::verify()) {
    flash('error', 'Token keamanan tidak valid. Silakan muat ulang halaman.');
    legacy_redirect('admin/transactions/index.php');
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'ID transaksi tidak valid.');
    legacy_redirect('admin/transactions/index.php');
}

$db = Database::connect();

function trx_delete_table_exists(PDO $db, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    $stmt = $db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
}

function trx_delete_column_exists(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
}

try {
    $db->beginTransaction();

    $stmt = $db->prepare('SELECT * FROM transactions WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$id]);
    $trx = $stmt->fetch();
    if (!$trx) {
        throw new Exception('Transaksi tidak ditemukan atau sudah dihapus.');
    }

    $invoice = (string)($trx['invoice_number'] ?? ('#' . $id));
    $branchId = (int)($trx['branch_id'] ?? 0);
    $paymentMethod = strtolower((string)($trx['payment_method'] ?? ''));
    $transactionStatus = strtolower((string)($trx['status'] ?? ''));

    // QRIS Pakasir mempunyai order di sistem eksternal. Jangan hard-delete selama
    // order masih pending/sukses karena pembayaran bisa masuk setelah data lokal hilang.
    if ($paymentMethod === 'qris_pakasir' && !in_array($transactionStatus, ['failed', 'cancelled', 'canceled', 'expired', 'refunded'], true)) {
        throw new Exception('Transaksi QRIS Otomatis Pakasir yang masih pending/sukses tidak dapat dihapus. Selesaikan pembatalan/refund pembayaran eksternal terlebih dahulu.');
    }

    // Retur mengubah stok/keuangan lagi. Jangan hard-delete transaksi yang sudah punya retur,
    // karena akan menghilangkan jejak audit dan berisiko menggandakan koreksi stok.
    if (trx_delete_table_exists($db, 'sales_returns')) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM sales_returns WHERE transaction_id = ?');
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new Exception('Transaksi sudah memiliki data retur. Batalkan/selesaikan retur terlebih dahulu sebelum menghapus transaksi.');
        }
    }

    // Piutang yang sudah menerima pembayaran tidak boleh dihapus, supaya histori uang masuk tidak hilang.
    if (trx_delete_table_exists($db, 'customer_debts')) {
        $stmt = $db->prepare('SELECT id FROM customer_debts WHERE transaction_id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $debtIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        if ($debtIds) {
            $placeholders = implode(',', array_fill(0, count($debtIds), '?'));

            if (trx_delete_table_exists($db, 'customer_debt_payments')) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM customer_debt_payments WHERE debt_id IN ($placeholders)");
                $stmt->execute($debtIds);
                if ((int)$stmt->fetchColumn() > 0) {
                    throw new Exception('Transaksi memiliki pembayaran piutang. Hapus transaksi diblokir agar histori pembayaran tidak hilang.');
                }
            }

            if (trx_delete_table_exists($db, 'customer_debt_qris')) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM customer_debt_qris WHERE debt_id IN ($placeholders) AND status IN ('paid','success','completed')");
                $stmt->execute($debtIds);
                if ((int)$stmt->fetchColumn() > 0) {
                    throw new Exception('Transaksi memiliki pembayaran QRIS piutang yang sudah berhasil. Hapus transaksi diblokir.');
                }
                $stmt = $db->prepare("DELETE FROM customer_debt_qris WHERE debt_id IN ($placeholders)");
                $stmt->execute($debtIds);
            }

            $stmt = $db->prepare("DELETE FROM customer_debts WHERE id IN ($placeholders)");
            $stmt->execute($debtIds);
        }
    }

    // Balikkan mutasi poin member yang dibuat oleh transaksi ini.
    if (trx_delete_table_exists($db, 'customer_points') && trx_delete_column_exists($db, 'customer_points', 'transaction_id')) {
        $stmt = $db->prepare("SELECT customer_id,
                SUM(CASE WHEN type='in' THEN points ELSE 0 END) AS points_in,
                SUM(CASE WHEN type='out' THEN points ELSE 0 END) AS points_out
            FROM customer_points
            WHERE transaction_id = ?
            GROUP BY customer_id");
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll() as $pointRow) {
            $customerId = (int)$pointRow['customer_id'];
            $delta = (int)$pointRow['points_out'] - (int)$pointRow['points_in'];
            if ($customerId > 0 && $delta !== 0 && trx_delete_table_exists($db, 'customers')) {
                $lock = $db->prepare('SELECT points FROM customers WHERE id = ? FOR UPDATE');
                $lock->execute([$customerId]);
                if ($lock->fetchColumn() !== false) {
                    $up = $db->prepare('UPDATE customers SET points = GREATEST(0, points + ?), updated_at = NOW() WHERE id = ?');
                    $up->execute([$delta, $customerId]);
                }
            }
        }
        $stmt = $db->prepare('DELETE FROM customer_points WHERE transaction_id = ?');
        $stmt->execute([$id]);
    }

    // Kembalikan stok berdasarkan stock_movements penjualan. Ini lebih aman daripada
    // transaction_items karena paket/bundle mengurangi stok komponen yang berbeda.
    if (trx_delete_table_exists($db, 'stock_movements')) {
        $hasBranchColumn = trx_delete_column_exists($db, 'stock_movements', 'branch_id');
        $branchSelect = $hasBranchColumn ? 'COALESCE(branch_id, ?) AS movement_branch_id,' : '? AS movement_branch_id,';
        $sql = "SELECT product_id, {$branchSelect} SUM(qty) AS restore_qty
                FROM stock_movements
                WHERE reference_type = 'transaction'
                  AND reference_id = ?
                  AND type IN ('sale','bundle_sale')
                GROUP BY product_id" . ($hasBranchColumn ? ', movement_branch_id' : '');
        $stmt = $db->prepare($sql);
        $stmt->execute([$branchId, $id]);
        $stockRows = $stmt->fetchAll();

        foreach ($stockRows as $stockRow) {
            $productId = (int)$stockRow['product_id'];
            $restoreQty = max(0, (int)$stockRow['restore_qty']);
            $movementBranchId = (int)($stockRow['movement_branch_id'] ?? $branchId);
            if ($productId <= 0 || $restoreQty <= 0) continue;

            $productExists = false;
            if (trx_delete_table_exists($db, 'products')) {
                $lockProduct = $db->prepare('SELECT stock FROM products WHERE id = ? FOR UPDATE');
                $lockProduct->execute([$productId]);
                $productExists = $lockProduct->fetchColumn() !== false;
            }

            $branchIsMain = false;
            if ($movementBranchId > 0 && trx_delete_table_exists($db, 'branches')) {
                $stmtMain = $db->prepare('SELECT is_main FROM branches WHERE id = ? LIMIT 1');
                $stmtMain->execute([$movementBranchId]);
                $branchIsMain = (int)$stmtMain->fetchColumn() === 1;
            }

            if ($movementBranchId > 0 && trx_delete_table_exists($db, 'product_branch_stocks')) {
                $lockBranch = $db->prepare('SELECT stock FROM product_branch_stocks WHERE branch_id = ? AND product_id = ? FOR UPDATE');
                $lockBranch->execute([$movementBranchId, $productId]);
                $branchStock = $lockBranch->fetchColumn();
                if ($branchStock !== false) {
                    $up = $db->prepare('UPDATE product_branch_stocks SET stock = stock + ?, updated_at = NOW() WHERE branch_id = ? AND product_id = ?');
                    $up->execute([$restoreQty, $movementBranchId, $productId]);
                } elseif ($productExists) {
                    $ins = $db->prepare('INSERT INTO product_branch_stocks (branch_id, product_id, stock, minimum_stock, created_at) SELECT ?, id, ?, minimum_stock, NOW() FROM products WHERE id = ?');
                    $ins->execute([$movementBranchId, $restoreQty, $productId]);
                }
            }

            if ($productExists && ($branchIsMain || $movementBranchId <= 0)) {
                $up = $db->prepare('UPDATE products SET stock = stock + ?, updated_at = NOW() WHERE id = ?');
                $up->execute([$restoreQty, $productId]);
            }
        }

        $stmt = $db->prepare("DELETE FROM stock_movements WHERE reference_type = 'transaction' AND reference_id = ?");
        $stmt->execute([$id]);
    }


    // Koreksi counter pemakaian voucher/promo bila kode promo masih dapat dikenali
    // dari catatan transaksi (kompatibel dengan format lama "Promo:" dan format baru "Voucher:").
    if (trx_delete_table_exists($db, 'promos') && trx_delete_column_exists($db, 'promos', 'used_count')) {
        $note = (string)($trx['note'] ?? '');
        if ($note !== '' && preg_match('/(?:Voucher|Promo):[^\n]*?\(([^()]+)\)\s*-\s*Diskon/i', $note, $match)) {
            $promoCode = trim((string)($match[1] ?? ''));
            if ($promoCode !== '') {
                $codeColumns = [];
                foreach (['code', 'barcode_code', 'barcode'] as $column) {
                    if (trx_delete_column_exists($db, 'promos', $column)) $codeColumns[] = $column;
                }
                if ($codeColumns) {
                    $where = implode(' OR ', array_map(fn($column) => $column . ' = ?', $codeColumns));
                    $params = array_fill(0, count($codeColumns), $promoCode);
                    $stmt = $db->prepare("SELECT id FROM promos WHERE {$where} LIMIT 1 FOR UPDATE");
                    $stmt->execute($params);
                    $promoId = (int)$stmt->fetchColumn();
                    if ($promoId > 0) {
                        $stmt = $db->prepare('UPDATE promos SET used_count = GREATEST(0, used_count - 1) WHERE id = ?');
                        $stmt->execute([$promoId]);
                    }
                }
            }
        }
    }

    // Pertahankan log WA, tetapi lepaskan relasi agar tidak menunjuk transaksi yang sudah tidak ada.
    if (trx_delete_table_exists($db, 'whatsapp_logs') && trx_delete_column_exists($db, 'whatsapp_logs', 'transaction_id')) {
        $stmt = $db->prepare('UPDATE whatsapp_logs SET transaction_id = NULL WHERE transaction_id = ?');
        $stmt->execute([$id]);
    }

    if (trx_delete_table_exists($db, 'transaction_items')) {
        $stmt = $db->prepare('DELETE FROM transaction_items WHERE transaction_id = ?');
        $stmt->execute([$id]);
    }

    $stmt = $db->prepare('DELETE FROM transactions WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() !== 1) {
        throw new Exception('Transaksi gagal dihapus.');
    }

    $db->commit();
    Auth::log('delete_transaction', 'Menghapus transaksi ' . $invoice . ' dan mengembalikan stok penjualan');
    flash('success', 'Transaksi ' . $invoice . ' berhasil dihapus dan stok penjualan dikembalikan.');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    flash('error', 'Gagal menghapus transaksi: ' . $e->getMessage());
}

legacy_redirect('admin/transactions/index.php');
