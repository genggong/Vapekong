Vapekong POS CI4 v17.0.4 PATCH

Pasang di document root yang sama dengan index.php/app/system/legacy lalu Extract -> Overwrite.
Setelah patch:
1. Buka /admin/system-upgrade/index.php
2. Klik Jalankan Upgrade Otomatis
3. Jika gambar lama masih ada di folder lama, klik Pulihkan / Migrasikan Gambar Lama
4. Tes upload gambar Produk baru
5. Download ulang Template Stock Opname

Kolom Stock Opname visible:
name - variant - category - unit - system_stock - physical_stock - purchase_price - total_purchase_price - note

product_id dan variant_id tetap ada sebagai kolom tersembunyi. Jangan hapus kedua kolom tersebut saat import balik.
