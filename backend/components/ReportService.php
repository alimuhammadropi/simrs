<?php
namespace app\components;

use Yii;

class ReportService
{
    public static function range($from, $to)
    {
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$from) ? $from : date('Y-m-01');
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$to) ? $to : date('Y-m-d');
        if ($from > $to) [$from, $to] = [$to, $from];
        return [$from, $to];
    }

    public static function dashboard($from = null, $to = null)
    {
        [$from, $to] = self::range($from, $to);
        $db = Yii::$app->db;
        $visits = (int)$db->createCommand('SELECT COUNT(*) FROM registrations WHERE registration_date BETWEEN :f AND :t AND status <> :c', [':f'=>$from, ':t'=>$to, ':c'=>'cancelled'])->queryScalar();
        $perPoly = $db->createCommand('SELECT c.name AS polyclinic_name, COUNT(*) AS total FROM registrations r INNER JOIN polyclinics c ON c.id=r.polyclinic_id WHERE r.registration_date BETWEEN :f AND :t AND r.status <> :c GROUP BY c.name ORDER BY total DESC', [':f'=>$from, ':t'=>$to, ':c'=>'cancelled'])->queryAll();
        $actions = (int)$db->createCommand('SELECT COUNT(*) FROM visit_actions va INNER JOIN registrations r ON r.id=va.registration_id WHERE r.registration_date BETWEEN :f AND :t', [':f'=>$from, ':t'=>$to])->queryScalar();
        $revenue = (float)$db->createCommand('SELECT COALESCE(SUM(p.amount),0) FROM payments p INNER JOIN invoices i ON i.id=p.invoice_id INNER JOIN registrations r ON r.id=i.registration_id WHERE r.registration_date BETWEEN :f AND :t', [':f'=>$from, ':t'=>$to])->queryScalar();
        $billed = (float)$db->createCommand('SELECT COALESCE(SUM(total),0) FROM invoices i INNER JOIN registrations r ON r.id=i.registration_id WHERE r.registration_date BETWEEN :f AND :t', [':f'=>$from, ':t'=>$to])->queryScalar();
        $paymentStatus = $db->createCommand('SELECT i.status AS status, COUNT(*) AS total, COALESCE(SUM(i.total),0) AS amount FROM invoices i INNER JOIN registrations r ON r.id=i.registration_id WHERE r.registration_date BETWEEN :f AND :t GROUP BY i.status ORDER BY i.status', [':f'=>$from, ':t'=>$to])->queryAll();
        $drugUsage = $db->createCommand('SELECT d.name AS drug_name, d.unit, ABS(SUM(sm.quantity)) AS quantity FROM stock_movements sm INNER JOIN drugs d ON d.id=sm.drug_id WHERE sm.reason = :r AND sm.created_at BETWEEN :f AND :t GROUP BY d.name,d.unit ORDER BY quantity DESC LIMIT 20', [':r'=>'dispense', ':f'=>$from.' 00:00:00', ':t'=>$to.' 23:59:59'])->queryAll();
        $labCount = (int)$db->createCommand('SELECT COUNT(*) FROM lab_requests lr INNER JOIN registrations r ON r.id=lr.registration_id WHERE r.registration_date BETWEEN :f AND :t', [':f'=>$from, ':t'=>$to])->queryScalar();
        return [
            'period' => ['from' => $from, 'to' => $to],
            'visits' => $visits,
            'visits_by_polyclinic' => $perPoly,
            'medical_actions' => $actions,
            'lab_requests' => $labCount,
            'billed' => $billed,
            'revenue' => $revenue,
            'payment_status' => $paymentStatus,
            'drug_usage' => $drugUsage,
        ];
    }
}
