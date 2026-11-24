<?php
namespace app\controllers\api;

use Yii;
use app\components\BillingService;
use app\components\ReportService;
use app\components\ServiceController;
use app\models\{AuditLog,Invoice,LabRequest,LabResult,User};
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

class InternalController extends ServiceController
{
    public function actionLabRequests()
    {
        $rows = (new \yii\db\Query())->select(['lr.id','lr.registration_id','lr.patient_id','lr.test_name','lr.status','lr.created_at','p.name AS patient_name','r.registration_number','res.result','res.reference_range'])
            ->from(['lr'=>'lab_requests'])
            ->innerJoin(['p'=>'patients'],'p.id=lr.patient_id')
            ->innerJoin(['r'=>'registrations'],'r.id=lr.registration_id')
            ->leftJoin(['res'=>'lab_results'],'res.lab_request_id=lr.id')
            ->where(['<>','lr.status','cancelled'])
            ->orderBy(['lr.id'=>SORT_DESC])->limit(200)->all();
        return $this->ok($rows);
    }

    public function actionLabResult()
    {
        $b = Yii::$app->request->bodyParams;
        foreach (['lab_request_id','result','created_by'] as $f) {
            if (empty($b[$f])) throw new BadRequestHttpException("$f wajib diisi.");
        }
        $user = User::findOne((int)$b['created_by']);
        if (!$user) throw new BadRequestHttpException('created_by tidak valid.');
        $tx = Yii::$app->db->beginTransaction();
        try {
            $request = LabRequest::findBySql('SELECT * FROM lab_requests WHERE id = :id FOR UPDATE', [':id'=>(int)$b['lab_request_id']])->one();
            if (!$request) throw new NotFoundHttpException('Permintaan tidak ditemukan.');
            if ($request->status === 'cancelled') throw new \DomainException('Permintaan dibatalkan.');
            $before = $request->attributes;
            $result = LabResult::findOne(['lab_request_id'=>$request->id]) ?: new LabResult();
            $result->lab_request_id = $request->id;
            $result->result = $b['result'];
            $result->reference_range = $b['reference_range'] ?? null;
            $result->created_by = $user->id;
            if (!$result->save()) throw new \DomainException(json_encode($result->errors));
            $request->status = 'completed';
            $request->completed_at = new \yii\db\Expression('NOW()');
            if (!$request->save()) throw new \DomainException(json_encode($request->errors));
            AuditLog::record('lab.result.integration', 'lab_request', $request->id, $before, $result->attributes);
            if (($invoice = Invoice::findOne(['registration_id'=>$request->registration_id]))) BillingService::rebuild($invoice->id, 'lab.result.integration.rebill');
            $tx->commit();
            return $this->ok(['request'=>$request,'result'=>$result], 'Hasil integrasi tersimpan');
        } catch (\Throwable $e) { $tx->rollBack(); throw new BadRequestHttpException($e->getMessage()); }
    }

    public function actionDashboard()
    {
        return $this->ok(ReportService::dashboard(Yii::$app->request->get('from'), Yii::$app->request->get('to')));
    }
}
