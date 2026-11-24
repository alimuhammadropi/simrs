# SIMRS Backend Services (Node.js/Express)

Layanan pendukung untuk integrasi laboratorium, dashboard, dan notifikasi.
**Yii2 tetap satu-satunya pemilik aturan bisnis dan transaksi klinis.** Node hanya
memforward/mengagregasi data melalui REST internal Yii; Node tidak menulis langsung ke
PostgreSQL dan tidak memutuskan validitas pendaftaran, stok, atau pembayaran.

## Konfigurasi
Salin `.env.example` menjadi `.env`:

| Variabel | Fungsi | Default |
| --- | --- | --- |
| `PORT` | Port layanan | `3010` |
| `NODE_SERVICE_KEY` | Kunci internal untuk `x-service-key` | wajib diisi |
| `CORS_ORIGIN` | Daftar origin dipisah koma, `*` untuk semua | `http://localhost:5173` |
| `YII_API_URL` | Base URL API Yii yang dipanggil Node | `http://127.0.0.1:8086/api` |

Jalankan dengan `npm run dev` (watch) atau `npm start`.

## Endpoint publik

### `GET /health`
Health check tanpa autentikasi.

### `GET /api/v1/lab/requests`
Daftar permintaan laboratorium dari Yii. Query opsional: `status`.

### `POST /api/v1/lab/results`
Meneruskan hasil pemeriksaan ke Yii. Body:
```json
{ "lab_request_id": 1, "result": "Hb 13 g/dL", "reference_range": "12-16", "created_by": 4 }
```
`created_by` adalah ID pengguna Yii yang input hasil. Yii yang menyimpan hasil,
mengubah status, menulis audit, dan menyusun ulang tagihan.

### `GET /api/v1/dashboard/summary`
Ringkasan operasional dari Yii. Query opsional: `from`, `to` (`YYYY-MM-DD`).

Semua respons menggunakan format:
```json
{ "success": true, "message": "OK", "data": { } }
```
dan error:
```json
{ "success": false, "message": "Pesan error", "details": { } }
```

## Endpoint internal

### `POST /internal/notifications/queue`
Dipanggil Yii setelah pendaftaran commit. Wajib header `x-service-key` dan body:
```json
{ "registration_number": "RJ202601010001", "queue_number": 1, "polyclinic_name": "Poli Umum", "idempotency_key": "RJ202601010001" }
```
Mengembalikan `202`. Pengiriman duplikat dengan `idempotency_key` yang sama tidak
dicatat ulang. Kegagalan layanan ini tidak membatalkan pendaftaran karena Yii
mencatat `node.integration.failed` di `audit_logs`.

## Pembagian tanggung jawab

- Yii: validasi, transaksi, RBAC, perhitungan tarif/tagihan, stok, audit, dan schema DB.
- Node: middleware validasi, autentikasi service, error handling seragam, serta facade
  API untuk konsumsi eksternal (laboratorium/dashboard).
- React: memanggil Yii untuk transaksi; boleh memanggil Node hanya untuk data pendukung.

## Keamanan
Ganti `NODE_SERVICE_KEY` di produksi, batasi endpoint internal ke jaringan privat,
dan gunakan HTTPS/gateway. Perbandingan kunci memakai `crypto.timingSafeEqual`.
