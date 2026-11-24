<?php
namespace app\models;
use yii\db\ActiveRecord;
class Invoice extends ActiveRecord {
    public static function tableName(){return '{{%invoices}}';}
    public function rules(){return [
        [['invoice_number','registration_id','patient_id'],'required'],
        [['registration_id','patient_id'],'integer'],
        [['subtotal','discount','total','paid_amount'],'number','min'=>0],
        [['status'],'in','range'=>['unpaid','partial','paid','cancelled']],
        [['invoice_number'],'string','max'=>50],
        [['invoice_number','registration_id'],'unique'],
    ];}
}
