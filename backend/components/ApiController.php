<?php
namespace app\components;

use Yii;
use yii\rest\Controller;
use yii\web\UnauthorizedHttpException;
use yii\web\ForbiddenHttpException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use app\models\User;

class ApiController extends Controller
{
    public $enableCsrfValidation = false;

    public function beforeAction($action)
    {
        $request = Yii::$app->request;
        $headers = Yii::$app->response->headers;
        $origin = $request->headers->get('Origin', '');
        $allowed = array_filter(array_map('trim', explode(',', (string)(Yii::$app->params['corsOrigins'] ?? ''))));
        $originHost = $origin ? parse_url($origin, PHP_URL_HOST) : '';
        $sameHost = $originHost && strcasecmp($originHost, $request->hostName) === 0;
        if ($origin && ($sameHost || in_array('*', $allowed, true) || in_array($origin, $allowed, true))) {
            $headers->set('Access-Control-Allow-Origin', in_array('*', $allowed, true) ? '*' : $origin);
            $headers->add('Vary', 'Origin');
        }
        $headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, x-service-key');
        $headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        if ($request->isOptions) { Yii::$app->response->statusCode = 204; return false; }

        if ($action->id !== 'login') {
            $token = null;
            $header = $request->headers->get('Authorization', '');
            if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
                $token = $m[1];
            } elseif ($action->id === 'print') {
                $token = $request->get('token');
            }
            if (!$token) throw new UnauthorizedHttpException('Bearer token diperlukan.');
            try {
                $payload = JWT::decode($token, new Key(Yii::$app->params['jwtSecret'], 'HS256'));
                $user = User::findIdentity((int)$payload->sub);
                if (!$user || !$user->is_active) throw new \Exception('User inactive');
                Yii::$app->user->setIdentity($user);
            } catch (\Throwable $e) {
                throw new UnauthorizedHttpException('Token tidak valid atau kedaluwarsa.');
            }
        }
        return parent::beforeAction($action);
    }

    protected function requirePermission($permission)
    {
        if (!Yii::$app->user->identity || !Yii::$app->user->identity->can($permission)) {
            throw new ForbiddenHttpException('Anda tidak memiliki hak akses: ' . $permission);
        }
    }

    protected function ok($data = [], $message = 'OK')
    {
        return ['success' => true, 'message' => $message, 'data' => $data];
    }
}
