<?php

namespace app\manager\controller;

use app\manager\CommonController;
use think\facade\Db;
use think\response\Json;

class Qrcode extends CommonController
{
  public function index(): Json
  {
    $company = input('company');
    $tags = input('tags/a');
    $area = input('area/a');
    $remark = input('remark');
    $username = input('username');

    $query = Db::name('qrcode')->alias('q')
      // ->leftJoin('tag t', 'FIND_IN_SET(t.id,q.tags)')
      ->whereIn('q.provider_id', function ($q) {
        $q->name('provider')->where('manager_id', $this->manager['id'])->field('id');
      })
      ->group('q.id')
      ->page($this->page, $this->pageSize)
      ->fieldRaw("q.id,q.provider_id,q.code,q.company,q.status,q.valid,q.valid_time,q.err_msg,q.add_time");

    if (!empty($company)) {
      $query->whereLike('q.company', "%{$company}%");
    }
    if (!empty($username)) {
      $providerId = $this->db->name('provider')->where('username', $username)->value('id');
      $query->where('provider_id', $providerId);
    }

    $data = $query->select()->toArray();

    foreach ($data as &$item) {
      $item['username'] = $this->db->name('provider')->where('id', $item['provider_id'])->value('username');

      $subData = Db::name('entrance')->alias('e')
        ->where('e.qrcode_id', $item['id'])
        ->where('e.expire_date', '>=', $this->db->raw('NOW()'))
        ->column("e.id,e.avatar,e.name,e.members,e.qr,e.expire_date,e.joinable_wc,e.add_time");
      $item['entrance'] = $subData;
    }

    $total = Db::name('qrcode')
      ->whereIn('provider_id', function ($q) {
        $q->name('provider')->where('manager_id', $this->manager['id'])->field('id');
      })
      ->count();

    return $this->successJson($this->paginate($data, $total));
  }
}
