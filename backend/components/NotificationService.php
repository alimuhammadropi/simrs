<?php
namespace app\components;

use Yii;
use yii\db\Query;

class NotificationService
{
    public static function notify(string $event, array $context = []): array
    {
        $route = Yii::$app->params['notificationRoutes'][$event] ?? null;
        if (!is_array($route)) return ['total' => 0, 'sent' => 0, 'failed' => 0, 'event' => $event];

        $userIds = self::recipients($route, $context);
        if (!$userIds) return ['total' => 0, 'sent' => 0, 'failed' => 0, 'event' => $event];

        $page = (string)($route['page'] ?? 'dashboard');
        $data = array_merge([
            'type' => $event,
            'url' => '/?page=' . rawurlencode($page),
        ], $context, $route['data'] ?? []);

        $result = FcmService::sendAll(
            FcmService::tokensForUsers($userIds),
            self::render((string)($route['title'] ?? 'Notifikasi SIMRS'), $context),
            self::render((string)($route['body'] ?? ''), $context),
            $data
        );
        $result['event'] = $event;
        $result['recipients'] = count($userIds);

        self::audit($event, $userIds, $result);
        return $result;
    }

    private static function recipients(array $route, array $context): array
    {
        $userIds = [];

        if (!empty($route['roles']) && is_array($route['roles'])) {
            $userIds = array_merge($userIds, (new Query())->select('ur.user_id')
                ->from(['ur' => 'user_roles'])
                ->innerJoin(['r' => 'roles'], 'r.id = ur.role_id')
                ->innerJoin(['u' => 'users'], 'u.id = ur.user_id')
                ->where(['r.code' => $route['roles'], 'u.is_active' => true])
                ->column());
        }

        if (!empty($route['permissions']) && is_array($route['permissions'])) {
            $userIds = array_merge($userIds, (new Query())->select('ur.user_id')
                ->from(['ur' => 'user_roles'])
                ->innerJoin(['rp' => 'role_permissions'], 'rp.role_id = ur.role_id')
                ->innerJoin(['p' => 'permissions'], 'p.id = rp.permission_id')
                ->innerJoin(['u' => 'users'], 'u.id = ur.user_id')
                ->where(['p.code' => $route['permissions'], 'u.is_active' => true])
                ->column());
        }

        if (!empty($route['user_ids']) && is_array($route['user_ids'])) {
            $userIds = array_merge($userIds, array_map('intval', $route['user_ids']));
        }

        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));

        if (!array_key_exists('exclude_actor', $route) || $route['exclude_actor'] !== false) {
            $actor = Yii::$app->user && !Yii::$app->user->isGuest ? (int)Yii::$app->user->id : 0;
            if ($actor) $userIds = array_values(array_filter($userIds, static fn(int $id): bool => $id !== $actor));
        }

        return $userIds;
    }

    private static function render(string $template, array $context): string
    {
        return preg_replace_callback('/\{(\w+)\}/', static function (array $match) use ($context): string {
            return array_key_exists($match[1], $context) ? (string)$context[$match[1]] : $match[0];
        }, $template) ?? $template;
    }

    private static function audit(string $event, array $userIds, array $result): void
    {
        \app\models\AuditLog::record('notification.route', 'notification', null, null, [
            'event' => $event,
            'recipients' => $userIds,
            'total' => $result['total'] ?? 0,
            'sent' => $result['sent'] ?? 0,
            'failed' => $result['failed'] ?? 0,
        ]);
    }
}
