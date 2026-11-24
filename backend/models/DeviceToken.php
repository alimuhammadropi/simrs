<?php
namespace app\models;
use yii\db\ActiveRecord;
class DeviceToken extends ActiveRecord {
    public static function tableName() { return '{{%device_tokens}}'; }
    public function rules() {
        return [
            [['user_id','token'], 'required'],
            [['token'], 'string', 'max' => 512],
            [['token'], 'unique'],
            [['platform'], 'string', 'max' => 20],
            [['platform'], 'in', 'range' => ['web','android','ios']],
            [['platform'], 'default', 'value' => 'web'],
            [['is_active'], 'boolean'],
            [['is_active'], 'default', 'value' => true],
        ];
    }
}
