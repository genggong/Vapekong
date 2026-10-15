# Changelog v17.0

## Database/POS
- Fix MySQL 1136 saat insert `transaction_items`.
- Menjaga snapshot nama/harga/varian pada transaksi.
- Tidak ada destructive migration terhadap transaksi lama.
- Database Doctor lama tetap tersedia di `admin/marketplace-database/index.php` untuk memastikan tabel/kolom/index marketplace, pembelian, request stock, warehouse, varian, dan stock opname.

## Performance
- Tambah `legacy/app/Modules/CatalogFast.php` untuk bulk-read varian, price level, warehouse variant stock, dan brand.
- Cache pemeriksaan table/column marketplace per request.
- Hapus full product scan/seed dari setiap pembukaan POS.
- Optimasi Produk dan Produk Marketplace dengan aggregate join.
- Optimasi storefront untuk brand/weight/variant count/variant stock.

## Produk & varian
- Produk: kolom Varian.
- Tambah Produk: Tambah Varian.
- Edit Produk: tambah/edit/nonaktifkan varian langsung.
- Stok parent disinkronkan dari total stok varian aktif.

## Pembelian / Request Stock
- Picker Produk Induk → Varian.
- Request stock grouped per parent product.
- Pembacaan stok varian gudang bulk.

## POS
- Daftar produk tanpa gambar untuk mengurangi request dan decoding gambar.
- Stok tersedia di atas, produk alfabetis.

## Marketplace
- Default A–Z dan stok 0 di bawah.
- Query admin/storefront lebih ringan.

## Stock Opname
- Exclude stok 0 dari template.
- Tambah Harga Beli + Total Harga Beli.

## Cleanup
- Folder duplikat `/pos` tidak dibawa ke build CI4.
- File `info.php`/`hash.php` diagnostik tidak dibawa.
- Backup SQL lama, cache, dan log lama tidak dibawa.
- 17 media upload yang tidak direferensikan source/database tidak dibawa (~18,33 MB).
- Upload yang masih direferensikan tetap dipertahankan.
