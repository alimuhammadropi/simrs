<?php
namespace app\models;
use yii\db\ActiveRecord;
class VisitAction extends ActiveRecord {
    public static function tableName(){return '{{%visit_actions}}';}
    public function rules(){return [
        [['registration_id','medical_action_id','quantity','price_snapshot'],'required'],
        [['registration_id','medical_action_id','quantity','examination_id','created_by'],'integer'],
        [['quantity'],'integer','min'=>1],
        [['price_snapshot'],'number','min'=>0],
    ];}
}
