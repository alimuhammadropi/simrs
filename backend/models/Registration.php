<?php
namespace app\models;
use yii\db\ActiveRecord;
class Registration extends ActiveRecord {
    public static function tableName(){return '{{%registrations}}';}
    public function rules(){return [
        [['patient_id','polyclinic_id','doctor_id','registration_date','queue_number','registration_number'],'required'],
        [['patient_id','polyclinic_id','doctor_id','queue_number','schedule_id'],'integer'],
        [['registration_date'],'date','format'=>'php:Y-m-d'],
        [['status'],'in','range'=>['registered','waiting','in_exam','completed','cancelled']],
        [['idempotency_key','registration_number'],'string','max'=>120],
        [['registration_number'],'unique'],
        [['idempotency_key'],'unique'],
    ];}
}
