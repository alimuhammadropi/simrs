<?php
namespace app\models;
use yii\db\ActiveRecord;
class Patient extends ActiveRecord {
    public static function tableName() { return '{{%patients}}'; }
    public function rules() {
        return [
            [['medical_record_number','name','gender'], 'required'],
            [['birth_date'], 'safe'],
            [['address'], 'string'],
            [['name'], 'string', 'max' => 150],
            [['nik'], 'string', 'max' => 16],
            [['phone'], 'string', 'max' => 30],
            [['medical_record_number'], 'string', 'max' => 30],
            [['gender'], 'in', 'range' => ['L','P']],
            [['nik'], 'match', 'pattern' => '/^\d{16}$/', 'skipOnEmpty' => true],
            [['is_active'], 'boolean'],
            [['medical_record_number'], 'unique'],
            [['nik'], 'unique'],
        ];
    }
    public function beforeValidate()
    {
        if (!parent::beforeValidate()) return false;
        if ($this->isNewRecord && !$this->medical_record_number) {
            $this->medical_record_number = 'RM' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        }
        return true;
    }
    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) return false;
        if ($insert && !$this->medical_record_number) {
            $this->medical_record_number = 'RM' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        }
        return true;
    }
}
