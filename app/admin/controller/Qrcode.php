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
    $tags = input('tags/a');
    $area = input('area/a');
    $remark = input('remark');
    $status = input('status/d');

    $query = $this->db->name('qrcode')->alias('q')
      ->leftJoin('tag t', 'FIND_IN_SET(t.id,q.tags)')
      ->group('q.id')
      ->fieldRaw("q.id,q.user_id,q.code,q.company,q.province,q.city,q.district,q.remark,q.status,q.sub_status,q.err_msg,GROUP_CONCAT(t.name) tags,q.add_time");

    if (!empty($status)) {
      $query->where('q.status', $status);
    }
    if (!empty($company)) {
      $query->whereLike('q.company', "%{$company}%");
    }
    if (!empty($remark)) {
      $query->whereLike('q.remark', "%{$remark}%");
    }

    if (!empty($tags)) {
      $where = array_reduce($tags, function ($carry, $tag) {
        return $carry . "FIND_IN_SET({$tag},q.tags) AND ";
      }, '');
      $where = rtrim($where, ' AND');
      $query->whereRaw($where);
    }

    if (!empty($area)) {
      array_walk($area, function (&$elem) {
        if ($elem === 'all') {
          $elem = null;
        }
      });
      if (!empty($area[0])) {
        $query->where('q.province', $area[0]);
      }
      if (!empty($area[1])) {
        $query->where('q.city', $area[1]);
      }
      if (!empty($area[2])) {
        $query->where('q.district', $area[2]);
      }
    }
    $total = $query->count();
    $data = $query->page($this->page, $this->pageSize)->select()->toArray();

    foreach ($data as &$item) {
      $item['tags'] = empty($item['tags']) ? [] : explode(',', $item['tags']);
      $item['username'] = $this->db->name('user')->where('id', $item['user_id'])->value('username');
      $item['area'] = array_filter([$item['province'], $item['city'], $item['district']], function ($elem) {
        return !is_null($elem);
      });
      if (count($item['area']) === 0) {
        $item['area'] = null;
      } elseif (count($item['area']) < 3) {
        $item['area'][] = 'all';
      }

      $subData = $this->db->name('entrance')->alias('e')
        ->leftJoin('buy b', 'b.entrance_id=e.id')
        ->where('e.expire_date', '>=', $this->db->raw('NOW()'))
        ->where('e.qrcode_id', $item['id'])
        ->column("e.id,e.avatar,e.name,e.members,e.qr,e.expire_date,e.joinable,e.reported,e.add_time,CONVERT(IF(b.id,'1','0'),UNSIGNED) 'bought'");
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
