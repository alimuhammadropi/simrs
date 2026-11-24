<?php

use yii\db\Migration;

class m260002_000005_add_fcm_token_to_users extends Migration
{
    public function safeUp()
    {
        $this->addColumn('users', 'fcm_token', $this->string(512)->null());
        $this->execute("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_fcm_token ON users(fcm_token) WHERE fcm_token IS NOT NULL");
        $this->execute("UPDATE users u SET fcm_token = t.token FROM (
            SELECT DISTINCT ON (user_id) user_id, token
            FROM device_tokens
            WHERE is_active = TRUE
            ORDER BY user_id, last_seen_at DESC
        ) t WHERE u.id = t.user_id");
    }

    public function safeDown()
    {
        $this->execute("DROP INDEX IF EXISTS idx_users_fcm_token");
        $this->dropColumn('users', 'fcm_token');
    }
}
