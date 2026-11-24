<?php
$env = static function (string $key, string $default = ''): string {
    $value = getenv($key);
    if ($value === false || $value === '') $value = $_SERVER[$key] ?? $default;
    return (string)$value;
};
return [
    'jwtSecret' => $env('JWT_SECRET', 'CHANGE-ME-IN-ENVIRONMENT-32-CHARACTERS-MINIMUM'),
    'jwtIssuer' => $env('JWT_ISSUER', 'simrs-api'),
    'jwtTtl' => (int)$env('JWT_TTL', '3600'),
    'corsOrigins' => $env('CORS_ORIGIN', 'http://localhost:5173,http://localhost:5174,http://localhost:8086,https://demo.mr-ali.my.id'),
    'registrationFee' => (float)$env('REGISTRATION_FEE', '25000'),
    'nodeServiceUrl' => $env('NODE_SERVICE_URL', 'http://127.0.0.1:3010'),
    'nodeServiceKey' => $env('NODE_SERVICE_KEY', 'local-dev-service-key'),
    'firebaseProjectId' => $env('FIREBASE_PROJECT_ID'),
    'firebaseServiceAccountJson' => $env('FIREBASE_SERVICE_ACCOUNT_JSON'),
    'fcmTimeout' => (int)$env('FCM_TIMEOUT', '10'),

    'notificationRoutes' => [
        'patient.created' => [
            'roles' => ['registration', 'admin'],
            'title' => 'Pasien baru',
            'body' => '{patient_name} · {medical_record_number} — perlu dibuatkan pendaftaran.',
            'page' => 'registrations',
        ],
        'registration.created' => [
            'roles' => ['nurse', 'doctor', 'admin'],
            'title' => 'Pendaftaran baru',
            'body' => '{patient_name} · {polyclinic_name} · antrean {queue_number}.',
            'page' => 'registrations',
        ],
        'registration.waiting' => [
            'roles' => ['doctor', 'nurse', 'admin'],
            'title' => 'Pasien siap diperiksa',
            'body' => '{patient_name} · antrean {queue_number} memanggil poli {polyclinic_name}.',
            'page' => 'examinations',
        ],
        'examination.finalized' => [
            'roles' => ['pharmacy', 'laboratory', 'cashier', 'admin'],
            'title' => 'Pemeriksaan selesai',
            'body' => '{patient_name} · {registration_number} selesai. Periksa resep, lab, dan tagihan.',
            'page' => 'examinations',
        ],
        'prescription.created' => [
            'roles' => ['pharmacy', 'admin'],
            'title' => 'Resep baru',
            'body' => '{patient_name} · {item_count} obat menunggu penyerahan.',
            'page' => 'pharmacy',
        ],
        'prescription.dispensed' => [
            'roles' => ['cashier', 'admin'],
            'title' => 'Obat diserahkan',
            'body' => '{patient_name} · resep sudah diserahkan, tagihan perlu diperbarui.',
            'page' => 'billing',
        ],
        'lab.request.created' => [
            'roles' => ['laboratory', 'admin'],
            'title' => 'Permintaan lab baru',
            'body' => '{patient_name} · {test_name} menunggu pemeriksaan.',
            'page' => 'laboratory',
        ],
        'lab.result' => [
            'roles' => ['doctor', 'admin'],
            'title' => 'Hasil lab tersedia',
            'body' => '{patient_name} · {test_name}: {result}',
            'page' => 'examinations',
        ],
        'invoice.created' => [
            'roles' => ['cashier', 'admin'],
            'title' => 'Tagihan baru',
            'body' => '{patient_name} · {invoice_number} · Rp {total}.',
            'page' => 'billing',
        ],
        'invoice.paid' => [
            'roles' => ['registration', 'admin'],
            'title' => 'Pembayaran diterima',
            'body' => '{invoice_number} · {payment_number} · Rp {amount}.',
            'page' => 'registrations',
        ],
    ],
];
