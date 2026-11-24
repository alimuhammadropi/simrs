<?php
namespace app\controllers\api;

use Yii;
use app\components\ApiController;
use app\components\BillingService;
use app\components\NotificationService;
use app\models\{AuditLog,Invoice,Registration};
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

class BillingController extends ApiController
{
    public function actionIndex()
    {
        if (Yii::$app->request->isPost) {
            $this->requirePermission('billing.manage');
            $b = Yii::$app->request->bodyParams;
            if (empty($b['registration_id'])) throw new BadRequestHttpException('registration_id wajib diisi.');
            $registration = Registration::findOne($b['registration_id']);
            if (!$registration) throw new NotFoundHttpException('Pendaftaran tidak ditemukan.');
            $existingInvoice = Invoice::findOne(['registration_id' => $registration->id]);
            $invoice = BillingService::ensureInvoice($registration->id);
            if (!$existingInvoice) {
                $patientName = (new \yii\db\Query())->select('name')->from('patients')->where(['id' => $registration->patient_id])->scalar();
                NotificationService::notify('invoice.created', [
                    'id' => (string)$invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'total' => number_format((float)$invoice->total, 0, ',', '.'),
                    'patient_name' => (string)$patientName,
                ]);
            }
            return $this->ok(BillingService::detail($invoice->id), 'Tagihan tersedia');
        }
        $this->requirePermission('billing.view');
        $query = (new \yii\db\Query())->select(['i.*','r.registration_number','r.registration_date','p.name AS patient_name','p.medical_record_number'])
            ->from(['i'=>'invoices'])
            ->innerJoin(['r'=>'registrations'],'r.id=i.registration_id')
            ->innerJoin(['p'=>'patients'],'p.id=i.patient_id');
        if (($status = Yii::$app->request->get('status'))) $query->andWhere(['i.status'=>$status]);
        if (($patient = Yii::$app->request->get('patient_id'))) $query->andWhere(['i.patient_id'=>(int)$patient]);
        if (($from = Yii::$app->request->get('from'))) $query->andWhere(['>=','r.registration_date',$from]);
        if (($to = Yii::$app->request->get('to'))) $query->andWhere(['<=','r.registration_date',$to]);
        return $this->ok($query->orderBy(['i.id'=>SORT_DESC])->limit(200)->all());
    }

    public function actionView($id)
    {
        $this->requirePermission('billing.view');
        $detail = BillingService::detail($id);
        if (!$detail) throw new NotFoundHttpException('Tagihan tidak ditemukan.');
        return $this->ok($detail);
    }

    public function actionPay($id)
    {
        $this->requirePermission('billing.manage');
        $b = Yii::$app->request->bodyParams;
        if (empty($b['amount']) || empty($b['method']) || empty($b['idempotency_key'])) {
            throw new BadRequestHttpException('amount, method, dan idempotency_key wajib diisi.');
        }
        try {
            $result = BillingService::pay($id, (float)$b['amount'], $b['method'], (string)$b['idempotency_key'], $b['reference_no'] ?? null);
            if (!$result['replayed']) {
                $invoice = Invoice::findOne($id);
                $patientName = (new \yii\db\Query())->select('name')->from('patients')->where(['id' => $invoice->patient_id])->scalar();
                NotificationService::notify('invoice.paid', [
                    'id' => (string)$invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'payment_number' => (string)$result['payment']->payment_number,
                    'amount' => number_format((float)$result['payment']->amount, 0, ',', '.'),
                    'patient_name' => (string)$patientName,
                ]);
            }
            $message = $result['replayed'] ? 'Pembayaran sudah tercatat' : 'Pembayaran berhasil';
            if ((float)$result['payment']->change_amount > 0) {
                $message .= ' — kembalian Rp ' . number_format((float)$result['payment']->change_amount, 0, ',', '.');
            }
            return $this->ok([
                'payment' => $result['payment'],
                'replayed' => $result['replayed'],
                'invoice' => Invoice::findOne($id),
            ], $message);
        } catch (\DomainException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }
    }

    public function actionPrint($id)
    {
        $this->requirePermission('billing.view');
        $detail = BillingService::detail($id);
        if (!$detail) throw new NotFoundHttpException('Tagihan tidak ditemukan.');
        $invoice = $detail['invoice'];
        $patient = $detail['patient'];
        $rows = '';
        foreach ($detail['items'] as $item) {
            $rows .= '<tr><td>' . htmlspecialchars($item->description) . '</td><td class="text-right">' . (int)$item->quantity . '</td><td class="text-right">' . number_format($item->unit_price, 0, ',', '.') . '</td><td class="text-right">' . number_format($item->amount, 0, ',', '.') . '</td></tr>';
        }
        $paymentRows = '';
        foreach ($detail['payments'] as $payment) {
            $paymentRows .= '<tr><td>' . htmlspecialchars($payment->payment_number) . '</td><td>' . date('d-m-Y H:i', strtotime($payment->created_at)) . '</td><td>' . htmlspecialchars($payment->method) . '</td><td class="text-right">' . number_format($payment->tendered_amount, 0, ',', '.') . '</td><td class="text-right">' . number_format($payment->amount, 0, ',', '.') . '</td><td class="text-right">' . number_format($payment->change_amount, 0, ',', '.') . '</td></tr>';
        }
        $html = '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>' . htmlspecialchars($invoice->invoice_number) . '</title>'
            . '<style>body{font-family:DejaVu Sans,Arial,sans-serif;font-size:13px;margin:32px;color:#111}h1{font-size:20px;margin:0 0 4px}small{color:#666}table{width:100%;border-collapse:collapse;margin-top:18px}th,td{padding:7px;border-bottom:1px solid #ddd}th{background:#f3f4f6;text-align:left}.text-right{text-align:right}.total{font-weight:bold;font-size:16px;margin-top:14px}.muted{color:#666}@media print{body{margin:10mm}}</style></head><body>'
            . '<h1>SIMRS Rawat Jalan</h1><div class="muted">Jl. Kesehatan No. 1 — Invoice / Bukti Pembayaran</div><hr>'
            . '<p><strong>No. Invoice:</strong> ' . htmlspecialchars($invoice->invoice_number) . '<br><strong>Pasien:</strong> ' . htmlspecialchars($patient->name ?? '-') . ' (' . htmlspecialchars($patient->medical_record_number ?? '-') . ')<br><strong>Status:</strong> ' . htmlspecialchars(strtoupper($invoice->status)) . '<br><strong>Tanggal:</strong> ' . date('d-m-Y H:i', strtotime($invoice->created_at)) . '</p>'
            . '<table><thead><tr><th>Rincian</th><th class="text-right">Qty</th><th class="text-right">Harga</th><th class="text-right">Jumlah</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<div class="total">Total: Rp ' . number_format($invoice->total, 0, ',', '.') . '</div>'
            . '<div>Dibayar: Rp ' . number_format($invoice->paid_amount, 0, ',', '.') . ' &nbsp;|&nbsp; Sisa: Rp ' . number_format(max(0, (float)$invoice->total - (float)$invoice->paid_amount), 0, ',', '.') . '</div>'
            . '<h3>Riwayat Pembayaran</h3><table><thead><tr><th>No. Bayar</th><th>Waktu</th><th>Metode</th><th class="text-right">Uang diterima</th><th class="text-right">Diterapkan</th><th class="text-right">Kembalian</th></tr></thead><tbody>' . ($paymentRows ?: '<tr><td colspan="6" class="muted">Belum ada pembayaran</td></tr>') . '</tbody></table>'
            . '<p style="margin-top:40px">Hormat kami,<br><br><br>____________________<br>Kasir</p>'
            . '<script>window.onload=function(){window.print()}</script></body></html>';
        Yii::$app->response->format = \yii\web\Response::FORMAT_HTML;
        return $html;
    }
}
