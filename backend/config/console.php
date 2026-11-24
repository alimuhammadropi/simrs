<?php
return [
    'id' => 'simrs-console',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'app\commands',
    'components' => [
        'db' => require __DIR__ . '/db.php',
        'log' => ['targets' => [['class' => yii\log\FileTarget::class, 'levels' => ['error', 'warning']]]],
    ],
    'params' => require __DIR__ . '/params.php',
    'controllerMap' => [
        'migrate' => ['class' => yii\console\controllers\MigrateController::class, 'migrationPath' => '@app/migrations'],
    ],
];
