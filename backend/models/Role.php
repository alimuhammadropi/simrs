<?php
namespace app\models;
use yii\db\ActiveRecord;
class Role extends ActiveRecord {
    public static function tableName(){return '{{%roles}}';}
    public function rules(){return [[['code','name'],'required'],[['code'],'string','max'=>50],[['name'],'string','max'=>100],[['code'],'unique']];}
}
