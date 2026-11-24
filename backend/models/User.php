<?php
namespace app\models;
use Yii;
use yii\db\ActiveRecord;
use yii\web\IdentityInterface;
class User extends ActiveRecord implements IdentityInterface {
    public $password;
    public static function tableName() { return '{{%users}}'; }
    public function rules() {
        return [
            [['username','name','password_hash'],'required'],
            [['username'],'string','max'=>60],
            [['name'],'string','max'=>150],
            [['password'],'string','min'=>8,'skipOnEmpty'=>true],
            [['is_active'],'boolean'],
            [['fcm_token'],'string','max'=>512],
            [['fcm_token'],'unique'],
            [['username'],'unique'],
        ];
    }
    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) return false;
        if ($this->password) $this->password_hash = password_hash($this->password, PASSWORD_DEFAULT);
        return true;
    }
    public static function findIdentity($id) { return static::findOne(['id' => $id, 'is_active' => true]); }
    public static function findIdentityByAccessToken($token, $type = null) { return null; }
    public function getId() { return $this->id; }
    public function getAuthKey() { return null; }
    public function validateAuthKey($authKey) { return false; }
    public function validatePassword($password) { return password_verify($password, $this->password_hash); }
    public function getRoles() {
        return (new \yii\db\Query())->select('r.name')->from(['ur' => 'user_roles'])
            ->innerJoin(['r' => 'roles'], 'r.id = ur.role_id')->where(['ur.user_id' => $this->id])->column();
    }
    public function getRoleCodes() {
        return (new \yii\db\Query())->select('r.code')->from(['ur' => 'user_roles'])
            ->innerJoin(['r' => 'roles'], 'r.id = ur.role_id')->where(['ur.user_id' => $this->id])->column();
    }
    public function getPermissions() {
        return (new \yii\db\Query())->select('p.code')->from(['ur' => 'user_roles'])
            ->innerJoin(['rp' => 'role_permissions'], 'rp.role_id = ur.role_id')
            ->innerJoin(['p' => 'permissions'], 'p.id = rp.permission_id')
            ->where(['ur.user_id' => $this->id])->column();
    }
    public function can($permission) {
        return (new \yii\db\Query())->from(['ur' => 'user_roles'])
            ->innerJoin(['rp' => 'role_permissions'], 'rp.role_id = ur.role_id')
            ->innerJoin(['p' => 'permissions'], 'p.id = rp.permission_id')
            ->where(['ur.user_id' => $this->id, 'p.code' => $permission])->exists();
    }
}
