<?php
namespace app\models;
use yii\db\ActiveRecord;
class LabTest extends ActiveRecord {
    public static function tableName(){return '{{%lab_tests}}';}
    public function rules(){return [
        [['code','name','price'],'required'],
        [['code'],'string','max'=>30],
        [['name'],'string','max'=>200],
        [['price'],'number','min'=>0],
        [['is_active'],'boolean'],
        [['code'],'unique'],
    ];}
}
