<?php
namespace app\components;

use Yii;
use Firebase\JWT\JWT;
use app\models\AuditLog;
use app\models\DeviceToken;
use app\models\User;

class FcmService
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public static function enabled(): bool
    {
        return self::projectId() !== '' && self::serviceAccount() !== null;
    }

    public static function projectId(): string
    {
        return trim((string)(Yii::$app->params['firebaseProjectId'] ?? ''));
    }

    private static function serviceAccount(): ?array
    {
        $raw = trim((string)(Yii::$app->params['firebaseServiceAccountJson'] ?? ''));
        if ($raw === '') return null;
        $json = $raw;
        if ($raw[0] !== '{' && is_file($raw)) {
            $json = @file_get_contents($raw);
        }
        if (!$json) return null;
        $sa = json_decode($json, true);
        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) return null;
        return $sa;
    }

    public static function send(string $token, string $title, string $body, array $data = []): array
    {
        if (!self::enabled()) {
            return ['success' => false, 'error' => 'Firebase belum dikonfigurasi (FIREBASE_PROJECT_ID / FIREBASE_SERVICE_ACCOUNT_JSON).'];
        }
        if ($token === '') {
            return ['success' => false, 'error' => 'Token perangkat kosong.'];
        }
        $accessToken = self::accessToken();
        if (!$accessToken) {
            return ['success' => false, 'error' => 'Gagal mendapatkan access token Firebase.'];
        }

        $payloadData = [];
        foreach ($data as $key => $value) {
            $payloadData[(string)$key] = is_scalar($value) ? (string)$value : (string)json_encode($value);
        }
        $notification = ['title' => $title, 'body' => $body];
        if (!empty($payloadData['image'])) {
            $notification['image'] = $payloadData['image'];
            unset($payloadData['image']);
        }
        $message = [
            'token' => $token,
            'notification' => $notification,
            'data' => (object)$payloadData,
            'webpush' => ['notification' => ['link' => $payloadData['url'] ?? '/']],
        ];
        $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode(self::projectId()) . '/messages:send';
        [$status, $response, $error] = self::http($url, json_encode(['message' => $message]), [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
        ]);
        $decoded = json_decode($response, true);
        if ($error) {
            AuditLog::record('fcm.send.failed', 'notification', null, null, ['error' => $error, 'status' => $status]);
            return ['success' => false, 'error' => $error, 'status' => $status];
        }
        if ($status >= 200 && $status < 300 && is_array($decoded)) {
            return ['success' => true, 'name' => $decoded['name'] ?? null];
        }
        AuditLog::record('fcm.send.failed', 'notification', null, null, [
            'status' => $status,
            'response' => substr($response, 0, 500),
        ]);
        return ['success' => false, 'error' => $decoded['error']['message'] ?? 'FCM menolak permintaan.', 'status' => $status];
    }

    public static function sendAll(array $tokens, string $title, string $body, array $data = []): array
    {
        $tokens = array_values(array_unique(array_filter(array_map('strval', $tokens))));
        if (!$tokens) return ['total' => 0, 'sent' => 0, 'failed' => 0];
        $sent = 0;
        $failed = 0;
        $errors = [];
        foreach ($tokens as $token) {
            $result = self::send($token, $title, $body, $data);
            if (!empty($result['success'])) {
                $sent++;
            } else {
                $failed++;
                if (count($errors) < 5) $errors[] = $result['error'] ?? 'Gagal mengirim notifikasi';
            }
        }
        AuditLog::record('fcm.send', 'notification', null, null, [
            'title' => $title,
            'total' => count($tokens),
            'sent' => $sent,
            'failed' => $failed,
            'errors' => $errors,
        ]);
        return ['total' => count($tokens), 'sent' => $sent, 'failed' => $failed];
    }

    public static function registerToken(int $userId, string $token, string $platform = 'web'): bool
    {
        $token = trim($token);
        if (strlen($token) < 100 || strlen($token) > 512) return false;
        if (!in_array($platform, ['web', 'android', 'ios'], true)) $platform = 'web';
        if (!User::findOne($userId)) return false;

        $transaction = Yii::$app->db->beginTransaction();
        try {
            Yii::$app->db->createCommand(
                "UPDATE users SET fcm_token = NULL WHERE fcm_token = :token AND id <> :id",
                [':token' => $token, ':id' => $userId]
            )->execute();

            $user = User::findOne($userId);
            $user->fcm_token = $token;
            if (!$user->save(false, ['fcm_token'])) {
                $transaction->rollBack();
                return false;
            }

            $device = DeviceToken::findOne(['token' => $token]) ?: new DeviceToken();
            $device->user_id = $userId;
            $device->token = $token;
            $device->platform = $platform;
            $device->is_active = true;
            $device->last_seen_at = date('Y-m-d H:i:s');
            if (!$device->save()) {
                $transaction->rollBack();
                return false;
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            AuditLog::record('fcm.token.failed', 'notification', null, null, ['error' => $e->getMessage()]);
            return false;
        }
        AuditLog::record('fcm.token.register', 'user', $userId, null, ['platform' => $platform]);
        return true;
    }

    public static function revokeToken(string $token, ?int $userId = null): bool
    {
        $token = trim($token);
        if ($token === '') return false;
        $condition = ['token' => $token];
        if ($userId !== null) {
            Yii::$app->db->createCommand(
                "UPDATE users SET fcm_token = NULL WHERE fcm_token = :token AND id = :id",
                [':token' => $token, ':id' => $userId]
            )->execute();
            $condition['user_id'] = $userId;
        } else {
            Yii::$app->db->createCommand(
                "UPDATE users SET fcm_token = NULL WHERE fcm_token = :token",
                [':token' => $token]
            )->execute();
        }
        DeviceToken::updateAll(['is_active' => false], $condition);
        return true;
    }

    public static function clearUserTokens(int $userId): ?string
    {
        $user = User::findOne($userId);
        if (!$user) return null;
        $token = $user->fcm_token;
        $user->fcm_token = null;
        $user->save(false, ['fcm_token']);
        DeviceToken::updateAll(['is_active' => false], ['user_id' => $userId]);
        return $token ?: null;
    }

    public static function tokensForUser(int $userId): array
    {
        $primary = (string)(User::find()->select('fcm_token')->where(['id' => $userId])->scalar() ?: '');
        $devices = DeviceToken::find()->select('token')
            ->where(['user_id' => $userId, 'is_active' => true])->column();
        return array_values(array_unique(array_filter(array_merge([$primary], $devices))));
    }

    public static function tokensForUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds) return [];
        $primary = User::find()->select('fcm_token')
            ->where(['id' => $userIds])->andWhere('fcm_token IS NOT NULL')->column();
        $devices = DeviceToken::find()->select('token')
            ->where(['user_id' => $userIds, 'is_active' => true])->column();
        return array_values(array_unique(array_filter(array_merge($primary, $devices))));
    }

    public static function notifyPermission(string $permission, string $title, string $body, array $data = []): array
    {
        $userIds = (new \yii\db\Query())->select('ur.user_id')
            ->from(['ur' => 'user_roles'])
            ->innerJoin(['rp' => 'role_permissions'], 'rp.role_id = ur.role_id')
            ->innerJoin(['p' => 'permissions'], 'p.id = rp.permission_id')
            ->innerJoin(['u' => 'users'], 'u.id = ur.user_id')
            ->where(['p.code' => $permission, 'u.is_active' => true])
            ->distinct()->column();
        if (!$userIds) return ['total' => 0, 'sent' => 0, 'failed' => 0];
        return self::sendAll(self::tokensForUsers($userIds), $title, $body, $data);
    }

    private static function accessToken(): ?string
    {
        $cacheFile = Yii::getAlias('@runtime') . '/fcm-access-token.json';
        if (is_file($cacheFile)) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['access_token']) && (int)($cached['expires_at'] ?? 0) > time() + 60) {
                return (string)$cached['access_token'];
            }
        }
        $sa = self::serviceAccount();
        if (!$sa) return null;
        $now = time();
        try {
            $assertion = JWT::encode([
                'iss' => $sa['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ], $sa['private_key'], 'RS256', $sa['private_key_id'] ?? null);
        } catch (\Throwable $e) {
            AuditLog::record('fcm.token.failed', 'notification', null, null, ['error' => $e->getMessage()]);
            return null;
        }
        $body = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]);
        [$status, $response, $error] = self::http(self::TOKEN_URL, $body, ['Content-Type: application/x-www-form-urlencoded']);
        $data = json_decode($response, true);
        if ($error || $status < 200 || $status >= 300 || empty($data['access_token'])) {
            AuditLog::record('fcm.token.failed', 'notification', null, null, [
                'status' => $status,
                'error' => $error,
                'response' => substr($response, 0, 300),
            ]);
            return null;
        }
        @file_put_contents($cacheFile, json_encode([
            'access_token' => $data['access_token'],
            'expires_at' => $now + (int)($data['expires_in'] ?? 3600),
        ]));
        @chmod($cacheFile, 0600);
        return (string)$data['access_token'];
    }

    private static function http(string $url, string $payload, array $headers, string $method = 'POST'): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int)(Yii::$app->params['fcmTimeout'] ?? 10),
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return [$status, (string)$response, $error];
    }
}
