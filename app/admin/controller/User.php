<?php

namespace app\admin\controller;

use app\admin\CommonController;
use Exception;
use think\response\Json;

class User extends CommonController
{
  public function index(): Json
  {
    $enable = input('enable/d', 1);
    $username = input('username');

    $query = $this->db->name('user')
      ->where('enable', $enable);
    $total = $query->count();

    if (!empty($username)) {
      $query->where('username', $username);
    }

    $data = $query->page($this->page, $this->pageSize)
      ->order('id', 'DESC')
      ->column('id,username,name,enable,invite_user_id,balance,score,add_time');
    foreach ($data as &$item) {
      $item['invite_user_username'] = $this->db->name('user')->where('id', $item['invite_user_id'])->value('username');
    }
    return $this->successJson($this->paginate($data, $total));
  }

  public function enable(): Json
  {
    $userId = input('uid');
    $enable = input('enable/d', 1);

    if ($enable !== 0 && $enable !== 1) {
      $enable = 1;
    }

    try {
      $this->db->name('user')->where('id', $userId)->update(['enable' => $enable, 'relogin' => 1]);
      return $this->successJson();
    } catch (Exception) {
      return $this->errorJson();
    }
  }

  public function del(): Json
  {
    $userId = input('uid');
    try {
      $this->db->name('user')->where('id', $userId)->delete();
      return $this->successJson();
    } catch (Exception) {
      return $this->errorJson();
    }
  }

  public function edit(): Json
  {
    $userId = input('uid');
    $password = input('password');

    if (empty($password)) {
      return $this->errorJson();
    }

    try {
      $this->db->name('user')->where('id', $userId)->update(['password' => md5($password), 'relogin' => 1]);
      return $this->successJson();
    } catch (Exception) {
      return $this->errorJson();
    }
  }

  public function recharge(): Json
  {
    $userId = input('uid');
    $value = input('value/d');

    if (empty($value) || intval($value) < 1) {
      return $this->errorJson();
    }

    $this->db->startTrans();
    try {
      $this->db->name('user')->where('id', $userId)->inc('balance', $value)->update();
      $this->db->name('deposit')->insert(['user_id' => $userId, 'value' => $value]);
      $this->db->commit();
      return $this->successJson();
    } catch (Exception) {
      $this->db->rollback();
      return $this->errorJson();
    }
  }

  // 充值记录
  function depositList(): Json
  {
    $username = input('username');
    $date = input('date/a');

    $query = $this->db->name('deposit')->alias('d')
      ->leftJoin('user u', 'u.id=d.user_id');

    if (!empty($username)) {
      $query->where('u.username', $username);
    }
    if (!empty($date)) {
      $begin = addslashes($date[0]);
      $end = addslashes($date[1]);
      $query->whereRaw("DATE(b.add_time) >= FROM_UNIXTIME({$begin}) AND DATE(b.add_time) <= FROM_UNIXTIME({$end})");
    }

    $total = $query->count();
    $data = $query->order('d.id', 'DESC')->column('d.id,d.value,d.way,u.username,d.add_time');

    return $this->successJson($this->paginate($data, $total));
  }
}
