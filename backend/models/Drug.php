<?php
namespace app\models;
use yii\db\ActiveRecord;
class Drug extends ActiveRecord {
    public static function tableName(){return '{{%drugs}}';}
    public function rules(){return [
        [['code','name','unit','price','stock'],'required'],
        [['code'],'string','max'=>30],
        [['name'],'string','max'=>150],
        [['unit'],'string','max'=>20],
        [['price'],'number','min'=>0],
        [['stock','min_stock'],'integer','min'=>0],
        [['is_active'],'boolean'],
        [['code'],'unique'],
    ];}
}
