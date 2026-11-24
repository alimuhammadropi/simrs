<?php
namespace app\controllers\api;

use Yii;
use app\components\ApiController;
use app\components\BillingService;
use app\components\NotificationService;
use app\models\{AuditLog,Drug,Examination,Invoice,Prescription,PrescriptionItem,Registration,StockMovement};
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

class PrescriptionController extends ApiController
{
    private function assertAvailableStock(array $needed, ?int $excludePrescriptionId = null)
    {
        ksort($needed);
        foreach ($needed as $drugId => $qty) {
            $drug = Drug::findBySql('SELECT * FROM drugs WHERE id = :id FOR UPDATE', [':id' => $drugId])->one();
            if (!$drug || !(bool)$drug->is_active) throw new \DomainException('Obat tidak ditemukan.');
            $reserved = (new \yii\db\Query())->select(new \yii\db\Expression('COALESCE(SUM(pi.quantity), 0)'))
                ->from(['pi' => 'prescription_items'])
                ->innerJoin(['p' => 'prescriptions'], 'p.id=pi.prescription_id')
                ->where(['pi.drug_id' => $drugId, 'p.status' => 'pending']);
            if ($excludePrescriptionId) $reserved->andWhere(['<>', 'p.id', $excludePrescriptionId]);
            $available = max(0, (int)$drug->stock - (int)$reserved->scalar());
            if ($available < $qty) {
                throw new \DomainException('Stok ' . $drug->name . ' tersedia hanya ' . $available . ' ' . $drug->unit . '.');
            }
        }
    }

    public function actionIndex()
    {
        $this->requirePermission('pharmacy.view');
        $query = (new \yii\db\Query())->select(['p.*','p_.name AS patient_name','p_.medical_record_number','u.name AS doctor_name'])
            ->from(['p'=>'prescriptions'])
            ->innerJoin(['p_'=>'patients'],'p_.id=p.patient_id')
            ->innerJoin(['u'=>'users'],'u.id=p.doctor_user_id');
        if (($status = Yii::$app->request->get('status'))) $query->andWhere(['p.status'=>$status]);
        if (($patient = Yii::$app->request->get('patient_id'))) $query->andWhere(['p.patient_id'=>(int)$patient]);
        $rows = $query->orderBy(['p.id'=>SORT_DESC])->limit(200)->all();
        foreach ($rows as &$row) {
            $row['items'] = (new \yii\db\Query())->select(['pi.*','d.name AS drug_name','d.unit'])->from(['pi'=>'prescription_items'])
                ->innerJoin(['d'=>'drugs'],'d.id=pi.drug_id')->where(['pi.prescription_id'=>$row['id']])->all();
        }
        return $this->ok($rows);
    }

    public function actionCreate()
    {
        $this->requirePermission('prescription.create');
        $b = Yii::$app->request->bodyParams;
        if (empty($b['registration_id']) || empty($b['items']) || !is_array($b['items'])) {
            throw new BadRequestHttpException('registration_id dan items wajib diisi.');
        }
        $registration = Registration::findOne($b['registration_id']);
        if (!$registration) throw new NotFoundHttpException('Pendaftaran tidak ditemukan.');
        if (!empty($b['examination_id'])) {
            $examination = Examination::findOne((int)$b['examination_id']);
            if ($examination && $examination->status === 'final') {
                throw new BadRequestHttpException('Pemeriksaan sudah difinalisasi; resep tidak dapat dibuat.');
            }
        }
        $needed = [];
        foreach ($b['items'] as $item) {
            $drugId = (int)($item['drug_id'] ?? 0);
            $qty = (int)($item['quantity'] ?? 0);
            if (!$drugId || $qty < 1 || empty($item['dose']) || empty($item['frequency'])) {
                throw new BadRequestHttpException('Setiap item memerlukan drug_id, dose, frequency, dan quantity.');
            }
            $needed[$drugId] = ($needed[$drugId] ?? 0) + $qty;
        }
        $tx = Yii::$app->db->beginTransaction();
        try {
            $this->assertAvailableStock($needed);
            $m = new Prescription();
            $m->registration_id = $registration->id;
            $m->examination_id = !empty($b['examination_id']) ? (int)$b['examination_id'] : null;
            $m->patient_id = $registration->patient_id;
            $m->doctor_user_id = Yii::$app->user->id;
            $m->notes = $b['notes'] ?? null;
            $m->status = 'pending';
            if (!$m->save()) throw new \DomainException(json_encode($m->errors));
            foreach ($b['items'] as $item) {
                $drug = Drug::findOne((int)$item['drug_id']);
                $row = new PrescriptionItem();
                $row->prescription_id = $m->id;
                $row->drug_id = $drug->id;
                $row->dose = $item['dose'];
                $row->frequency = $item['frequency'];
                $row->quantity = (int)$item['quantity'];
                $row->price_snapshot = $drug->price;
                if (!$row->save()) throw new \DomainException(json_encode($row->errors));
            }
            AuditLog::record('prescription.create', 'prescription', $m->id, null, $b);
            $tx->commit();
            $patientName = (new \yii\db\Query())->select('name')->from('patients')->where(['id' => $registration->patient_id])->scalar();
            NotificationService::notify('prescription.created', [
                'id' => (string)$m->id,
                'patient_name' => (string)$patientName,
                'item_count' => count($b['items']),
            ]);
            return $this->ok($m, 'Resep dibuat');
        } catch (\Throwable $e) { $tx->rollBack(); throw new BadRequestHttpException($e->getMessage()); }
    }

    public function actionUpdate($id)
    {
        $this->requirePermission('prescription.create');
        $m = Prescription::findOne($id);
        if (!$m) throw new NotFoundHttpException('Resep tidak ditemukan.');
        if ((int)$m->doctor_user_id !== (int)Yii::$app->user->id) {
            throw new ForbiddenHttpException('Hanya dokter pembuat yang dapat mengubah resep ini.');
        }
        if ($m->status !== 'pending') {
            throw new BadRequestHttpException('Resep sudah diproses farmasi dan tidak dapat diubah.');
        }
        if ($m->examination_id) {
            $examination = Examination::findOne($m->examination_id);
            if ($examination && $examination->status === 'final') {
                throw new BadRequestHttpException('Pemeriksaan sudah difinalisasi; resep tidak dapat diubah.');
            }
        }

        $b = Yii::$app->request->bodyParams;
        if (empty($b['items']) || !is_array($b['items'])) {
            throw new BadRequestHttpException('Items wajib diisi.');
        }
        $needed = [];
        foreach ($b['items'] as $item) {
            $drugId = (int)($item['drug_id'] ?? 0);
            $qty = (int)($item['quantity'] ?? 0);
            if (!$drugId || $qty < 1 || empty($item['dose']) || empty($item['frequency'])) {
                throw new BadRequestHttpException('Setiap item memerlukan drug_id, dose, frequency, dan quantity.');
            }
            $needed[$drugId] = ($needed[$drugId] ?? 0) + $qty;
        }

        $tx = Yii::$app->db->beginTransaction();
        try {
            $this->assertAvailableStock($needed, (int)$m->id);
            $before = $m->attributes;
            $m->notes = $b['notes'] ?? null;
            if (!$m->save()) throw new \DomainException(json_encode($m->errors));
            PrescriptionItem::deleteAll(['prescription_id'=>$m->id]);
            foreach ($b['items'] as $item) {
                $drug = Drug::findOne((int)$item['drug_id']);
                $row = new PrescriptionItem();
                $row->prescription_id = $m->id;
                $row->drug_id = $drug->id;
                $row->dose = $item['dose'];
                $row->frequency = $item['frequency'];
                $row->quantity = (int)$item['quantity'];
                $row->price_snapshot = $drug->price;
                if (!$row->save()) throw new \DomainException(json_encode($row->errors));
            }
            AuditLog::record('prescription.update', 'prescription', $m->id, $before, $b);
            $tx->commit();
            return $this->ok($m, 'Resep diperbarui');
        } catch (\Throwable $e) { $tx->rollBack(); throw new BadRequestHttpException($e->getMessage()); }
    }

    public function actionDispense($id)
    {
        $this->requirePermission('pharmacy.manage');
        $tx = Yii::$app->db->beginTransaction();
        try {
            $m = Prescription::findBySql('SELECT * FROM prescriptions WHERE id = :id FOR UPDATE', [':id'=>$id])->one();
            if (!$m) throw new NotFoundHttpException('Resep tidak ditemukan.');
            if ($m->status !== 'pending') throw new \DomainException('Resep sudah diproses atau dibatalkan.');
            $items = PrescriptionItem::findAll(['prescription_id'=>$m->id]);
            foreach ($items as $item) {
                $updated = Yii::$app->db->createCommand(
                    'UPDATE drugs SET stock = stock - :q WHERE id = :id AND stock >= :q RETURNING stock',
                    [':q'=>$item->quantity, ':id'=>$item->drug_id]
                )->queryScalar();
                if ($updated === false || $updated === null) throw new \DomainException('Stok obat tidak mencukupi saat penyerahan.');
                $movement = new StockMovement();
                $movement->drug_id = $item->drug_id;
                $movement->quantity = -1 * (int)$item->quantity;
                $movement->reason = 'dispense';
                $movement->reference_type = 'prescription';
                $movement->reference_id = $m->id;
                $movement->created_by = Yii::$app->user->id;
                if (!$movement->save()) throw new \DomainException(json_encode($movement->errors));
            }
            $before = $m->attributes;
            $m->status = 'dispensed';
            $m->dispensed_at = new \yii\db\Expression('NOW()');
            $m->dispensed_by = Yii::$app->user->id;
            if (!$m->save()) throw new \DomainException(json_encode($m->errors));
            AuditLog::record('prescription.dispense', 'prescription', $m->id, $before, $m->attributes);
            if (($invoice = Invoice::findOne(['registration_id'=>$m->registration_id]))) BillingService::rebuild($invoice->id, 'prescription.dispense.rebill');
            $tx->commit();
            $patientName = (new \yii\db\Query())->select('name')->from('patients')->where(['id' => $m->patient_id])->scalar();
            NotificationService::notify('prescription.dispensed', [
                'id' => (string)$m->id,
                'patient_name' => (string)$patientName,
            ]);
            return $this->ok($m, 'Obat diserahkan dan stok diperbarui');
        } catch (\Throwable $e) { $tx->rollBack(); throw new BadRequestHttpException($e->getMessage()); }
    }

    public function actionCancel($id)
    {
        $this->requirePermission('pharmacy.manage');
        $m = Prescription::findOne($id);
        if (!$m) throw new NotFoundHttpException('Resep tidak ditemukan.');
        if ($m->status !== 'pending') throw new BadRequestHttpException('Resep tidak dapat dibatalkan.');
        $before = $m->attributes;
        $m->status = 'cancelled';
        $m->save(false);
        AuditLog::record('prescription.cancel', 'prescription', $m->id, $before, $m->attributes);
        return $this->ok($m, 'Resep dibatalkan');
    }
}
