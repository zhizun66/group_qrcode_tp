<?php

namespace app\admin\controller;

use app\admin\CommonController;
use Exception;
use think\response\Json;

class Provider extends CommonController
{
  public function index(): Json
  {
    $enable = input('enable/d', 1);

    $query = $this->db->name('provider')->where('enable', $enable);

    $data = $query->page($this->page, $this->pageSize)
      ->order('id', 'DESC')
      ->column('id,username,name,score,enable,add_time');

    $total = $query->count();

    return $this->successJson($this->paginate($data, $total));
  }

  public function enable(): Json
  {
    $providerId = input('pid');
    $enable = input('enable/d', 1);

    if ($enable !== 0 && $enable !== 1) {
      $enable = 1;
    }
    try {
      $this->db->name('provider')->where('id', $providerId)->update(['enable' => $enable, 'relogin' => 1]);
      return $this->successJson();
    } catch (Exception) {
      return $this->errorJson();
    }
  }

  public function del(): Json
  {
    $providerId = input('pid');
    try {
      $this->db->name('provider')->where('id', $providerId)->delete();
      return $this->successJson();
    } catch (Exception) {
      return $this->errorJson();
    }
  }

  public function edit(): Json
  {
    $providerId = input('pid');
    $password = input('password');

    if (empty($password)) {
      return $this->errorJson();
    }

    try {
      $this->db->name('provider')->where('id', $providerId)->update(['password' => md5($password), 'relogin' => 1]);
      return $this->successJson();
    } catch (Exception) {
      return $this->errorJson();
    }
  }
}
