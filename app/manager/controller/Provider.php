<?php

namespace app\manager\controller;

use app\manager\CommonController;
use Exception;
use think\response\Json;

class Provider extends CommonController
{
  public function index(): Json
  {
    $enable = input('enable/d', 1);
    $query = $this->db->name('provider')->where('manager_id', $this->manager['id'])->where('enable', $enable);
    $total = $query->count();

    $data = $query->page($this->page, $this->pageSize)->order('id', 'ASC')->column('id,username,enable,add_time', 'id');
    $qrcodeData = $this->db->name('qrcode')->whereIn('provider_id', array_keys($data))->group('provider_id')->column('COUNT(id)', 'provider_id');
    $qrcodeValidData = $this->db->name('qrcode')->whereIn('provider_id', array_keys($data))->where('valid', 1)->group('provider_id')->column('COUNT(id)', 'provider_id');
    $entranceData = $this->db->name('entrance')->whereIn('provider_id', array_keys($data))->group('provider_id')->column('COUNT(id)', 'provider_id');

    foreach ($data as $k => &$item) {
      //$k = $item['id'];
      $item['qrcode_cnt'] = $qrcodeData[$k] ?? 0;
      $item['qrcode_valid_cnt'] = $qrcodeValidData[$k] ?? 0;
      $item['entrance_cnt'] = $entranceData[$k] ?? 0;
    }

    $data = array_values($data);
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
      $this->db->name('provider')->where('id', $providerId)->where('manager_id', $this->manager['id'])->update(['enable' => $enable]);
      return $this->successJson();
    } catch (Exception) {
      return $this->errorJson();
    }
  }

  public function del(): Json
  {
    $providerId = input('pid');
    try {
      $this->db->name('provider')->where('id', $providerId)->where('manager_id', $this->manager['id'])->delete();
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
      $this->db->name('provider')->where('id', $providerId)->where('manager_id', $this->manager['id'])->update(['password' => md5($password)]);
      return $this->successJson();
    } catch (Exception) {
      return $this->errorJson();
    }
  }

    public function inviteUrl(): Json
    {
        $url = $this->request->domain() . '/rest/provider/base/invite?inviter=' . $this->manager['id'];
        return $this->successJson(['url' => $url]);
    }
}
