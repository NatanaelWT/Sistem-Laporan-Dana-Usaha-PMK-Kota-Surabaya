# Sistem Laporan Dana Usaha

Aplikasi PHP satu file untuk mencatat operasional dana usaha PMK Kota Surabaya. Sistem ini dipakai untuk memantau kas, stok produk, stok bahan racikan, aset non-stok, histori transaksi, dan export laporan PDF berdasarkan periode.

## Ringkasan

Project ini dibuat tanpa database. Seluruh logika aplikasi ada di `index.php`, sedangkan data disimpan di `data.json`.

Fokus utamanya:

- pencatatan operasional harian
- rekap penjualan / self payment
- restock produk siap jual
- restock bahan racikan
- pengelolaan master data produk, bahan, dan menu racikan
- pencatatan aset, biaya operasional, dan penarikan PMK Kota
- histori transaksi dengan lazy load AJAX
- export laporan PDF per rentang bulan

## Fitur Utama

### 1. Workspace Ringkasan Bisnis

- melihat posisi kas usaha
- melihat nilai stok produk dan bahan
- melihat posisi operasional saat ini
- melihat tabel produk terjual dan stok akhir

### 2. Workspace Operasional

- input rekap penjualan harian
- input restock produk
- input restock bahan racikan
- kelola produk siap jual
- kelola bahan racikan
- kelola menu racikan
- kelola aset non-stok
- input biaya operasional
- input penarikan PMK Kota

### 3. Workspace Riwayat Usaha

- melihat histori transaksi
- memuat histori bertahap saat user scroll
- export laporan PDF berdasarkan periode yang dipilih

### 4. Export Laporan PDF

Laporan PDF periode berisi:

- ringkasan keuangan inti
- tabel produk terjual dan stok akhir
- tabel detail saldo periode
- daftar aset pembelian sampai akhir periode
- log seluruh aktivitas pada periode terpilih

## Teknologi

- PHP 8.2+ direkomendasikan
- HTML, CSS, dan JavaScript vanilla
- penyimpanan data berbasis JSON
- generator PDF custom langsung dari PHP tanpa library eksternal

## Struktur File

```text
.
|-- index.php
|-- data.json
|-- README.md
```

## Cara Menjalankan

### Opsi 1: XAMPP

1. Salin folder project ini ke `htdocs`.
2. Jalankan Apache dari XAMPP.
3. Buka browser ke:

```text
http://localhost/Danusan/
```

### Opsi 2: PHP Built-in Server

Jalankan dari root project:

```bash
php -S localhost:8000
```

Lalu buka:

```text
http://localhost:8000
```

## Kebutuhan Sistem

- PHP 8.2 atau lebih baru
- web server lokal seperti Apache/XAMPP atau built-in server PHP
- izin tulis pada file `data.json`

## Penyimpanan Data

Semua data disimpan di `data.json`, termasuk:

- master produk
- master bahan racikan
- master menu racikan
- data arsip
- ringkasan saldo
- log transaksi

Sistem memakai file locking (`flock`) saat menyimpan agar risiko bentrok tulis berkurang.

## Catatan Penggunaan

- jika `data.json` hilang, sistem akan membuat ulang file default otomatis
- karena memakai JSON file, aplikasi ini cocok untuk penggunaan internal / skala kecil
- untuk penggunaan multi-user besar, sebaiknya migrasi ke database

## Alur Operasional Singkat

1. Tambahkan produk, bahan, dan menu racikan.
2. Catat restock saat stok masuk.
3. Isi rekap penjualan harian untuk stok keluar dan uang aktual masuk.
4. Catat biaya operasional, aset, atau penarikan jika ada.
5. Buka `Riwayat Usaha` untuk audit transaksi.
6. Export laporan PDF sesuai rentang bulan yang dibutuhkan.

## Kelebihan Project Ini

- sederhana untuk dijalankan
- tidak butuh instalasi database
- seluruh sistem mudah dipindahkan karena hanya memakai file
- cocok untuk kebutuhan pencatatan usaha kecil / organisasi internal

## Pengembangan Lanjutan yang Mungkin

- migrasi ke MySQL/PostgreSQL
- autentikasi login
- multi-user dan role access
- dashboard statistik yang lebih detail
- export Excel / CSV
- backup otomatis data

