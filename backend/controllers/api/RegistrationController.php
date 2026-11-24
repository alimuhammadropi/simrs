<?php
namespace app\controllers\api;

use Yii;
use app\components\ApiController;
use app\components\NodeClient;
use app\components\NotificationService;
use app\models\{AuditLog,Doctor,DoctorSchedule,Patient,Polyclinic,Registration};
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

class RegistrationController extends ApiController
{
    public function actionIndex()
    {
        if (Yii::$app->request->isPost) {
            $this->requirePermission('registration.create');
            $b = Yii::$app->request->bodyParams;
            foreach (['patient_id','polyclinic_id','doctor_id','registration_date'] as $f) {
                if (empty($b[$f])) throw new BadRequestHttpException("$f wajib diisi.");
            }
            $key = trim((string)($b['idempotency_key'] ?? ''));
            if ($key !== '') {
                $replay = Registration::findOne(['idempotency_key' => $key]);
                if ($replay) return $this->ok(array_merge($replay->attributes, ['replayed' => true]), 'Pendaftaran sudah ada');
            }
            $tx = Yii::$app->db->beginTransaction();
            try {
                $date = $b['registration_date'];
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new \DomainException('Format tanggal tidak valid.');
                Yii::$app->db->createCommand('SELECT pg_advisory_xact_lock(hashtext(:k))', [':k' => 'regno:' . $date])->execute();
                $lockKey = 'reg:' . (int)$b['patient_id'] . ':' . (int)$b['polyclinic_id'] . ':' . $date;
                Yii::$app->db->createCommand('SELECT pg_advisory_xact_lock(hashtext(:k))', [':k' => $lockKey])->execute();
                if ($key !== '') {
                    $replay = Registration::findOne(['idempotency_key' => $key]);
                    if ($replay) { $tx->commit(); return $this->ok(array_merge($replay->attributes, ['replayed' => true]), 'Pendaftaran sudah ada'); }
                }
                $patient = Patient::findOne(['id' => (int)$b['patient_id'], 'is_active' => true]);
                $doctor = Doctor::findOne(['id' => (int)$b['doctor_id'], 'is_active' => true]);
                $poli = Polyclinic::findOne(['id' => (int)$b['polyclinic_id'], 'is_active' => true]);
                if (!$patient || !$doctor || !$poli) throw new \DomainException('Pasien, dokter, atau poli tidak valid.');
                $duplicate = Registration::find()->where([
                    'patient_id' => $patient->id,
                    'polyclinic_id' => $poli->id,
                    'registration_date' => $date,
                ])->andWhere(['<>', 'status', 'cancelled'])->exists();
                if ($duplicate) throw new \DomainException('Pasien sudah terdaftar di poli ini pada tanggal tersebut.');

                $scheduleId = !empty($b['schedule_id']) ? (int)$b['schedule_id'] : null;
                $dayOfWeek = (int)date('w', strtotime($date));
                if ($scheduleId) {
                    $schedule = DoctorSchedule::findOne(['id' => $scheduleId, 'is_active' => true]);
                    if (!$schedule || $schedule->doctor_id != $doctor->id || $schedule->polyclinic_id != $poli->id || $schedule->day_of_week != $dayOfWeek) {
                        throw new \DomainException('Jadwal praktik tidak sesuai dengan dokter, poli, dan tanggal.');
                    }
                } elseif (!(new \yii\db\Query())->from('doctor_polyclinics')->where(['doctor_id' => $doctor->id, 'polyclinic_id' => $poli->id])->exists()) {
                    throw new \DomainException('Dokter tidak terdaftar pada poli tersebut.');
                }

                Yii::$app->db->createCommand(
                    'INSERT INTO registration_counters (queue_date, last_number) VALUES (:d, 0) ON CONFLICT (queue_date) DO NOTHING',
                    [':d' => $date]
                )->execute();
                $sequence = (int)Yii::$app->db->createCommand(
                    'UPDATE registration_counters SET last_number = last_number + 1, updated_at = NOW() WHERE queue_date = :d RETURNING last_number',
                    [':d' => $date]
                )->queryScalar();

                Yii::$app->db->createCommand(
                    'INSERT INTO queue_counters (polyclinic_id, queue_date, last_number) VALUES (:p, :d, 0) ON CONFLICT (polyclinic_id, queue_date) DO NOTHING',
                    [':p' => $poli->id, ':d' => $date]
                )->execute();
                $queueNumber = (int)Yii::$app->db->createCommand(
                    'UPDATE queue_counters SET last_number = last_number + 1 WHERE polyclinic_id = :p AND queue_date = :d RETURNING last_number',
                    [':p' => $poli->id, ':d' => $date]
                )->queryScalar();

                $m = new Registration();
                $m->registration_number = 'RJ' . date('Ymd', strtotime($date)) . str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
                $m->patient_id = $patient->id;
                $m->polyclinic_id = $poli->id;
                $m->doctor_id = $doctor->id;
                $m->registration_date = $date;
                $m->queue_number = $queueNumber;
                $m->status = 'registered';
                $m->schedule_id = $scheduleId;
                $m->idempotency_key = $key !== '' ? $key : null;
                if (!$m->save()) throw new \DomainException(json_encode($m->errors));
                AuditLog::record('registration.create', 'registration', $m->id, null, $m->attributes);
                $tx->commit();
                NotificationService::notify('registration.created', [
                    'id' => (string)$m->id,
                    'registration_number' => $m->registration_number,
                    'queue_number' => (string)$m->queue_number,
                    'patient_name' => $patient->name,
                    'polyclinic_name' => $poli->name,
                ]);
                NodeClient::post('/internal/notifications/queue', [
                    'registration_number' => $m->registration_number,
                    'queue_number' => $m->queue_number,
                    'polyclinic_name' => $poli->name,
                    'idempotency_key' => $key !== '' ? $key : $m->registration_number,
                ]);
                return $this->ok(array_merge($m->attributes, ['replayed' => false]), 'Pendaftaran berhasil');
            } catch (\Throwable $e) {
                $tx->rollBack();
                throw new BadRequestHttpException($e->getMessage());
            }
        }

        $this->requirePermission('registration.view');
        $query = (new \yii\db\Query())->select(['r.*','p.name AS patient_name','p.medical_record_number','d.name AS doctor_name','c.name AS polyclinic_name'])
            ->from(['r' => 'registrations'])
            ->innerJoin(['p' => 'patients'], 'p.id = r.patient_id')
            ->innerJoin(['d' => 'doctors'], 'd.id = r.doctor_id')
            ->innerJoin(['c' => 'polyclinics'], 'c.id = r.polyclinic_id');
        if (($date = Yii::$app->request->get('date'))) $query->andWhere(['r.registration_date' => $date]);
        if (($poly = Yii::$app->request->get('polyclinic_id'))) $query->andWhere(['r.polyclinic_id' => (int)$poly]);
        if (($patient = Yii::$app->request->get('patient_id'))) $query->andWhere(['r.patient_id' => (int)$patient]);
        if (($status = Yii::$app->request->get('status'))) $query->andWhere(['r.status' => $status]);
        $perPage = min(200, max(1, (int)Yii::$app->request->get('per_page', 100)));
        $page = max(1, (int)Yii::$app->request->get('page', 1));
        $total = (int)(clone $query)->count();
        $rows = $query->orderBy(['r.registration_date' => SORT_DESC, 'r.id' => SORT_DESC])->offset(($page - 1) * $perPage)->limit($perPage)->all();
        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
    }

    public function actionQueue()
    {
        $this->requirePermission('registration.view');
        $date = Yii::$app->request->get('date', date('Y-m-d'));
        $query = (new \yii\db\Query())->select(['r.id','r.queue_number','r.registration_number','r.status','c.name AS polyclinic_name','p.name AS patient_name'])
            ->from(['r' => 'registrations'])
            ->innerJoin(['p' => 'patients'], 'p.id = r.patient_id')
            ->innerJoin(['c' => 'polyclinics'], 'c.id = r.polyclinic_id')
            ->where(['r.registration_date' => $date])
            ->andWhere(['<>', 'r.status', 'cancelled']);
        if (($poly = Yii::$app->request->get('polyclinic_id'))) $query->andWhere(['r.polyclinic_id' => (int)$poly]);
        return $this->ok($query->orderBy(['c.name' => SORT_ASC, 'r.queue_number' => SORT_ASC])->all());
    }

    public function actionStatus($id)
    {
        $this->requirePermission('registration.create');
        $b = Yii::$app->request->bodyParams;
        $status = $b['status'] ?? '';
        if ($status === 'in_exam' && !Yii::$app->user->identity->can('examination.create')) {
            throw new ForbiddenHttpException('Pemeriksaan hanya dimulai oleh dokter melalui EMR.');
        }
        $allowed = ['registered' => ['waiting','cancelled'], 'waiting' => ['in_exam','cancelled'], 'in_exam' => ['cancelled'], 'completed' => [], 'cancelled' => []];
        $m = Registration::findOne($id);
        if (!$m) throw new NotFoundHttpException('Pendaftaran tidak ditemukan.');
        if (!in_array($status, $allowed[$m->status] ?? [], true)) throw new BadRequestHttpException('Transisi status tidak valid.');
        $before = $m->attributes;
        $m->status = $status;
        if (!$m->save()) throw new BadRequestHttpException(json_encode($m->errors));
        AuditLog::record('registration.status', 'registration', $m->id, $before, $m->attributes);
        if ($status === 'waiting') {
            $patientName = (new \yii\db\Query())->select('name')->from('patients')->where(['id' => $m->patient_id])->scalar();
            $polyclinicName = (new \yii\db\Query())->select('name')->from('polyclinics')->where(['id' => $m->polyclinic_id])->scalar();
            NotificationService::notify('registration.waiting', [
                'id' => (string)$m->id,
                'registration_number' => $m->registration_number,
                'queue_number' => (string)$m->queue_number,
                'patient_name' => (string)$patientName,
                'polyclinic_name' => (string)$polyclinicName,
            ]);
        }
        return $this->ok($m, 'Status antrean diperbarui');
    }

    public function actionView($id)
    {
        $this->requirePermission('registration.view');
        $m = Registration::findOne($id);
        if (!$m) throw new NotFoundHttpException('Pendaftaran tidak ditemukan.');
        return $this->ok($m);
    }
}
