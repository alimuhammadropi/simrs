<?php
$params = require __DIR__ . '/params.php';
return [
    'id' => 'simrs-api',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'components' => [
        'request' => [
            'cookieValidationKey' => getenv('COOKIE_KEY') ?: 'change-this-cookie-key',
            'parsers' => ['application/json' => 'yii\web\JsonParser'],
            'enableCsrfValidation' => false,
        ],
        'response' => [
            'format' => yii\web\Response::FORMAT_JSON,
            'on beforeSend' => function ($event) {
                $response = $event->sender;
                if ($response->data !== null && $response->statusCode >= 400) {
                    if (is_array($response->data)) return;
                    $response->data = ['success' => false, 'message' => (string)$response->data];
                }
            },
        ],
        'user' => [
            'identityClass' => app\models\User::class,
            'enableSession' => false,
            'enableAutoLogin' => false,
        ],
        'db' => require __DIR__ . '/db.php',
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                'POST api/auth/login' => 'api/auth/login',
                'GET api/auth/me' => 'api/auth/me',

                'POST api/notifications/token' => 'api/notification/token',
                'POST api/notifications/token/delete' => 'api/notification/delete-token',
                'POST api/notifications/test' => 'api/notification/test',

                'GET,POST api/patients' => 'api/patient/index',
                'GET api/patients/<id:\d+>/visits' => 'api/patient/visits',
                'GET api/patients/<id:\d+>/medications' => 'api/patient/medications',
                'GET api/patients/<id:\d+>' => 'api/patient/view',
                'PUT,PATCH api/patients/<id:\d+>' => 'api/patient/update',
                'DELETE api/patients/<id:\d+>' => 'api/patient/delete',

                'GET,POST api/polyclinics' => 'api/polyclinic/index',
                'GET api/polyclinics/<id:\d+>' => 'api/polyclinic/view',
                'PUT,PATCH api/polyclinics/<id:\d+>' => 'api/polyclinic/update',
                'DELETE api/polyclinics/<id:\d+>' => 'api/polyclinic/delete',
                'GET,POST api/doctors' => 'api/doctor/index',
                'GET api/doctors/<id:\d+>' => 'api/doctor/view',
                'PUT,PATCH api/doctors/<id:\d+>' => 'api/doctor/update',
                'DELETE api/doctors/<id:\d+>' => 'api/doctor/delete',
                'GET,POST api/schedules' => 'api/doctor-schedule/index',
                'GET api/schedules/<id:\d+>' => 'api/doctor-schedule/view',
                'PUT,PATCH api/schedules/<id:\d+>' => 'api/doctor-schedule/update',
                'DELETE api/schedules/<id:\d+>' => 'api/doctor-schedule/delete',
                'GET,POST api/medical-actions' => 'api/medical-action/index',
                'GET api/medical-actions/<id:\d+>' => 'api/medical-action/view',
                'PUT,PATCH api/medical-actions/<id:\d+>' => 'api/medical-action/update',
                'DELETE api/medical-actions/<id:\d+>' => 'api/medical-action/delete',
                'GET,POST api/drugs' => 'api/drug/index',
                'GET api/drugs/<id:\d+>' => 'api/drug/view',
                'PUT,PATCH api/drugs/<id:\d+>' => 'api/drug/update',
                'DELETE api/drugs/<id:\d+>' => 'api/drug/delete',
                'GET,POST api/diagnoses' => 'api/diagnosis/index',
                'GET api/diagnoses/<id:\d+>' => 'api/diagnosis/view',
                'PUT,PATCH api/diagnoses/<id:\d+>' => 'api/diagnosis/update',
                'DELETE api/diagnoses/<id:\d+>' => 'api/diagnosis/delete',
                'GET,POST api/medical-staff' => 'api/medical-staff/index',
                'GET api/medical-staff/<id:\d+>' => 'api/medical-staff/view',
                'PUT,PATCH api/medical-staff/<id:\d+>' => 'api/medical-staff/update',
                'DELETE api/medical-staff/<id:\d+>' => 'api/medical-staff/delete',
                'GET,POST api/lab-tests' => 'api/lab-test/index',
                'GET api/lab-tests/<id:\d+>' => 'api/lab-test/view',
                'PUT,PATCH api/lab-tests/<id:\d+>' => 'api/lab-test/update',
                'DELETE api/lab-tests/<id:\d+>' => 'api/lab-test/delete',

                'GET api/registrations/queue' => 'api/registration/queue',
                'GET,POST api/registrations' => 'api/registration/index',
                'POST api/registrations/<id:\d+>/status' => 'api/registration/status',
                'GET api/registrations/<id:\d+>' => 'api/registration/view',
                'GET api/registrations/<registration_id:\d+>/actions' => 'api/visit-action/index',
                'POST api/registrations/<registration_id:\d+>/actions' => 'api/visit-action/create',
                'PUT,PATCH api/visit-actions/<id:\d+>' => 'api/visit-action/update',
                'DELETE api/visit-actions/<id:\d+>' => 'api/visit-action/delete',

                'GET api/examinations/waiting' => 'api/examination/waiting',
                'GET api/examinations' => 'api/examination/index',
                'POST api/examinations' => 'api/examination/create',
                'GET api/examinations/<id:\d+>' => 'api/examination/view',
                'PUT,PATCH api/examinations/<id:\d+>' => 'api/examination/update',
                'POST api/examinations/<id:\d+>/finalize' => 'api/examination/finalize',

                'GET api/prescriptions' => 'api/prescription/index',
                'POST api/prescriptions' => 'api/prescription/create',
                'PUT,PATCH api/prescriptions/<id:\d+>' => 'api/prescription/update',
                'POST api/prescriptions/<id:\d+>/dispense' => 'api/prescription/dispense',
                'POST api/prescriptions/<id:\d+>/cancel' => 'api/prescription/cancel',

                'GET api/lab/requests' => 'api/lab/index',
                'POST api/lab/requests' => 'api/lab/create',
                'GET api/lab/requests/<id:\d+>' => 'api/lab/view',
                'POST api/lab/requests/<id:\d+>/start' => 'api/lab/start',
                'POST api/lab/requests/<id:\d+>/result' => 'api/lab/result',

                'GET,POST api/invoices' => 'api/billing/index',
                'GET api/invoices/<id:\d+>/print' => 'api/billing/print',
                'POST api/invoices/<id:\d+>/pay' => 'api/billing/pay',
                'GET api/invoices/<id:\d+>' => 'api/billing/view',

                'GET api/reports/dashboard' => 'api/report/dashboard',
                'GET api/reports/payments' => 'api/report/payments',
                'GET,POST api/users' => 'api/user/index',
                'GET api/users/<id:\d+>' => 'api/user/view',
                'PUT,PATCH api/users/<id:\d+>' => 'api/user/update',
                'POST api/users/<id:\d+>/reset-notification-token' => 'api/user/reset-notification-token',
                'DELETE api/users/<id:\d+>' => 'api/user/delete',

                'GET api/internal/lab/requests' => 'api/internal/lab-requests',
                'POST api/internal/lab/results' => 'api/internal/lab-result',
                'GET api/internal/reports/dashboard' => 'api/internal/dashboard',
            ],
        ],
        'errorHandler' => ['errorAction' => null],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [['class' => yii\log\FileTarget::class, 'levels' => ['error', 'warning']]],
        ],
    ],
    'params' => $params,
];
