# Toko Online - Patch Multi Cabang

## Cara apply SQL patch
1. Backup database terlebih dulu.
2. Jalankan file SQL berikut ke database aplikasi:
   - `db/patch_multi_cabang.sql`
3. Patch ini bersifat additive (tidak menghapus tabel legacy).

## Uji cepat fitur Tambah Cabang
1. Login sebagai `owner` atau `admin`.
2. Buka menu **Produk & Inventory → Cabang**.
3. Isi form **Tambah Cabang**, klik **Simpan Cabang**.
4. Pastikan cabang baru muncul di tabel list.
5. Edit data cabang, klik **Update**, dan pastikan tersimpan.
6. Jika terjadi kegagalan insert/update, cek log di `logs/app.log`.

## Uji cepat Produk & Inventory (multi cabang)
1. Tambah produk global di **Produk & Inventory → Produk (Global)**.
2. Set harga jual cabang di **Produk & Inventory → Harga Jual Cabang** untuk cabang A dan B dengan harga berbeda.
3. Pilih cabang aktif dari sidebar, lalu input pembelian di **Pembelian Pihak Ketiga** dengan qty + harga beli per item.
4. Cek stok per cabang di modul stok/opname, pastikan stok cabang A dan B dapat berbeda.
5. Lakukan stock opname, lalu pastikan histori opname tersimpan dan stok sistem menyesuaikan hasil hitung.
