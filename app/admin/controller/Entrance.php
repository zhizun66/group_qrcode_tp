<?php

namespace app\admin\controller;

use app\admin\CommonController;
use Exception;
use think\response\Json;

class Entrance extends CommonController
{
  public function index(): Json
  {
    $company = input('company');
    // $remark = input('remark');
    // $area = input('area/a') ?? [];
    // $tags = input('tags/a');
    $type = input('type/d');
    $group_name = input('group_name');
    $joinable_wc = input('joinable_wc/d');
    $status = input('status/d');
    // $sql = $this->db->name('buy')->where('add_time', '>', $this->db->raw('DATE_SUB(NOW(),INTERVAL 1 MONTH)'))->field('id,entrance_id,user_id')->buildSql();
    $sql = $this->db->name('buy')->field('id,entrance_id,user_id')->buildSql();
    $query = $this->db->name('entrance')->alias('e')
      ->leftJoin('buy b', 'b.entrance_id=e.id')
      ->leftJoin([$sql => 'b2'], 'b2.entrance_id=e.id')
      // ->leftJoin('tag t', 'FIND_IN_SET(t.id,e.tags)')
      ->group('e.id');



    if (!empty($company)) {
      $query->whereLike('e.company', "%{$company}%");
    }
    if (!empty($group_name)) {
      $query->whereLike('e.name', "%{$group_name}%");
    }
    if (!empty($type)) {
      $query->where('type', $type);
    }
    if (!empty($joinable_wc)) {
      $joinable_wc = $joinable_wc - 1;
      $query->where('joinable_wc', $joinable_wc);
    }
    if (!empty($status)) {
      $status = $status - 1;
      $query->where('status', $status);
    }

    $query->where('e.expire_date', '>=', $this->db->raw('NOW()'));
    $total = $query->count();
    $data = $query->order('e.id', 'DESC')
      //没到期的
      ->page($this->page, $this->pageSize)
      ->column("e.id,e.qrcode_id,e.name,e.avatar,e.expire_date,e.members,e.qr,e.im,e.hide,e.update_time,e.add_time,e.company,e.status,e.joinable_wc,e.error_msg,e.source,e.type,COUNT(DISTINCT b.id) buy_cnt,COUNT(DISTINCT b2.id) buy_cnt_month");

    foreach ($data as &$item) {
      $item['im'] = $this->request->domain(true) . '/storage/' . $item['im'];
    }
    return $this->successJson($this->paginate($data, $total));
  }

  public function itemDel(): Json
  {
    $entranceId = input('eid/d');
    if (empty($entranceId)) {
      return $this->errorJson(-99, '参数错误');
    }

    if ($this->db->name('buy')->where('entrance_id', $entranceId)->value('id')) {
      return $this->errorJson(1, '该群码无法删除，因为已有买家');
    }

    try {
      $info = $this->db->name('entrance')->where(['id' => $entranceId])->field('im,im2')->findOrFail();
      $dir = $this->app->getRootPath() . 'public/storage/';
      @unlink($dir . $info['im2']);
      if (!empty($info['im'])) {
        @unlink($dir . $info['im']);
      }
      $this->db->name('entrance')->where(['id' => $entranceId])->delete();
      return $this->successJson();
    } catch (Exception) {
      return $this->errorJson();
    }
  }

  public function itemHide(): Json
  {
    $entranceId = input('eid/d');
    $hide = input('hide/d');
    if (empty($entranceId)) {
      return $this->errorJson(-99, '参数错误');
    }
    if ($hide !== 0 && $hide !== 1) {
      $hide = 0;
    }

    try {
      $this->db->name('entrance')->where(['id' => $entranceId])->update(['hide' => $hide]);
      return $this->successJson();
    } catch (Exception) {
      return $this->errorJson();
    }
  }

  public function itemEdit(): Json
  {
    $entranceId = input('eid/d');
    $name = input('name');
    $members = input('members/d');
    $expire = input('expire');
    $company = input('company');
    $area = input('area/a') ?? [];
    $remark = input('remark');
    $price = input('price/d');

    $area = array_map(function ($item) {
      return $item === 'all' ? null : $item;
    }, $area);

    if (!$this->db->name('entrance')->where(['id' => $entranceId])->value('id')) {
      return $this->errorJson();
    }

    $defaultPrice = $this->getSysSetting('entrance_price');
    if ($price < $defaultPrice) {
      return $this->errorJson(-1, '价格不能低于' . $defaultPrice);
    }

    try {
      $this->db->name('entrance')
        ->where(['id' => $entranceId])
        ->update([
          'name'          => empty($name) ? null : $name,
          'members'       => empty($members) ? null : $members,
          'expire_date'   => empty($expire) ? null : $expire,
          'company'       => empty($company) ? null : $company,
          'remark'        => empty($remark) ? null : $remark,
          'price'         => $price,
          'province'      => $area[0] ?? null,
          'city'          => $area[1] ?? null,
          'district'      => $area[2] ?? null
        ]);
      return $this->successJson();
    } catch (Exception $e) {
      return $this->errorJson(-1, $e->getMessage());
    }
  }

  // 更改状态
  public function itemJoinablewc(): Json
  {
    $entranceId = input('post.eid/d');
    $joinable_wc = input('joinable_wc/d');
    $this->assertNotNull($entranceId, $joinable_wc);

    if (!in_array($joinable_wc, [0, 1, 2, 3])) {
      return $this->errorJson();
    }

    try {
      $this->db->name('entrance')->where('id', $entranceId)->update(['joinable_wc' => $joinable_wc]);
      return $this->successJson();
    } catch (Exception) {
      return $this->errorJson();
    }
  }

  // 购买记录
  public function buyList(): Json
  {
    $username = input('username');
    $date = input('date/a');

    $query = $this->db->name('buy')->alias('b')
      ->leftJoin('user u', 'u.id=b.user_id')
      ->leftJoin('entrance e', 'e.id=b.entrance_id');

    if (!empty($username)) {
      $query->where('u.username', $username);
    }
    if (!empty($date)) {
      $begin = addslashes($date[0]);
      $end = addslashes($date[1]);
      $query->whereRaw("DATE(b.add_time) >= FROM_UNIXTIME({$begin}) AND DATE(b.add_time) <= FROM_UNIXTIME({$end})");
    }

    $total = $query->count();
    $data = $query->page($this->page, $this->pageSize)->order('b.id', 'DESC')->column('b.id,b.price,b.add_time,u.username,e.name,e.company,e.remark,e.type,e.expire_date');

    return $this->successJson($this->paginate($data, $total));
  }
}
