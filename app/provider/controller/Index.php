<?php

declare(strict_types=1);

namespace app\provider\controller;

use app\provider\CommonController;
use Exception;
use think\response\Json;

class Index extends CommonController
{
  public function index(): Json
  {
    $info = $this->db->name('provider')->where('id', $this->provider['id'])->field('id,username,score')->findOrEmpty();
    $info['entrance_price'] = $this->getSysSetting('entrance_price');
    $info['max_buy_times'] = $this->getSysSetting('max_buy_times');

    $info['entrance_valid'] = $this->db->name('qrcode')->where(['provider_id' => $this->provider['id'], 'valid' => 1])->count();
    return $this->successJson($info);
  }

  public function repwd(): Json
  {
    $password = input('post.password');
    if (empty($password)) {
      return $this->errorJson();
    }

    try {
      $this->db->name('provider')->where('id', $this->provider['id'])->update(['password' => md5($password)]);
      return $this->successJson();
    } catch (Exception) {
      return $this->errorJson();
    }
  }
}
