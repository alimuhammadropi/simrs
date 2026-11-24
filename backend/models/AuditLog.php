<?php
namespace app\models;
use Yii;
use yii\db\ActiveRecord;
class AuditLog extends ActiveRecord {
    public static function tableName(){return '{{%audit_logs}}';}
    public static function record($action, $entity = null, $entityId = null, $before = null, $after = null)
    {
        $log = new static();
        $log->user_id = Yii::$app->user && !Yii::$app->user->isGuest ? Yii::$app->user->id : null;
        $log->action = $action;
        $log->entity = $entity;
        $log->entity_id = $entityId === null ? null : (string)$entityId;
        $log->ip_address = Yii::$app->request ? Yii::$app->request->userIP : null;
        $log->before_data = $before === null ? null : $before;
        $log->after_data = $after === null ? null : $after;
        $log->save(false);
    }
}
