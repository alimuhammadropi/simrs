# Notifikasi Firebase Cloud Messaging (FCM)

Fitur ini mengirim notifikasi push ke browser saat data baru masuk (contoh: pasien baru dan
pendaftaran baru). Polanya mengikuti implementasi Firebase pada proyek referensi: service
account untuk OAuth2, FCM HTTP v1, dan token perangkat yang disimpan di database.

Token utama disimpan di `users.fcm_token` (diisi saat login), sedangkan `device_tokens`
tetap dipakai sebagai daftar perangkat agar satu user bisa menerima notifikasi di beberapa
perangkat. Administrator bisa mereset token melalui menu **Pengguna → Reset token**.

## Komponen

| Komponen | Lokasi | Fungsi |
| --- | --- | --- |
| Pengirim FCM | `backend/components/FcmService.php` | OAuth2 JWT → access token → FCM v1 `messages:send` |
| Router notifikasi | `backend/components/NotificationService.php` | Memetakan event → unit kerja → pesan → menu tujuan |
| Konfigurasi rute | `backend/config/params.php` (`notificationRoutes`) | Daftar event, peran penerima, template pesan, dan `page` |
| Model user | `backend/models/User.php` | Kolom `fcm_token` sebagai token utama per user |
| Model token | `backend/models/DeviceToken.php` | Menyimpan token FCM per user/platform (multi-device) |
| Endpoint token | `backend/controllers/api/NotificationController.php` | Daftar, nonaktifkan, dan tes notifikasi |
| Reset admin | `backend/controllers/api/UserController.php` | `POST /api/users/<id>/reset-notification-token` |
| Hook pasien | `backend/controllers/api/PatientController.php` | `afterCreate()` → notifikasi `patient.created` |
| Hook pendaftaran | `backend/controllers/api/RegistrationController.php` | Notifikasi `registration.created` setelah commit |
| Frontend FCM | `frontend/src/firebase.js` | Izin browser, token, listener foreground |
| Service worker | `frontend/public/firebase-messaging-sw.js` | Notifikasi saat tab tidak aktif + klik notifikasi |
| UI | `frontend/src/App.jsx` | Tombol Notifikasi, panel riwayat, toast, petunjuk saat belum terkonfigurasi |

## Alur

```text
Halaman login (checkbox "Aktifkan notifikasi" hanya jika firebaseConfigured())
  → Notification.requestPermission()
  → service worker didaftarkan (/firebase-messaging-sw.js?config)
  → getToken(VAPID) → dikirim sebagai fcm_token pada POST /api/auth/login
  → token tersimpan di users.fcm_token dan device_tokens
  (Jika tidak terkonfigurasi → checkbox disembunyikan, login tetap sukses tanpa fcm_token)

Tombol "Notifikasi" di dalam aplikasi
  → getToken(VAPID) → POST /api/notifications/token
  → sinkronisasi users.fcm_token dan device_tokens (token terakhir jadi utama)

POST /api/patients, /api/registrations, /api/examinations, dll.
  → data tersimpan & audit
  → NotificationService::notify(event, context)
  → baca notificationRoutes[event] (roles/permissions, title, body, page)
  → cari user aktif pada peran tujuan (user yang memicu event dikecualikan)
  → gabungkan token users.fcm_token + device_tokens milik penerima
  → render template {placeholder} dari context
  → FcmService::sendAll(...) → FCM v1
  → audit notification.route + fcm.send (total/sent/failed)
  → klik notifikasi membuka /?page=<menu tujuan>
```

## Routing per event/unit kerja

Rute notifikasi dapat diubah di `backend/config/params.php` pada `notificationRoutes`
(tanpa mengubah controller). Format tiap entri: `roles`/`permissions`, `title`, `body`
(template `{placeholder}`), `page` (menu frontend), dan opsional `exclude_actor`.

| Event | Penerima | Menu tujuan |
| --- | --- | --- |
| `patient.created` | Pendaftaran, admin | Pendaftaran |
| `registration.created` | Perawat, dokter, admin | Pendaftaran |
| `registration.waiting` | Dokter, perawat, admin | Pemeriksaan |
| `examination.finalized` | Farmasi, lab, kasir, admin | Pemeriksaan |
| `prescription.created` | Farmasi, admin | Farmasi |
| `prescription.dispensed` | Kasir, admin | Tagihan |
| `lab.request.created` | Laboratorium, admin | Laboratorium |
| `lab.result` | Dokter, admin | Pemeriksaan |
| `invoice.created` | Kasir, admin | Tagihan |
| `invoice.paid` | Pendaftaran, admin | Pendaftaran |

> Catatan alur resep: `prescription.created` dipicu saat dokter menekan **Simpan resep**
> (`POST /prescriptions`), sedangkan `examination.finalized` dipicu saat **Finalisasi**
> (`POST /examinations/<id>/finalize`). Keduanya dikirim ke farmasi dengan judul berbeda.
> `PUT /prescriptions/<id>` tidak memicu notifikasi baru agar tidak terjadi duplikat.

Contoh mengubah penerima/menyontek teks:

```php
'patient.created' => [
    'roles' => ['registration', 'admin'],
    'title' => 'Pasien baru',
    'body' => '{patient_name} · {medical_record_number} — perlu dibuatkan pendaftaran.',
    'page' => 'registrations',
],
```

## Konfigurasi backend

Tambahkan ke environment sebelum menjalankan Yii (Yii tidak memuat `.env` otomatis):

```bash
export FIREBASE_PROJECT_ID=your-project-id
export FIREBASE_SERVICE_ACCOUNT_JSON=/secure/path/service-account.json
export FCM_TIMEOUT=10
```

Jika variabel di atas atau file service account tidak ada (misalnya hasil `git clone`),
`FcmService::enabled()` bernilai `false`. `send()` hanya mengembalikan error, `sendAll()`
mencatat audit `fcm.send` dengan `sent: 0`, dan transaksi bisnis tetap berhasil. Service
account dengan private key rusak juga ditangkap sebagai `fcm.token.failed`, bukan error 500.

`FIREBASE_SERVICE_ACCOUNT_JSON` dapat berupa path absolut ke file atau JSON service account langsung.
Jangan pernah menyimpan private key di repository. Access token OAuth2 disimpan sementara
di `backend/runtime/fcm-access-token.json` dengan mode `0600` dan diperbarui sebelum
kedaluwarsa.

Karena Yii dijalankan lewat PHP-FPM, variabel harus tersedia di proses FPM. Pada server ini
sudah ditambahkan `fastcgi_param FIREBASE_PROJECT_ID`, `FIREBASE_SERVICE_ACCOUNT_JSON`, dan
`FCM_TIMEOUT` di vhost nginx `simrs_demo.com` (bagian `location ^~ /api/`). `params.php`
juga membaca `$_SERVER` sebagai fallback. File service account harus bisa dibaca user FPM
(`www:www`, mode `0600`) dan sudah masuk `.gitignore`.

Jalankan migrasi:

```bash
cd backend && php yii migrate/up
```

## Konfigurasi frontend

Isi `frontend/.env` (lihat `frontend/.env.example`):

```env
VITE_FIREBASE_API_KEY=...
VITE_FIREBASE_AUTH_DOMAIN=your-project.firebaseapp.com
VITE_FIREBASE_PROJECT_ID=your-project-id
VITE_FIREBASE_MESSAGING_SENDER_ID=000000000000
VITE_FIREBASE_APP_ID=1:000000000000:web:...
VITE_FIREBASE_VAPID_KEY=...
```

Lalu jalankan ulang `npm run dev` / build ulang `npm run build`.

Tanpa kelima variabel tersebut, `firebaseConfigured()` bernilai `false`: checkbox
**Aktifkan notifikasi di browser ini** disembunyikan saat login dan tombol **Notifikasi**
hanya menampilkan petunjuk konfigurasi. Login, build, dan transaksi tetap berjalan normal.

## Endpoint

| Method | Endpoint | Keterangan |
| --- | --- | --- |
| POST | `/api/notifications/token` | Simpan/perbarui token aktif. Body: `{token, platform}` (`web`/`android`/`ios`) |
| POST | `/api/notifications/token/delete` | Nonaktifkan token milik user login. Body: `{token}` |
| POST | `/api/notifications/test` | Kirim notifikasi tes ke token user login |
| POST | `/api/users/<id>/reset-notification-token` | Admin menghapus `users.fcm_token` dan menonaktifkan semua token user (butuh `user.manage`) |

Semua endpoint memerlukan `Authorization: Bearer <JWT>`, kecuali `/api/auth/login`.
Login dapat menerima field tambahan `fcm_token` yang diambil dari browser saat halaman login.

## Penyimpanan token & reset admin

- `FcmService::registerToken()` menyimpan token ke `users.fcm_token` sekaligus `device_tokens`.
  Jika token yang sama dipakai user lain, token lama di `users` dikosongkan agar tidak bentrok.
- `FcmService::tokensForUser()` menggabungkan token utama di `users` dan token aktif di
  `device_tokens`, lalu melakukan deduplikasi sebelum mengirim.
- Tombol **Reset token** di menu Pengguna memanggil endpoint reset: kolom `users.fcm_token`
  dikosongkan dan seluruh baris `device_tokens` milik user dinonaktifkan. User harus login
  ulang (atau klik Notifikasi) untuk mendapatkan token baru.
- Kolom `fcm_token_set` pada response user menandakan apakah token utama masih tersimpan.

## Perilaku kegagalan

Pengiriman bersifat **best-effort**: kegagalan FCM tidak membatalkan transaksi pasien atau
pendaftaran. Detail kegagalan ditulis ke `audit_logs` dengan action `fcm.send.failed`,
`fcm.token.failed`, atau ringkasan `fcm.send`. Rute yang dipilih dicatat sebagai
`notification.route` (event, jumlah penerima, total/sent/failed). Jika Firebase belum dikonfigurasi, endpoint
tetap sukses dengan `sent: 0, failed: <jumlah token>` agar alur bisnis tidak terhenti.

## Uji notifikasi saat tab/Chrome tidak aktif

1. Buka aplikasi di Chrome, login dengan checkbox **Aktifkan notifikasi di browser ini** centang
   (atau klik **Notifikasi** setelah masuk), izinkan. Pastikan status `aktif` dan service worker
   `/firebase-messaging-sw.js` terdaftar di *DevTools → Application → Service Workers*.
2. Tutup tab SIMRS (biarkan proses Chrome tetap jalan; di *Chrome Settings → System* aktifkan
   *Continue running background apps when Google Chrome is closed* jika ingin menutup jendela Chrome).
3. Dari terminal/perangkat lain, trigger notifikasi:

```bash
TOKEN=$(curl -s http://127.0.0.1:8086/api/auth/login -X POST -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"Admin123!"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["token"])')
curl -s -X POST http://127.0.0.1:8086/api/notifications/test \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{}'
```

Notifikasi harus tetap muncul lewat `onBackgroundMessage` di service worker meski tab sudah
ditutup. Klik notifikasi akan membuka `/?page=<menu tujuan>` dan `App.jsx` langsung memilih menu
tersebut (atau Dashboard jika user tidak punya permission). Jika Chrome benar-benar di-*quit*
(proses mati), Web Push tidak bisa dikirimkan; yang bisa diverifikasi adalah audit `fcm.send`
menunjukkan `sent: 1` (FCM menerima pesan). Penanganan foreground (`onMessage` → toast di UI)
hanya berjalan saat tab masih terbuka.

## Catatan produksi

- Gunakan HTTPS; service worker dan Web Push tidak bekerja pada HTTP non-localhost.
- Batasi rotasi token dan bersihkan token lama/invalid secara berkala.
- Simpan service account di secret manager, bukan di document root atau Git.
- Pertimbangkan queue (Redis/RabbitMQ) jika volume notifikasi besar agar request API tidak
  menunggu FCM.
