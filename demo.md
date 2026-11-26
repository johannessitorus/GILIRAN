# Demo penggunaan GILIRAN

Panduan ini mengikuti satu toko dari pendaftaran sampai pemeriksaan riwayat audit. Siapkan empat alamat surel berbeda yang belum terdaftar untuk Owner, Supervisor, Karyawan A, dan Karyawan B. Gunakan akun berbeda di jendela atau profil browser terpisah, atau klik **Keluar** setiap kali berganti peran.

## 1. Jalankan aplikasi lokal

Buka Docker Desktop. Jika backend sudah disiapkan, jalankan dari root proyek:

```powershell
docker compose -f backend/compose.yaml up -d
docker compose -f backend/compose.yaml logs -f api
```

Tunggu pesan `Server running` sebelum membuka aplikasi. Tekan `Ctrl+C` untuk keluar dari tampilan log. Container tetap berjalan. Jika baru pertama kali memasang proyek, ikuti [setup backend](backend/README.md#menjalankan-secara-lokal) sampai migrasi selesai. Akun bawaan dari `giliran:demo` bersifat opsional untuk skenario ini.

Di terminal kedua, jalankan:

```powershell
cd frontend
npm ci
npm run dev
```

Buka [GILIRAN di localhost](http://localhost:5173). Jika frontend sudah berjalan, langsung buka halaman tersebut.

### Mengambil tautan aktivasi dan undangan

Gunakan kotak masuk penerima jika pengiriman surel sudah dikonfigurasi. Untuk Docker lokal dengan `MAIL_MAILER=log`, jalankan dari root proyek setelah mengirim permintaan:

```powershell
docker compose -f backend/compose.yaml exec api tail -n 100 /srv/giliran/storage/logs/laravel.log
```

Cari entri terbaru berdasarkan alamat penerima dan subjek. Buka tautannya di browser yang memakai `http://localhost:5173`. Tautan ini memberi akses ke proses aktivasi, undangan, atau reset sandi. Jangan membagikannya bersama tangkapan layar atau log demo.

## 2. Daftarkan toko dan aktifkan akun Owner

1. Di halaman **Masuk**, klik **Daftarkan usaha**.
2. Isi **Nama**, **Nama usaha**, dan **Surel** pemilik. Klik **Kirim tautan**.
3. Ambil tautan dari pesan **Aktifkan akun Owner GILIRAN**. Buka halaman **Aktifkan akun Owner**, buat kata sandi, ulangi kata sandi, lalu kirim formulir.
4. Masuk dengan surel dan kata sandi yang baru dibuat. GILIRAN menyiapkan departemen **Utama** dan tipe shift **Pagi** untuk usaha baru.

Akun Owner dibuat saat aktivasi selesai. Jika ada dua toko dengan pemilik berbeda, masing-masing mendaftar memakai alamat surel pemiliknya sendiri. Jika satu usaha punya beberapa cabang, Owner bisa menambahnya di **Kelola usaha**. Satu alamat surel saat ini terhubung ke satu usaha.

## 3. Undang Supervisor dan karyawan

1. Sebagai Owner, buka **Kelola usaha** dan cari bagian **Undang anggota**.
2. Kirim tiga undangan ke departemen **Utama**. Pilih peran **Supervisor** untuk satu orang dan **Karyawan** untuk dua orang. Gunakan **Utama** selama demo agar ketiga anggota berada di departemen yang sama dan memakai tipe shift **Pagi** yang sudah tersedia.
3. Untuk tiap undangan, buka tautan dari pesan **Terima undangan bergabung di GILIRAN** setelah keluar dari akun Owner atau di sesi browser terpisah. Isi nama dan tetapkan kata sandi di halaman **Terima undangan**.
4. Uji masuk dengan masing-masing akun. Supervisor membuka **Jadwal tim**, sedangkan Karyawan membuka **Jadwal saya**.

## 4. Buat jadwal dan jalankan tukar shift

1. Masuk sebagai Supervisor, buka **Jadwal tim**, lalu klik **Tambah penugasan**.
2. Buat dua penugasan bertipe **Pagi** pada hari yang sama, minimal tiga hari ke depan. Contoh untuk demo dengan kebijakan bawaan adalah Karyawan A pukul **08.00–12.00** dan Karyawan B pukul **12.00–16.00**. Pilih tanggal dan isi jam secara terpisah dalam WIB. Klik **Simpan** untuk tiap penugasan.
3. Masuk sebagai Karyawan A, buka **Tukar shift**, lalu klik **Ajukan pertukaran**. Jika perlu, pindah ke minggu yang memuat jadwal tersebut. Pilih shift A di **Shift saya** dan shift B di **Shift rekan**, isi alasan, lalu klik **Kirim permintaan**.
4. Masuk sebagai Karyawan B. Buka permintaan di **Tukar shift**, periksa kedua shift, lalu klik **Terima**. Status berubah menjadi **Menunggu Supervisor**.
5. Masuk sebagai Supervisor. Buka permintaan tersebut dan klik **Setujui**. Periksa status **Disetujui**, lalu buka kembali kalender. Karyawan B sekarang memegang shift **08.00–12.00** dan Karyawan A memegang shift **12.00–16.00**.

Untuk mencoba jalur penolakan, gunakan permintaan terpisah. Rekan atau Supervisor yang berwenang dapat memilih **Tolak** pada tahapnya. Pemohon juga dapat membatalkan permintaan yang masih menunggu.

## 5. Kirim serah terima dan selesaikan tugas

1. Masuk sebagai **Karyawan B**, pemilik shift keluar setelah pertukaran. Buka **Serah terima**, klik **Buat draf**, lalu pilih penugasan **08.00–12.00**. Pindah minggu jika shift belum muncul.
2. Pada **Shift penerima**, biarkan **Tentukan otomatis** jika hanya ada satu penugasan yang mulai paling awal setelah shift keluar berakhir. Jika beberapa mulai bersamaan, pilih penugasan yang dituju. Dalam contoh ini, penerima adalah Karyawan A pada shift **12.00–16.00**.
3. Isi **Ringkasan** dan, jika ada, **Kendala**. Klik **Simpan draf**. Buka **Tambah tugas**, isi judul seperti *Periksa stok rak depan*, lalu klik **Simpan tugas**. Saat laporan masih draf, pemilik dapat mengubah atau menghapus tugas.
4. Periksa kembali laporan dan tugas, lalu klik **Kirim laporan**. Status menjadi **Terkirim**.
5. Saat shift penerima sudah dimulai, masuk sebagai **Karyawan A**, buka laporan di **Serah terima**, lalu klik **Akui penerimaan**. Jika halaman sudah terbuka sebelum shift dimulai, klik **Muat ulang**. Status laporan menjadi **Diterima**, sementara tugas masih **Terbuka**.
6. Pada tugas tersebut, pilih **Sedang dikerjakan** atau **Selesai** di **Ubah status**, lalu klik **Simpan status**. Supervisor yang berwenang juga dapat memperbarui status tugas setelah laporan dikirim.

Jika demo harus selesai dalam satu sesi, gunakan sepasang shift yang sudah disiapkan sebelumnya dengan shift penerima yang sudah mulai untuk langkah 5. Pertukaran pada langkah 4 tetap harus memakai dua shift mendatang yang memenuhi tenggat pengajuan.

## 6. Periksa riwayat audit

1. Masuk sebagai Owner, buka **Riwayat audit**, lalu cari catatan pembuatan penugasan, persetujuan pertukaran, pengiriman dan pengakuan laporan, serta perubahan status tugas.
2. Buka **Rincian perubahan** untuk melihat nilai sebelum dan sesudah jika tersedia. Catatan menampilkan pelaku sebagai ID pengguna atau **Sistem**, waktu, objek, tindakan, dan hasil.
3. Masuk sebagai Supervisor dan buka halaman yang sama. Catatannya mengikuti cakupan departemen Supervisor. Menu ini tidak tersedia bagi Karyawan.
4. Klik **Keluar** setelah selesai.

## Jika lupa kata sandi

Dari halaman **Masuk**, pilih **Lupa kata sandi?**, isi surel akun, lalu klik **Kirim tautan**. Buka tautan reset dari kotak masuk atau log lokal, tetapkan kata sandi baru, lalu masuk kembali.
