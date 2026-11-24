# Kontrak API

## Format umum

Sukses:
```json
{ "success": true, "message": "OK", "data": [] }
```

Error Yii:
```json
{ "name": "Bad Request", "message": "Pesan error", "status": 400 }
```

Error Node:
```json
{ "success": false, "message": "Pesan error", "details": {} }
```

Semua endpoint Yii kecuali `POST /api/auth/login` memerlukan:
```
Authorization: Bearer <token>
```

`POST /api/auth/login` mengembalikan `data.token`, `data.user`, `data.user.permissions`,
dan `data.user.roles`.

## Permission

| Permission | Digunakan oleh |
| --- | --- |
| `patient.view` | semua peran klinis/operasional |
| `patient.create` | admin, registration |
| `registration.view` | admin, registration, doctor, nurse, management, laboratory, cashier |
| `registration.create` | admin, registration |
| `master.view` | semua peran (lookup master; menu kelola hanya admin lewat `master.manage`) |
| `master.manage` | admin |
| `examination.view` | admin, doctor, nurse |
| `examination.create` | admin, doctor |
| `prescription.create` | admin, doctor |
| `pharmacy.view` | admin, pharmacy |
| `pharmacy.manage` | admin, pharmacy |
| `lab.request` | admin, doctor |
| `lab.view` | admin, doctor, laboratory |
| `lab.manage` | admin, laboratory |
| `billing.view` | admin, cashier |
| `billing.manage` | admin, cashier |
| `report.view` | admin, management, cashier |
| `user.manage` | admin |

## Endpoint Yii

### Auth
- `POST /api/auth/login` — body `{username,password,fcm_token?}`; `fcm_token` diisi token FCM
  dari browser saat login dan disimpan ke `users.fcm_token` + `device_tokens`
- `GET /api/auth/me` — profil + roles + permissions

### Master (umum: `GET` list `?q=...&all=1&limit=100`, `POST` create, `GET/PUT/DELETE /<id>`)
- `/api/patients` — search `q`, `all=1`; `GET /api/patients/<id>/visits`; `GET /api/patients/<id>/medications` (riwayat obat: resep + item, status, dokter, dan petugas serah)
- `/api/polyclinics` — data poli / unit pelayanan
- `/api/doctors`
- `/api/schedules` — filter `doctor_id`, `polyclinic_id`
- `/api/medical-actions`
- `/api/drugs` — `stock` = stok master, `reserved_stock` = total qty resep `pending`,
  `available_stock` = `max(0, stock - reserved_stock)`; dipakai UI resep sebagai batas jumlah
- `/api/diagnoses`
- `/api/medical-staff`
- `/api/lab-tests`

Contoh create pasien:
```json
{ "name": "Siti Aminah", "nik": "3201...", "gender": "P", "birth_date": "1992-04-10" }
```
`medical_record_number` dibuat otomatis.

Contoh create jadwal:
```json
{ "doctor_id": 1, "polyclinic_id": 1, "day_of_week": 1, "start_time": "08:00", "end_time": "12:00" }
```
`day_of_week`: `0` = Minggu, `1` = Senin, …, `6` = Sabtu. Jadwal seed tersedia untuk ketujuh hari.

### Registrasi
- `POST /api/registrations`
```json
{
  "patient_id": 2,
  "polyclinic_id": 1,
  "doctor_id": 1,
  "registration_date": "2026-11-22",
  "schedule_id": 7,
  "idempotency_key": "uuid-v4"
}
```
`registration_number` memakai sequence global per tanggal (`RJ{YYYYMMDD}{seq:04d}`)
sehingga unik lintas poliklinik; `queue_number` tetap unik per poli dan tanggal.
- `GET /api/registrations` — filter `date`, `polyclinic_id`, `patient_id`, `status`, `page`, `per_page`
- `GET /api/registrations/queue?date=2026-11-22&polyclinic_id=1`
- `POST /api/registrations/<id>/status` — body `{"status":"waiting"}`; petugas hanya boleh
  `waiting`/`cancelled`. `in_exam` butuh permission `examination.create` (dokter/admin,
  tetapi normalnya otomatis saat EMR dibuat); `completed` **ditolak** di endpoint ini.
- `GET|POST /api/registrations/<registration_id>/actions`

Transisi status: `registered → waiting` (petugas), `waiting → in_exam` (dokter saat membuat
EMR), `in_exam → completed` (dokter saat finalisasi EMR via
`POST /examinations/<id>/finalize`). `cancelled` tersedia dari `registered`/`waiting`/`in_exam`.
Transisi tidak valid → `400`; `in_exam` tanpa permission dokter → `403`.

### Pemeriksaan
- `GET /api/examinations/waiting?date=...&polyclinic_id=...`
- `GET /api/examinations` — filter `status`, `patient_id`
- `POST /api/examinations`
```json
{
  "registration_id": 1,
  "subjective": "Demam 2 hari",
  "objective": "Keadaan umum baik",
  "physical_exam": "Paru vesikuler",
  "assessment": "J00",
  "plan": "Istirahat",
  "vital_signs": { "temperature": "38.0", "pulse": "88" },
  "diagnoses": [{ "diagnosis_id": 1, "is_primary": true }]
}
```
- `GET|PUT /api/examinations/<id>` — `GET` juga mengembalikan `actions`, `prescriptions`
  beserta `items`-nya, dan `lab_requests` lengkap dengan `result`, `reference_range`,
  `result_at`, dan `result_by` dari `lab_results`
- `POST /api/examinations/<id>/finalize` — EMR `final`, kunjungan `completed`, dan setelah ini
  resep tidak bisa dibuat/diubah

### Tindakan
- `POST /api/registrations/<registration_id>/actions`
```json
{ "medical_action_id": 1, "quantity": 1 }
```
- `PUT|DELETE /api/visit-actions/<id>`

### Farmasi dan resep dokter
- `GET|POST /api/prescriptions`
```json
{
  "registration_id": 1,
  "examination_id": 1,
  "notes": "Diminum setelah makan",
  "items": [{ "drug_id": 1, "dose": "1 tablet", "frequency": "3x hari", "quantity": 3 }]
}
```
- `PUT|PATCH /api/prescriptions/<id>` — perbarui resep `pending` milik dokter pembuat;
  body sama seperti `POST`. Menolak jika pemeriksaan sudah `final` atau status resep
  bukan `pending` (`400`).
- `GET /api/examinations/<id>` dipakai dokter untuk memuat ulang item resep tersimpan.
- `POST /api/prescriptions/<id>/dispense`
- `POST /api/prescriptions/<id>/cancel`

Batas jumlah obat mengikuti `available_stock` dari `/api/drugs`, bukan hanya `stock`.
`POST`/`PUT` resep mengunci baris obat dengan `SELECT ... FOR UPDATE`, menjumlahkan semua
resep `pending` (kecuali resep yang sedang diubah), lalu menolak `400` bila
`qty > available_stock`. Dengan demikian beberapa dokter tidak bisa saling menghabiskan
stok yang sama secara bersamaan; stok fisik tetap baru berkurang saat `dispense`.

### Laboratorium
- `GET|POST /api/lab/requests`
```json
{ "registration_id": 1, "lab_test_id": 1, "notes": "Routine" }
```
- `GET /api/lab/requests/<id>`
- `POST /api/lab/requests/<id>/start`
- `POST /api/lab/requests/<id>/result` — body `{"result":"Hb 13 g/dL","reference_range":"12-16"}`

### Billing
- `GET|POST /api/invoices` — `POST` body `{"registration_id":1}`
- `GET /api/invoices/<id>` — invoice + items + payments
- `POST /api/invoices/<id>/pay`
```json
{
  "amount": 150000,
  "method": "cash",
  "reference_no": "TRX-001",
  "idempotency_key": "uuid-v4"
}
```
  `amount` boleh lebih besar dari sisa tagihan. Response `data.payment.tendered_amount` berisi
  uang yang diterima kasir, `data.payment.amount` berisi nominal yang benar-benar dipakai
  (maksimal sisa), sedangkan `data.payment.change_amount` berisi kembalian. Tagihan yang sudah
  lunas menolak pembayaran baru dengan `400`.
- `GET /api/invoices/<id>/print?token=<jwt>` — HTML cetak; token juga boleh di header;
  riwayat pembayaran menampilkan kolom **Uang diterima**, **Diterapkan**, dan **Kembalian**

### Laporan & pengguna
- `GET /api/reports/dashboard?from=2026-11-01&to=2026-11-30`
- `GET /api/reports/payments?from=...&to=...`
- `GET|POST /api/users`, `GET|PUT /api/users/<id>`, `DELETE /api/users/<id>`
- `POST /api/users/<id>/reset-notification-token` — reset `users.fcm_token` + nonaktifkan semua
  token perangkat user (butuh `user.manage`)

### Internal (dipanggil Node, header `x-service-key`)
- `GET /api/internal/lab/requests`
- `POST /api/internal/lab/results`
- `GET /api/internal/reports/dashboard`

## Endpoint Node

- `GET /services/api/v1/lab/requests?status=requested`
- `POST /services/api/v1/lab/results`
- `GET /services/api/v1/dashboard/summary?from=...&to=...`
- `POST /services/internal/notifications/queue` (internal, `x-service-key`)
- Monitoring endpoint tersedia di menu admin **Integrasi Penunjang** (`frontend/src/pages/Integration.jsx`): status Node/Yii, daftar endpoint, contoh payload, dan permintaan lab terbaru via Node. Hanya peran dengan `master.manage` yang melihat menu ini; URL layanan diambil dari `VITE_SERVICES_URL` (`frontend/.env`).
