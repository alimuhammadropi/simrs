<?php
namespace app\components;

use Yii;
use app\models\AuditLog;

class MasterController extends ApiController
{
    public $modelClass;
    public $viewPermission = 'master.view';
    public $managePermission = 'master.manage';
    public $searchFields = ['name'];
    public $orderBy = ['id' => SORT_DESC];

    protected function body()
    {
        $body = Yii::$app->request->bodyParams;
        return is_array($body) ? $body : [];
    }

    protected function findModel($id)
    {
        $class = $this->modelClass;
        $model = $class::findOne($id);
        if (!$model) throw new \yii\web\NotFoundHttpException('Data tidak ditemukan.');
        return $model;
    }

    public function actionIndex()
    {
        if (Yii::$app->request->isPost) {
            $this->requirePermission($this->managePermission);
            $class = $this->modelClass;
            $model = new $class();
            if (!$model->load($this->body(), '') || !$model->save()) {
                throw new \yii\web\BadRequestHttpException(json_encode($model->errors));
            }
            $short = (new \ReflectionClass($class))->getShortName();
            AuditLog::record($short . '.create', $short, $model->id, null, $model->attributes);
            $this->afterCreate($model);
            return $this->ok($model, 'Data berhasil disimpan');
        }
        $this->requirePermission($this->viewPermission);
        $class = $this->modelClass;
        $query = $class::find();
        $search = trim((string)Yii::$app->request->get('q', ''));
        if ($search && $this->searchFields) {
            $or = ['or'];
            foreach ($this->searchFields as $field) $or[] = ['ilike', $field, $search];
            $query->andWhere($or);
        }
        if ($class::getTableSchema()->getColumn('is_active') && !Yii::$app->request->get('all')) {
            $query->andWhere(['is_active' => true]);
        }
        $limit = min(200, max(1, (int)Yii::$app->request->get('limit', 100)));
        return $this->ok($query->orderBy($this->orderBy)->limit($limit)->asArray()->all());
    }

    protected function afterCreate($model)
    {
    }

    public function actionView($id)
    {
        $this->requirePermission($this->viewPermission);
        return $this->ok($this->findModel($id));
    }

    public function actionUpdate($id)
    {
        $this->requirePermission($this->managePermission);
        $model = $this->findModel($id);
        $before = $model->attributes;
        if (!$model->load($this->body(), '') || !$model->save()) {
            throw new \yii\web\BadRequestHttpException(json_encode($model->errors));
        }
        $short = (new \ReflectionClass($this->modelClass))->getShortName();
        AuditLog::record($short . '.update', $short, $model->id, $before, $model->attributes);
        return $this->ok($model, 'Data berhasil diperbarui');
    }

    public function actionDelete($id)
    {
        $this->requirePermission($this->managePermission);
        $model = $this->findModel($id);
        $before = $model->attributes;
        if ($model->hasAttribute('is_active')) {
            $model->is_active = false;
            $model->save(false);
        } else {
            $model->delete();
        }
        $short = (new \ReflectionClass($this->modelClass))->getShortName();
        AuditLog::record($short . '.delete', $short, $id, $before, null);
        return $this->ok([], 'Data berhasil dihapus');
    }
}
