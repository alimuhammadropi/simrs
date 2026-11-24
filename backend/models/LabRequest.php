<?php
namespace app\models;
use yii\db\ActiveRecord;
class LabRequest extends ActiveRecord {
    public static function tableName(){return '{{%lab_requests}}';}
    public function rules(){return [
        [['registration_id','patient_id','doctor_user_id','test_name'],'required'],
        [['registration_id','patient_id','doctor_user_id','examination_id'],'integer'],
        [['test_name'],'string','max'=>200],
        [['notes'],'string'],
        [['status'],'in','range'=>['requested','in_progress','completed','cancelled']],
    ];}
}
