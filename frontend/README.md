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
- Di menu Tukar shift, karyawan dapat mengajukan pertukaran dengan rekan satu
  departemen, menanggapi permintaan masuk, dan membatalkan pengajuan yang masih
  menunggu. Supervisor memutuskan permintaan yang sudah diterima rekan. Admin
  dapat membaca permintaan dan riwayatnya.

Jalankan `npm run build` untuk memeriksa tipe TypeScript dan menghasilkan `dist/`. Di lingkungan produksi, sajikan `dist/`, API, dan rute CSRF dari origin HTTPS yang sama. Konfigurasikan domain stateful Sanctum dan kuki Secure pada backend.
