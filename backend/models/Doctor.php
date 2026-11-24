<?php
namespace app\models;
use yii\db\ActiveRecord;
class Doctor extends ActiveRecord {
    public static function tableName(){return '{{%doctors}}';}
    public function rules(){return [
        [['name'],'required'],
        [['name','sip_number','specialty'],'string','max'=>150],
        [['is_active'],'boolean'],
    ];}
}
