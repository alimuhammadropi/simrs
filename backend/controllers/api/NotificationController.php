<?php
namespace app\controllers\api;

use Yii;
use yii\web\BadRequestHttpException;
use app\components\ApiController;
use app\components\FcmService;

class NotificationController extends ApiController
{
    public function actionToken()
    {
        $body = Yii::$app->request->bodyParams;
        $token = trim((string)($body['token'] ?? ''));
        if (strlen($token) < 100) {
            throw new BadRequestHttpException('Token perangkat tidak valid.');
        }
        $platform = (string)($body['platform'] ?? 'web');
        if (!in_array($platform, ['web', 'android', 'ios'], true)) $platform = 'web';

        if (!FcmService::registerToken((int)Yii::$app->user->id, $token, $platform)) {
            throw new BadRequestHttpException('Token perangkat tidak valid.');
        }
        return $this->ok(['registered' => true, 'platform' => $platform, 'primary' => true], 'Token notifikasi tersimpan');
    }

    public function actionDeleteToken()
    {
        $body = Yii::$app->request->bodyParams;
        $token = trim((string)($body['token'] ?? ''));
        $removed = $token !== '' && FcmService::revokeToken($token, (int)Yii::$app->user->id);
        return $this->ok(['removed' => $removed], 'Token notifikasi dinonaktifkan');
    }

    public function actionTest()
    {
        $tokens = FcmService::tokensForUser((int)Yii::$app->user->id);
        if (!$tokens) {
            throw new BadRequestHttpException('Belum ada token notifikasi untuk pengguna ini.');
        }
        $result = FcmService::sendAll($tokens, 'Tes notifikasi SIMRS', 'Notifikasi Firebase sudah aktif.', [
            'type' => 'notification.test',
            'url' => '/',
        ]);
        return $this->ok($result, $result['failed'] ? 'Sebagian notifikasi gagal dikirim' : 'Notifikasi tes dikirim');
    }
}
