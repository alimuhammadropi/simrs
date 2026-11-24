<?php
namespace app\controllers\api;
use app\components\MasterController;
use app\models\LabTest;
class LabTestController extends MasterController {
    public $modelClass = LabTest::class;
    public $searchFields = ['code','name'];
}
