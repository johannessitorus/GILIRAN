# GILIRAN — Kontrak API Awal dan Uji Penerimaan

Dokumen pendamping untuk [desain sistem](02_system_design.txt) dan [kebutuhan MVP](01_requirements.txt). Semua rute di bawah memakai awalan `/api/v1`, kecuali rute inisialisasi CSRF Sanctum. Skenario uji di bawah adalah acuan cakupan Tahap 3.

## Transport, sesi, dan galat

- Gunakan HTTPS dengan asal yang sama, isi permintaan JSON, dan `Accept: application/json`. Sebelum operasi yang mengubah data dengan autentikasi kuki, ambil kuki CSRF melalui `GET /sanctum/csrf-cookie` (204) dan kirim nilainya melalui `X-XSRF-TOKEN`. Kuki sesi memakai Secure, HttpOnly, dan SameSite=Lax. Ganti ID sesi saat masuk dan batalkan sesi saat keluar. Periksa status aktif, peran, dan lingkup pada setiap permintaan terautentikasi. Cabut sesi yang ada saat akun dinonaktifkan atau kata sandi diatur ulang.
- Satu sumber daya dikembalikan sebagai `{"data": {...}}`. Daftar dikembalikan sebagai `{"data": [...], "meta": {"page": 1, "per_page": 25, "total": 0}}`. Gunakan page >= 1 dan per_page 1–100 dengan nilai bawaan 25. Terapkan lingkup visibilitas sebelum menyaring dan menghitung hasil. Departemen dan pengguna diurutkan menurut ID. Jadwal diurutkan menurut starts_at lalu ID.
- Kembalikan ID sebagai teks berisi angka desimal. Kembalikan waktu RFC 3339 dalam UTC dan terima waktu RFC 3339 dengan selisih zona waktu eksplisit. Buang spasi tepi surel dan ubah menjadi huruf kecil. Tolak atribut tulis tidak dikenal, tipe data yang salah, serta PATCH kosong. Terima hanya atribut yang tercantum di bawah.
- Galat menggunakan format `{"error":{"code":"VALIDATION_FAILED","message":"Periksa data yang dikirim.","details":{"fields":{"email":["Masukkan alamat surel yang valid."]}}}}`. Objek details bersifat opsional. Jika keadaan sudah berubah, kembalikan versi atau status terkini hanya jika pengguna berhak membacanya. Galat dari kerangka kerja mengikuti format yang sama. Jangan sertakan jejak tumpukan atau data rahasia.

| HTTP | Kode dan arti |
| --- | --- |
| 400 | `MALFORMED_JSON` untuk sintaks JSON yang salah |
| 401 | `UNAUTHENTICATED` atau `INVALID_CREDENTIALS` yang bersifat umum untuk kata sandi salah, surel tidak terdaftar, atau akun nonaktif |
| 403 | `FORBIDDEN` untuk tindakan atau daftar yang dibatasi, termasuk tindakan terlarang pada sumber daya yang dapat dilihat |
| 404 | `NOT_FOUND` untuk ID yang tidak ada atau di luar lingkup, termasuk ID referensi |
| 409 | `DUPLICATE_EMAIL`, `DUPLICATE_DEPARTMENT_NAME`, `VERSION_CONFLICT`, `INVALID_TRANSITION`, `ACCOUNT_HAS_DUTIES`, `DEPARTMENT_IN_USE`, `ASSIGNMENT_OVERLAP`, `MINIMUM_REST_VIOLATION`, `QUALIFICATION_REQUIRED`, `ASSIGNMENT_RESERVED`, `ASSIGNMENT_CHANGED`, `SWAP_EXPIRED`, `HANDOVER_LINKED`, `NO_RECIPIENT` |
| 415 | `UNSUPPORTED_MEDIA_TYPE` untuk tipe media yang tidak didukung |
| 419 | `CSRF_TOKEN_MISMATCH` saat token CSRF hilang atau kedaluwarsa |
| 422 | `VALIDATION_FAILED` untuk atribut wajib yang tidak ada, format atau rentang yang salah, atau kombinasi atribut yang tidak valid |
| 429 | `RATE_LIMITED`, sertakan `Retry-After` |
| 503 | `TEMPORARILY_UNAVAILABLE` setelah batas percobaan ulang basis data tercapai, dengan rincian umum |

Untuk rute terlindungi, autentikasi pengguna, tentukan visibilitas, periksa izin tindakan, validasi masukan, dan periksa kembali aturan yang bergantung pada keadaan terbaru di dalam transaksi tulis. Daftar tindakan dalam respons dihitung di server sebagai panduan. Tindakan yang sudah tidak sesuai keadaan memperoleh 409 meskipun sebelumnya tersedia. Sumber daya yang tidak lagi terlihat memperoleh 404. Operasi tulis boleh dicoba ulang jika telah dipastikan gagal tanpa menyimpan perubahan. Jika hasilnya tidak pasti, periksa lewat operasi baca sebelum mengulangi pembuatan. Transisi dan versi penugasan mencegah pemrosesan ganda.

## Autentikasi

| Metode dan rute | Permintaan dan otorisasi | Respons berhasil | Galat khusus |
| --- | --- | --- | --- |
| POST /auth/login | Pengunjung dengan CSRF. `email` dan `password` wajib berupa teks yang tidak kosong. Surel valid maksimal 255 karakter. Kata sandi maksimal 72 bita UTF-8. Batasi laju berdasarkan identitas yang dinormalisasi dan IP. | 200 dengan data pengguna saat ini dan kuki sesi | 401 `INVALID_CREDENTIALS`, 409 `INVALID_TRANSITION` jika sudah masuk, 419, 422, 429 |
| GET /auth/me | Pengguna aktif yang terautentikasi | 200 dengan id, name, email, role, department_id (boleh null) dan organization_timezone miliknya | 401 |
| POST /auth/logout | Pengguna aktif terautentikasi dengan CSRF dan isi permintaan kosong | 204, batalkan sesi dan buat ulang token CSRF | 401, 419 |

Gunakan hash kata sandi yang didukung Laravel. Admin menetapkan kata sandi awal dan pengganti sepanjang 12–72 bita UTF-8. Kata sandi tidak dikembalikan dalam respons atau dicatat dalam audit.

## Departemen dan pengguna

| Metode dan rute | Permintaan dan otorisasi | Respons berhasil | Galat khusus |
| --- | --- | --- | --- |
| GET /departments | Admin melihat semua. Karyawan dan Supervisor hanya departemen sendiri. Penyaring `active` boolean dan paginasi opsional. | 200 daftar departemen | 401, 422 |
| GET /departments/{id} | Lingkup sama dengan daftar. | 200 departemen | 401, 404 |
| POST /departments | Admin. Wajib `name` unik sepanjang 1–120 karakter setelah spasi tepi dibuang. `active` boolean opsional, bawaan true. | 201 departemen dengan header Location | 403, 409 duplikat, 422 |
| PATCH /departments/{id} | Admin. Kirim `name`, `active`, atau keduanya. Terapkan syarat penonaktifan di §3 desain. | 200 departemen | 403, 404, 409 duplikat atau masih digunakan, 422 |
| GET /users | Admin melihat daftar akun. Supervisor melihat daftar Karyawan di departemennya. Karyawan memakai /auth/me. Penyaring opsional `department_id`, `role`, `active`, dan paginasi dalam lingkup akses. | 200 daftar akun atau staf | 403, 422 |
| GET /users/{id} | Admin melihat semua. Supervisor melihat data terbatas karyawan satu departemen. Karyawan melihat dirinya sendiri. | 200 data akun atau staf | 404 |
| POST /users | Admin. Wajib `name`, `email`, `password`, dan `role`. `department_id` wajib sesuai peran. `active` opsional, bawaan true. | 201 akun dengan header Location | 403, 404 referensi, 409 surel duplikat, 422 |
| PATCH /users/{id} | Admin. Atribut yang sama dapat dikirim secara opsional. Validasi gabungan data lama dan baru. Kata sandi menggantikan hash sebelumnya. Periksa kewajiban yang masih berjalan dan cabut sesi yang terdampak. | 200 akun | 403, 404, 409 surel duplikat atau kewajiban tertunda, 422 |

Data departemen memuat id, name, active. Data akun memuat id, name, email, role, department_id, active. Daftar staf supervisor memuat id, name, active. Panjang nama pengguna 1–160 karakter setelah spasi tepi dibuang. Peran yang valid adalah ADMIN, SUPERVISOR, dan EMPLOYEE. Departemen Admin dapat bernilai null. Peran lain wajib memiliki departemen yang valid dan, jika akunnya aktif, departemennya juga harus aktif. Gunakan PATCH untuk penonaktifan. Tidak tersedia rute penghapusan fisik. Catat perubahan administratif dalam audit tanpa menyimpan kredensial.

## Penjadwalan

Atribut penugasan meliputi `id`, `department_id`, `employee_id`, `shift_type_id`, `starts_at`, `ends_at`, `status`, dan `version`. Kalender tim untuk karyawan menampilkan ID penugasan, nama tampilan, ID tipe shift, waktu mulai dan selesai, serta status. Penugasan sendiri dapat ditampilkan lengkap. Admin dan Supervisor melihat data lengkap dalam lingkupnya.

| Metode dan rute | Permintaan dan otorisasi | Respons berhasil | Galat khusus |
| --- | --- | --- | --- |
| GET /shift-types | Admin melihat semua tipe; Supervisor dan Karyawan melihat tipe departemen masing-masing. Paginasi opsional. | 200 daftar dengan id, department_id, name, dan active | 401, 422 |
| GET /assignments | Admin melihat seluruh organisasi, Supervisor departemen sendiri. Karyawan melihat penugasan sendiri atau memakai `view=team` untuk kalender departemen. Wajib `from` dan `to` dengan from < to, rentang maksimal 31 hari. Opsional `department_id` (Admin), `employee_id` (Admin/Supervisor), `status`, dan paginasi. | 200 daftar interval yang beririsan dengan [from,to) | 403 penyaring tidak diizinkan, 404 referensi di luar lingkup, 422 penyaring tidak valid |
| GET /assignments/{id} | Visibilitas mengikuti data pada daftar. | 200 penugasan. Data lengkap juga memuat `available_actions` sesuai lingkup. | 404 |
| POST /assignments | Supervisor untuk departemen yang diambil dari sesi. Wajib `employee_id`, `shift_type_id`, `starts_at`, dan `ends_at`. | 201 penugasan SCHEDULED, versi 1, dengan header Location | 403, 404 referensi, 409 tumpang tindih, istirahat, kualifikasi, atau referensi nonaktif, 422 interval tidak valid |
| PATCH /assignments/{id} | Supervisor di departemennya sendiri. Wajib `version` berupa bilangan bulat positif dan minimal satu dari `employee_id`, `shift_type_id`, `starts_at`, `ends_at`. Hanya untuk SCHEDULED yang belum mulai. Hasil perubahan juga harus belum mulai. | 200 penugasan, versi naik jika berubah | 403, 404, 409 versi, status, tumpang tindih, istirahat, kualifikasi, atau serah terima terkait, 422 |
| POST /assignments/{id}/cancel | Supervisor di departemennya sendiri. Wajib `version`. Hanya untuk SCHEDULED yang belum mulai. Terapkan batasan serah terima. | 200 penugasan CANCELLED dengan versi yang dinaikkan | 403, 404, 409 versi, status, atau serah terima terkait, 422 |

Pembuatan dan perubahan memerlukan karyawan dan tipe shift aktif di departemen aktif yang sama, starts_at setelah waktu server, ends_at > starts_at, durasi sesuai konfigurasi, tanpa tumpang tindih, istirahat cukup, dan kualifikasi jika diwajibkan. Tolak atribut tak dikenal, termasuk penulisan status langsung. Data kalender dan staf lintas departemen tidak muncul dalam hasil. Seluruh rute terlindungi mengikuti galat autentikasi dan CSRF umum. Aturan pengelolaan tipe shift dan kualifikasi, tukar shift, serah terima, dan pembacaan audit tercantum di §2–§6 desain. Kontrak untuk perubahan tipe shift dan pengelolaan kualifikasi menyusul. Data uji untuk AC-01–AC-03 pada Tahap 3 memerlukan departemen fiktif, Supervisor yang sesuai, dan tipe shift yang tersedia.

## Tukar shift

| Metode dan rute | Permintaan dan otorisasi | Respons berhasil |
| --- | --- | --- |
| GET /swaps | Admin seluruh organisasi. Supervisor hanya departemennya. Karyawan hanya sebagai pemohon atau karyawan tujuan di departemennya. Paginasi opsional. | 200 daftar permintaan terbaru |
| GET /swaps/{id} | Lingkup sama dengan daftar. | 200 permintaan dan events dari yang terlama |
| POST /swaps | Karyawan pemilik penugasan asal. Wajib source_assignment_id dan target_assignment_id yang berbeda, serta reason 1–1000 karakter. Kedua shift aktif, belum mulai, dan satu departemen. | 201 PENDING_PEER dan header Location |
| POST /swaps/{id}/accept | Karyawan tujuan pada PENDING_PEER. | 200 PENDING_APPROVAL |
| POST /swaps/{id}/peer-reject | Karyawan tujuan pada PENDING_PEER. | 200 REJECTED |
| POST /swaps/{id}/approve | Supervisor berwenang pada PENDING_APPROVAL. | 200 APPROVED dan kedua pemilik jadwal telah bertukar |
| POST /swaps/{id}/reject | Supervisor berwenang pada PENDING_APPROVAL. | 200 REJECTED |
| POST /swaps/{id}/cancel | Pemohon pada salah satu status menunggu. | 200 CANCELLED |

Rute keputusan menerima objek kosong atau tanpa isi. Respons permintaan memuat
ID peserta, penugasan dan departemen, alasan, status, tenggat, cap waktu
keputusan, serta terminal_reason bila sudah berakhir. Rincian menambahkan
riwayat perubahan status. Waktu dan pengenal mengikuti format umum di atas.
Status efektif di daftar dan rincian menjadi EXPIRED begitu tenggat tercapai.
Riwayat kedaluwarsa tersimpan saat pekerjaan berkala atau pemeriksaan berikutnya
menutup permintaan.

Galat mengikuti format umum. Referensi di luar lingkup memberi 404, tindakan
oleh pihak yang salah memberi 403, dan masukan yang tidak valid memberi 422.
Konflik 409 mencakup ASSIGNMENT_RESERVED, ASSIGNMENT_CHANGED, INVALID_TRANSITION,
SWAP_EXPIRED, NOTICE_TOO_SHORT, INELIGIBLE_EMPLOYEE, SHIFT_DURATION_LIMIT,
ASSIGNMENT_OVERLAP, MINIMUM_REST_VIOLATION, dan QUALIFICATION_REQUIRED.
Jika persetujuan gagal validasi definitif, respons 409 disertai perubahan
permintaan menjadi CANCELLED dan pelepasan reservasi. Penugasan tetap utuh.
Baca rincian permintaan untuk melihat status dan alasan terbaru.

## Serah terima dan audit

| Metode dan rute | Permintaan dan otorisasi | Respons berhasil |
| --- | --- | --- |
| GET /handovers | Admin organisasi, Supervisor departemen, atau Karyawan terkait. Draf hanya terlihat oleh Admin, Supervisor departemen, dan pemilik terkini shift keluar. Paginasi opsional. | 200 daftar laporan |
| GET /handovers/{id} | Lingkup sama dengan daftar. | 200 laporan beserta tugas |
| POST /handovers | Pemilik shift keluar mengirim `source_assignment_id` dan dapat menyertakan `recipient_assignment_id`, `summary`, serta `issues`. | 201 DRAFT dan header Location |
| PATCH /handovers/{id} | Pemilik shift keluar yang memegang draf dapat mengubah `summary`, `issues`, atau `recipient_assignment_id`. | 200 draf |
| POST /handovers/{id}/adopt | Pemilik baru shift keluar mengambil alih draf. | 200 draf |
| POST /handovers/{id}/submit | Pemilik shift keluar yang memegang draf merampungkan laporan. Pemilihan ulang `recipient_assignment_id` opsional. | 200 SUBMITTED |
| POST /handovers/{id}/acknowledge | Pemilik terkini shift penerima mengakui laporan sejak shiftnya mulai. | 200 ACKNOWLEDGED |
| POST /handovers/{id}/tasks | Pemilik draf menambah tugas dengan `title` dan `details` opsional. | 201 OPEN |
| PATCH /handovers/{id}/tasks/{taskId} | Pemilik draf mengubah judul atau detail. Setelah pengiriman, pemilik terkini shift penerima atau Supervisor departemen mengubah `status`. | 200 tugas |
| DELETE /handovers/{id}/tasks/{taskId} | Pemilik draf menghapus tugas. | 204 |
| GET /audit-logs | Admin organisasi atau Supervisor departemen. Paginasi opsional, diurutkan terbaru dahulu. | 200 daftar audit |

Perubahan laporan dan perubahan atau penghapusan tugas memerlukan `version`
terkini pada badan JSON. Laporan yang dikirim dapat dibaca oleh pemilik terkini
shift keluar atau penerima, pengirim, dan pengaku penerimaan di departemen
yang sama. Jika beberapa shift penerima memiliki waktu mulai paling awal yang
sama, sertakan ID salah satunya saat membuat draf.

Konflik 409 mencakup `HANDOVER_EXISTS`, `NO_RECIPIENT`,
`RECIPIENT_SELECTION_REQUIRED`, `INVALID_RECIPIENT`, `DRAFT_ADOPTION_REQUIRED`,
`RECIPIENT_SHIFT_NOT_STARTED`, `VERSION_CONFLICT`, dan `INVALID_TRANSITION`.
Pembatalan atau penjadwalan ulang shift yang terkait dengan laporan terkirim
memberi 409 `ASSIGNMENT_HAS_HANDOVER`. Audit menyimpan metadata perubahan
tanpa isi bebas laporan dan tugas.

## Pemetaan uji penerimaan

Jalankan pengujian API dan integrasi dengan PHPUnit dan PostgreSQL. Siapkan data uji terisolasi, waktu terkendali, serta koneksi dan transaksi PostgreSQL yang terpisah untuk menguji operasi serentak. Playwright dapat mencakup alur yang sama melalui antarmuka pada tahap berikutnya.

| SRS | Skenario dan hasil yang diharapkan |
| --- | --- |
| AC-01 | Admin membuat Karyawan dengan peran dan departemen valid (201). Keanggotaan tidak ada atau tidak valid memberi 422 atau 404 sesuai kasus, surel duplikat 409, dan upaya tulis Supervisor 403. Akun aktif dengan kredensial valid dapat masuk. Kredensial salah atau akun nonaktif memberi 401. Keluar membatalkan sesi. |
| AC-02 | Supervisor menerbitkan dua penugasan valid dalam lingkupnya (masing-masing 201). Uji tumpang tindih lewat tengah malam (409), interval yang bersentuhan saat minimum istirahat nol (201), istirahat terhadap shift aktif dan selesai, Karyawan nonaktif atau tanpa kualifikasi, referensi lintas departemen, dan peran yang salah. |
| AC-03 | Secara bawaan, Karyawan hanya melihat penugasannya. Tampilan tim mengembalikan data terbatas sesuai lingkup dan tidak membocorkan atribut pribadi. Detail lintas departemen memberi 404 dan permintaan anonim memberi 401. |
| AC-04 | Ajukan pertukaran dua arah untuk shift mendatang yang memenuhi syarat. Periksa PENDING_PEER, dua reservasi, dan tenggat. Uji pemilik yang salah, shift sudah mulai, pelanggaran tenggat pengajuan, pasangan lintas departemen, dan kualifikasi yang kurang. Jalankan dua pengajuan serentak dengan penugasan yang sama di sisi asal dan tujuan. Pastikan hanya satu tersimpan dan penugasan dapat digunakan kembali setelah kedaluwarsa. |
| AC-05 | Hanya karyawan tujuan yang dapat menerima atau menolak PENDING_PEER. Penolakan mempertahankan kedua pemilik dan versi serta melepas kedua reservasi. Pengulangan memberi 409 tanpa kejadian tambahan. Pembatalan pemohon dan kedaluwarsa memblokir keputusan selanjutnya. |
| AC-06 | Supervisor berwenang menyetujui permintaan yang diterima rekan. Periksa bahwa kedua pemilik, versi, keputusan, dan audit tersimpan bersama. Upaya Admin, Supervisor lintas departemen, atau persetujuan sebelum rekan menerima harus gagal. Uji perubahan kepemilikan oleh penjadwal dan persetujuan atas versi lama. |
| AC-07 | Ajukan permintaan valid, lalu ubah penugasan lain, kualifikasi, atau kebijakan istirahat sebelum persetujuan. Pelanggaran tumpang tindih, istirahat, atau kualifikasi menutup permintaan dengan kode alasan dan membiarkan jadwal utuh. Sertakan shift lewat tengah malam dan batas istirahat dengan shift yang sudah selesai. |
| AC-08 | Jalankan dua persetujuan serentak serta persetujuan bersamaan dengan penambahan atau perubahan jadwal. Verifikasi pertukaran hanya diterapkan sekali, jadwal akhir valid, dan reservasi, audit, serta versi konsisten. Picu kegagalan setelah perubahan pemilik pertama untuk menguji pembatalan transaksi. Uji pertukaran yang valid pada hasil akhir tetapi sementara menimbulkan tumpang tindih. |
| AC-09 | Pemilik shift keluar menyimpan draf dan mengirimnya ke penerima paling awal yang memenuhi syarat. Uji jeda, pengecualian tumpang tindih, beberapa calon dengan waktu mulai sama, tidak ada penerima, penulis yang tidak terkait, dan pasangan yang tidak lagi valid. Alihkan penugasan keluar. Pemilik lama ditolak dan pemilik baru harus mengadopsi draf dengan isi dan atribusi tetap utuh. Isi laporan yang dikirim tidak dapat ditimpa. |
| AC-10 | Tukar pemilik shift penerima sebelum pengakuan penerimaan. Pemilik lama dan karyawan yang tidak terkait ditolak. Pemilik baru berhasil sekali setelah shift dimulai. Uji batasan pembatalan, pengakuan terlambat, dan atribusi pelaku yang tetap setelah pengulangan atau pergantian pemilik. |
| AC-11 | Akui penerimaan laporan yang memiliki tugas OPEN dan IN_PROGRESS. Keduanya tetap pada status semula. Penerima yang berwenang atau Supervisor memperbarui tugas dengan audit. Versi lama dan pelaku tanpa izin ditolak. |
| AC-12 | Periksa pelaku, waktu, tindakan, hasil, dan referensi sebelum serta sesudah untuk penugasan, pertukaran, adopsi draf, pengakuan penerimaan, dan perubahan tugas. Verifikasi pembatalan transaksi, lingkup baca departemen, atribusi sistem saat kedaluwarsa, serta ketiadaan kata sandi, token, dan isi teks bebas. |

Ukur NFR-04 secara terpisah dengan kumpulan data dan beban uji lokal yang disepakati.
