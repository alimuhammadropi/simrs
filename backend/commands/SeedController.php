<?php
namespace app\commands;
use Yii;
use yii\console\Controller;

class SeedController extends Controller
{
    private function insertIfMissing($table, $where, $values)
    {
        if ((new \yii\db\Query())->from($table)->where($where)->exists()) return false;
        Yii::$app->db->createCommand()->insert($table, $values)->execute();
        return true;
    }

    public function actionInit()
    {
        $db = Yii::$app->db;
        $roles = [
            'admin' => 'Administrator',
            'registration' => 'Petugas Pendaftaran',
            'doctor' => 'Dokter',
            'nurse' => 'Perawat',
            'management' => 'Manajemen',
            'pharmacy' => 'Petugas Farmasi',
            'laboratory' => 'Petugas Laboratorium',
            'cashier' => 'Kasir',
        ];
        foreach ($roles as $code => $name) {
            $this->insertIfMissing('roles', ['code' => $code], ['code' => $code, 'name' => $name]);
        }

        $perms = [
            'patient.view' => 'Lihat pasien',
            'patient.create' => 'Buat dan ubah pasien',
            'registration.view' => 'Lihat pendaftaran',
            'registration.create' => 'Buat pendaftaran dan antrean',
            'master.view' => 'Lihat master',
            'master.manage' => 'Kelola master',
            'examination.view' => 'Lihat pemeriksaan',
            'examination.create' => 'Buat dan ubah pemeriksaan',
            'prescription.create' => 'Buat resep',
            'pharmacy.view' => 'Lihat resep',
            'pharmacy.manage' => 'Proses resep dan stok obat',
            'lab.request' => 'Minta pemeriksaan penunjang',
            'lab.view' => 'Lihat pemeriksaan penunjang',
            'lab.manage' => 'Input hasil pemeriksaan penunjang',
            'billing.view' => 'Lihat tagihan',
            'billing.manage' => 'Buat tagihan dan catat pembayaran',
            'report.view' => 'Lihat laporan',
            'user.manage' => 'Kelola pengguna',
        ];
        foreach ($perms as $code => $name) {
            $this->insertIfMissing('permissions', ['code' => $code], ['code' => $code, 'name' => $name]);
        }

        $all = array_keys($perms);
        $rolePerms = [
            'admin' => $all,
            'registration' => ['patient.view','patient.create','registration.view','registration.create','master.view'],
            'doctor' => ['patient.view','registration.view','examination.view','examination.create','prescription.create','lab.request','lab.view','master.view'],
            'nurse' => ['patient.view','registration.view','examination.view','master.view'],
            'management' => ['patient.view','registration.view','master.view','report.view'],
            'pharmacy' => ['patient.view','pharmacy.view','pharmacy.manage','master.view'],
            'laboratory' => ['patient.view','lab.view','lab.manage','registration.view','master.view'],
            'cashier' => ['patient.view','billing.view','billing.manage','report.view','registration.view'],
        ];
        foreach ($rolePerms as $role => $codes) {
            foreach ($codes as $code) {
                $db->createCommand(
                    'INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r, permissions p WHERE r.code=:r AND p.code=:p ON CONFLICT DO NOTHING',
                    [':r' => $role, ':p' => $code]
                )->execute();
            }
        }

        $users = [
            'admin' => ['Administrator SIMRS', 'admin'],
            'petugas' => ['Petugas Pendaftaran', 'registration'],
            'dokter' => ['dr. Andi Pratama', 'doctor'],
            'farmasi' => ['Petugas Farmasi', 'pharmacy'],
            'lab' => ['Petugas Laboratorium', 'laboratory'],
            'kasir' => ['Kasir Rawat Jalan', 'cashier'],
        ];
        foreach ($users as $username => [$name, $role]) {
            if (!(new \yii\db\Query())->from('users')->where(['username' => $username])->exists()) {
                $db->createCommand()->insert('users', [
                    'username' => $username,
                    'name' => $name,
                    'password_hash' => password_hash('Admin123!', PASSWORD_DEFAULT),
                    'is_active' => true,
                ])->execute();
            }
            $db->createCommand(
                'INSERT INTO user_roles(user_id,role_id) SELECT u.id,r.id FROM users u,roles r WHERE u.username=:u AND r.code=:r ON CONFLICT DO NOTHING',
                [':u' => $username, ':r' => $role]
            )->execute();
        }

        $polyclinics = [
            ['code' => 'UMUM', 'name' => 'Poli Umum', 'description' => 'Pelayanan umum'],
            ['code' => 'ANAK', 'name' => 'Poli Anak', 'description' => 'Pelayanan pediatri'],
            ['code' => 'GIGI', 'name' => 'Poli Gigi', 'description' => 'Pelayanan kesehatan gigi'],
        ];
        foreach ($polyclinics as $p) {
            $this->insertIfMissing('polyclinics', ['code' => $p['code']], $p);
        }
        if (!(new \yii\db\Query())->from('doctors')->where(['name' => 'dr. Andi Pratama'])->exists()) {
            $db->createCommand()->insert('doctors', ['name' => 'dr. Andi Pratama', 'sip_number' => 'SIP-001', 'specialty' => 'Penyakit Dalam'])->execute();
        }
        $doctorId = (int)$db->createCommand('SELECT id FROM doctors WHERE name=:n', [':n'=>'dr. Andi Pratama'])->queryScalar();
        foreach ($polyclinics as $p) {
            $polyId = (int)$db->createCommand('SELECT id FROM polyclinics WHERE code=:c', [':c'=>$p['code']])->queryScalar();
            $db->createCommand('INSERT INTO doctor_polyclinics(doctor_id,polyclinic_id) VALUES (:d,:p) ON CONFLICT DO NOTHING', [':d'=>$doctorId, ':p'=>$polyId])->execute();
            $db->createCommand(
                "INSERT INTO doctor_schedules(doctor_id,polyclinic_id,day_of_week,start_time,end_time,is_active)
                 SELECT :d,:p,d,'08:00','12:00',TRUE FROM generate_series(0,6) d
                 WHERE NOT EXISTS (SELECT 1 FROM doctor_schedules s WHERE s.doctor_id=:d AND s.polyclinic_id=:p AND s.day_of_week=d)",
                [':d'=>$doctorId, ':p'=>$polyId]
            )->execute();
        }

        $actions = [
            ['code' => 'KONSUL', 'name' => 'Konsultasi dokter', 'price' => 50000],
            ['code' => 'TINDAKAN-KECIL', 'name' => 'Tindakan kecil', 'price' => 75000],
            ['code' => 'INFUS', 'name' => 'Pemasangan infus', 'price' => 120000],
        ];
        foreach ($actions as $a) $this->insertIfMissing('medical_actions', ['code'=>$a['code']], $a);

        $drugs = [
            ['code' => 'PARA', 'name' => 'Paracetamol 500mg', 'unit' => 'tablet', 'price' => 2000, 'stock' => 500, 'min_stock' => 50],
            ['code' => 'AMOK', 'name' => 'Amoxicillin 500mg', 'unit' => 'kapsul', 'price' => 5000, 'stock' => 200, 'min_stock' => 30],
            ['code' => 'VIT-C', 'name' => 'Vitamin C 1000mg', 'unit' => 'tablet', 'price' => 3000, 'stock' => 300, 'min_stock' => 30],
        ];
        foreach ($drugs as $d) $this->insertIfMissing('drugs', ['code'=>$d['code']], $d);

        $labTests = [
            ['code' => 'DARAH-LENGKAP', 'name' => 'Darah lengkap', 'price' => 55000],
            ['code' => 'GULA', 'name' => 'Gula darah sewaktu', 'price' => 25000],
            ['code' => 'URIN', 'name' => 'Pemeriksaan urin', 'price' => 30000],
        ];
        foreach ($labTests as $t) $this->insertIfMissing('lab_tests', ['code'=>$t['code']], $t);

        $diagnoses = [
            ['code' => 'J00', 'name' => 'Common cold'],
            ['code' => 'J10', 'name' => 'Influenza'],
            ['code' => 'R50', 'name' => 'Fever'],
            ['code' => 'K05', 'name' => 'Gingivitis'],
        ];
        foreach ($diagnoses as $d) $this->insertIfMissing('diagnoses', ['code'=>$d['code']], $d);

        $staff = [
            ['employee_number' => 'NS-001', 'name' => 'Ns. Budi', 'role' => 'Perawat'],
            ['employee_number' => 'FR-001', 'name' => 'Petugas Farmasi', 'role' => 'Apoteker'],
            ['employee_number' => 'LF-001', 'name' => 'Petugas Lab', 'role' => 'Analis Laboratorium'],
        ];
        foreach ($staff as $s) $this->insertIfMissing('medical_staff', ['employee_number'=>$s['employee_number']], $s);

        $this->stdout("Seed selesai (idempoten). Akun demo: admin, petugas, dokter, farmasi, lab, kasir / Admin123!\n");
    }
}
