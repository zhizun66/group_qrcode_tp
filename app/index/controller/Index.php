<?php
declare (strict_types = 1);

namespace app\index\controller;

use app\index\CommonController;
use Exception;
use think\facade\Db;
use think\response\Json;

class Index extends CommonController
{
    public function index(): Json
    {
        $info = Db::name('user')->where('id', $this->user['id'])->field('id,username,balance,score')->findOrEmpty();
        return $this->successJson($info);
    }

    public function repwd(): Json
    {
        $password = input('post.password');
        if (empty($password)) {
            return $this->errorJson();
        }
        try {
            $this->db->name('staff')->where('id', $this->user['id'])->update(['password' => md5($password)]);
            return $this->successJson();
        } catch (Exception) {
            return $this->errorJson();
        }
    }

}
