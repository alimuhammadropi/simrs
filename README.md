# SIMRS Rawat Jalan
 
Sistem informasi manajemen rawat jalan berbasis web dengan tiga komponen:

- **Frontend**: React + Vite + Tailwind CSS
- **Backend transaksional**: Yii2 Basic REST API + JWT + PostgreSQL
- **Layanan pendukung**: Node.js + Express (integrasi laboratorium, dashboard, notifikasi antrean)

Yii2 adalah satu-satunya pemilik aturan bisnis, transaksi database, RBAC, perhitungan
tarif, stok, dan audit. Node hanya menjadi fasade/aggregator dan tidak menulis langsung
ke database klinis. React mengonsumsi API Yii untuk transaksi dan API Node untuk data
pendukung.

## Fitur yang tersedia

- Master data: poliklinik/unit pelayanan, dokter, jadwal praktik (validasi bentrok),
  tindakan & tarif, obat & stok, diagnosis, tenaga medis, jenis pemeriksaan laboratorium;
  UI **Data master** menyediakan pencarian teks dan filter status Aktif/Nonaktif.
- Pendaftaran: idempotensi, pencegahan duplikasi, advisory lock PostgreSQL, antrian per
  poli per tanggal, status `registered → waiting → in_exam → completed` (`completed` hanya
  lewat finalisasi EMR oleh dokter), notifikasi Node.
- Rekam medis: SOAP, physical exam, vital signs, diagnosis dengan ICD code, draft/final,
  addendum dengan alasan dan audit perubahan, riwayat kunjungan pasien.
- Tindakan kunjungan dengan snapshot tarif; perubahan harga master tidak mengubah tagihan lama.
- Farmasi: resep dibuat dokter sebelum finalisasi, bisa dimuat ulang dan diperbarui,
  snapshot harga, validasi stok, penyerahan obat atomik, pembatalan, dan pencatatan
  `stock_movements`.
- Laboratorium: permintaan, status `requested → in_progress → completed`, hasil + nilai
  rujukan, input hasil langsung via Yii atau melalui API Node, dan hasil terbaca kembali
  pada detail riwayat kunjungan dokter.
- Billing: invoice otomatis (pendaftaran, tindakan, resep, lab), rebuild tagihan saat
  ada perubahan, pembayaran parsial/cicilan, uang lebih dicatat sebagai kembalian,
  idempotensi pembayaran, lock `FOR UPDATE`, status `unpaid/partial/paid`, dan cetak
  invoice HTML.
- Dashboard & laporan: kunjungan per poli, tindakan, lab, pendapatan, status tagihan,
  penggunaan obat, rincian pembayaran, ekspor CSV.
- RBAC: `admin`, `registration`, `doctor`, `nurse`, `management`, `pharmacy`,
  `laboratory`, `cashier` dengan permission granuler; menu Tagihan hanya `admin`/`kasir`,
  menu Data master hanya `admin` (baca master tetap tersedia untuk lookup).
- Audit log untuk perubahan master, registrasi, pemeriksaan, resep, lab, pembayaran, dan
  kegagalan integrasi Node.
- Notifikasi push Firebase Cloud Messaging (FCM, opsional): token per pengguna, izin browser via
  tombol **Notifikasi**, notifikasi saat pasien/pendaftaran baru masuk, serta endpoint tes.

## Prasyarat

- PHP 8.1+ dengan ekstensi `pdo_pgsql`, `openssl`, `mbstring`
- Composer
- Node.js 20+
- PostgreSQL 14+
- nginx + PHP-FPM untuk deployment demo (port `8086` pada lingkungan ini)

## 1. Konfigurasi lingkungan

Backend Yii tidak memuat `.env` otomatis. Export variabel ke shell/pool FPM atau ubah
default di `backend/config/params.php` dan `backend/config/db.php`.

```bash
export DB_DSN='pgsql:host=127.0.0.1;port=5432;dbname=simrs_demo'
export DB_USER='<db_user>'
export DB_PASSWORD='<db_password>'
export JWT_SECRET='<rahasia-acak-minimal-32-karakter>'
export JWT_ISSUER='simrs-api'
export JWT_TTL=3600
export CORS_ORIGIN='http://localhost:5173,http://localhost:5174,http://localhost:8086,https://demo.mr-ali.my.id'
export COOKIE_KEY='<rahasia-acak>'
export NODE_SERVICE_URL='http://127.0.0.1:3010'
export NODE_SERVICE_KEY='<kunci-layanan-internal>'
```

`CORS_ORIGIN` adalah daftar origin yang dipisahkan koma. Origin dengan host yang sama
dengan host request selalu diizinkan; selain itu harus tercantum, atau gunakan `*` untuk
mengizinkan semua.

## 2. Database

```bash
createdb simrs_demo
cd backend
composer install
php yii migrate --interactive=0
php yii seed/init
```

`seed/init` bersifat **idempoten**: aman dijalankan ulang. Seed membuat peran, permission,
data master, jadwal, serta akun demo berikut (password `Admin123!`):

| Username | Peran |
| --- | --- |
| `admin` | admin |
| `petugas` | registration |
| `dokter` | doctor |
| `farmasi` | pharmacy |
| `lab` | laboratory |
| `kasir` | cashier |

Ganti password demo sebelum penggunaan nyata.

## 3. Backend Yii2

Development:

```bash
cd backend
php yii serve --docroot=web --port=8080
```

Deployment demo memakai nginx yang menyajikan `frontend/dist` dan meneruskan `^~ /api/`
ke `backend/web/index.php` melalui PHP-FPM (listen `8086`).

## 4. Frontend

Produksi/demo memakai URL relatif agar request mengikuti host halaman:

```bash
cd frontend
cp .env.example .env   # VITE_API_URL=/api
npm install
npm run build
```

Untuk Vite dev server, biarkan `VITE_API_URL=/api` dan `VITE_SERVICES_URL=/services/api/v1`.
Vite mem-proxy `/api` ke `VITE_API_PROXY_TARGET` (default `http://127.0.0.1:8086`) dan
`/services` ke `VITE_SERVICES_PROXY_TARGET` (default `http://127.0.0.1:3010`). Jika port
berbeda, ubah target terkait lalu jalankan `npm run dev`. Dengan proxy ini browser tetap
mengakses `localhost:5173`, sehingga `CORS_ORIGIN` tidak perlu menambahkan origin Vite.

## 5. Node services

```bash
cd services
cp .env.example .env
npm install
npm run dev
```

Konfigurasi penting: `PORT`, `NODE_SERVICE_KEY`, `CORS_ORIGIN`, dan
`YII_API_URL=http://127.0.0.1:8086/api`. Endpoint publik:

- `GET /health`
- `GET /api/v1/lab/requests`
- `POST /api/v1/lab/results`
- `GET /api/v1/dashboard/summary`

Endpoint internal `POST /internal/notifications/queue` memerlukan header
`x-service-key` yang sama dengan `NODE_SERVICE_KEY`.

## 6. Notifikasi Firebase (FCM)

Siapkan project di Firebase Console: daftarkan web app, aktifkan **Cloud Messaging**, buat
**Web Push certificates** (VAPID), dan unduh service account JSON dari *Project settings →
Service accounts*. Jangan commit service account tersebut.

Notifikasi bersifat **opsional**. Hasil clone tanpa `frontend/.env`, `FIREBASE_*`, atau service
account tetap bisa login, build, dan menjalankan seluruh transaksi; opsi notifikasi
disembunyikan saat login dan tombol **Notifikasi** hanya menampilkan petunjuk konfigurasi.
Push tidak dikirim, audit `fcm.send` mencatat `sent: 0`, dan tidak ada error aplikasi.

Backend (export sebelum menjalankan Yii, karena Yii tidak memuat `.env` otomatis):

```bash
export FIREBASE_PROJECT_ID=your-project-id
export FIREBASE_SERVICE_ACCOUNT_JSON=/secure/path/service-account.json
export FCM_TIMEOUT=10
cd backend && php yii migrate/up
```

`FIREBASE_SERVICE_ACCOUNT_JSON` juga boleh berisi JSON service account secara langsung.
Frontend: salin `frontend/.env.example` ke `frontend/.env`, isi `VITE_FIREBASE_API_KEY`,
`VITE_FIREBASE_AUTH_DOMAIN`, `VITE_FIREBASE_PROJECT_ID`,
`VITE_FIREBASE_MESSAGING_SENDER_ID`, `VITE_FIREBASE_APP_ID`, dan
`VITE_FIREBASE_VAPID_KEY`, lalu jalankan ulang Vite.

Pengguna membuka aplikasi, menekan tombol **Notifikasi**, dan mengizinkan notifikasi.
Token FCM disimpan ke tabel `device_tokens` melalui `POST /api/notifications/token`.
Saat `POST /api/patients` atau `POST /api/registrations` berhasil, Yii mengirim FCM v1
ke seluruh token aktif pengguna yang memiliki permission terkait. Untuk mencoba tanpa
menambah data, gunakan `POST /api/notifications/test`.

Detail lengkap ada di [`docs/NOTIFICATIONS.md`](docs/NOTIFICATIONS.md).

## API utama

Semua endpoint Yii berada di `/api` dan (kecuali login) memerlukan
`Authorization: Bearer <token>`.

- Auth: `POST /auth/login`, `GET /auth/me`
- Master: `/patients`, `/polyclinics`, `/doctors`, `/schedules`, `/medical-actions`,
  `/drugs`, `/diagnoses`, `/medical-staff`, `/lab-tests`
- Registrasi & antrean: `/registrations`, `/registrations/queue`,
  `/registrations/<id>/status`, `/registrations/<id>/actions`
- Pemeriksaan: `/examinations`, `/examinations/waiting`, `/examinations/<id>`,
  `/examinations/<id>/finalize`
- Farmasi/resep dokter: `/prescriptions`, `/prescriptions/<id>` (PUT, sebelum final),
  `/prescriptions/<id>/dispense`, `/prescriptions/<id>/cancel`
- Lab: `/lab/requests`, `/lab/requests/<id>/start`, `/lab/requests/<id>/result`
- Billing: `/invoices`, `/invoices/<id>`, `/invoices/<id>/pay`, `/invoices/<id>/print`
- Laporan: `/reports/dashboard`, `/reports/payments`
- Pengguna: `/users`
- Internal Yii ← Node: `/internal/lab/requests`, `/internal/lab/results`,
  `/internal/reports/dashboard`

Detail lengkap ada di [`docs/API.md`](docs/API.md).

## Verifikasi

```bash
bash scripts/smoke.sh http://127.0.0.1:8086/api
```

Skrip smoke mencakup login, pasien, pendaftaran, pemeriksaan, tindakan, resep, lab, billing,
pembayaran, idempotensi, dan dashboard. Skrip ini **menulis data uji ke database**, jadi
jalankan hanya di environment demo/dev. Skenario manual ada di
[`docs/TEST-CASES.md`](docs/TEST-CASES.md).

Koleksi Postman `postman/SIMRS.postman_collection.json` berisi 26 request: alur Yii,
idempotensi registrasi/pembayaran (04b, 13b), kembalian saat bayar lebih (13c), hasil lab
di riwayat kunjungan (11b), dan endpoint layanan Node (19–22). Impor koleksi lalu jalankan
01–22 berurutan lewat Collection Runner dengan `baseUrl` mengarah ke nginx dan `nodeUrl`
ke layanan Node.

## Dokumentasi

- [`docs/FLOW.md`](docs/FLOW.md) — diagram alur pengguna, status, dan alur teknis lengkap
- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — komponen, alur data, dan tanggung jawab
- [`docs/API.md`](docs/API.md) — kontrak REST, permission, dan contoh request
- [`docs/ERD.md`](docs/ERD.md) — tabel dan relasi utama
- [`docs/DECISIONS.md`](docs/DECISIONS.md) — keputusan teknis dan trade-off
- [`docs/TEST-CASES.md`](docs/TEST-CASES.md) — Skenario fungsional, konkurensi, dan integrasi
- [`docs/NOTIFICATIONS.md`](docs/NOTIFICATIONS.md) — Alur, konfigurasi, dan endpoint FCM
- [`postman/SIMRS.postman_collection.json`](postman/SIMRS.postman_collection.json) — koleksi Postman

## Catatan keamanan

Proyek ini ditujukan untuk pengembangan dan pengujian. Sebelum operasional nyata:
aktifkan HTTPS, gunakan secret manager, rate limiting, refresh/revocation token, backup
dan uji restore, audit append-only, retensi data, enkripsi backup, hardening server, uji
beban, pengawasan klinis, dan review keamanan. Matikan `YII_DEBUG`, hapus kredensial
demo, dan ganti semua nilai fallback di konfigurasi sebelum produksi.
