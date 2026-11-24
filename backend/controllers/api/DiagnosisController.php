<?php
namespace app\controllers\api;
use app\components\MasterController;
use app\models\Diagnosis;
class DiagnosisController extends MasterController {
    public $modelClass = Diagnosis::class;
    public $searchFields = ['code','name'];
}
