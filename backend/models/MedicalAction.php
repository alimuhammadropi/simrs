<?php
namespace app\models;
use yii\db\ActiveRecord;
class MedicalAction extends ActiveRecord {
    public static function tableName(){return '{{%medical_actions}}';}
    public function rules(){return [
        [['code','name','price'],'required'],
        [['code'],'string','max'=>30],
        [['name'],'string','max'=>150],
        [['price'],'number','min'=>0],
        [['is_active'],'boolean'],
        [['code'],'unique'],
    ];}
}
