<?php
namespace app\controllers\api;
use app\components\MasterController;
use app\models\Doctor;
class DoctorController extends MasterController {
    public $modelClass = Doctor::class;
    public $searchFields = ['name','sip_number','specialty'];
}
