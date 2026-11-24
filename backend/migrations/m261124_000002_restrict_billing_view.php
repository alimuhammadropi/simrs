<?php

use yii\db\Migration;

class m261124_000002_restrict_billing_view extends Migration
{
    public function safeUp()
    {
        $remove = [
            ['registration', 'billing.view'],
            ['management', 'billing.view'],
            ['pharmacy', 'billing.view'],
        ];
        foreach ($remove as [$role, $permission]) {
            $this->execute(
                'DELETE FROM role_permissions rp USING roles r, permissions p WHERE rp.role_id=r.id AND rp.permission_id=p.id AND r.code=:r AND p.code=:p',
                [':r' => $role, ':p' => $permission]
            );
        }
    }

    public function safeDown()
    {
        foreach (['registration', 'management', 'pharmacy'] as $role) {
            $this->execute(
                'INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r, permissions p WHERE r.code=:r AND p.code=:p ON CONFLICT DO NOTHING',
                [':r' => $role, ':p' => 'billing.view']
            );
        }
    }
}
