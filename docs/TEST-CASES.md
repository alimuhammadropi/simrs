# Test cases

## Fungsional

| ID | Skenario | Langkah | Expected |
| --- | --- | --- | --- |
| TC-01 | Login & RBAC | Login sebagai `kasir`, buka daftar pasien | Pasien bisa dilihat; tombol tambah pasien tidak muncul; POST `/patients` ditolak 403 |
| TC-02 | Seed idempoten | Jalankan `php yii seed/init` dua kali | Keduanya sukses, tidak ada duplikat role/user/master |
| TC-03 | Buat pasien | POST `/patients` tanpa `medical_record_number` | No. RM otomatis `RM...`, NIK 16 digit tersimpan |
| TC-04 | NIK invalid | POST `/patients` dengan NIK 10 digit | 400, error validasi |
| TC-05 | Jadwal bentrok | Buat dua jadwal dokter+poli+hari yang overlap | Jadwal kedua ditolak |
| TC-06 | Idempotensi registrasi | Kirim dua POST `/registrations` dengan key sama | ID registrasi sama, `replayed: true`, nomor antrean tidak naik |
| TC-07 | Duplikat registrasi | Daftarkan pasien yang sama ke poli sama pada tanggal sama dengan key berbeda | 400 "Pasien sudah terdaftar..." |
| TC-08 | Antrean konkurensi | Kirim 20 request registrasi berbeda secara paralel | 20 nomor antrean unik dan berurutan tanpa duplikat |
| TC-09 | Jadwal tidak sesuai | Registrasi dengan `schedule_id` hari Selasa pada tanggal Minggu | 400 "Jadwal praktik tidak sesuai..." |
| TC-10 | Transisi status & wewenang | `registered → in_exam` langsung; petugas mencoba `in_exam → completed` | 400 transisi tidak valid; `completed` hanya lewat finalisasi EMR oleh dokter |
| TC-11 | Pemeriksaan | Ubah ke `waiting`, buat EMR dengan diagnosis utama | EMR `draft`, kunjungan `in_exam`, audit `examination.create` |
| TC-12 | Finalisasi & addendum | Finalisasi EMR lalu PUT tanpa `addendum_reason` | 400; dengan alasan → sukses, version naik, audit addendum |
| TC-13 | Tindakan | Tambah tindakan, lalu ubah tarif master | `price_snapshot` lama tetap dipakai tagihan |
| TC-14 | Resep & stok | Buat resep 3 tablet, dispense dua kali | Pertama sukses, kedua 400 karena status bukan `pending`; stok berkurang sekali |
| TC-15 | Stok tidak cukup | Resep quantity melebihi stok | 400 saat create/dispense, tidak ada `stock_movements` |
| TC-16 | Lab via Node | Buat lab request, kirim hasil ke `/services/api/v1/lab/results` | Status `completed`, hasil terbaca di Yii, invoice rebuild |
| TC-17 | Rebuild tagihan | Tambah tindakan setelah invoice ada | Item invoice bertambah, total otomatis diperbarui |
| TC-18 | Cicilan | Bayar 50% lalu 50% | Status `partial` lalu `paid`, `paid_amount` = total |
| TC-19 | Idempotensi pembayaran | Kirim pembayaran dua kali dengan key sama | Satu payment, `replayed: true`, total tidak dobel |
| TC-20 | Pembayaran berlebih | Bayar 150000 untuk sisa 135000 | Tersimpan `amount: 135000`, `change_amount: 15000`, status `paid`; pembayaran pada invoice lunas ditolak 400 |
| TC-21 | Cetak invoice | Buka `/invoices/<id>/print?token=...` | HTML 200, rincian dan pembayaran tampil |
| TC-22 | Dashboard | Bandingkan `/reports/dashboard` dan Node summary | Angka kunjungan/pendapatan identik |
| TC-23 | Ekspor laporan | Klik Ekspor CSV pada Laporan | File `pembayaran-<from>-<to>.csv` terunduh |
| TC-24 | Manajemen user | Buat user baru dengan peran `pharmacy`, login | Menu farmasi tampil, menu pengguna tidak tampil |
| TC-33 | Muat ulang resep dokter | Simpan resep `pending`, klik lagi **Buat resep** pada pemeriksaan sama | Item, dosis, frekuensi, jumlah, dan catatan terisi; tombol **Perbarui resep**; PUT memperbarui ID resep yang sama (tanpa duplikat) |
| TC-34 | Resep setelah finalisasi | Finalisasi EMR lalu coba POST/PUT resep untuk pemeriksaan itu | Tombol **Buat resep** tidak tampil; API `400` "Pemeriksaan sudah difinalisasi..." |
| TC-35 | Notifikasi resep vs finalisasi | Simpan resep, lalu klik **Finalisasi** | Audit `prescription.created` muncul saat simpan; `examination.finalized` muncul saat finalisasi (dua notifikasi berbeda di farmasi) |
| TC-36 | Clone tanpa Firebase | Hapus `frontend/.env` dan `FIREBASE_*`, jalankan build/login lalu buat resep | Build & login sukses; checkbox notifikasi tidak tampil; panel **Notifikasi** memberi petunjuk; resep tersimpan; audit `fcm.send` `sent: 0`; tidak ada error 500 |
| TC-37 | Hasil lab di riwayat kunjungan | Buat permintaan lab, input hasil via Yii/Node, lalu buka **Detail** pemeriksaan terkait | `GET /examinations/<id>` mengembalikan `lab_requests[].result`, `reference_range`, `result_at`, `result_by`; UI menampilkan nama tes, status, hasil, nilai rujukan, dan petugas input |
| TC-38 | Batas & reservasi stok resep | Obat stok 10 sudah dipesan resep `pending` 7; dokter lain buka resep lalu mengetik jumlah 999, lalu 3; stok diubah dokter lain tepat sebelum **Simpan resep** | `GET /api/drugs` memberi `reserved_stock: 7`, `available_stock: 3`; input `max=3` dan ketikan 999 langsung dibatasi menjadi 3; saat Simpan/Perbarui UI mengambil ulang `/api/drugs`, field jumlah yang tidak memenuhi diberi border merah + pesan "Periksa: Stok ... tersedia hanya ...", dan API tetap menolak `qty > 3` dengan 400 |
| TC-39 | Riwayat obat pasien (farmasi) | Login `farmasi`, buka **Pasien → Riwayat obat** pada pasien yang punya resep | Modal menampilkan no. registrasi, tanggal resep, nama obat + jumlah, dosis, frekuensi, dokter, status, serta tanggal/petugas penyerahan; resep `cancelled` tetap tampil dengan statusnya |
| TC-40 | Menu Integrasi Penunjang | Login `admin`, buka **Integrasi Penunjang**; ulangi dengan login `farmasi` | Admin melihat halaman status Node/Yii, daftar endpoint, contoh payload, dan permintaan lab terbaru; peran tanpa `master.manage` tidak melihat menu tersebut |
| TC-41 | Ringkasan detail tagihan | Buka **Tagihan → Detail** pada invoice yang sudah dibayar sebagian | Ringkasan menampilkan Total, **Jumlah dibayar** (`paid_amount`), dan Sisa; angka jumlah dibayar sama dengan total `payments` pada invoice |
| TC-42 | Uang diterima vs diterapkan | Bayar sisa tagihan Rp 200.000 dengan uang Rp 250.000, lalu buka detail tagihan dan cetak invoice | Kolom **Uang diterima** Rp 250.000, **Diterapkan** Rp 200.000, **Kembalian** Rp 50.000; data lama otomatis terisi `amount + change_amount` oleh migrasi |

## Konkurensi & integrasi

| ID | Skenario | Cara uji | Expected |
| --- | --- | --- | --- |
| TC-25 | Double click pendaftaran | Kirim dua request bersamaan dengan key berbeda | Advisory lock + constraint mencegah duplikat; satu sukses, satu 400 |
| TC-26 | Double dispense | Dua request dispense bersamaan untuk resep sama | Hanya satu transaksi sukses; stok tidak negatif |
| TC-27 | Node mati saat registrasi | Set `NODE_SERVICE_URL` salah lalu daftar | Registrasi tetap sukses; audit `node.integration.failed` |
| TC-28 | Node service key salah | POST `/internal/lab/results` tanpa/tanpa key benar | 401 |
| TC-29 | CORS | Request dari origin tidak terdaftar | Tidak ada `Access-Control-Allow-Origin` untuk origin tersebut |
| TC-30 | Token kedaluwarsa | Pakai JWT lama | 401, frontend logout otomatis |
| TC-31 | Nomor registrasi lintas poliklinik | Daftar dua pasien berbeda pada tanggal sama di poli berbeda | `RJ{YYYYMMDD}0001`, `0002`, ... unik; tidak ada error `Registration Number ... has already been taken` |
| TC-32 | Konkurensi penomoran global | Kirim 8 pendaftaran bersamaan pada tanggal sama ke beberapa poli | Delapan nomor registrasi berbeda, counter global naik 8, queue per poli naik sesuai poli |
| TC-33 | Pencarian & filter data master | Di UI **Data master**, ketik kode/nama pada **Cari data**, lalu pilih Status = Aktif/Nonaktif | Tabel hanya menampilkan baris cocok; pencarian juga bekerja pada jadwal praktik (nama dokter/poli); `GET /api/<resource>?q=...` mengembalikan subset yang sama untuk resource dengan `searchFields` |
| TC-34 | Akses Tagihan & Data master | Login sebagai petugas/farmasi, lalu kasir, lalu admin; cek menu dan coba `GET /invoices` serta `GET /polyclinics` | Petugas/farmasi: menu Tagihan & Data master tersembunyi, `/invoices` 403, `/polyclinics` 200. Kasir: `/invoices` 200, `/polyclinics` 403. Admin: keduanya 200 |

## Smoke otomatis

```bash
bash scripts/smoke.sh http://127.0.0.1:8086/api
```

Skrip membuat data baru dengan NIK dan UUID acak, menjalankan alur lengkap, lalu
memverifikasi idempotensi pendaftaran/pembayaran dan angka dashboard.
