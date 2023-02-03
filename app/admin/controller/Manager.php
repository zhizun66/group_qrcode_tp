<?php

namespace app\admin\controller;

use app\admin\CommonController;
use Exception;
use think\response\Json;

class Manager extends CommonController
{
  public function index(): Json
  {
    $enable = input('enable/d', 1);

    $query = $this->db->name('manager')->where('enable', $enable);

    $data = $query->page($this->page, $this->pageSize)
      ->order('id', 'DESC')
      ->column('id,username,enable,add_time');

    $total = $query->count();

    return $this->successJson($this->paginate($data, $total));
  }

  public function enable(): Json
  {
    $managerId = input('mid');
    $enable = input('enable/d', 1);

    if ($enable !== 0 && $enable !== 1) {
      $enable = 1;
    }
    try {
      $this->db->name('manager')->where('id', $managerId)->update(['enable' => $enable, 'relogin' => 1]);
      return $this->successJson();
    } catch (Exception) {
      return $this->errorJson();
    }
  }

  public function del(): Json
  {
    $managerId = input('mid');
    try {
      $this->db->name('manager')->where('id', $managerId)->delete();
      return $this->successJson();
    } catch (Exception) {
      return $this->errorJson();
    }
  }

  public function edit(): Json
  {
      $managerId = input('mid');
    $password = input('password');

    if (empty($password)) {
      return $this->errorJson();
    }

    try {
      $this->db->name('manager')->where('id', $managerId)->update(['password' => md5($password), 'relogin' => 1]);
      return $this->successJson();
    } catch (Exception) {
      return $this->errorJson();
    }
  }
}
