<?php
namespace app\components;

use Yii;
use yii\rest\Controller;

class ServiceController extends Controller
{
    public $enableCsrfValidation = false;

    public function beforeAction($action)
    {
        Yii::$app->response->headers->set('Access-Control-Allow-Origin', '*');
        Yii::$app->response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, x-service-key');
        Yii::$app->response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        if (Yii::$app->request->isOptions) { Yii::$app->response->statusCode = 204; return false; }
        $key = Yii::$app->request->headers->get('x-service-key', '');
        $expected = (string)(Yii::$app->params['nodeServiceKey'] ?? '');
        if (!$expected || !hash_equals($expected, $key)) {
            throw new \yii\web\UnauthorizedHttpException('Unauthorized service.');
        }
        return parent::beforeAction($action);
    }

    protected function ok($data = [], $message = 'OK')
    {
        return ['success' => true, 'message' => $message, 'data' => $data];
    }
}
