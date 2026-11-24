<?php
namespace app\models;
use yii\db\ActiveRecord;
class LabResult extends ActiveRecord {
    public static function tableName(){return '{{%lab_results}}';}
    public function rules(){return [
        [['lab_request_id','result','created_by'],'required'],
        [['lab_request_id','created_by'],'integer'],
        [['result'],'string'],
        [['reference_range'],'string','max'=>150],
        [['lab_request_id'],'unique'],
    ];}
}
