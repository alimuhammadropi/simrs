<?php

use yii\db\Migration;

class m261124_000003_add_payment_tendered_amount extends Migration
{
    public function safeUp()
    {
        $this->addColumn('payments', 'tendered_amount', $this->decimal(14, 2)->notNull()->defaultValue(0));
        $this->execute('ALTER TABLE payments ADD CONSTRAINT chk_payments_tendered_amount CHECK (tendered_amount >= 0)');
        $this->execute('UPDATE payments SET tendered_amount = amount + change_amount');
    }

    public function safeDown()
    {
        $this->execute('ALTER TABLE payments DROP CONSTRAINT IF EXISTS chk_payments_tendered_amount');
        $this->dropColumn('payments', 'tendered_amount');
    }
}
