<?php

use yii\db\Migration;

class m260002_000002_add_visit_actions_and_lab_tests extends Migration
{
    public function safeUp()
    {
        $this->execute("CREATE TABLE IF NOT EXISTS visit_actions (
            id BIGSERIAL PRIMARY KEY,
            registration_id BIGINT NOT NULL REFERENCES registrations(id) ON DELETE CASCADE,
            examination_id BIGINT REFERENCES examinations(id),
            medical_action_id BIGINT NOT NULL REFERENCES medical_actions(id),
            quantity INTEGER NOT NULL DEFAULT 1 CHECK(quantity > 0),
            price_snapshot NUMERIC(14,2) NOT NULL,
            created_by BIGINT REFERENCES users(id),
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )");
        $this->execute("CREATE TABLE IF NOT EXISTS lab_tests (
            id BIGSERIAL PRIMARY KEY,
            code VARCHAR(30) UNIQUE NOT NULL,
            name VARCHAR(200) NOT NULL,
            price NUMERIC(14,2) NOT NULL DEFAULT 0,
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )");
        $this->execute("ALTER TABLE lab_requests ADD COLUMN IF NOT EXISTS lab_test_id BIGINT REFERENCES lab_tests(id)");
        $this->execute("ALTER TABLE lab_requests ADD COLUMN IF NOT EXISTS price_snapshot NUMERIC(14,2) NOT NULL DEFAULT 0");
        $this->execute("CREATE INDEX IF NOT EXISTS idx_visit_actions_registration ON visit_actions(registration_id)");
        $this->execute("CREATE INDEX IF NOT EXISTS idx_lab_requests_registration ON lab_requests(registration_id)");
    }

    public function safeDown()
    {
        $this->execute("DROP INDEX IF EXISTS idx_lab_requests_registration");
        $this->execute("DROP INDEX IF EXISTS idx_visit_actions_registration");
        $this->execute("ALTER TABLE lab_requests DROP COLUMN IF EXISTS price_snapshot");
        $this->execute("ALTER TABLE lab_requests DROP COLUMN IF EXISTS lab_test_id");
        $this->execute("DROP TABLE IF EXISTS lab_tests");
        $this->execute("DROP TABLE IF EXISTS visit_actions");
    }
}
