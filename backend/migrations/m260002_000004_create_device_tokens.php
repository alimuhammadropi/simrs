<?php

use yii\db\Migration;

class m260002_000004_create_device_tokens extends Migration
{
    public function safeUp()
    {
        $this->execute("CREATE TABLE IF NOT EXISTS device_tokens (
            id BIGSERIAL PRIMARY KEY,
            user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            token VARCHAR(512) UNIQUE NOT NULL,
            platform VARCHAR(20) NOT NULL DEFAULT 'web' CHECK(platform IN ('web','android','ios')),
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            last_seen_at TIMESTAMP NOT NULL DEFAULT NOW(),
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )");
        $this->execute("CREATE INDEX IF NOT EXISTS idx_device_tokens_user_active ON device_tokens(user_id, is_active)");
    }

    public function safeDown()
    {
        $this->execute("DROP INDEX IF EXISTS idx_device_tokens_user_active");
        $this->execute("DROP TABLE IF EXISTS device_tokens");
    }
}
