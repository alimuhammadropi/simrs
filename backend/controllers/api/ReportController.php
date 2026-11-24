<?php
namespace app\controllers\api;
use Yii;
use app\components\ApiController;
use app\components\ReportService;
class ReportController extends ApiController
{
    public function actionDashboard()
    {
        $this->requirePermission('report.view');
        return $this->ok(ReportService::dashboard(Yii::$app->request->get('from'), Yii::$app->request->get('to')));
    }
    public function actionPayments()
    {
        $this->requirePermission('report.view');
        [$from,$to] = ReportService::range(Yii::$app->request->get('from'), Yii::$app->request->get('to'));
        $rows = Yii::$app->db->createCommand('SELECT p.payment_number,p.amount,p.method,p.created_at,i.invoice_number,pa.name AS patient_name FROM payments p INNER JOIN invoices i ON i.id=p.invoice_id INNER JOIN patients pa ON pa.id=i.patient_id WHERE p.created_at BETWEEN :f AND :t ORDER BY p.created_at DESC', [':f'=>$from.' 00:00:00', ':t'=>$to.' 23:59:59'])->queryAll();
        return $this->ok($rows);
    }
}
