<?php
namespace app\controllers\api;
use app\components\MasterController;
use app\components\NotificationService;
use app\models\Patient;
class PatientController extends MasterController {
    public $modelClass = Patient::class;
    public $viewPermission = 'patient.view';
    public $managePermission = 'patient.create';
    public $searchFields = ['name','medical_record_number','nik'];

    protected function afterCreate($model)
    {
        NotificationService::notify('patient.created', [
            'id' => (string)$model->id,
            'patient_name' => (string)$model->name,
            'medical_record_number' => (string)$model->medical_record_number,
        ]);
    }

    public function actionVisits($id)
    {
        $this->requirePermission('patient.view');
        if (!Patient::findOne($id)) throw new \yii\web\NotFoundHttpException('Pasien tidak ditemukan.');
        $rows = (new \yii\db\Query())->select(['r.id','r.registration_number','r.registration_date','r.queue_number','r.status','c.name AS polyclinic_name','d.name AS doctor_name','e.id AS examination_id','e.assessment'])
            ->from(['r'=>'registrations'])
            ->innerJoin(['c'=>'polyclinics'],'c.id=r.polyclinic_id')
            ->innerJoin(['d'=>'doctors'],'d.id=r.doctor_id')
            ->leftJoin(['e'=>'examinations'],'e.registration_id=r.id')
            ->where(['r.patient_id'=>$id])->orderBy(['r.registration_date'=>SORT_DESC,'r.id'=>SORT_DESC])->all();
        return $this->ok($rows);
    }

    public function actionMedications($id)
    {
        $this->requirePermission('patient.view');
        if (!Patient::findOne($id)) throw new \yii\web\NotFoundHttpException('Pasien tidak ditemukan.');
        $rows = (new \yii\db\Query())->select([
            'p.id AS prescription_id', 'pi.id AS item_id', 'r.registration_number', 'r.registration_date',
            'p.status', 'p.created_at AS prescribed_at', 'p.dispensed_at', 'p.notes',
            'd.id AS drug_id', 'd.name AS drug_name', 'd.unit', 'pi.dose', 'pi.frequency', 'pi.quantity',
            'du.name AS doctor_name', 'su.name AS dispensed_by_name',
        ])
            ->from(['p' => 'prescriptions'])
            ->innerJoin(['pi' => 'prescription_items'], 'pi.prescription_id=p.id')
            ->innerJoin(['d' => 'drugs'], 'd.id=pi.drug_id')
            ->innerJoin(['r' => 'registrations'], 'r.id=p.registration_id')
            ->leftJoin(['du' => 'users'], 'du.id=p.doctor_user_id')
            ->leftJoin(['su' => 'users'], 'su.id=p.dispensed_by')
            ->where(['p.patient_id' => $id])
            ->orderBy(['p.created_at' => SORT_DESC, 'pi.id' => SORT_DESC])
            ->all();
        return $this->ok($rows);
    }
}
