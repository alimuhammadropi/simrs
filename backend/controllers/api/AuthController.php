<?php
namespace app\controllers\api;

use Yii;
use yii\web\BadRequestHttpException;
use yii\web\UnauthorizedHttpException;
use app\components\ApiController;
use app\components\FcmService;
use app\models\User;
use Firebase\JWT\JWT;

class AuthController extends ApiController
{
    public function actionLogin()
    {
        $body = Yii::$app->request->bodyParams;
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';
        if (!$username || !$password) throw new BadRequestHttpException('Username dan password wajib diisi.');
        $user = User::findOne(['username' => $username, 'is_active' => true]);
        if (!$user || !$user->validatePassword($password)) throw new UnauthorizedHttpException('Username atau password salah.');

        $fcmToken = trim((string)($body['fcm_token'] ?? ''));
        if ($fcmToken !== '') FcmService::registerToken((int)$user->id, $fcmToken, 'web');

        $now = time();
        $payload = [
            'iss' => Yii::$app->params['jwtIssuer'],
            'iat' => $now,
            'exp' => $now + Yii::$app->params['jwtTtl'],
            'sub' => (string)$user->id,
        ];
        $token = JWT::encode($payload, Yii::$app->params['jwtSecret'], 'HS256');
        return $this->ok([
            'token' => $token,
            'expires_in' => Yii::$app->params['jwtTtl'],
            'user' => ['id' => $user->id, 'name' => $user->name, 'username' => $user->username, 'roles' => $user->getRoles(), 'permissions' => $user->getPermissions()],
        ], 'Login berhasil');
    }

    public function actionMe()
    {
        $u = Yii::$app->user->identity;
        return $this->ok(['id' => $u->id, 'name' => $u->name, 'username' => $u->username, 'roles' => $u->getRoles(), 'permissions' => $u->getPermissions()]);
    }
}
