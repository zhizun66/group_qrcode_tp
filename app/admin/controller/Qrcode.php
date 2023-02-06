<?php

namespace app\admin\controller;

use app\admin\CommonController;
use Exception;
use think\response\Json;

class Qrcode extends CommonController
{
  public function index(): Json
  {
    $company = input('company');
    $group_name = input('group_name');
    // $tags = input('tags/a');
    // $area = input('area/a');
    // $remark = input('remark');
    $status = input('status/d');

    $query = $this->db->name('qrcode')->alias('q')
      ->leftJoin('entrance e', 'e.qrcode_id=q.id AND e.expire_date>=NOW()')
      // ->leftJoin('tag t', 'FIND_IN_SET(t.id,q.tags)')
      ->group('q.id')
      ->fieldRaw("q.id,q.user_id,q.provider_id,q.code,q.company,q.status,q.err_msg,q.add_time");

    if (!empty($status)) {
      $query->where('q.status', $status - 1);
    }
    if (!empty($company)) {
      $query->whereLike('q.company', "%{$company}%");
    }
    if (!empty($group_name)) {
      $query->whereLike('e.name', "%{$group_name}%");
    }
    $total = $query->count();
    $data = $query->page($this->page, $this->pageSize)->select()->toArray();

    foreach ($data as &$item) {
      if ($item['user_id'] != null) {
        $item['username'] = $this->db->name('user')->where('id', $item['user_id'])->value('username');
      } elseif ($item['provider_id'] != null) {
        $item['username'] = $this->db->name('provider')->where('id', $item['provider_id'])->value('username');
      }

      $subData = $this->db->name('entrance')->alias('e')
        ->leftJoin('buy b', 'b.entrance_id=e.id')
        ->where('e.expire_date', '>=', $this->db->raw('NOW()'))
        ->where('e.qrcode_id', $item['id'])
        ->column("e.id,e.avatar,e.name,e.members,e.qr,e.expire_date,e.joinable_wc,e.reported,e.add_time,CONVERT(IF(b.id,'1','0'),UNSIGNED) 'bought'");
      $item['entrance'] = $subData;
    }
    return $this->successJson($this->paginate($data, $total));
  }

  public function del(): Json
  {
    $qrcodeId = input('post.qid');
    $this->assertNotEmpty($qrcodeId);

    try {
      $this->db->name('qrcode')->where('id', $qrcodeId)->delete();
      return $this->successJson();
    } catch (Exception) {
      return $this->errorJson();
    }
  }

  // 编辑活码
  public function edit(): Json
  {
    $qrcodeId = input('id');
    $company = input('company');
    $area = input('area/a') ?? [];
    $tags = input('tags/a') ?? [];
    $subStatus = input('sub_status/d') ?? 0;

    $this->assertNotEmpty($qrcodeId);
    $area = array_map(function ($item) {
      return $item === 'all' ? null : $item;
    }, $area);
    $area = array_pad($area, 3, null);
    if (!in_array($subStatus, [0, 1, 2])) {
      $subStatus = 0;
    }
    $company = empty($company) ? null : $company;
    $tags = array_unique($tags);

    $this->db->startTrans();
    try {
      $this->db->name('qrcode')->where(['id' => $qrcodeId])->update([
        'company' => $company,
        'tags' => implode(',', $tags),
        'sub_status' => $subStatus,
        'province' => $area[0],
        'city' => $area[1],
        'district' => $area[2]
      ]);
      $this->db->name('entrance')->where(['qrcode_id' => $qrcodeId])->update([
        'company' => $company,
        'tags' => implode(',', $tags),
        'province' => $area[0],
        'city' => $area[1],
        'district' => $area[2]
      ]);
      $this->db->commit();
      return $this->successJson();
    } catch (Exception $e) {
      $this->db->rollback();
      return $this->errorJson(1, $e->getMessage());
    }
  }
}
