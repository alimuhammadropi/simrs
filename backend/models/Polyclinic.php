<?php
namespace app\models;
use yii\db\ActiveRecord;
class Polyclinic extends ActiveRecord {
    public static function tableName(){return '{{%polyclinics}}';}
    public function rules(){return [
        [['code','name'],'required'],
        [['code'],'string','max'=>20],
        [['name'],'string','max'=>100],
        [['description'],'string'],
        [['is_active'],'boolean'],
        [['code'],'unique'],
    ];}
}
