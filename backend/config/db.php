<?php
return [
    'class' => yii\db\Connection::class,
    'dsn' => getenv('DB_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=simrs_demo',
    'username' => getenv('DB_USER') ?: 'simrs_demo',
    'password' => getenv('DB_PASSWORD') ?: '',
    'charset' => 'utf8',
];
