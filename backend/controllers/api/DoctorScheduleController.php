<?php
namespace app\controllers\api;
use Yii;
use app\components\MasterController;
use app\models\DoctorSchedule;
class DoctorScheduleController extends MasterController {
    public $modelClass = DoctorSchedule::class;
    public $searchFields = [];
    public function actionIndex()
    {
        $this->requirePermission($this->viewPermission);
        $query = (new \yii\db\Query())->select(['s.*','d.name AS doctor_name','c.name AS polyclinic_name'])
            ->from(['s'=>'doctor_schedules'])
            ->innerJoin(['d'=>'doctors'],'d.id=s.doctor_id')
            ->innerJoin(['c'=>'polyclinics'],'c.id=s.polyclinic_id');
        if (($doctor = Yii::$app->request->get('doctor_id'))) $query->andWhere(['s.doctor_id'=>(int)$doctor]);
        if (($poly = Yii::$app->request->get('polyclinic_id'))) $query->andWhere(['s.polyclinic_id'=>(int)$poly]);
        return $this->ok($query->orderBy(['s.day_of_week'=>SORT_ASC,'s.start_time'=>SORT_ASC])->all());
    }
}
