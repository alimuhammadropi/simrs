<?php
use yii\db\Migration;

class m260001_000001_init_simrs extends Migration
{
    public function safeUp()
    {
        $sql = [
"CREATE TABLE users (id BIGSERIAL PRIMARY KEY, username VARCHAR(60) UNIQUE NOT NULL, name VARCHAR(150) NOT NULL, password_hash VARCHAR(255) NOT NULL, is_active BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMP NOT NULL DEFAULT NOW())",
"CREATE TABLE roles (id BIGSERIAL PRIMARY KEY, code VARCHAR(50) UNIQUE NOT NULL, name VARCHAR(100) NOT NULL)",
"CREATE TABLE permissions (id BIGSERIAL PRIMARY KEY, code VARCHAR(100) UNIQUE NOT NULL, name VARCHAR(150) NOT NULL)",
"CREATE TABLE user_roles (user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE, role_id BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE, PRIMARY KEY(user_id,role_id))",
"CREATE TABLE role_permissions (role_id BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE, permission_id BIGINT NOT NULL REFERENCES permissions(id) ON DELETE CASCADE, PRIMARY KEY(role_id,permission_id))",
"CREATE TABLE patients (id BIGSERIAL PRIMARY KEY, medical_record_number VARCHAR(30) UNIQUE NOT NULL, nik VARCHAR(16) UNIQUE, name VARCHAR(150) NOT NULL, gender CHAR(1) NOT NULL CHECK(gender IN ('L','P')), birth_date DATE, phone VARCHAR(30), address TEXT, created_at TIMESTAMP NOT NULL DEFAULT NOW(), updated_at TIMESTAMP NOT NULL DEFAULT NOW())",
"CREATE TABLE polyclinics (id BIGSERIAL PRIMARY KEY, code VARCHAR(20) UNIQUE NOT NULL, name VARCHAR(100) NOT NULL, description TEXT, is_active BOOLEAN NOT NULL DEFAULT TRUE)",
"CREATE TABLE doctors (id BIGSERIAL PRIMARY KEY, name VARCHAR(150) NOT NULL, sip_number VARCHAR(100), specialty VARCHAR(150), is_active BOOLEAN NOT NULL DEFAULT TRUE)",
"CREATE TABLE doctor_polyclinics (doctor_id BIGINT NOT NULL REFERENCES doctors(id), polyclinic_id BIGINT NOT NULL REFERENCES polyclinics(id), PRIMARY KEY(doctor_id,polyclinic_id))",
"CREATE TABLE doctor_schedules (id BIGSERIAL PRIMARY KEY, doctor_id BIGINT NOT NULL REFERENCES doctors(id), polyclinic_id BIGINT NOT NULL REFERENCES polyclinics(id), day_of_week SMALLINT NOT NULL CHECK(day_of_week BETWEEN 0 AND 6), start_time TIME NOT NULL, end_time TIME NOT NULL, is_active BOOLEAN NOT NULL DEFAULT TRUE)",
"CREATE TABLE queue_counters (polyclinic_id BIGINT NOT NULL REFERENCES polyclinics(id), queue_date DATE NOT NULL, last_number INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(polyclinic_id,queue_date))",
"CREATE TABLE registrations (id BIGSERIAL PRIMARY KEY, registration_number VARCHAR(40) UNIQUE NOT NULL, patient_id BIGINT NOT NULL REFERENCES patients(id), polyclinic_id BIGINT NOT NULL REFERENCES polyclinics(id), doctor_id BIGINT NOT NULL REFERENCES doctors(id), registration_date DATE NOT NULL, queue_number INTEGER NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'registered' CHECK(status IN ('registered','waiting','in_exam','completed','cancelled')), created_at TIMESTAMP NOT NULL DEFAULT NOW(), UNIQUE(polyclinic_id,registration_date,queue_number))",
"CREATE TABLE medical_records (id BIGSERIAL PRIMARY KEY, patient_id BIGINT NOT NULL UNIQUE REFERENCES patients(id), created_at TIMESTAMP NOT NULL DEFAULT NOW())",
"CREATE TABLE examinations (id BIGSERIAL PRIMARY KEY, registration_id BIGINT NOT NULL UNIQUE REFERENCES registrations(id), doctor_user_id BIGINT NOT NULL REFERENCES users(id), subjective TEXT NOT NULL, objective TEXT NOT NULL, assessment TEXT NOT NULL, plan TEXT NOT NULL, vital_signs JSONB, status VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','final')), created_at TIMESTAMP NOT NULL DEFAULT NOW(), finalized_at TIMESTAMP)",
"CREATE TABLE diagnoses (id BIGSERIAL PRIMARY KEY, code VARCHAR(20) UNIQUE NOT NULL, name VARCHAR(255) NOT NULL)",
"CREATE TABLE examination_diagnoses (examination_id BIGINT NOT NULL REFERENCES examinations(id), diagnosis_id BIGINT NOT NULL REFERENCES diagnoses(id), is_primary BOOLEAN NOT NULL DEFAULT FALSE, PRIMARY KEY(examination_id,diagnosis_id))",
"CREATE TABLE audit_logs (id BIGSERIAL PRIMARY KEY, user_id BIGINT REFERENCES users(id), action VARCHAR(100) NOT NULL, entity VARCHAR(100), entity_id VARCHAR(100), ip_address INET, details JSONB, created_at TIMESTAMP NOT NULL DEFAULT NOW())",
"CREATE INDEX idx_registrations_date ON registrations(registration_date)",
"CREATE INDEX idx_patients_name ON patients(name)"
        ];
        foreach ($sql as $statement) $this->execute($statement);
    }

    public function safeDown()
    {
        foreach (['audit_logs','examination_diagnoses','diagnoses','examinations','medical_records','registrations','queue_counters','doctor_schedules','doctor_polyclinics','doctors','polyclinics','patients','role_permissions','user_roles','permissions','roles','users'] as $table) {
            $this->dropTable($table);
        }
    }
}
