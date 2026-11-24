<?php

use yii\db\Migration;

class m260002_000001_add_outpatient_workflow extends Migration
{
    public function safeUp()
    {
        $tables = [
            "CREATE TABLE IF NOT EXISTS medical_staff (
                id BIGSERIAL PRIMARY KEY,
                employee_number VARCHAR(40) UNIQUE NOT NULL,
                name VARCHAR(150) NOT NULL,
                role VARCHAR(100) NOT NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )",
            "CREATE TABLE IF NOT EXISTS medical_actions (
                id BIGSERIAL PRIMARY KEY,
                code VARCHAR(30) UNIQUE NOT NULL,
                name VARCHAR(150) NOT NULL,
                price NUMERIC(14,2) NOT NULL DEFAULT 0,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )",
            "CREATE TABLE IF NOT EXISTS drugs (
                id BIGSERIAL PRIMARY KEY,
                code VARCHAR(30) UNIQUE NOT NULL,
                name VARCHAR(150) NOT NULL,
                unit VARCHAR(20) NOT NULL DEFAULT 'pcs',
                price NUMERIC(14,2) NOT NULL DEFAULT 0,
                stock INTEGER NOT NULL DEFAULT 0 CHECK(stock >= 0),
                min_stock INTEGER NOT NULL DEFAULT 0,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )",
            "CREATE TABLE IF NOT EXISTS prescriptions (
                id BIGSERIAL PRIMARY KEY,
                examination_id BIGINT REFERENCES examinations(id),
                registration_id BIGINT NOT NULL REFERENCES registrations(id),
                patient_id BIGINT NOT NULL REFERENCES patients(id),
                doctor_user_id BIGINT NOT NULL REFERENCES users(id),
                status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','dispensed','cancelled')),
                notes TEXT,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                dispensed_at TIMESTAMP,
                dispensed_by BIGINT REFERENCES users(id)
            )",
            "CREATE TABLE IF NOT EXISTS prescription_items (
                id BIGSERIAL PRIMARY KEY,
                prescription_id BIGINT NOT NULL REFERENCES prescriptions(id) ON DELETE CASCADE,
                drug_id BIGINT NOT NULL REFERENCES drugs(id),
                dose VARCHAR(100) NOT NULL,
                frequency VARCHAR(100) NOT NULL,
                quantity INTEGER NOT NULL CHECK(quantity > 0),
                price_snapshot NUMERIC(14,2) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )",
            "CREATE TABLE IF NOT EXISTS lab_requests (
                id BIGSERIAL PRIMARY KEY,
                registration_id BIGINT NOT NULL REFERENCES registrations(id),
                examination_id BIGINT REFERENCES examinations(id),
                patient_id BIGINT NOT NULL REFERENCES patients(id),
                doctor_user_id BIGINT NOT NULL REFERENCES users(id),
                test_name VARCHAR(200) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'requested' CHECK(status IN ('requested','in_progress','completed','cancelled')),
                notes TEXT,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                completed_at TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS lab_results (
                id BIGSERIAL PRIMARY KEY,
                lab_request_id BIGINT NOT NULL UNIQUE REFERENCES lab_requests(id) ON DELETE CASCADE,
                result TEXT NOT NULL,
                reference_range VARCHAR(150),
                created_by BIGINT NOT NULL REFERENCES users(id),
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )",
            "CREATE TABLE IF NOT EXISTS invoices (
                id BIGSERIAL PRIMARY KEY,
                invoice_number VARCHAR(50) UNIQUE NOT NULL,
                registration_id BIGINT NOT NULL UNIQUE REFERENCES registrations(id),
                patient_id BIGINT NOT NULL REFERENCES patients(id),
                status VARCHAR(20) NOT NULL DEFAULT 'unpaid' CHECK(status IN ('unpaid','partial','paid','cancelled')),
                subtotal NUMERIC(14,2) NOT NULL DEFAULT 0,
                discount NUMERIC(14,2) NOT NULL DEFAULT 0,
                total NUMERIC(14,2) NOT NULL DEFAULT 0,
                paid_amount NUMERIC(14,2) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )",
            "CREATE TABLE IF NOT EXISTS invoice_items (
                id BIGSERIAL PRIMARY KEY,
                invoice_id BIGINT NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
                item_type VARCHAR(20) NOT NULL CHECK(item_type IN ('registration','action','lab','drug')),
                reference_id BIGINT,
                description VARCHAR(255) NOT NULL,
                quantity INTEGER NOT NULL DEFAULT 1,
                unit_price NUMERIC(14,2) NOT NULL,
                amount NUMERIC(14,2) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )",
            "CREATE TABLE IF NOT EXISTS payments (
                id BIGSERIAL PRIMARY KEY,
                payment_number VARCHAR(50) UNIQUE NOT NULL,
                invoice_id BIGINT NOT NULL REFERENCES invoices(id),
                amount NUMERIC(14,2) NOT NULL CHECK(amount > 0),
                method VARCHAR(20) NOT NULL CHECK(method IN ('cash','transfer','qris','card')),
                idempotency_key VARCHAR(120) UNIQUE NOT NULL,
                reference_no VARCHAR(100),
                created_by BIGINT REFERENCES users(id),
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )",
            "CREATE TABLE IF NOT EXISTS stock_movements (
                id BIGSERIAL PRIMARY KEY,
                drug_id BIGINT NOT NULL REFERENCES drugs(id),
                quantity INTEGER NOT NULL,
                reason VARCHAR(100) NOT NULL,
                reference_type VARCHAR(50),
                reference_id BIGINT,
                created_by BIGINT REFERENCES users(id),
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )",
        ];
        foreach ($tables as $sql) $this->execute($sql);

        $columns = [
            "ALTER TABLE patients ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE",
            "ALTER TABLE diagnoses ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE",
            "ALTER TABLE registrations ADD COLUMN IF NOT EXISTS idempotency_key VARCHAR(120)",
            "ALTER TABLE registrations ADD COLUMN IF NOT EXISTS schedule_id BIGINT REFERENCES doctor_schedules(id)",
            "ALTER TABLE registrations ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT NOW()",
            "ALTER TABLE examinations ADD COLUMN IF NOT EXISTS physical_exam TEXT",
            "ALTER TABLE examinations ADD COLUMN IF NOT EXISTS version INTEGER NOT NULL DEFAULT 1",
            "ALTER TABLE examinations ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT NOW()",
            "ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS before_data JSONB",
            "ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS after_data JSONB",
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_registrations_idempotency ON registrations(idempotency_key) WHERE idempotency_key IS NOT NULL",
        ];
        foreach ($columns as $sql) $this->execute($sql);

        $indexes = [
            "CREATE INDEX IF NOT EXISTS idx_registrations_patient_date ON registrations(patient_id, registration_date DESC)",
            "CREATE INDEX IF NOT EXISTS idx_registrations_poly_date_status ON registrations(polyclinic_id, registration_date DESC, status)",
            "CREATE INDEX IF NOT EXISTS idx_examinations_status ON examinations(status, created_at DESC)",
            "CREATE INDEX IF NOT EXISTS idx_invoices_status_created ON invoices(status, created_at DESC)",
            "CREATE INDEX IF NOT EXISTS idx_invoice_items_invoice ON invoice_items(invoice_id)",
            "CREATE INDEX IF NOT EXISTS idx_payments_invoice ON payments(invoice_id)",
            "CREATE INDEX IF NOT EXISTS idx_prescriptions_patient ON prescriptions(patient_id, created_at DESC)",
            "CREATE INDEX IF NOT EXISTS idx_lab_requests_status ON lab_requests(status, created_at DESC)",
            "CREATE INDEX IF NOT EXISTS idx_stock_movements_drug ON stock_movements(drug_id, created_at DESC)",
            "CREATE INDEX IF NOT EXISTS idx_audit_logs_entity ON audit_logs(entity, entity_id, created_at DESC)",
        ];
        foreach ($indexes as $sql) $this->execute($sql);
    }

    public function safeDown()
    {
        foreach (['stock_movements', 'payments', 'invoice_items', 'invoices', 'lab_results', 'lab_requests', 'prescription_items', 'prescriptions', 'drugs', 'medical_actions', 'medical_staff'] as $table) {
            $this->execute("DROP TABLE IF EXISTS $table");
        }
        $this->execute("DROP INDEX IF EXISTS idx_registrations_idempotency");
        $this->execute("ALTER TABLE audit_logs DROP COLUMN IF EXISTS after_data");
        $this->execute("ALTER TABLE audit_logs DROP COLUMN IF EXISTS before_data");
        $this->execute("ALTER TABLE examinations DROP COLUMN IF EXISTS updated_at");
        $this->execute("ALTER TABLE examinations DROP COLUMN IF EXISTS version");
        $this->execute("ALTER TABLE examinations DROP COLUMN IF EXISTS physical_exam");
        $this->execute("ALTER TABLE registrations DROP COLUMN IF EXISTS updated_at");
        $this->execute("ALTER TABLE registrations DROP COLUMN IF EXISTS schedule_id");
        $this->execute("ALTER TABLE registrations DROP COLUMN IF EXISTS idempotency_key");
        $this->execute("ALTER TABLE diagnoses DROP COLUMN IF EXISTS is_active");
        $this->execute("ALTER TABLE patients DROP COLUMN IF EXISTS is_active");
    }
}
