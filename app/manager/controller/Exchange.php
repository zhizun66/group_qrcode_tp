<?php

namespace app\manager\controller;

use app\manager\CommonController;
use Exception;
use think\response\Json;

class Exchange extends CommonController
{
  public function exchange(): Json
  {
    $username = input('username');
    $quantity = input('quantity/d');

    if (empty($username) || empty($quantity)) {
      return $this->errorJson();
    }

    if ($username === $this->manager['username']) {
      return $this->errorJson(5, '不能给自己转赠群码');
    }

    $toManagerId = $this->db->name('manager')->where('username', $username)->value('id');
    if (empty($toManagerId)) {
      return $this->errorJson(2, '用户名不存在');
    }

    $arrFrom = $this->db->name('entrance')
      ->whereIn('provider_id', function ($query) {
        $query->name('provider')->where('manager_id', $this->manager['id'])->field('id');
      })
      ->where('expire_date', '>=', $this->db->raw('NOW()'))
      ->whereNotIn('id', function ($query) use ($toManagerId) {
        $query->name('exchange')->where(['from_manager_id' => $this->manager['id'], 'to_manager_id' => $toManagerId])->field('entrance_id');
      })
      ->order('id', 'desc')
      ->limit(0, $quantity)
      ->column('id');
    var_dump(($arrFrom));
    if ($quantity > count($arrFrom)) {
      return $this->errorJson(1, '我方群码数量不足');
    }

    $arrTo = $this->db->name('entrance')
      ->whereIn('provider_id', function ($query) use ($toManagerId) {
        $query->name('provider')->where('manager_id', $toManagerId)->field('id');
      })
      ->where('expire_date', '>=', $this->db->raw('NOW()'))
      ->whereNotIn('id', function ($query) use ($toManagerId) {
        $query->name('exchange')->where(['to_manager_id' => $this->manager['id'], 'from_manager_id' => $toManagerId])->field('entrance_id');
      })
      ->order('id', 'desc')
      ->limit(0, $quantity)
      ->column('id');
    if ($quantity > count($arrTo)) {
      return $this->errorJson(1, '对方群码数量不足');
    }

    $this->db->startTrans();
    try {
      for ($i = 0; $i < $quantity; $i++) {
        if (!empty($this->db->name('exchange')->where(['to_manager_id' => $toManagerId, 'entrance_id' => $arrFrom[$i]])->value('id'))) {
          $this->db->rollback();
          return $this->errorJson(3, '这些群码已经交换过');
        }
        $this->db->name('exchange')->insert([
          'from_manager_id' => $this->manager['id'],
          'to_manager_id' => $toManagerId,
          'entrance_id' => $arrFrom[$i],
        ]);
        // $this->db->name('exchange')->insert([
        //   'from_manager_id' => $toManagerId,
        //   'to_manager_id' => $this->manager['id'],
        //   'entrance_id' => $arrTo[$i],
        // ]);
      }
      $this->db->commit();
      return $this->successJson();
    } catch (Exception $e) {
      return $this->errorJson(4, $e->getMessage());
    }
  }
  public function exchange1(): Json
  {
    $username = input('username');
    $quantity = input('quantity/d');

    if (empty($username) || empty($quantity)) {
      return $this->errorJson();
    }

    if ($username === $this->manager['username']) {
      return $this->errorJson(5, '不能给自己转赠群码');
    }

    $toManagerId = $this->db->name('manager')->where('username', $username)->value('id');
    if (empty($toManagerId)) {
      return $this->errorJson(2, '用户名不存在');
    }

    $arrFrom = $this->db->name('entrance')
      ->whereIn('provider_id', function ($query) {
        $query->name('provider')->where('manager_id', $this->manager['id'])->field('id');
      })
      ->whereNotIn('id', function ($query) use ($toManagerId) {
        $query->name('exchange')->where(['from_manager_id' => $this->manager['id'], 'to_manager_id' => $toManagerId])->field('entrance_id');
      })
      ->order('id', 'desc')
      ->limit(0, $quantity)
      ->column('id');
    if ($quantity > count($arrFrom)) {
      return $this->errorJson(1, '我方群码数量不足');
    }

    $arrTo = $this->db->name('entrance')
      ->whereIn('provider_id', function ($query) use ($toManagerId) {
        $query->name('provider')->where('manager_id', $toManagerId)->field('id');
      })
      ->whereNotIn('id', function ($query) use ($toManagerId) {
        $query->name('exchange')->where(['to_manager_id' => $this->manager['id'], 'from_manager_id' => $toManagerId])->field('entrance_id');
      })
      ->order('id', 'desc')
      ->limit(0, $quantity)
      ->column('id');
    if ($quantity > count($arrTo)) {
      return $this->errorJson(1, '对方群码数量不足');
    }

    $this->db->startTrans();
    try {
      for ($i = 0; $i < $quantity; $i++) {
        if (!empty($this->db->name('exchange')->where(['to_manager_id' => $toManagerId, 'entrance_id' => $arrFrom[$i]])->value('id'))) {
          $this->db->rollback();
          return $this->errorJson(3, '这些群码已经交换过');
        }
        $this->db->name('exchange')->insert([
          'from_manager_id' => $this->manager['id'],
          'to_manager_id' => $toManagerId,
          'entrance_id' => $arrFrom[$i],
        ]);
        $this->db->name('exchange')->insert([
          'from_manager_id' => $toManagerId,
          'to_manager_id' => $this->manager['id'],
          'entrance_id' => $arrTo[$i],
        ]);
      }
      $this->db->commit();
      return $this->successJson();
    } catch (Exception $e) {
      return $this->errorJson(4, $e->getMessage());
    }
  }
  public function index(): Json
  {
    $company = input('company');
    // $remark = input('remark');
    // $area = input('area/a') ?? [];
    // $tags = input('tags/a');
    $username = input('username');

    $query = $this->db->name('exchange')->alias('ex')
      ->leftJoin('entrance e', 'e.id=ex.entrance_id')
      ->leftJoin('manager m', 'm.id=ex.from_manager_id')
      ->leftJoin('buy b', 'b.entrance_id=e.id')
      // ->leftJoin('tag t', 'FIND_IN_SET(t.id,e.tags)')
      ->group('e.id');



    if (!empty($company)) {
      $query->whereLike('e.company', "%{$company}%");
    }

    if (!empty($username)) {
      $providerId = $this->db->name('provider')->where('username', $username)->value('id');
      $query->where('provider_id', $providerId);
    }

    $data = $query
      ->where('ex.to_manager_id', $this->manager['id'])
      ->order('e.id', 'DESC')
      ->page($this->page, $this->pageSize)
      ->column("e.id,e.qrcode_id,e.name,e.avatar,e.expire_date,e.members,e.im,e.hide,e.add_time,e.company,e.type,COUNT(DISTINCT b.id) buy_cnt,e.status,MAX(m.username) from_username");

    foreach ($data as &$item) {
      $item['im'] = $item['im'] ? $this->request->domain(true) . '/storage/' . $item['im'] : null;
    }

    $total = $query->count();

    return $this->successJson($this->paginate($data, $total));
  }
}
