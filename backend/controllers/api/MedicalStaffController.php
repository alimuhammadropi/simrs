<?php
namespace app\controllers\api;
use app\components\MasterController;
use app\models\MedicalStaff;
class MedicalStaffController extends MasterController {
    public $modelClass = MedicalStaff::class;
    public $searchFields = ['employee_number','name','role'];
}
