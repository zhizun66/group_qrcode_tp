<?php

namespace app\admin\controller;

use app\BaseController;
use think\response\Json;

class Base extends BaseController
{
    public function login(): Json
    {
        $username = input('username');
        $password = input('password');

        if (empty($username) || empty($password)) {
            return $this->errorJson(-1, '请输入账号和密码');
        }

        $info = $this->db->name('admin')->where(['username' => $username, 'password' => md5($password)])
            ->field('id,username')->findOrEmpty();
        if (empty($info)) {
            return $this->errorJson(1, '账号或密码错误');
        } else {
            session('admin', $info);
            return $this->successJson($info);
        }
    }

    public function logout(): Json
    {
        session('admin', null);
        return $this->successJson();
    }

    public function tags(): Json
    {
        $data = $this->db->name('tag')->order('id')->column('id,name');
        return $this->successJson($data);
    }
}