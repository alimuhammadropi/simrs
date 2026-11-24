<?php
namespace app\controllers\api;

use Yii;
use app\components\ApiController;
use app\components\BillingService;
use app\components\NotificationService;
use app\models\{AuditLog,Invoice,LabRequest,LabResult,LabTest,Registration};
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

class LabController extends ApiController
{
    public function actionIndex()
    {
        $this->requirePermission('lab.view');
        $query = (new \yii\db\Query())            ->select(['lr.*','p.name AS patient_name','p.medical_record_number','u.name AS doctor_name','lt.name AS test_name','lt.code AS test_code','lres.result AS result','lres.reference_range'])
            ->from(['lr'=>'lab_requests'])
            ->innerJoin(['p'=>'patients'],'p.id=lr.patient_id')
            ->innerJoin(['u'=>'users'],'u.id=lr.doctor_user_id')
            ->leftJoin(['lt'=>'lab_tests'],'lt.id=lr.lab_test_id')
            ->leftJoin(['lres'=>'lab_results'],'lres.lab_request_id=lr.id');
        if (($status = Yii::$app->request->get('status'))) $query->andWhere(['lr.status'=>$status]);
        if (($patient = Yii::$app->request->get('patient_id'))) $query->andWhere(['lr.patient_id'=>(int)$patient]);
        return $this->ok($query->orderBy(['lr.id'=>SORT_DESC])->limit(200)->all());
    }

    public function actionCreate()
    {
        $this->requirePermission('lab.request');
        $b = Yii::$app->request->bodyParams;
        if (empty($b['registration_id'])) throw new BadRequestHttpException('registration_id wajib diisi.');
        $registration = Registration::findOne($b['registration_id']);
        if (!$registration) throw new NotFoundHttpException('Pendaftaran tidak ditemukan.');
        $test = null;
        if (!empty($b['lab_test_id'])) {
            $test = LabTest::findOne(['id'=>(int)$b['lab_test_id'],'is_active'=>true]);
            if (!$test) throw new BadRequestHttpException('Jenis pemeriksaan tidak valid.');
        } elseif (empty($b['test_name'])) {
            throw new BadRequestHttpException('lab_test_id atau test_name wajib diisi.');
        }
        $m = new LabRequest();
        $m->registration_id = $registration->id;
        $m->examination_id = !empty($b['examination_id']) ? (int)$b['examination_id'] : null;
        $m->patient_id = $registration->patient_id;
        $m->doctor_user_id = Yii::$app->user->id;
        $m->lab_test_id = $test ? $test->id : null;
        $m->test_name = $test ? $test->name : $b['test_name'];
        $m->price_snapshot = $test ? $test->price : (float)($b['price'] ?? 0);
        $m->notes = $b['notes'] ?? null;
        $m->status = 'requested';
        $tx = Yii::$app->db->beginTransaction();
        try {
            if (!$m->save()) throw new \DomainException(json_encode($m->errors));
            AuditLog::record('lab.request.create', 'lab_request', $m->id, null, $m->attributes);
            if (($invoice = Invoice::findOne(['registration_id'=>$registration->id]))) BillingService::rebuild($invoice->id, 'lab.request.rebill');
            $tx->commit();
            $patientName = (new \yii\db\Query())->select('name')->from('patients')->where(['id' => $registration->patient_id])->scalar();
            NotificationService::notify('lab.request.created', [
                'id' => (string)$m->id,
                'patient_name' => (string)$patientName,
                'test_name' => (string)$m->test_name,
            ]);
            return $this->ok($m, 'Permintaan pemeriksaan dibuat');
        } catch (\Throwable $e) { $tx->rollBack(); throw new BadRequestHttpException($e->getMessage()); }
    }

    public function actionView($id)
    {
        $this->requirePermission('lab.view');
        $m = LabRequest::findOne($id);
        if (!$m) throw new NotFoundHttpException('Permintaan tidak ditemukan.');
        $data = $m->attributes;
        $data['result'] = LabResult::findOne(['lab_request_id'=>$m->id]);
        return $this->ok($data);
    }

    public function actionStart($id)
    {
        $this->requirePermission('lab.manage');
        $m = LabRequest::findOne($id);
        if (!$m) throw new NotFoundHttpException('Permintaan tidak ditemukan.');
        if ($m->status !== 'requested') throw new BadRequestHttpException('Permintaan tidak dalam status requested.');
        $before = $m->attributes;
        $m->status = 'in_progress';
        $m->save(false);
        AuditLog::record('lab.request.start', 'lab_request', $m->id, $before, $m->attributes);
        return $this->ok($m, 'Pemeriksaan sedang diproses');
    }

    public function actionResult($id)
    {
        $this->requirePermission('lab.manage');
        $b = Yii::$app->request->bodyParams;
        if (empty($b['result'])) throw new BadRequestHttpException('Hasil pemeriksaan wajib diisi.');
        $tx = Yii::$app->db->beginTransaction();
        try {
            $m = LabRequest::findBySql('SELECT * FROM lab_requests WHERE id = :id FOR UPDATE', [':id'=>$id])->one();
            if (!$m) throw new NotFoundHttpException('Permintaan tidak ditemukan.');
            if (in_array($m->status, ['completed','cancelled'], true)) throw new \DomainException('Permintaan sudah selesai atau dibatalkan.');
            $before = $m->attributes;
            $result = LabResult::findOne(['lab_request_id'=>$m->id]) ?: new LabResult();
            $result->lab_request_id = $m->id;
            $result->result = $b['result'];
            $result->reference_range = $b['reference_range'] ?? null;
            $result->created_by = Yii::$app->user->id;
            if (!$result->save()) throw new \DomainException(json_encode($result->errors));
            $m->status = 'completed';
            $m->completed_at = new \yii\db\Expression('NOW()');
            if (!$m->save()) throw new \DomainException(json_encode($m->errors));
            AuditLog::record('lab.result.create', 'lab_request', $m->id, $before, array_merge($m->attributes, ['result'=>$result->attributes]));
            if (($invoice = Invoice::findOne(['registration_id'=>$m->registration_id]))) BillingService::rebuild($invoice->id, 'lab.result.rebill');
            $tx->commit();
            $patientName = (new \yii\db\Query())->select('name')->from('patients')->where(['id' => $m->patient_id])->scalar();
            NotificationService::notify('lab.result', [
                'id' => (string)$m->id,
                'patient_name' => (string)$patientName,
                'test_name' => (string)$m->test_name,
                'result' => mb_substr((string)$b['result'], 0, 120),
            ]);
            return $this->ok(array_merge($m->attributes, ['result'=>$result]), 'Hasil pemeriksaan tersimpan');
        } catch (\Throwable $e) { $tx->rollBack(); throw new BadRequestHttpException($e->getMessage()); }
    }
}
