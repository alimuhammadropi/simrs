<?php
namespace app\components;

use Yii;
use app\models\AuditLog;
use app\models\Drug;
use app\models\Invoice;
use app\models\InvoiceItem;
use app\models\LabRequest;
use app\models\MedicalAction;
use app\models\Patient;
use app\models\Payment;
use app\models\Polyclinic;
use app\models\Prescription;
use app\models\PrescriptionItem;
use app\models\Registration;
use app\models\VisitAction;

class BillingService
{
    public static function ensureInvoice($registrationId)
    {
        $invoice = Invoice::findOne(['registration_id' => $registrationId]);
        if ($invoice) return $invoice;
        $registration = Registration::findOne($registrationId);
        if (!$registration) throw new \DomainException('Pendaftaran tidak ditemukan.');
        $invoice = new Invoice();
        $invoice->invoice_number = 'INV' . date('Ymd') . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
        $invoice->registration_id = $registration->id;
        $invoice->patient_id = $registration->patient_id;
        $invoice->status = 'unpaid';
        if (!$invoice->save()) throw new \DomainException(json_encode($invoice->errors));
        AuditLog::record('invoice.create', 'invoice', $invoice->id, null, $invoice->attributes);
        return self::rebuild($invoice->id, 'invoice.create');
    }

    public static function rebuild($invoiceId, $reason = 'invoice.rebuild')
    {
        $tx = Yii::$app->db->beginTransaction();
        try {
            $invoice = Invoice::findBySql('SELECT * FROM invoices WHERE id = :id FOR UPDATE', [':id' => $invoiceId])->one();
            if (!$invoice) throw new \DomainException('Tagihan tidak ditemukan.');
            $before = $invoice->attributes;
            $registration = Registration::findOne($invoice->registration_id);
            if (!$registration) throw new \DomainException('Pendaftaran tidak ditemukan.');

            $items = [];
            $poly = Polyclinic::findOne($registration->polyclinic_id);
            $fee = (float)(Yii::$app->params['registrationFee'] ?? 25000);
            $items[] = ['registration', $registration->id, 'Pendaftaran ' . ($poly->name ?? ''), 1, $fee];

            $actions = (new \yii\db\Query())->select(['va.*', 'ma.name AS action_name'])
                ->from(['va' => 'visit_actions'])
                ->innerJoin(['ma' => 'medical_actions'], 'ma.id = va.medical_action_id')
                ->where(['va.registration_id' => $invoice->registration_id])->all();
            foreach ($actions as $action) {
                $items[] = ['action', $action['medical_action_id'], $action['action_name'], (int)$action['quantity'], (float)$action['price_snapshot']];
            }

            $labs = LabRequest::findAll(['registration_id' => $invoice->registration_id]);
            foreach ($labs as $lab) {
                if ($lab->status === 'cancelled') continue;
                $items[] = ['lab', $lab->id, 'Lab: ' . $lab->test_name, 1, (float)$lab->price_snapshot];
            }

            $drugs = (new \yii\db\Query())->select(['pi.*', 'd.name AS drug_name'])
                ->from(['pi' => 'prescription_items'])
                ->innerJoin(['p' => 'prescriptions'], 'p.id = pi.prescription_id')
                ->innerJoin(['d' => 'drugs'], 'd.id = pi.drug_id')
                ->where(['p.registration_id' => $invoice->registration_id, 'p.status' => 'dispensed'])->all();
            foreach ($drugs as $drug) {
                $items[] = ['drug', $drug['drug_id'], 'Obat: ' . $drug['drug_name'], (int)$drug['quantity'], (float)$drug['price_snapshot']];
            }

            InvoiceItem::deleteAll(['invoice_id' => $invoice->id]);
            $subtotal = 0;
            foreach ($items as $item) {
                [$type, $referenceId, $description, $quantity, $unitPrice] = $item;
                $amount = round($quantity * $unitPrice, 2);
                $row = new InvoiceItem();
                $row->invoice_id = $invoice->id;
                $row->item_type = $type;
                $row->reference_id = $referenceId;
                $row->description = $description;
                $row->quantity = $quantity;
                $row->unit_price = $unitPrice;
                $row->amount = $amount;
                if (!$row->save()) throw new \DomainException(json_encode($row->errors));
                $subtotal += $amount;
            }
            $invoice->subtotal = round($subtotal, 2);
            $invoice->total = round($subtotal - (float)$invoice->discount, 2);
            if ($invoice->total < 0) $invoice->total = 0;
            $invoice->status = self::statusFor((float)$invoice->paid_amount, (float)$invoice->total);
            if (!$invoice->save()) throw new \DomainException(json_encode($invoice->errors));
            AuditLog::record($reason, 'invoice', $invoice->id, $before, $invoice->attributes);
            $tx->commit();
            return $invoice;
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
    }

    public static function pay($invoiceId, $amount, $method, $idempotencyKey, $referenceNo = null)
    {
        $existing = Payment::findOne(['idempotency_key' => $idempotencyKey]);
        if ($existing) return ['payment' => $existing, 'replayed' => true];
        $tx = Yii::$app->db->beginTransaction();
        try {
            $invoice = Invoice::findBySql('SELECT * FROM invoices WHERE id = :id FOR UPDATE', [':id' => $invoiceId])->one();
            if (!$invoice) throw new \DomainException('Tagihan tidak ditemukan.');
            if ($invoice->status === 'cancelled') throw new \DomainException('Tagihan dibatalkan.');
            $remaining = round((float)$invoice->total - (float)$invoice->paid_amount, 2);
            if ($remaining <= 0.001) throw new \DomainException('Tagihan sudah lunas.');
            $amount = round((float)$amount, 2);
            if ($amount <= 0) throw new \DomainException('Jumlah pembayaran tidak valid.');
            $applied = round(min($amount, $remaining), 2);
            $change = round($amount - $applied, 2);
            $payment = new Payment();
            $payment->payment_number = 'PAY' . date('Ymd') . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
            $payment->invoice_id = $invoice->id;
            $payment->amount = $applied;
            $payment->tendered_amount = $amount;
            $payment->change_amount = $change;
            $payment->method = $method;
            $payment->idempotency_key = $idempotencyKey;
            $payment->reference_no = $referenceNo;
            $payment->created_by = Yii::$app->user->id;
            if (!$payment->save()) throw new \DomainException(json_encode($payment->errors));
            $before = $invoice->attributes;
            $invoice->paid_amount = round((float)$invoice->paid_amount + $applied, 2);
            $invoice->status = self::statusFor((float)$invoice->paid_amount, (float)$invoice->total);
            if (!$invoice->save()) throw new \DomainException(json_encode($invoice->errors));
            AuditLog::record('payment.create', 'invoice', $invoice->id, $before, $invoice->attributes);
            $tx->commit();
            return ['payment' => $payment, 'replayed' => false];
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
    }

    public static function statusFor($paid, $total)
    {
        if ($total <= 0) return 'paid';
        if ($paid >= $total - 0.001) return 'paid';
        if ($paid > 0) return 'partial';
        return 'unpaid';
    }

    public static function detail($invoiceId)
    {
        $invoice = Invoice::findOne($invoiceId);
        if (!$invoice) return null;
        $patient = Patient::findOne($invoice->patient_id);
        return [
            'invoice' => $invoice,
            'patient' => $patient,
            'items' => InvoiceItem::findAll(['invoice_id' => $invoice->id]),
            'payments' => Payment::find()->where(['invoice_id' => $invoice->id])->orderBy(['id' => SORT_ASC])->all(),
        ];
    }
}
