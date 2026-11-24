<?php
namespace app\models;
use yii\db\ActiveRecord;
class MedicalStaff extends ActiveRecord {
    public static function tableName(){return '{{%medical_staff}}';}
    public function rules(){return [
        [['employee_number','name','role'],'required'],
        [['employee_number'],'string','max'=>40],
        [['name','role'],'string','max'=>150],
        [['is_active'],'boolean'],
        [['employee_number'],'unique'],
    ];}
}
