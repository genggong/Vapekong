# Vapekong POS — Audit & Upgrade v17.0

Tanggal audit: 10 Oktober 2026.

## Temuan utama yang diperbaiki

1. **POS gagal menyimpan transaksi / MySQL 1136**
   - Ditemukan query `INSERT INTO transaction_items` pada jalur produk non-varian/fallback memiliki jumlah kolom dan value yang tidak sama.
   - Query sudah disejajarkan sehingga `discount=0`, `subtotal=?`, dan `created_at=NOW()` menempati kolom yang benar.
   - Dua jalur transaksi yang memakai format tersebut sudah diperbaiki.

2. **POS lambat saat produk banyak**
   - POS lama menjalankan query varian dan harga per produk (pola N+1).
   - Sekarang varian dan harga level dibaca secara bulk dengan jumlah query tetap.
   - Query produk POS hanya mengambil kolom yang dibutuhkan.
   - Gambar produk tidak dimuat di daftar POS; kartu memakai ikon ringan.
   - Proses `price_install()` tidak lagi melakukan scan/seed semua produk setiap kali POS dibuka.

3. **Pembelian & Request Stock**
   - Daftar barang sekarang memakai struktur **Produk Induk → Varian**.
   - Varian, stok cabang, dan stok gudang dibaca bulk, bukan query per produk/per varian.
   - Request Stock menampilkan parent product sebagai grup dan varian sebagai baris anak.

4. **Produk**
   - Kolom **Varian** aktif pada tabel Produk dan menampilkan jumlah/nama varian.
   - Tambah Produk mendukung **Tambah Varian**.
   - Edit Produk memiliki panel **Varian Produk** untuk tambah/edit stok, harga beli, harga jual, SKU, barcode, berat, urutan, dan status varian.
   - Penghapusan varian dari halaman Edit menggunakan nonaktif/soft-delete agar riwayat transaksi tidak kehilangan referensi ID varian.
   - Query daftar Produk dioptimalkan dengan agregasi varian sekali jalan.

5. **Marketplace**
   - Urutan default: **stok tersedia lebih dulu → A–Z**.
   - Produk stok 0 dipindahkan ke bagian bawah.
   - Admin Produk Marketplace tidak lagi memakai subquery count per baris untuk varian dan galeri.
   - Storefront membaca brand, berat, jumlah varian, dan stok varian melalui query gabungan, mengurangi N+1.

6. **Stock Opname Excel**
   - Produk/varian dengan stok `0` tidak dimasukkan ke template.
   - Kolom baru: **Harga Beli** dan **Total Harga Beli**.
   - Total harga beli = stok sistem × harga beli.
   - Kolom tambahan tetap kompatibel dengan import karena parser menggunakan nama header.

## Audit struktur source

- PHP yang diaudit (di luar folder duplikat `/pos`): **280 file**.
- PHP syntax check: **0 syntax error**.
- Link menu sidebar: **53 endpoint**, **0 target file hilang**.
- Literal `require/include` berbasis `__DIR__`: **872 referensi**, **0 file hilang**.
- Dump database: **77 tabel**.
- Row INSERT dump yang diparsing: **44.584 row**, **0 row dengan jumlah value tidak sesuai daftar kolom**.
- Data utama pada dump:
  - products: 748
  - marketplace_product_variants: 434
  - marketplace_product_variant_stocks: 434
  - transactions: 427
  - transaction_items: 631
  - purchases: 35
  - purchase_items: 288
  - stock_opname_items: 5.163

## Kesimpulan error database pada screenshot

Dump SQL yang dikirim tidak menunjukkan row INSERT dump yang rusak. Error `SQLSTATE[21S01] / 1136` berasal dari query aplikasi POS saat membuat `transaction_items`, bukan dari struktur row dump. Karena itu perbaikan utamanya berada di kode POS, bukan dengan menghapus data transaksi.

## Batas audit

Audit ini meliputi source, struktur dump, lint PHP, target menu, include path, dan jalur kode utama. Pengujian klik end-to-end terhadap database live, Pakasir, RajaOngkir/Komerce, WhatsApp, SMTP, kamera/barcode, dan webhook membutuhkan staging/hosting dengan MySQL serta extension PHP yang aktif. Jangan langsung overwrite produksi tanpa backup database + folder upload.
