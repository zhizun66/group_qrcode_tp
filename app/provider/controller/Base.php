<?php

namespace app\provider\controller;

use app\BaseController;
use Exception;
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

        $info = $this->db->name('provider')->where(['username' => $username, 'password' => md5($password)])
            ->field('id,username,score,enable')->findOrEmpty();
        if (empty($info)) {
            return $this->errorJson(1, '账号或密码错误');
        } elseif ($info['enable'] === 1) {
            unset($info['enable']);
            session('provider', $info);
            $this->db->name('provider')->where('id', $info['id'])->update(['relogin' => 0]);
            return $this->successJson($info);
        } else {
            return $this->errorJson(2, '账号暂未生效');
        }
    }

    public function logout(): Json
    {
        session('provider', null);
        return $this->successJson();
    }

    public function tags(): Json
    {
        $data = $this->db->name('tag')->order('id')->column('id,name');
        return $this->successJson($data);
    }

    public function reg(): Json
    {
        $username = input('username');
        $password = input('password');
        $name = input('name');

        $this->assertNotEmpty($username, $password);
        $name = empty($name) ? $username : $name;

        if (!empty($this->db->name('provider')->where('username', $username)->value('id'))) {
            return $this->errorJson(1, '用户名已存在');
        }

        $inviteManagerId = session('invite_manager_id');
        if (empty($inviteManagerId)) {
            return $this->errorJson(2, '请通过邀请链接注册');
        }

        try {
            $this->db->name('provider')->insert(['username' => $username, 'password' => md5($password), 'name' => $name, 'manager_id' => $inviteManagerId]);
            return $this->successJson();
        } catch (Exception) {
            return $this->errorJson(-2);
        }
    }

    public function invite()
    {
        $inviterId = input('inviter');

        $inviteUserId = $this->db->name('manager')->where('id', $inviterId)->value('id');
        if ($inviteUserId) {
            session('invite_manager_id', $inviteUserId);
            cookie('_invite_', '1');
        }

        header('location:' . $this->request->domain() . '/login.html?provider');
    }
}