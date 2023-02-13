<?php

namespace app\manager\controller;

use app\manager\CommonController;
use think\response\Json;

class Entrance extends CommonController
{
  public function index(): Json
  {
    $company = input('company');
    $username = input('username');

    $query = $this->db->name('entrance')->alias('e');
    // ->leftJoin('buy b', 'b.entrance_id=e.id')
    // ->group('e.id');



    if (!empty($company)) {
      $query->whereLike('e.company', "%{$company}%");
    }

    if (!empty($username)) {
      $providerId = $this->db->name('provider')->where('username', $username)->value('id');
      $query->where('provider_id', $providerId);
    }

    $data = $query->whereIn('provider_id', function ($q) {
      $q->name('provider')->where('manager_id', $this->manager['id'])->field('id');
    })
      ->where('e.expire_date', '>=', $this->db->raw('NOW()'))
      ->order('e.id', 'DESC')
      ->page($this->page, $this->pageSize)
      ->column("e.id,e.qrcode_id,e.name,e.avatar,e.expire_date,e.members,e.im,e.hide,e.add_time,e.company,e.type,e.status");

    foreach ($data as &$item) {
      $item['im'] = $item['im'] ? $this->request->domain(true) . '/storage/' . $item['im'] : null;
    }

    $total = $query->count();

    return $this->successJson($this->paginate($data, $total));
  }
}
