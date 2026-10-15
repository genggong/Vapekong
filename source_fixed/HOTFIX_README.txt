Vapekong POS CI4 v17.0.1 - Storefront Loader Hotfix

Perubahan:
1. Loader storefront tidak lagi bergantung penuh pada event window.load/storefront.js.
2. Fail-safe menutup loader saat DOM siap dan maksimal 1.8 detik.
3. Cache storefront.js dinaikkan ke v17.0.1.
4. Rewrite assets/uploads menggunakan flag [L] untuk kompatibilitas shared hosting/LiteSpeed.

Cara pasang:
- Extract ZIP ini di document root yang sama dengan instalasi v17.
- Izinkan overwrite 3 file yang ada.
- Hapus cache browser / buka Incognito, lalu akses ulang domain.
