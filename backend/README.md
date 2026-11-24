# Backend GILIRAN

Backend Tahap 3 mencakup fondasi penjadwalan dan tukar shift (AC-01 sampai AC-08). Kontrak awal ada di [dokumen API](../docs/03_api_and_acceptance.md).

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
hanya pada loopback untuk pengembangan. Gunakan HTTPS dan kuki Secure pada
lingkungan selain lokal. Setel header Origin atau Referer dengan asal yang sama
saat menguji API sesi melalui klien HTTP.

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
docker compose run --rm -e DB_DATABASE=giliran_test api php artisan test --filter='FoundationTest|SwapTest'
```