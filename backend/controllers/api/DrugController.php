<?php
namespace app\controllers\api;
use app\components\MasterController;
use app\models\Drug;
use yii\db\ActiveRecord;
use yii\db\Query;
class DrugController extends MasterController {
    public $modelClass = Drug::class;
    public $searchFields = ['code','name'];

    public function actionIndex()
    {
        $result = parent::actionIndex();
        $result['data'] = $this->withAvailability($result['data']);
        return $result;
    }

    public function actionView($id)
    {
        $result = parent::actionView($id);
        $row = $result['data'] instanceof ActiveRecord ? $result['data']->toArray() : $result['data'];
        $result['data'] = $this->withAvailability([$row])[0];
        return $result;
    }

    private function withAvailability(array $rows)
    {
        $reserved = (new Query())->select(['pi.drug_id AS drug_id','SUM(pi.quantity) AS qty'])
            ->from(['pi'=>'prescription_items'])
            ->innerJoin(['p'=>'prescriptions'],'p.id=pi.prescription_id')
            ->where(['p.status'=>'pending'])
            ->groupBy(['pi.drug_id'])
            ->all();
        $map = [];
        foreach ($reserved as $row) $map[(int)$row['drug_id']] = (int)$row['qty'];
        foreach ($rows as &$row) {
            $stock = (int)($row['stock'] ?? 0);
            $held = $map[(int)$row['id']] ?? 0;
            $row['reserved_stock'] = $held;
            $row['available_stock'] = max(0, $stock - $held);
        }
        return $rows;
    }
}
