<?php
namespace app\controllers\api;
use app\components\MasterController;
use app\models\MedicalAction;
class MedicalActionController extends MasterController {
    public $modelClass = MedicalAction::class;
    public $searchFields = ['code','name'];
}
