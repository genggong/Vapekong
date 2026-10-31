#!/bin/bash
# ============================================================
#  Install patch Vapekong v17.0.52 (guest->member + edit tarif kurir)
#  Jalankan DARI docroot hosting (folder berisi index.php):
#      wget -qO apply.sh "https://raw.githubusercontent.com/genggong/Vapekong/fix/purchase-input-and-checkout/releases/v17.0.52/apply.sh"
#      bash apply.sh
# ============================================================
set -u

[ -f index.php ] || { echo "ERROR: jalankan dari docroot (folder berisi index.php)."; exit 1; }

ZIP_URL="https://raw.githubusercontent.com/genggong/Vapekong/fix/purchase-input-and-checkout/releases/v17.0.52/Vapekong_POS_PATCH_v17.0.52_GUEST_MEMBER_TARIFF_EDIT.zip"
ZIP="p1752.zip"

echo "===[1/5] Backup kode =="
BK="backup_code_$(date +%Y%m%d_%H%M%S).tar.gz"
tar czf "$HOME/$BK" --exclude='./writable' --exclude='./uploads' . 2>/dev/null
echo "backup -> $HOME/$BK"

echo "===[2/5] Unduh patch =="
wget -qO "$ZIP" "$ZIP_URL" || { echo "ERROR: unduh gagal."; exit 1; }

echo "===[3/5] Uji zip =="
unzip -t "$ZIP" >/dev/null || { echo "ERROR: zip rusak."; exit 1; }

echo "===[4/5] Overwrite =="
unzip -o "$ZIP"
echo "VERSION sekarang: $(cat VERSION 2>/dev/null)"

echo "===[5/5] Bersihkan file patch =="
rm -f "$ZIP"

echo; echo "SELESAI. Langkah berikutnya:"
echo "  1. Buka /admin/system-upgrade/index.php dan klik 'Jalankan Upgrade Otomatis'"
echo "  2. Purge LiteSpeed Cache"
echo "  3. Tes: Edit tarif kurir toko; checkout tamu dgn nomor member & nomor baru"
echo "  (Rollback: tar xzf $HOME/$BK)"
