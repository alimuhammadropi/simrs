<?php
namespace app\components;

use Yii;
use app\models\AuditLog;

class NodeClient
{
    public static function request($method, $path, $payload = null)
    {
        $url = rtrim((string)(Yii::$app->params['nodeServiceUrl'] ?? ''), '/') . '/' . ltrim($path, '/');
        if (!$url || $url === '/') return null;
        $ch = curl_init($url);
        $headers = ['Content-Type: application/json', 'x-service-key: ' . (Yii::$app->params['nodeServiceKey'] ?? '')];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false || $status >= 400) {
            AuditLog::record('node.integration.failed', 'node', $path, null, ['status' => $status, 'error' => $error, 'response' => substr((string)$response, 0, 500)]);
            return null;
        }
        return json_decode((string)$response, true);
    }

    public static function post($path, $payload)
    {
        return self::request('POST', $path, $payload);
    }

    public static function get($path)
    {
        return self::request('GET', $path);
    }
}
