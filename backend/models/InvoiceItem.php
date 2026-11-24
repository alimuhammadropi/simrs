<?php
namespace app\models;
use yii\db\ActiveRecord;
class InvoiceItem extends ActiveRecord {
    public static function tableName(){return '{{%invoice_items}}';}
    public function rules(){return [
        [['invoice_id','item_type','description','quantity','unit_price','amount'],'required'],
        [['invoice_id','quantity','reference_id'],'integer'],
        [['unit_price','amount'],'number','min'=>0],
        [['description'],'string','max'=>255],
        [['item_type'],'in','range'=>['registration','action','lab','drug']],
    ];}
}
