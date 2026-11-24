# Alur Proses SIMRS Rawat Jalan

Dokumen ini menjelaskan **alur pengguna dan alur teknis** sistem dari login hingga
laporan. Diagram menggunakan [Mermaid](https://mermaid.js.org/), sehingga bisa langsung
dilihat di GitHub, GitLab, atau editor Markdown yang mendukung Mermaid.

Untuk kontrak request/response lihat [`API.md`](API.md); untuk relasi tabel lihat
[`ERD.md`](ERD.md); untuk alasan desain lihat [`DECISIONS.md`](DECISIONS.md).

---

## 1. Peta alur keseluruhan

```mermaid
flowchart LR
  U["Petugas / Dokter / Farmasi / Lab / Kasir"] --> FE["Frontend React"]
  FE -->|"/api/** + JWT"| YII["Yii2 REST API"]
  FE -->|"/services/**"| NODE["Node Express"]
  NODE -->|"x-service-key"| YII
  YII --> DB[(PostgreSQL)]
  YII -.->|"notifikasi antrean best-effort"| NODE
  NODE -.->|"fasade lab & dashboard"| FE
```

Prinsip utama: **Yii2 memegang semua transaksi klinis**. Node hanya meneruskan/aggregasi
data dan tidak pernah menulis langsung ke database.

---

## 2. Alur login dan otorisasi

```mermaid
sequenceDiagram
  participant U as Pengguna
  participant FE as Frontend
  participant Y as Yii2 API
  participant DB as PostgreSQL

  U->>FE: Input username & password
  FE->>Y: POST /api/auth/login
  Y->>DB: Cek user aktif + password_hash
  alt Kredensial valid
    Y-->>FE: JWT + roles + permissions
    FE->>FE: Simpan token, filter menu sesuai permission
    FE-->>U: Dashboard tampil
  else Kredensial invalid
    Y-->>FE: 401 Username atau password salah
    FE-->>U: Tampilkan error
  end

  Note over FE,Y: Setiap request berikutnya
  FE->>Y: Authorization: Bearer <token>
  Y->>DB: Decode JWT, cek user + permission
  alt Diizinkan
    Y-->>FE: Data / mutasi berhasil
  else Ditolak
    Y-->>FE: 401 token invalid atau 403 tanpa permission
    FE->>FE: Hapus token, kembali ke login untuk 401
  end
```

---

## 3. Alur kunjungan lengkap (happy path)

```mermaid
flowchart TD
  A["Master data siap<br/>poli, dokter, jadwal, tarif, obat, lab"] --> B["Petugas: cari atau buat pasien"]
  B --> C["Petugas: registrasi<br/>POST /registrations + idempotency_key"]
  C --> D["Sistem: nomor antrean terbentuk<br/>status = registered"]
  D --> E["Petugas: ubah status waiting"]
  E --> F["Dokter: buat EMR<br/>SOAP + vital sign + diagnosis"]
  F --> G["Dokter: tambah tindakan, resep, permintaan lab<br/>(resep sebelum finalisasi)"]
  G --> H["Farmasi: serahkan obat<br/>stok berkurang"]
  H --> I["Lab: input hasil"]
  I --> J["Kasir: buka invoice + catat pembayaran"]
  J --> K["Dokter: finalisasi EMR<br/>kunjungan = completed"]
  K --> L["Manajemen: dashboard & laporan"]
```

> Invoice dapat dibuka lebih awal. Setiap perubahan tindakan/resep/lab/addendum
> memicu `rebuild()` otomatis, jadi total selalu diperbarui. Resep harus dibuat
> sebelum finalisasi; setelah `final`, tombol **Buat resep** hilang dan API menolak.

---

## 4. Alur pendaftaran dan antrean

```mermaid
flowchart TD
  S["POST /api/registrations"] --> K{"idempotency_key<br/>sudah ada?"}
  K -- Ya --> R["Kembalikan registrasi lama<br/>replayed = true"]
  K -- Tidak --> L["Ambil advisory lock<br/>regno:tanggal + pasien + poli + tanggal"]
  L --> V{"Pasien, dokter, poli<br/>aktif?"}
  V -- Tidak --> E1["400 Data tidak valid"]
  V -- Ya --> D{"Pasien sudah terdaftar<br/>di poli tanggal ini?"}
  D -- Ya --> E2["400 Duplikat registrasi"]
  D -- Tidak --> J{"Jadwal praktik cocok<br/>dengan hari & dokter?"}
  J -- Tidak --> E3["400 Jadwal tidak sesuai"]
  J -- Ya --> Q["registration_counters + 1 → RJ{YYYYMMDD}{seq:04d}<br/>queue_counters + 1 → nomor antrean per poli"]
  Q --> I["Simpan registrasi<br/>status = registered"]
  I --> A["Tulis audit + commit transaksi"]
  A --> N["NodeClient kirim notifikasi antrean"]
  N --> F{"Node gagal?"}
  F -- Ya --> LOG["Audit node.integration.failed<br/>registrasi tetap sukses"]
  F -- Tidak --> OK["Antrean tampil di UI"]
```

> Notifikasi push FCM juga bersifat best-effort dan opsional. Tanpa konfigurasi Firebase,
> transaksi tetap sukses, audit `fcm.send` mencatat `sent: 0`, dan UI menyembunyikan opsi
> notifikasi saat login.

Endpoint terkait:

| Aksi | Endpoint |
| --- | --- |
| Daftar / cari registrasi | `GET /api/registrations` |
| Buat registrasi | `POST /api/registrations` |
| Lihat antrean hari ini | `GET /api/registrations/queue` |
| Ubah status | `POST /api/registrations/<id>/status` |
| Lihat tindakan kunjungan | `GET /api/registrations/<id>/actions` |

---

## 5. Transisi status kunjungan

```mermaid
stateDiagram-v2
  [*] --> registered: POST /registrations
  registered --> waiting: POST /status
  waiting --> in_exam: saat EMR dibuat (dokter)
  in_exam --> completed: finalize EMR (dokter)
  registered --> cancelled: POST /status
  waiting --> cancelled: POST /status
  in_exam --> cancelled: POST /status
  completed --> [*]
  cancelled --> [*]
```

Catatan:

- Membuat pemeriksaan otomatis mengubah kunjungan menjadi `in_exam` oleh dokter.
- Finalisasi EMR otomatis mengubah kunjungan menjadi `completed` oleh dokter.
- Endpoint `POST /registrations/<id>/status` hanya untuk `waiting`/`cancelled` (petugas);
  `in_exam` butuh `examination.create` dan `completed` ditolak `400`.
- Transisi selain di atas ditolak dengan `400 Transisi status tidak valid`; percobaan
  `in_exam` tanpa permission dokter ditolak `403`.

---

## 6. Alur pemeriksaan / EMR

```mermaid
flowchart TD
  A["Pasien berstatus waiting atau in_exam"] --> B["Dokter isi SOAP<br/>subjective, objective, assessment, plan"]
  B --> C["Isi physical_exam & vital_signs"]
  C --> D["Pilih diagnosis utama + sekunder<br/>dari master diagnoses"]
  D --> E["POST /api/examinations"]
  E --> F{"Kunjungan valid &<br/>belum punya EMR?"}
  F -- Tidak --> ERR["400"]
  F -- Ya --> G["Simpan examinations<br/>status = draft"]
  G --> H["Simpan examination_diagnoses"]
  H --> I["Kunjungan → in_exam"]
  I --> J["Tulis audit examination.create"]

  J --> K{"Perlu final?"}
  K -- Belum --> L["EMR tetap draft<br/>boleh diubah"]
  K -- Ya --> M["POST /examinations/<id>/finalize"]
  M --> N["status = final<br/>version + 1<br/>kunjungan → completed"]
  N --> O["Audit examination.finalize"]

  O --> P{"Ada perubahan setelah final?"}
  P -- Ya --> Q["PUT dengan addendum_reason"]
  Q --> R["version + 1, audit addendum<br/>invoice di-rebuild"]
  P -- Tidak --> S["Selesai"]
```

---

## 7. Alur tindakan, resep, dan stok obat

### 7.1 Tindakan kunjungan

```mermaid
flowchart LR
  A["Pilih pendaftaran"] --> B["Pilih tindakan dari master"]
  B --> C["Input quantity"]
  C --> D["POST /registrations/<id>/actions"]
  D --> E["Simpan price_snapshot tarif saat itu"]
  E --> F["Jika invoice ada → rebuild"]
```

Harga master boleh berubah; tagihan lama tetap memakai `price_snapshot`.

### 7.2 Resep dan penyerahan obat

```mermaid
sequenceDiagram
  participant D as Dokter
  participant Y as Yii2 API
  participant DB as PostgreSQL
  participant F as Farmasi

  D->>Y: POST /prescriptions + items
  Y->>DB: Tolak jika examination.status = final
  Y->>DB: SELECT drug FOR UPDATE + cek available_stock<br/>= stock master - total resep pending
  Y->>DB: Simpan prescriptions + prescription_items<br/>price_snapshot diambil dari master
  Note over Y,DB: Stok fisik belum dikurangi; resep pending<br/>berlaku sebagai reservasi sementara
  Y-->>D: Resep status = pending

  D->>Y: Klik Buat resep lagi → GET /examinations/{id}
  Y-->>D: Item + catatan resep pending dimuat kembali
  D->>Y: PUT /prescriptions/{id} (perbarui)
  Y->>DB: Cek status pending + examination belum final
  Y-->>D: 200 Resep diperbarui (tanpa duplikat)

  F->>Y: POST /prescriptions/<id>/dispense
  Y->>DB: SELECT prescription FOR UPDATE
  Y->>DB: UPDATE drugs SET stock = stock - qty<br/>WHERE stock >= qty RETURNING stock
  alt Stok mencukupi
    Y->>DB: INSERT stock_movements reason = dispense
    Y->>DB: status = dispensed + audit
    Y->>DB: Rebuild invoice
    Y-->>F: 200 Obat diserahkan
  else Stok tidak mencukupi
    Y-->>F: 400, transaksi di-rollback
  end
```

> UI dokter memuat `/api/drugs` saat modal resep dibuka dan menyegarkannya setiap 10 detik.
> Field `available_stock` menjadi `max` pada input jumlah dan nilai yang diketik langsung
> dibatasi ke sisa jatah (termasuk sisa antar-baris obat yang sama). Saat **Simpan/Perbarui**,
> UI mengambil ulang `/api/drugs` sebelum mengirim payload; bila stok berubah dan jumlah tidak
> memenuhi, field terkait diberi highlight merah dan pesan peringatan, lalu request dibatalkan.
> Saat mengubah resep `pending`, jumlah yang sudah dipesan resep itu sendiri ditambahkan kembali
> agar tidak terhitung sebagai stok orang lain.

---

## 8. Alur laboratorium

```mermaid
flowchart LR
  D["Dokter"] -->|"POST /lab/requests"| Y["Yii2 API"]
  Y --> R["lab_requests status = requested"]
  R --> P["Petugas lab: POST /start"]
  P --> I{"Input hasil melalui"}
  I -- "Yii langsung" --> YR["POST /lab/requests/<id>/result"]
  I -- "Node service" --> NP["POST /services/api/v1/lab/results"]
  NP --> NI["Node: tambah x-service-key"]
  NI --> YI["POST /api/internal/lab/results"]
  YR --> S["Yii: simpan lab_results<br/>status = completed"]
  YI --> S
  S --> A["Audit + rebuild invoice"]
  S --> V["Dokter: detail pemeriksaan menampilkan<br/>hasil, rujukan, dan petugas input"]
```

Jalur Node tetap berakhir di Yii. Node hanya meneruskan `created_by`, `result`, dan
`reference_range`; validasi dan penyimpanan tetap dilakukan Yii.
`GET /api/examinations/<id>` menggabungkan `lab_requests` dengan `lab_results`, sehingga
dokter melihat hasil pada detail riwayat kunjungan tanpa membuka modul laboratorium.

---

## 9. Alur billing dan pembayaran

```mermaid
flowchart TD
  T1["Registrasi dibuat"] --> ENS["ensureInvoice<br/>item: biaya pendaftaran"]
  T2["Tindakan ditambah"] --> REB["rebuild invoice"]
  T3["Resep diserahkan"] --> REB
  T4["Hasil lab masuk"] --> REB
  T5["Addendum EMR"] --> REB
  ENS --> INV["Invoice status = unpaid"]
  REB --> INV

  INV --> PAY["POST /invoices/<id>/pay<br/>amount + method + idempotency_key"]
  PAY --> IDEM{"Idempotency key<br/>sudah dipakai?"}
  IDEM -- Ya --> OLD["Kembalikan payment lama<br/>replayed = true"]
  IDEM -- Tidak --> LOCK["SELECT invoice FOR UPDATE"]
  LOCK --> CEK{"Masih ada sisa?"}
  CEK -- Tidak --> ERR["400 Tagihan sudah lunas"]
  CEK -- Ya --> HITUNG["applied = min(amount, sisa)<br/>change = amount - applied"]
  HITUNG --> SAVE["Simpan payments + nomor unik<br/>amount = applied, change_amount = change"]
  SAVE --> UPD["Update paid_amount + status invoice"]
  UPD --> ST{"Lunas?"}
  ST -- Ya --> PAID["status = paid"]
  ST -- Tidak --> PARTIAL["status = partial"]
  PAID --> PRINT["GET /invoices/<id>/print?token=JWT"]
  PARTIAL --> PRINT
```

Status invoice: `unpaid` → `partial` → `paid`. Pembayaran dengan kunci yang sama
tidak pernah menambah `paid_amount` dua kali. Nominal yang melebihi sisa dicatat sebagai
`payments.change_amount` (kembalian), bukan ditolak; yang masuk `paid_amount` tetap maksimal
sisa tagihan.

---

## 10. Alur dashboard dan laporan

```mermaid
sequenceDiagram
  participant M as Manajemen
  participant FE as Frontend
  participant Y as Yii2 API
  participant N as Node Service
  participant DB as PostgreSQL

  M->>FE: Pilih periode from / to
  FE->>Y: GET /reports/dashboard
  Y->>DB: Agregasi kunjungan, tindakan, lab, tagihan, stok
  Y-->>FE: Ringkasan + tabel
  FE->>N: GET /services/api/v1/dashboard/summary
  N->>Y: GET /api/internal/reports/dashboard
  Y-->>N: Data yang sama
  N-->>FE: Data identik untuk indikator Node
  FE-->>M: Kartu statistik & grafik
  M->>FE: Klik Ekspor CSV
  FE-->>M: File pembayaran-<from>-<to>.csv
```

---

## 11. Peta peran, aksi, dan endpoint

| Peran | Aksi utama | Endpoint kunci |
| --- | --- | --- |
| `registration` | Buat pasien, daftarkan pasien, kelola antrean | `/patients`, `/registrations`, `/registrations/<id>/status` |
| `doctor` | Baca EMR, buat EMR, tindakan, resep & ubah resep sebelum final, minta lab | `/examinations`, `/prescriptions`, `/prescriptions/<id>` (PUT), `/lab/requests` |
| `nurse` | Lihat pasien & antrean, bantu EMR | `/patients`, `/registrations`, `/examinations` |
| `pharmacy` | Lihat/proses resep, pantau stok | `/prescriptions`, `/prescriptions/<id>/dispense`, `/drugs` |
| `laboratory` | Lihat request, mulai, input hasil | `/lab/requests`, `/lab/requests/<id>/result` |
| `cashier` | Lihat/buat invoice, catat pembayaran, laporan | `/invoices`, `/invoices/<id>/pay`, `/reports/*` |
| `management` | Dashboard, laporan | `/reports/*` |
| `admin` | Semua aksi + kelola master & pengguna | `/users`, master, dan seluruh endpoint |

Filter menu di frontend memakai `user.permissions` dari response login; backend tetap
mengvalidasi permission dengan `requirePermission()` pada setiap endpoint. Tagihan hanya
untuk `admin`/`kasir` (`billing.view`); menu Data master hanya untuk `admin`
(`master.manage` di `frontend/src/App.jsx:32`), sedangkan `master.view` tetap dipakai
sebagai permission baca untuk lookup seperti `/drugs` pada Farmasi.

---

## 12. Checklist menjalankan alur demo

Gunakan akun demo `admin / Admin123!` (atau akun peran sesuai tabel di atas).

1. Buka `Data master` → pastikan poliklinik/unit pelayanan, dokter, jadwal, tindakan, obat, diagnosis,
   dan jenis lab tersedia.
2. `Pasien` → Tambah pasien → catat No. RM.
3. `Pendaftaran` → pilih pasien, poli, dokter, tanggal → Daftarkan → catat nomor antrean.
4. `Pendaftaran` → ubah status menjadi `waiting`.
5. `Pemeriksaan` → klik **Isi EMR** → isi SOAP, vital sign, diagnosis → Simpan.
6. `Tindakan` → pilih pendaftaran → tambah tindakan.
7. `Pemeriksaan` → **Buat resep** pada pemeriksaan pasien (dokter) → `Farmasi` → klik
   **Serahkan** pada antrian resep.
8. `Laboratorium` → buat permintaan → **Mulai** → **Input hasil** (boleh via Node).
9. `Tagihan` → Buka tagihan → Detail → Catat pembayaran → Cetak.
10. `Pemeriksaan` → Finalisasi EMR. Resep wajib dibuat sebelum langkah ini; setelah
    final, tombol **Buat resep** disembunyikan dan API menolak pembuatan/perubahan resep.
11. `Dashboard` / `Laporan` → pilih periode → verifikasi angka → Ekspor CSV.

Untuk versi otomatis dari checklist ini, jalankan:

```bash
bash scripts/smoke.sh http://127.0.0.1:8086/api
```
