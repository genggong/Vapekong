# Cara Deploy Vapekong POS CI4 v17.0

## 1. Wajib backup

Sebelum upload:
- backup database aktif dari phpMyAdmin/cPanel;
- backup folder website lama, terutama `public/uploads`;
- simpan file konfigurasi database lama.

Gunakan staging/subdomain terlebih dahulu bila memungkinkan.

## 2. Syarat hosting

- PHP 8.2+ (disarankan PHP 8.4 sesuai server dump).
- Extension minimum: `intl`, `mbstring`, `json`, `openssl`, `fileinfo`, `pdo`, `pdo_mysql`.
- Apache/LiteSpeed `mod_rewrite`/rewrite harus aktif.
- Folder `writable` dan `legacy/storage` harus writable oleh PHP.

Jalankan lewat CLI bila tersedia:

```bash
php tools/preflight.php
```

## 3. Upload

Ekstrak seluruh isi ZIP ke document root domain. Struktur yang benar:

```text
/index.php
/.htaccess
/app/
/system/
/writable/
/legacy/app/
/legacy/public/
```

Jangan memindahkan `legacy/public` menjadi public_html terpisah; `.htaccess` root sudah memetakan assets/uploads.

## 4. Database

Build ini mempertahankan konfigurasi database aplikasi lama di:

```text
legacy/app/Config/database.php
```

Sesuaikan file itu bila staging memakai database berbeda.

Setelah login admin, buka:

```text
Admin → Database & System Doctor
```

Lalu jalankan **Cek & Upgrade Database**. Proses tersebut menambah struktur yang kurang tanpa sengaja menghapus produk/order.

## 5. Uji wajib sebelum produksi

1. Login/logout.
2. POS: tambah produk biasa dan varian, checkout cash/QRIS, cek Riwayat.
3. Produk: tambah produk + 2 varian, edit harga/stok varian.
4. Pembelian: pilih parent + varian, simpan PO.
5. Request Stock: pilih parent + varian, approve/fulfill.
6. Stock Opname: download template dan pastikan stok 0 tidak ada; cek kolom Harga Beli dan Total Harga Beli.
7. Marketplace: cek A–Z; stok 0 di bawah; detail produk/varian.
8. Pakasir/webhook, WA, RajaOngkir/Komerce, email bila dipakai.

## 6. Tentang migrasi CI4

v17.0 memakai **staged migration**: request masuk melalui kernel/router CodeIgniter 4.7.4, sementara modul bisnis lama yang sudah stabil ditempatkan di `legacy/` dan dijalankan melalui bridge CI4. Ini sengaja dilakukan agar upgrade tidak mematahkan ratusan endpoint sekaligus. Jalur berat (POS, produk, pembelian, request stock, marketplace, stock opname) sudah dioptimalkan sekarang.

Migrasi controller/model satu per satu dapat dilakukan pada versi berikutnya tanpa mengubah URL pengguna.
