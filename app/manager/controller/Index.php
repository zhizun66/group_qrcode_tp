<?php
declare (strict_types = 1);

namespace app\manager\controller;


use app\manager\CommonController;
use think\response\Json;

class index extends CommonController
{
    public function index(): Json
    {
        $info = $this->db->name('manager')->where('id', $this->manager['id'])->field('id,username')->findOrEmpty();
        return $this->successJson($info);
    }
}
