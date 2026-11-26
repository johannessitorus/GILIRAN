# Frontend GILIRAN

Frontend React, TypeScript, dan Tailwind untuk login, kalender shift mingguan, dan pengelolaan penugasan.

## Menjalankan secara lokal

Siapkan [backend Laravel](../backend/README.md) dengan Docker Compose dan akun demo. Jika API sudah berjalan dari konfigurasi lama, jalankan kembali `docker compose up -d` di folder backend agar konfigurasi Sanctum diterapkan.

Dengan Node.js 22.12+ dan npm, jalankan dari folder `frontend`.

```powershell
npm ci
npm run dev
```

Buka `http://localhost:5173`. Vite meneruskan `/api` dan `/sanctum` ke backend lokal di port 8000.

- Karyawan melihat shift miliknya.
- Supervisor melihat kalender tim dan bisa menambah, mengubah, atau membatalkan penugasan yang diizinkan API.
- Admin melihat kalender organisasi dan dapat menyaringnya berdasarkan departemen.
- Di halaman masuk, calon Owner dapat mendaftarkan usaha dan mengaktifkannya
  lewat Email. Di menu Kelola usaha, Owner dapat menambah cabang atau departemen
  serta mengundang Supervisor dan Karyawan. Gunakan Lupa kata sandi untuk
  meminta tautan reset melalui Email.
- Di menu Tukar shift, karyawan dapat mengajukan pertukaran dengan rekan satu
  departemen, menanggapi permintaan masuk, dan membatalkan pengajuan yang masih
  menunggu. Supervisor memutuskan permintaan yang sudah diterima rekan. Admin
  dapat membaca permintaan dan riwayatnya.
- Di menu Serah terima, karyawan pemilik shift keluar dapat membuat, mengubah,
  mengambil alih, dan mengirim draf. Pemilik terkini shift penerima dapat
  mengakui laporan setelah shiftnya dimulai. Owner dan Supervisor dapat
  membaca laporan dalam lingkup akses masing-masing.
- Pemilik draf dapat menambah, mengubah, atau menghapus tugas tindak lanjut.
  Setelah laporan dikirim, pemilik shift penerima atau Supervisor yang
  berwenang dapat mengubah status tugas. Mengakui laporan tidak mengubah status tugas.
- Di Riwayat audit, Owner dan Supervisor dapat melihat tindakan, hasil, pelaku,
  serta rincian perubahan dalam lingkup usaha atau departemennya.
- Menu Notifikasi menampilkan permintaan tukar shift, keputusan, serta
  pengiriman dan pengakuan serah terima untuk pengguna yang terkait. Dari
  menu ini, pengguna dapat membuka rincian dan menandai notifikasi sebagai dibaca.

Jalankan `npm run build` untuk memeriksa tipe TypeScript dan menghasilkan `dist/`. Di lingkungan produksi, sajikan `dist/`, API, dan rute CSRF dari origin HTTPS yang sama. Konfigurasikan domain stateful Sanctum dan kuki Secure pada backend.
