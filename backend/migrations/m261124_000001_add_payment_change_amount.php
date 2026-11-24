<?php

use yii\db\Migration;

class m261124_000001_add_payment_change_amount extends Migration
{
    public function safeUp()
    {
        $this->addColumn('payments', 'change_amount', $this->decimal(14, 2)->notNull()->defaultValue(0));
        $this->execute('ALTER TABLE payments ADD CONSTRAINT chk_payments_change_amount CHECK (change_amount >= 0)');
    }

    public function safeDown()
    {
        $this->execute('ALTER TABLE payments DROP CONSTRAINT IF EXISTS chk_payments_change_amount');
        $this->dropColumn('payments', 'change_amount');
    }
}
