# Arsitektur SIMRS Rawat Jalan

Untuk diagram alur langkah demi langkah, lihat [`FLOW.md`](FLOW.md).

## Komponen

```
Browser (React/Vite)
   │  /api/**              ▲ JSON
   ▼                       │
nginx :8086 ──> Yii2 REST API ──> PostgreSQL
   │                  ▲  │
   │ /services/**     │  │ x-service-key
   ▼                  │  ▼
Node/Express :3010 ───┘  (hanya fasade/aggregator)
```

- **React** menampilkan UI dan menyimpan JWT di `localStorage`. Semua mutasi klinis
  dikirim ke Yii.
- **Yii2** memegang schema, validasi, transaksi, RBAC, perhitungan tagihan, stok, audit,
  dan integrasi keluar ke Node.
- **Node/Express** menyediakan endpoint pendukung untuk laboratorium/dashboard eksternal
  dan menerima notifikasi antrean. Semua data berasal dari REST internal Yii.
- **PostgreSQL** menyimpan data transaksional, counter antrean, dan audit log.

## Alur utama

### Pendaftaran
1. Frontend kirim `POST /api/registrations` dengan `idempotency_key` (UUID).
2. Yii cek replay key, lalu ambil `pg_advisory_xact_lock` untuk `regno:{tanggal}`
   dan kombinasi pasien + poli + tanggal.
3. Cek duplikat, validitas pasien/dokter/poli, relasi dokter-poli, dan jadwal praktik.
4. `registration_counters` dinaikkan untuk `registration_number` global harian,
   lalu `queue_counters` dinaikkan untuk nomor antrean per poli; keduanya memakai
   `UPDATE ... RETURNING` dalam transaksi yang sama.
5. Registrasi tersimpan, audit ditulis, transaksi di-commit.
6. NodeClient mengirim notifikasi antrean secara best-effort; kegagalan dicatat sebagai
   `node.integration.failed` tetapi tidak membatalkan pendaftaran.
7. `NotificationService` mengirim FCM bila Firebase terkonfigurasi. Tanpa kredensial,
   transaksi tetap sukses dan audit `fcm.send` mencatat `sent: 0`.

### Pemeriksaan
1. Petugas menaikkan status menjadi `waiting`.
2. Dokter membuat pemeriksaan SOAP + vital signs + diagnosis.
3. Yii memastikan status kunjungan `waiting`/`in_exam`, menulis `examination_diagnoses`,
   mengubah status menjadi `in_exam`, dan mencatat audit.
4. Finalisasi mengubah status menjadi `final` dan kunjungan menjadi `completed`.
5. Perubahan setelah final wajib menyertakan `addendum_reason`; versi dan audit naik.

### Farmasi
1. Dokter membuat resep dengan item, dosis, frekuensi, dan quantity **sebelum** finalisasi.
2. Klik **Buat resep** lagi memuat item/catatan resep `pending` dari `GET /examinations/<id>`;
   tombol berubah menjadi **Perbarui resep** dan `PUT /prescriptions/<id>` memperbarui data yang sama.
3. Yii menolak create/update jika pemeriksaan sudah `final` atau status resep bukan `pending`,
   dan memvalidasi stok saat resep dibuat/diubah tetapi **belum** menguranginya.
4. Penyerahan (`dispense`) menggunakan `SELECT ... FOR UPDATE` pada resep,
   `UPDATE drugs SET stock = stock - q WHERE stock >= q RETURNING stock`, dan
   `stock_movements` dengan `reason = dispense` dalam satu transaksi.
5. Jika invoice sudah ada, tagihan dihitung ulang otomatis.

### Laboratorium
1. Dokter membuat `lab_requests` dengan `lab_test_id` atau nama manual + `price_snapshot`.
2. Petugas lab memulai pemeriksaan lalu mengisi hasil.
3. Hasil dapat dikirim langsung ke Yii atau melalui Node
   (`POST /services/api/v1/lab/results` → `/api/internal/lab/results`).
4. Node meneruskan `created_by` (user Yii); Yii yang menyimpan hasil, mengubah status,
   menulis audit, dan rebuild invoice.

### Billing
1. `BillingService::ensureInvoice()` membuat invoice dari registration fee.
2. `rebuild()` menyusun ulang `invoice_items` dari pendaftaran, tindakan, resep yang
   sudah diserahkan, dan permintaan lab; harga diambil dari `price_snapshot`.
3. `pay(amount, method, idempotency_key)` memakai lock invoice, menolak nominal melebihi
   sisa, menyimpan payment dengan nomor unik, dan mengubah status invoice.
4. Payment dengan idempotency key yang sama mengembalikan payment lama (`replayed: true`).

## Cross-cutting concerns

- **Autentikasi**: JWT HS256, `sub` = user id. Middleware `ApiController::beforeAction()`.
- **Otorisasi**: `requirePermission()` memeriksa relasi user → role → permission.
- **Audit**: `AuditLog::record()` menyimpan actor, aksi, entitas, before/after JSONB, IP,
  dan user agent.
- **Idempotensi**: registration dan payment memakai `idempotency_key` unik.
- **Konkurensi**: advisory lock untuk antrean, `FOR UPDATE` untuk resep/invoice,
  constraint unik untuk kode/no registrasi/no invoice/no payment.
- **CORS**: same-host otomatis diizinkan; daftar tambahan dari `CORS_ORIGIN`.
- **Error**: Yii mengembalikan `{name,message,code,status,type}`; Node mengembalikan
  `{success:false,message,details}`.
- **Respons sukses**: `{success:true,message,data}`.

## Deployment pada lingkungan demo

- nginx listen `8086`, root `frontend/dist`.
- `/api/` → `backend/web/index.php` lewat PHP-FPM.
- `/services/` → `http://127.0.0.1:3010/` (path prefix dibuang).
- Node memanggil Yii lewat `YII_API_URL=http://127.0.0.1:8086/api`.
