<?php
declare (strict_types = 1);

namespace app\staff;

use app\BaseController;
use think\App;

class CommonController extends BaseController
{
    protected ?array $staff = null;
    function __construct(App $app)
    {
        parent::__construct($app);

        $staff = session('staff');
        if (empty($staff)) {
            header('Content-Type: application/json; charset=utf-8');
            echo $this->errorJson(-999, '/login.html?staff')->getContent();
            exit;
        } else {
            $this->staff = $staff;
        }
    }
}