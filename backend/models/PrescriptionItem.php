<?php
namespace app\models;
use yii\db\ActiveRecord;
class PrescriptionItem extends ActiveRecord {
    public static function tableName(){return '{{%prescription_items}}';}
    public function rules(){return [
        [['prescription_id','drug_id','dose','frequency','quantity','price_snapshot'],'required'],
        [['prescription_id','drug_id','quantity'],'integer'],
        [['quantity'],'integer','min'=>1],
        [['price_snapshot'],'number','min'=>0],
        [['dose','frequency'],'string','max'=>100],
    ];}
}
