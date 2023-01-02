<?php
declare (strict_types = 1);

namespace app\admin;

use app\BaseController;
use think\App;

class CommonController extends BaseController
{
    protected ?array $admin = null;
    function __construct(App $app)
    {
        parent::__construct($app);

        $admin = session('admin');
        if (empty($admin)) {
            header('Content-Type: application/json; charset=utf-8');
            echo $this->errorJson(-999, '/login.html?admin')->getContent();
            exit;
        } else {
            $this->admin = $admin;
        }
    }
}
