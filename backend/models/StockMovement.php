<?php
namespace app\models;
use yii\db\ActiveRecord;
class StockMovement extends ActiveRecord {
    public static function tableName(){return '{{%stock_movements}}';}
    public function rules(){return [
        [['drug_id','quantity','reason'],'required'],
        [['drug_id','quantity','reference_id','created_by'],'integer'],
        [['reason'],'string','max'=>100],
        [['reference_type'],'string','max'=>50],
    ];}
}
