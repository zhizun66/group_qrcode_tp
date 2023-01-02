<?php

namespace app\staff\controller;

use app\BaseController;
use Exception;
use think\response\Json;

class User extends BaseController
{
    public function login(): Json
    {
        $username = input('username');
        $password = input('password');

        if (empty($username) || empty($password)) {
            return $this->errorJson(-1, '请输入账号和密码');
        }

        $info = $this->db->name('staff')->where(['username' => $username, 'password' => md5($password)])
            ->field('id,username,name,enable')->findOrEmpty();
        if (empty($info)) {
            return $this->errorJson(1, '账号或密码错误');
        } elseif ($info['enable'] === 1) {
            session('staff', $info);
            return $this->successJson($info);
        } else {
            return $this->errorJson(2, '账号暂未生效');
        }
    }

    public function reg(): Json
    {
        $username = input('post.username');
        $password = input('post.password');
        $name = input('post.name');

        if (empty($username) || empty($password)) {
            return $this->errorJson(-1, '请输入账号和密码');
        }

        if (!empty($this->db->name('staff')->where('username', $username)->value('id'))) {
            return $this->errorJson(1, '员工账号已存在');
        }

        try {
            $this->db->name('staff')->insert(['username' => $username, 'password' => md5($password), 'name' => $name]);
            return $this->successJson();
        } catch (Exception) {
            return $this->errorJson(-2);
        }
    }

    public function logout(): Json
    {
        if ($this->request->isPost()) {
            session('staff', null);
            return $this->successJson();
        } else {
            return $this->errorJson();
        }
    }
}