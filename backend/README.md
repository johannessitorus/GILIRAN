# Backend GILIRAN

Backend Tahap 3 mencakup penjadwalan, tukar shift, serah terima, tugas, dan audit (AC-01 sampai AC-12). Kontrak tersedia di [dokumen API](../docs/03_api_and_acceptance.md).

## Menjalankan secara lokal

Jalankan dari folder backend dengan Docker Compose.

```powershell
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
docker compose build
docker compose run --rm api composer install
docker compose run --rm api php artisan key:generate
docker compose run --rm api php artisan migrate
docker compose run --rm api php artisan giliran:demo
docker compose up -d
```

Perintah demo menampilkan kata sandi acak hanya untuk akun yang baru dibuat.
Gunakan `http://localhost:8000` untuk layanan lokal. Compose menggunakan HTTP
hanya pada loopback untuk pengembangan. Saat layanan API dimulai, Compose
menyalin proyek dan dependensi ke filesystem container. Jalankan
`docker compose restart api` setelah mengubah kode backend, konfigurasi, atau
dependensi agar server membaca perubahan tersebut. Jalankan perintah Artisan
untuk setup dari direktori proyek seperti pada langkah di atas.
Gunakan HTTPS dan kuki Secure pada
lingkungan selain lokal. Setel header Origin atau Referer dengan asal yang sama
saat menguji API sesi melalui klien HTTP.

Pendaftaran usaha, undangan anggota, dan reset kata sandi mengirim tautan ke
`FRONTEND_URL`. Untuk menguji dengan `MAIL_MAILER=log`, buka
`docker compose exec api tail -n 100 /srv/giliran/storage/logs/laravel.log`
di lingkungan lokal. Di produksi, gunakan mailer yang mengirim Email, URL
frontend HTTPS, dan sesi database agar reset dapat mencabut sesi aktif.
Data proyek yang sudah ada dimigrasikan ke usaha bawaan GILIRAN. Peran
`ADMIN` ditampilkan sebagai Owner dan hanya dapat mengakses usahanya sendiri.
Setiap alamat Email terhubung ke satu usaha.

Perintah `php artisan giliran:complete-assignments` menutup penugasan yang
telah berakhir. Gunakan `php artisan giliran:expire-swaps` untuk menutup permintaan tukar yang
kedaluwarsa. API juga memeriksa tenggat saat ada tindakan. Jalankan
`docker compose exec api php artisan schedule:work` untuk menjalankan
kedua pekerjaan terjadwal setiap menit secara lokal.

## Uji integrasi

Gunakan basis data PostgreSQL khusus pengujian. PHPUnit memakai RefreshDatabase,
yang membangun ulang skema pada basis data tersebut.

```powershell
docker compose up -d --wait db
docker compose exec -T db createdb -U giliran giliran_test
docker compose run --rm -e DB_DATABASE=giliran_test -e SESSION_DRIVER=array api php artisan test
```
