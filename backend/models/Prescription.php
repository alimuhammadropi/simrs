<?php
namespace app\models;
use yii\db\ActiveRecord;
class Prescription extends ActiveRecord {
    public static function tableName(){return '{{%prescriptions}}';}
    public function rules(){return [
        [['registration_id','patient_id','doctor_user_id'],'required'],
        [['registration_id','patient_id','doctor_user_id','examination_id','dispensed_by'],'integer'],
        [['notes'],'string'],
        [['status'],'in','range'=>['pending','dispensed','cancelled']],
    ];}
}
