<?php
namespace app\models;
use yii\db\ActiveRecord;
class Examination extends ActiveRecord {
    public static function tableName(){return '{{%examinations}}';}
    public function rules(){return [
        [['registration_id','doctor_user_id','subjective','objective','assessment','plan'],'required'],
        [['registration_id','doctor_user_id','version'],'integer'],
        [['subjective','objective','assessment','plan','physical_exam','vital_signs'],'safe'],
        [['status'],'in','range'=>['draft','final']],
        [['registration_id'],'unique'],
    ];}
}
