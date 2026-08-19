ABSENSI & BELAJAR - VERSI PERSISTEN MYSQL

PENTING:
Gunakan SELURUH folder absensi-belajar-xampp, bukan hanya index.html.

1. Salin folder absensi-belajar-xampp ke:
   C:\xampp\htdocs\

2. Pastikan Apache dan MySQL aktif di XAMPP.

3. Pastikan database absensi_belajar tersedia.
   Jika belum ada, import:
   database\absensi_belajar.sql

4. Buka:
   http://localhost/absensi-belajar-xampp/

Akun awal:
Nama   : Guru
Password: GantiPassword123!

PENYIMPANAN:
- Sumber data permanen adalah MySQL tabel app_state.
- Logout tidak menghapus data.
- Save menggunakan transaksi database.
- Setelah INSERT/UPDATE, server membaca kembali data untuk verifikasi.
- Jika penyimpanan gagal, aplikasi tidak boleh menganggap data tersimpan.

JANGAN menghapus tabel app_state jika ingin mempertahankan seluruh data.


FIX V2: Struktur absensi dikunci sebagai object tanggal->siswa. Ini memperbaiki bug PHP/MySQL yang mengembalikan absensi kosong sebagai [] sehingga JavaScript dapat mengabaikan property tanggal saat JSON.stringify(). Database lama tetap digunakan.
