<?php
namespace app\models;
use yii\db\ActiveRecord;
class Diagnosis extends ActiveRecord {
    public static function tableName(){return '{{%diagnoses}}';}
    public function rules(){return [
        [['code','name'],'required'],
        [['code'],'string','max'=>20],
        [['name'],'string','max'=>255],
        [['is_active'],'boolean'],
        [['code'],'unique'],
    ];}
}
