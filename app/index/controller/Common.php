<?php
declare (strict_types = 1);

namespace app\index\controller;

use app\index\CommonController;
use think\facade\Db;
use think\response\Json;

class Common extends CommonController
{
    public function tags(): Json
    {
        $data = Db::name('tag')->order('id')->column('id,name');
        return $this->successJson($data);
    }
}