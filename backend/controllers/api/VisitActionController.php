<?php
namespace app\controllers\api;

use Yii;
use app\components\ApiController;
use app\components\BillingService;
use app\models\{AuditLog,Invoice,MedicalAction,Registration,VisitAction};
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

class VisitActionController extends ApiController
{
    public function actionIndex($registration_id)
    {
        $this->requirePermission('examination.view');
        if (!Registration::findOne($registration_id)) throw new NotFoundHttpException('Pendaftaran tidak ditemukan.');
        $rows = (new \yii\db\Query())->select(['va.*','ma.name AS action_name','ma.code AS action_code'])
            ->from(['va'=>'visit_actions'])->innerJoin(['ma'=>'medical_actions'],'ma.id=va.medical_action_id')
            ->where(['va.registration_id'=>(int)$registration_id])->orderBy(['va.id'=>SORT_ASC])->all();
        return $this->ok($rows);
    }

    public function actionCreate($registration_id)
    {
        $this->requirePermission('examination.create');
        $b = Yii::$app->request->bodyParams;
        if (empty($b['medical_action_id'])) throw new BadRequestHttpException('medical_action_id wajib diisi.');
        $registration = Registration::findOne($registration_id);
        if (!$registration) throw new NotFoundHttpException('Pendaftaran tidak ditemukan.');
        $action = MedicalAction::findOne(['id'=>(int)$b['medical_action_id'],'is_active'=>true]);
        if (!$action) throw new BadRequestHttpException('Tindakan tidak valid.');
        $quantity = max(1, (int)($b['quantity'] ?? 1));
        $m = new VisitAction();
        $m->registration_id = $registration->id;
        $m->medical_action_id = $action->id;
        $m->quantity = $quantity;
        $m->price_snapshot = $action->price;
        $m->created_by = Yii::$app->user->id;
        $tx = Yii::$app->db->beginTransaction();
        try {
            if (!$m->save()) throw new \DomainException(json_encode($m->errors));
            AuditLog::record('visit_action.create', 'visit_action', $m->id, null, $m->attributes);
            if (($invoice = Invoice::findOne(['registration_id'=>$registration->id]))) BillingService::rebuild($invoice->id, 'visit_action.create.rebill');
            $tx->commit();
            return $this->ok($m, 'Tindakan ditambahkan');
        } catch (\Throwable $e) { $tx->rollBack(); throw new BadRequestHttpException($e->getMessage()); }
    }

    public function actionUpdate($id)
    {
        $this->requirePermission('examination.create');
        $m = VisitAction::findOne($id);
        if (!$m) throw new NotFoundHttpException('Tindakan tidak ditemukan.');
        $b = Yii::$app->request->bodyParams;
        $before = $m->attributes;
        if (isset($b['quantity'])) $m->quantity = max(1, (int)$b['quantity']);
        $tx = Yii::$app->db->beginTransaction();
        try {
            if (!$m->save()) throw new \DomainException(json_encode($m->errors));
            AuditLog::record('visit_action.update', 'visit_action', $m->id, $before, $m->attributes);
            if (($invoice = Invoice::findOne(['registration_id'=>$m->registration_id]))) BillingService::rebuild($invoice->id, 'visit_action.update.rebill');
            $tx->commit();
            return $this->ok($m, 'Tindakan diperbarui');
        } catch (\Throwable $e) { $tx->rollBack(); throw new BadRequestHttpException($e->getMessage()); }
    }

    public function actionDelete($id)
    {
        $this->requirePermission('examination.create');
        $m = VisitAction::findOne($id);
        if (!$m) throw new NotFoundHttpException('Tindakan tidak ditemukan.');
        $before = $m->attributes;
        $tx = Yii::$app->db->beginTransaction();
        try {
            $m->delete();
            AuditLog::record('visit_action.delete', 'visit_action', $id, $before, null);
            if (($invoice = Invoice::findOne(['registration_id'=>$before['registration_id']]))) BillingService::rebuild($invoice->id, 'visit_action.delete.rebill');
            $tx->commit();
            return $this->ok([], 'Tindakan dihapus');
        } catch (\Throwable $e) { $tx->rollBack(); throw new BadRequestHttpException($e->getMessage()); }
    }
}
