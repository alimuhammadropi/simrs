<?php
namespace app\controllers\api;
use Yii;
use app\components\ApiController;
use app\components\FcmService;
use app\models\{AuditLog,Role,User};
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
class UserController extends ApiController
{
    private function assignRoles($userId, $codes)
    {
        if (!is_array($codes)) return;
        $valid = Role::find()->where(['code'=>$codes])->column();
        Yii::$app->db->createCommand()->delete('user_roles', ['user_id'=>$userId])->execute();
        foreach ($valid as $code) {
            Yii::$app->db->createCommand('INSERT INTO user_roles(user_id,role_id) SELECT :u,id FROM roles WHERE code=:c', [':u'=>$userId, ':c'=>$code])->execute();
        }
    }
    public function actionIndex()
    {
        $this->requirePermission('user.manage');
        if (Yii::$app->request->isPost) {
            $b = Yii::$app->request->bodyParams;
            $m = new User();
            $m->username = trim($b['username'] ?? '');
            $m->name = trim($b['name'] ?? '');
            $m->password = $b['password'] ?? '';
            $m->is_active = $b['is_active'] ?? true;
            if (!$m->save()) throw new BadRequestHttpException(json_encode($m->errors));
            $this->assignRoles($m->id, $b['roles'] ?? []);
            AuditLog::record('user.create','user',$m->id,null,['username'=>$m->username,'roles'=>$m->getRoleCodes()]);
            return $this->ok(['id'=>$m->id,'username'=>$m->username,'roles'=>$m->getRoleCodes()], 'Pengguna dibuat');
        }
        $rows = (new \yii\db\Query())->select(['u.id','u.username','u.name','u.is_active','u.created_at', new \yii\db\Expression('u.fcm_token IS NOT NULL AS fcm_token_set')])->from(['u'=>'users'])->orderBy(['u.id'=>SORT_ASC])->all();
        foreach ($rows as &$row) $row['roles'] = (new \yii\db\Query())->select('r.code')->from(['ur'=>'user_roles'])->innerJoin(['r'=>'roles'],'r.id=ur.role_id')->where(['ur.user_id'=>$row['id']])->column();
        return $this->ok($rows);
    }
    public function actionView($id)
    {
        $this->requirePermission('user.manage');
        $m = User::findOne($id);
        if (!$m) throw new NotFoundHttpException('Pengguna tidak ditemukan.');
        return $this->ok(['id'=>$m->id,'username'=>$m->username,'name'=>$m->name,'is_active'=>$m->is_active,'fcm_token_set'=>(bool)$m->fcm_token,'roles'=>$m->getRoleCodes()]);
    }
    public function actionUpdate($id)
    {
        $this->requirePermission('user.manage');
        $m = User::findOne($id);
        if (!$m) throw new NotFoundHttpException('Pengguna tidak ditemukan.');
        $b = Yii::$app->request->bodyParams;
        $before = ['username'=>$m->username,'name'=>$m->name];
        if (isset($b['name'])) $m->name = $b['name'];
        if (isset($b['username'])) $m->username = $b['username'];
        if (!empty($b['password'])) $m->password = $b['password'];
        if (isset($b['is_active'])) $m->is_active = $b['is_active'];
        if (!$m->save()) throw new BadRequestHttpException(json_encode($m->errors));
        if (array_key_exists('roles', $b)) $this->assignRoles($m->id, $b['roles']);
        AuditLog::record('user.update','user',$m->id,$before,['username'=>$m->username,'name'=>$m->name,'roles'=>$m->getRoleCodes()]);
        return $this->ok(['id'=>$m->id,'username'=>$m->username,'roles'=>$m->getRoleCodes()], 'Pengguna diperbarui');
    }
    public function actionResetNotificationToken($id)
    {
        $this->requirePermission('user.manage');
        $m = User::findOne($id);
        if (!$m) throw new NotFoundHttpException('Pengguna tidak ditemukan.');
        $token = FcmService::clearUserTokens((int)$m->id);
        AuditLog::record('user.fcm_token.reset', 'user', $m->id, ['fcm_token_set' => (bool)$token], ['fcm_token_set' => false]);
        return $this->ok(['fcm_token_set' => false], 'Token notifikasi pengguna direset');
    }

    public function actionDelete($id)
    {
        $this->requirePermission('user.manage');
        $m = User::findOne($id);
        if (!$m) throw new NotFoundHttpException('Pengguna tidak ditemukan.');
        $m->is_active = false;
        $m->save(false);
        AuditLog::record('user.delete','user',$m->id,['is_active'=>true],['is_active'=>false]);
        return $this->ok([], 'Pengguna dinonaktifkan');
    }
}
