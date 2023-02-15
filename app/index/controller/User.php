<?php

declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use Exception;
use think\facade\Db;
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

    $info = Db::name('user')->where(['username' => $username, 'password' => md5($password)])
      ->field('id,username,name,enable')->findOrEmpty();
    if (empty($info)) {
      return $this->errorJson(1, '账号或密码错误');
    } elseif ($info['enable'] === 1) {
      unset($info['enable']);
      $this->db->name('user')->where('id', $info['id'])->update(['relogin' => 0]);
      session('user', $info);
      return $this->successJson($info);
    } else {
      return $this->errorJson(2, '账号暂未生效');
    }
  }

  public function logout(): Json
  {
    session('user', null);
    return $this->successJson();
  }

  public function reg(): Json
  {
    $username = input('username');
    $password = input('password');
    $name = input('name');

    $this->assertNotEmpty($username, $password);
    $name = empty($name) ? $username : $name;

    if (!empty(Db::name('user')->where('username', $username)->value('id'))) {
      return $this->errorJson(1, '用户名已存在');
    }

    $inviteUserId = session('invite_user_id');
    if (empty($inviteUserId)) {
      return $this->errorJson(2, '请通过邀请链接注册');
    }


    if ($inviteUserId == 1 || $inviteUserId == 29 || $inviteUserId == 30) {
    } else {
      return $this->errorJson(1, '暂不开放注册,请联系管理员V：pojieban1');
    }
    $this->setEmptyStringToNull($inviteUserId);
    $this->db->startTrans();
    try {

      $userId = Db::name('user')->insertGetId([
        'username' => $username,
        'password' => md5($password),
        'name' => $name,
        'enable' => 1,
        'invite_user_id' => $inviteUserId
      ]);
      $outerId = substr(md5($userId . uniqid()), 8, 16);
      // var_dump($outerId);


      $this->db->name('user')->where('id', $userId)->update(['outer_id' => $outerId]);
      $this->db->commit();
      return $this->successJson();
    } catch (\Exception $e) {
      $this->db->rollback();
      return $this->errorJson(-2, $e->getMessage());
    }
  }

  public function invite()
  {
    $outerId = input('p');

    $inviteUserId = $this->db->name('user')->where('outer_id', $outerId)->value('id');
    if ($inviteUserId) {
      session('invite_user_id', $inviteUserId);
    }

    header('location:' . $this->request->domain() . '/login.html');
  }
}
