# Keputusan teknis

## Yii sebagai pemilik transaksi
Yii memegang semua aturan bisnis. Node tidak pernah menulis ke database klinis dan hanya
memanggil REST internal Yii. Keputusan ini mencegah dua sumber kebenaran untuk status
kunjungan, stok, dan pembayaran.

## Snapshot harga
Setiap tindakan, item resep, dan permintaan lab menyimpan `price_snapshot`. Jika tarif
master berubah, tagihan kunjungan lama tidak berubah. `BillingService::rebuild()` selalu
memakai snapshot tersebut.

## Idempotensi
Registrasi dan pembayaran menerima `idempotency_key` dari client (UUID). Handler mencari
key lebih dulu dan mengembalikan record lama dengan `replayed: true`. Ini melindungi dari
double submit saat jaringan lambat.

## Konkurensi antrean dan penomoran
`pg_advisory_xact_lock(hashtext(regno:tanggal))` menyerialisasi pembuatan nomor
registrasi lintas poliklinik, sementara lock `pasien:poli:tanggal` mencegah registrasi
ganda. `registration_counters` menghasilkan `RJ{YYYYMMDD}{seq:04d}` yang unik secara
global per tanggal; `queue_counters` tetap menghasilkan nomor antrean per poli.
Keduanya memakai `UPDATE ... RETURNING` di dalam transaksi yang sama.

## Locking stok dan pembayaran
Penyerahan obat memakai `SELECT ... FOR UPDATE` pada resep dan
`UPDATE ... WHERE stock >= q RETURNING` agar stok tidak pernah negatif. Pembuatan/perubahan
resep juga mengunci baris `drugs` dengan `SELECT ... FOR UPDATE` dan membandingkan jumlah
dengan `available_stock = stock - SUM(resep pending)`, sehingga beberapa dokter yang
menginput resep bersamaan tidak bisa melewati stok yang tersisa. Resep `pending` berlaku
sebagai reservasi sementara; stok fisik baru dikurangi saat penyerahan. Pembayaran
memakai lock pada invoice agar total pembayaran tidak melebihi tagihan.

## Resep sebelum finalisasi
Dokter hanya boleh membuat/memperbarui resep selama pemeriksaan belum `final`. Klik
**Buat resep** lagi memuat item dan catatan tersimpan, lalu `PUT /prescriptions/<id>`
memperbarui resep `pending` yang sama sehingga tidak ada duplikat. Setelah finalisasi,
tombol disembunyikan dan API menolak (`400`) karena EMR dianggap tertutup. Notifikasi
`prescription.created` tetap dikirim saat simpan awal, tidak diulang saat update.

## Notifikasi push opsional
Kredensial Firebase sengaja tidak ikut repository. Jika `FIREBASE_*` atau `frontend/.env`
tidak ada, `FcmService::enabled()` bernilai `false` dan pengiriman hanya menghasilkan audit
`fcm.send` dengan `sent: 0`; transaksi tetap di-commit. Private key rusak ditangkap sebagai
`fcm.token.failed`. Di frontend, opsi notifikasi disembunyikan agar use case `git clone`
tidak menampilkan kontrol yang tidak berfungsi.

## Audit
`AuditLog::record()` mencatat before/after sebagai JSONB. Perubahan rekam medis final
mengharuskan `addendum_reason`, menaikkan `version`, dan memicu rebuild tagihan.

## Soft delete
Data master dan pasien dinonaktifkan lewat `is_active`, bukan dihapus, agar riwayat
tetap dapat dibaca. Data transaksi tidak dihapus.

## Autentikasi JWT stateless
Token HS256 berisi `sub` user id. Tidak ada session server.frontend menyimpan token di
`localStorage`; untuk produksi disarankan cookie HttpOnly + refresh token.

## CORS same-host
Origin dengan host sama dengan host request diizinkan otomatis. Origin lain harus
tercantum di `CORS_ORIGIN`. Ini mengatasi deployment HTTPS tanpa mengizinkan semua origin.

## Relative API URL di frontend
Frontend memakai `/api` dan `/services`. nginx melakukan proxy keduanya saat produksi,
sedangkan Vite dev server melakukan hal yang sama lewat `VITE_API_PROXY_TARGET` dan
`VITE_SERVICES_PROXY_TARGET` (default `127.0.0.1:8086` dan `127.0.0.1:3010`). Jadi tidak
ada override `VITE_API_URL` saat berpindah dev/produksi, serta terhindar dari URL hardcoded,
mixed content, dan CORS.

## Print invoice dengan token query
`GET /api/invoices/<id>/print?token=<jwt>` membuka tab baru karena `window.open()` tidak
dapat mengirim header Authorization. Token tetap tunduk pembatasan permission dan
kedaluwarsa.

## Seed idempoten
Semua seed memakai `insert ... where not exists` atau `ON CONFLICT DO NOTHING`, sehingga
`php yii seed/init` aman diulang setelah migrasi.

## Trade-off yang tersisa
- `localStorage` rentan XSS; produksi sebaiknya memakai HttpOnly cookie.
- Audit log belum append-only pada level database.
- Node menyimpan dedupe notifikasi di memory; produksi sebaiknya memakai Redis/DB.
- Belum ada rate limiting, refresh token, notifikasi email/SMS, atau tanda tangan digital.
- Uji beban dan review keamanan klinis masih diperlukan sebelum pemakaian nyata.
