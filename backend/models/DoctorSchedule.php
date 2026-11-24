<?php
namespace app\models;
use yii\db\ActiveRecord;
class DoctorSchedule extends ActiveRecord {
    public static function tableName(){return '{{%doctor_schedules}}';}
    public function rules(){return [
        [['doctor_id','polyclinic_id','day_of_week','start_time','end_time'],'required'],
        [['doctor_id','polyclinic_id'],'integer'],
        [['day_of_week'],'integer','min'=>0,'max'=>6],
        [['start_time','end_time'],'safe'],
        [['is_active'],'boolean'],
    ];}
    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) return false;
        if (strtotime($this->start_time) >= strtotime($this->end_time)) {
            $this->addError('start_time', 'Jam mulai harus sebelum jam selesai.');
            return false;
        }
        $overlap = static::find()->where([
            'doctor_id' => $this->doctor_id,
            'polyclinic_id' => $this->polyclinic_id,
            'day_of_week' => $this->day_of_week,
            'is_active' => true,
        ])->andWhere(['<', 'start_time', $this->end_time])->andWhere(['>', 'end_time', $this->start_time]);
        if (!$this->isNewRecord) $overlap->andWhere(['<>', 'id', $this->id]);
        if ($overlap->exists()) {
            $this->addError('start_time', 'Jadwal bertabrakan dengan jadwal aktif lainnya.');
            return false;
        }
        return true;
    }
}
