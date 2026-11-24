<?php
namespace app\models;
use yii\db\ActiveRecord;
class Payment extends ActiveRecord {
    public static function tableName(){return '{{%payments}}';}
    public function rules(){return [
        [['payment_number','invoice_id','amount','method','idempotency_key'],'required'],
        [['invoice_id','created_by'],'integer'],
        [['change_amount','tendered_amount'],'default','value'=>0],
        [['amount'],'number','min'=>0.01],
        [['change_amount'],'number','min'=>0],
        [['tendered_amount'],'number','min'=>0],
        [['method'],'in','range'=>['cash','transfer','qris','card']],
        [['payment_number','idempotency_key'],'string','max'=>120],
        [['reference_no'],'string','max'=>100],
        [['payment_number','idempotency_key'],'unique'],
    ];}
}
