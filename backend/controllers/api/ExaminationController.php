<?php
namespace app\controllers\api;

use Yii;
use app\components\ApiController;
use app\components\BillingService;
use app\components\NotificationService;
use app\models\{AuditLog,Diagnosis,Examination,Invoice,Registration};
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

class ExaminationController extends ApiController
{
    private function body()
    {
        $b = Yii::$app->request->bodyParams;
        return is_array($b) ? $b : [];
    }

    private function saveDiagnoses($examinationId, $diagnoses)
    {
        Yii::$app->db->createCommand()->delete('examination_diagnoses', ['examination_id' => $examinationId])->execute();
        if (!is_array($diagnoses)) return;
        $primaryUsed = false;
        foreach ($diagnoses as $item) {
            $id = (int)($item['diagnosis_id'] ?? $item['id'] ?? 0);
            if (!$id || !Diagnosis::findOne(['id' => $id, 'is_active' => true])) continue;
            $isPrimary = !$primaryUsed && !empty($item['is_primary']);
            if ($isPrimary) $primaryUsed = true;
            Yii::$app->db->createCommand()->insert('examination_diagnoses', [
                'examination_id' => $examinationId,
                'diagnosis_id' => $id,
                'is_primary' => $isPrimary ? true : false,
            ])->execute();
        }
    }

    private function diagnosesOf($examinationId)
    {
        return (new \yii\db\Query())->select(['d.id','d.code','d.name','ed.is_primary'])
            ->from(['ed' => 'examination_diagnoses'])
            ->innerJoin(['d' => 'diagnoses'], 'd.id = ed.diagnosis_id')
            ->where(['ed.examination_id' => $examinationId])
            ->orderBy(['ed.is_primary' => SORT_DESC, 'd.code' => SORT_ASC])->all();
    }

    public function actionIndex()
    {
        $this->requirePermission('examination.view');
        $query = (new \yii\db\Query())->select(['e.*','r.registration_number','r.registration_date','p.id AS patient_id','p.name AS patient_name','p.medical_record_number','u.name AS doctor_name'])
            ->from(['e' => 'examinations'])
            ->innerJoin(['r' => 'registrations'], 'r.id = e.registration_id')
            ->innerJoin(['p' => 'patients'], 'p.id = r.patient_id')
            ->innerJoin(['u' => 'users'], 'u.id = e.doctor_user_id');
        if (($status = Yii::$app->request->get('status'))) $query->andWhere(['e.status' => $status]);
        if (($patient = Yii::$app->request->get('patient_id'))) $query->andWhere(['p.id' => (int)$patient]);
        $rows = $query->orderBy(['e.id' => SORT_DESC])->limit(200)->all();
        foreach ($rows as &$row) $row['diagnoses'] = $this->diagnosesOf($row['id']);
        return $this->ok($rows);
    }

    public function actionWaiting()
    {
        $this->requirePermission('examination.view');
        $date = Yii::$app->request->get('date', date('Y-m-d'));
        $query = (new \yii\db\Query())->select(['r.id','r.registration_number','r.queue_number','r.status','r.registration_date','p.name AS patient_name','p.medical_record_number','c.name AS polyclinic_name','e.id AS examination_id'])
            ->from(['r' => 'registrations'])
            ->innerJoin(['p' => 'patients'], 'p.id = r.patient_id')
            ->innerJoin(['c' => 'polyclinics'], 'c.id = r.polyclinic_id')
            ->leftJoin(['e' => 'examinations'], 'e.registration_id = r.id')
            ->where(['r.registration_date' => $date, 'r.status' => ['waiting','in_exam']]);
        if (($poly = Yii::$app->request->get('polyclinic_id'))) $query->andWhere(['r.polyclinic_id' => (int)$poly]);
        return $this->ok($query->orderBy(['r.queue_number' => SORT_ASC])->all());
    }

    public function actionCreate()
    {
        $this->requirePermission('examination.create');
        $b = $this->body();
        foreach (['registration_id','subjective','objective','assessment','plan'] as $f) {
            if (empty($b[$f])) throw new BadRequestHttpException("$f wajib diisi.");
        }
        $tx = Yii::$app->db->beginTransaction();
        try {
            $registration = Registration::findOne($b['registration_id']);
            if (!$registration) throw new \DomainException('Pendaftaran tidak ditemukan.');
            if (!in_array($registration->status, ['waiting','in_exam'], true)) throw new \DomainException('Pasien belum dalam antrean pemeriksaan.');
            if (Examination::findOne(['registration_id' => $registration->id])) throw new \DomainException('Pemeriksaan untuk kunjungan ini sudah ada.');
            $m = new Examination();
            $m->registration_id = $registration->id;
            $m->doctor_user_id = Yii::$app->user->id;
            $m->subjective = $b['subjective'];
            $m->objective = $b['objective'];
            $m->physical_exam = $b['physical_exam'] ?? null;
            $m->assessment = $b['assessment'];
            $m->plan = $b['plan'];
            $m->vital_signs = isset($b['vital_signs']) && is_array($b['vital_signs']) ? $b['vital_signs'] : null;
            $m->status = 'draft';
            if (!$m->save()) throw new \DomainException(json_encode($m->errors));
            $this->saveDiagnoses($m->id, $b['diagnoses'] ?? []);
            $before = $registration->attributes;
            $registration->status = 'in_exam';
            $registration->save(false);
            AuditLog::record('examination.create', 'examination', $m->id, $before, $m->attributes);
            $tx->commit();
            return $this->ok(array_merge($m->attributes, ['diagnoses' => $this->diagnosesOf($m->id)]), 'Pemeriksaan tersimpan');
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw new BadRequestHttpException($e->getMessage());
        }
    }

    public function actionView($id)
    {
        $this->requirePermission('examination.view');
        $m = Examination::findOne($id);
        if (!$m) throw new NotFoundHttpException('Pemeriksaan tidak ditemukan.');
        $data = $m->attributes;
        $data['diagnoses'] = $this->diagnosesOf($m->id);
        $data['actions'] = (new \yii\db\Query())->select(['va.*','ma.name AS action_name'])->from(['va'=>'visit_actions'])
            ->innerJoin(['ma'=>'medical_actions'],'ma.id=va.medical_action_id')->where(['va.registration_id'=>$m->registration_id])->all();
        $data['prescriptions'] = (new \yii\db\Query())->select(['p.*','u.name AS doctor_name'])->from(['p'=>'prescriptions'])
            ->leftJoin(['u'=>'users'],'u.id=p.doctor_user_id')->where(['p.examination_id'=>$m->id])->orderBy(['p.id'=>SORT_DESC])->all();
        foreach ($data['prescriptions'] as &$prescription) {
            $prescription['items'] = (new \yii\db\Query())->select(['pi.*','d.name AS drug_name','d.unit'])
                ->from(['pi'=>'prescription_items'])
                ->innerJoin(['d'=>'drugs'],'d.id=pi.drug_id')
                ->where(['pi.prescription_id'=>$prescription['id']])->all();
        }
        unset($prescription);
        $data['lab_requests'] = (new \yii\db\Query())->select(['lr.*','lt.name AS test_name','lt.code AS test_code','lres.result AS result','lres.reference_range AS reference_range','lres.created_at AS result_at','u.name AS result_by'])->from(['lr'=>'lab_requests'])
            ->leftJoin(['lt'=>'lab_tests'],'lt.id=lr.lab_test_id')
            ->leftJoin(['lres'=>'lab_results'],'lres.lab_request_id=lr.id')
            ->leftJoin(['u'=>'users'],'u.id=lres.created_by')
            ->where(['lr.examination_id'=>$m->id])->orderBy(['lr.id'=>SORT_ASC])->all();
        return $this->ok($data);
    }

    public function actionUpdate($id)
    {
        $this->requirePermission('examination.create');
        $m = Examination::findOne($id);
        if (!$m) throw new NotFoundHttpException('Pemeriksaan tidak ditemukan.');
        $b = $this->body();
        $invoice = Invoice::findOne(['registration_id' => $m->registration_id]);
        if ($m->status === 'final' && empty($b['addendum_reason'])) {
            throw new BadRequestHttpException('Pemeriksaan final hanya dapat diubah sebagai addendum dengan alasan.');
        }
        $tx = Yii::$app->db->beginTransaction();
        try {
            $before = $m->attributes;
            foreach (['subjective','objective','assessment','plan'] as $f) {
                if (isset($b[$f])) $m->$f = $b[$f];
            }
            if (isset($b['physical_exam'])) $m->physical_exam = $b['physical_exam'];
            if (isset($b['vital_signs'])) $m->vital_signs = is_array($b['vital_signs']) ? $b['vital_signs'] : null;
            $m->version = (int)$m->version + 1;
            if (!$m->save()) throw new \DomainException(json_encode($m->errors));
            if (array_key_exists('diagnoses', $b)) $this->saveDiagnoses($m->id, $b['diagnoses']);
            AuditLog::record($m->status === 'final' ? 'examination.addendum' : 'examination.update', 'examination', $m->id, $before, [
                'after' => $m->attributes,
                'reason' => $b['addendum_reason'] ?? null,
                'diagnoses' => $this->diagnosesOf($m->id),
            ]);
            if ($invoice) BillingService::rebuild($invoice->id, 'examination.addendum.rebill');
            $tx->commit();
            return $this->ok(array_merge($m->attributes, ['diagnoses' => $this->diagnosesOf($m->id)]), 'Pemeriksaan diperbarui');
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw new BadRequestHttpException($e->getMessage());
        }
    }

    public function actionFinalize($id)
    {
        $this->requirePermission('examination.create');
        $m = Examination::findOne($id);
        if (!$m) throw new NotFoundHttpException('Pemeriksaan tidak ditemukan.');
        if ($m->status === 'final') throw new BadRequestHttpException('Pemeriksaan sudah difinalisasi.');
        $before = $m->attributes;
        $m->status = 'final';
        $m->finalized_at = new \yii\db\Expression('NOW()');
        $m->version = (int)$m->version + 1;
        if (!$m->save()) throw new BadRequestHttpException(json_encode($m->errors));
        $registration = Registration::findOne($m->registration_id);
        if ($registration) { $registration->status = 'completed'; $registration->save(false); }
        AuditLog::record('examination.finalize', 'examination', $m->id, $before, $m->attributes);
        $patientName = $registration
            ? (new \yii\db\Query())->select('name')->from('patients')->where(['id' => $registration->patient_id])->scalar()
            : '';
        NotificationService::notify('examination.finalized', [
            'id' => (string)$m->id,
            'registration_number' => (string)($registration->registration_number ?? ''),
            'patient_name' => (string)$patientName,
        ]);
        return $this->ok($m, 'Pemeriksaan difinalisasi');
    }
}
