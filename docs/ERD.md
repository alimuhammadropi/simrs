# ERD / Schema ringkas

## Identitas & akses
- `users(id, username, name, password_hash, is_active, created_at)`
- `roles(id, code, name)`
- `permissions(id, code, name)`
- `user_roles(user_id, role_id)`
- `role_permissions(role_id, permission_id)`

## Master
- `patients(id, medical_record_number, name, nik, gender, birth_date, phone, address, is_active)`
- `polyclinics(id, code, name, description, is_active)` — poli / unit pelayanan
- `doctors(id, name, sip_number, specialty, is_active)`
- `doctor_polyclinics(doctor_id, polyclinic_id)`
- `doctor_schedules(id, doctor_id, polyclinic_id, day_of_week, start_time, end_time, is_active)`
- `medical_actions(id, code, name, price, is_active)`
- `drugs(id, code, name, unit, price, stock, min_stock, is_active)`
- `diagnoses(id, code, name, is_active)`
- `medical_staff(id, employee_number, name, role, is_active)`
- `lab_tests(id, code, name, price, is_active)`

## Kunjungan & rekam medis
- `registration_counters(queue_date, last_number, updated_at)` — sequence global harian untuk `registration_number`.
- `queue_counters(polyclinic_id, queue_date, last_number)`
- `registrations(id, registration_number, patient_id, polyclinic_id, doctor_id,
  schedule_id, registration_date, queue_number, status, idempotency_key, created_at, updated_at)`
- `examinations(id, registration_id, doctor_user_id, subjective, objective, physical_exam,
  assessment, plan, vital_signs JSONB, status, version, created_at, finalized_at, updated_at)`
- `examination_diagnoses(examination_id, diagnosis_id, is_primary)`
- `visit_actions(id, registration_id, medical_action_id, quantity, price_snapshot, created_by)`
- `audit_logs(id, user_id, action, entity_type, entity_id, before_data JSONB,
  after_data JSONB, ip, user_agent, created_at)`

## Farmasi & laboratorium
- `prescriptions(id, prescription_number, registration_id, examination_id, patient_id,
  doctor_user_id, notes, status, dispensed_at, dispensed_by)`
- `prescription_items(id, prescription_id, drug_id, dose, frequency, quantity, price_snapshot)`
- `stock_movements(id, drug_id, quantity, reason, reference_type, reference_id, created_by)`
- `lab_requests(id, registration_id, examination_id, patient_id, doctor_user_id,
  lab_test_id, test_name, price_snapshot, notes, status, completed_at)`
- `lab_results(id, lab_request_id, result, reference_range, created_by, created_at)`

## Billing
- `invoices(id, invoice_number, registration_id, patient_id, total, paid_amount, status, created_at)`
- `invoice_items(id, invoice_id, description, source_type, source_id, quantity, unit_price, amount)`
- `payments(id, payment_number, invoice_id, amount, change_amount, method, reference_no,
  idempotency_key, created_at)`

## Relasi penting
```
patients 1──* registrations *──1 doctors / polyclinics
registrations 1──0..1 examinations 1──* examination_diagnoses *──1 diagnoses
registrations 1──* visit_actions *──1 medical_actions
registrations 1──* prescriptions 1──* prescription_items *──1 drugs
registrations 1──* lab_requests 1──0..1 lab_results
registrations 1──0..1 invoices 1──* invoice_items / payments
drugs 1──* stock_movements
users 1──* audit_logs
```

## Constraint & indeks
- Unique: kode master, `patients.nik`, `medical_record_number`,
  `registrations.registration_number/idempotency_key`, `invoices.invoice_number`,
  `payments.payment_number/idempotency_key`.
- Indeks pada foreign key dan kolom filter umum (`registration_date`, `status`,
  `created_at`, `nik`, `medical_record_number`).
- `registration_counters` unik `queue_date`; `queue_counters` unik `(polyclinic_id, queue_date)`.
- Soft delete memakai `is_active = false` pada master dan pasien; transaksi klinis tidak
  dihapus, kecuali `visit_actions` yang dihapus dengan audit.
