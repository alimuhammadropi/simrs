<?php
namespace app\controllers\api;
use app\components\MasterController;
use app\models\Polyclinic;
class PolyclinicController extends MasterController {
    public $modelClass = Polyclinic::class;
    public $searchFields = ['code','name'];
}
