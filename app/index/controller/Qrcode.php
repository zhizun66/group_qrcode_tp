<?php

declare(strict_types=1);

namespace app\index\controller;

use app\index\CommonController;
use Exception;
use think\exception\ValidateException;
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
    $status = input('status/d');


    $query = $this->db->name('qrcode_ref')->alias('ref')
      ->leftJoin('qrcode q', 'q.id=ref.qrcode_id')
      ->leftJoin('tag t', 'FIND_IN_SET(t.id,q.tags)')
      ->where('ref.user_id', $this->user['id'])
      ->order('ref.id', 'DESC')
      ->group('ref.id')

      ->fieldRaw("ref.id,q.id qrcode_id,q.code,q.company,q.province,q.city,q.district,q.remark,q.status,q.sub_status,q.err_msg,GROUP_CONCAT(t.name) tags,q.add_time");

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
    // 查询活码解析服务费
    $price = $this->getSysSetting('qrcode_decode_price');
    $data = $query->page($this->page, $this->pageSize)->select()->toArray();

    foreach ($data as &$item) {
      $item['tags'] = empty($item['tags']) ? [] : explode(',', $item['tags']);

      $item['area'] = array_filter([$item['province'], $item['city'], $item['district']], function ($elem) {
        return !is_null($elem);
      });
      if (count($item['area']) === 0) {
        $item['area'] = null;
      } elseif (count($item['area']) < 3) {
        $item['area'][] = 'all';
      }

      $subData = Db::name('entrance')->alias('e')
        ->leftJoin('buy b', 'b.entrance_id=e.id')
        ->where('e.qrcode_id', $item['qrcode_id'])
        ->column("e.id,e.avatar,e.name,e.members,e.qr,{$price} price,e.expire_date,e.joinable,e.status,e.reported,e.add_time,CONVERT(IF(b.user_id = {$this->user['id']},'1','0'),UNSIGNED) 'bought'");
      $item['entrance'] = $subData;
      // $item['price'] = $price;
    }



    return $this->successJson($this->paginate($data, $total));
  }

  public function add(): Json
  {
    $qrcode = input('qrcode/a');
    $company = input('company');
    $tags = input('tags/a') ?? [];
    $area = input('area/a') ?? [];
    $remark = input('remark');
    $subStatus = input('sub_status/d') ?? 0;

    $data = [
      'qrcode' => $qrcode,
      // 'company' => $company,
      'tags' => $tags
    ];

    $rule = [
      'qrcode' => 'require',
      // 'company' => 'require',
      'tags' => 'require'
    ];

    $message = [
      'qrcode.require' => '活码必填',
      // 'company.require' => '公司必填',
      'tags.require' => '标签必填'
    ];

    try {
      $this->validate($data, $rule, $message);
    } catch (ValidateException $e) {
      return $this->errorJson(-100, $e->getMessage());
    }

    if (empty($area)) {
      $area = [null, null, null];
    }

    $area = array_map(function ($item) {
      return $item === 'all' ? null : $item;
    }, $area);

    if (!in_array($subStatus, [0, 1, 2])) {
      $subStatus = 0;
    }

    // 标签去重
    $tags = array_unique($tags);
    $price = $this->getSysSetting('entrance_price');
    $this->db->startTrans();
    try {
      $ignoredCount = $succeedCount = 0;
      foreach ($qrcode as $qr) {
        $qrcodeId = $this->db->name('qrcode')->where('code', $qr)->value('id');
        if (empty($qrcodeId)) {
          $qrcodeId = $this->db->name('qrcode')->insertGetId([
            'user_id' => $this->user['id'],
            'code' => $qr,
            'company' => $company,
            'province' => $area[0] ?? null,
            'city' => $area[1] ?? null,
            'district' => $area[2] ?? null,
            'remark' => empty($remark) ? null : $remark,
            'tags' => implode(',', $tags),
            'sub_status' => $subStatus,
          ]);
        }
        if (!$this->db->name('qrcode_ref')->where(['qrcode_id' => $qrcodeId, 'user_id' => $this->user['id']])->value('id')) {
          $this->db->name('qrcode_ref')->insert([
            'qrcode_id' => $qrcodeId,
            'user_id' => $this->user['id']
          ]);
          ++$succeedCount;
        } else {
          ++$ignoredCount;
        }
      }
      $this->db->commit();
      return $this->successJson(['succeed' => $succeedCount, 'ignored' => $ignoredCount]);
    } catch (Exception $e) {
      $this->db->rollback();
      return $this->errorJson(2, $e->getMessage());
    }
  }

  public function del(): Json
  {
    $refId = input('id/d');
    $this->assertNotEmpty($refId);

    try {
      $this->db->name('qrcode_ref')->where(['id' => $refId, 'user_id' => $this->user['id']])->delete();
      return $this->successJson();
    } catch (Exception) {
      return $this->errorJson();
    }
  }

  public function report(): Json
  {
    $id = input('post.id');

    try {
      Db::name('entrance')->where(['id' => $id, 'user_id' => $this->user['id'], 'reported' => 0])->update(['reported' => 1]);
      return $this->successJson();
    } catch (Exception) {
      return $this->errorJson();
    }
  }

  public function buy(): Json
  {
    $entranceIdArr = input('eids/a');

    if (empty($entranceIdArr)) {
      $this->errorJson(-2, '参数错误');
    }
    // 查询活码解析服务费
    $price = $this->getSysSetting('qrcode_decode_price');
    //统计批量购买的群码费用
    $totalPrice = count($entranceIdArr) * intval($price); //Db::name('entrance')->whereIn('id', $entranceIdArr)->sum('price');

    $info = Db::name('user')->where('id', $this->user['id'])->field('balance,invite_user_id')->findOrEmpty();
    if ($totalPrice > $info['balance']) {
      return $this->errorJson(1, '金币不足');
    }

    if (Db::name('buy')->whereIn('entrance_id', $entranceIdArr)->where('user_id', $this->user['id'])->value('id')) {
      return $this->errorJson(2, '包含已购买的码');
    }

    // 查询佣金比例
    $rate_percentage = $this->getSysSetting('commission_level_1');
    $rate = $rate_percentage / 100;

    // 查询邀请人是否存在
    $inviteUserId = empty($info['invite_user_id']) ? null : Db::name('user')->where('id', $info['invite_user_id'])->value('id');

    $data = Db::name('entrance')->whereIn('id', $entranceIdArr)->column("id entrance_id,{$price} price,{$this->user['id']} 'user_id'");

    // 查询合作商需要加的积分
    // $serviceCharge = $this->getSysSetting('service_charge') / 100;
    // $providerIncomeData = $this->db->name('entrance')->whereIn('id', $entranceIdArr)->whereNotNull('provider_id')
    //   ->group('provider_id')->column("provider_id,SUM(FLOOR({$price}*{$serviceCharge})) summary");

    Db::startTrans();
    try {
      Db::name('buy')->insertAll($data);
      Db::name('user')->where('id', $this->user['id'])->dec('balance', $totalPrice)->update();
      // if ($inviteUserId) {
      //   // 邀请人加佣金
      //   $commission = intval($totalPrice * $rate);
      //   Db::name('commission')->insert([
      //     'buy_user_id' => $this->user['id'],
      //     'invite_user_id' => $inviteUserId,
      //     'pay' => $totalPrice,
      //     'rate' => $rate_percentage,
      //     'commission' => $commission
      //   ]);
      //   Db::name('user')->where('id', $inviteUserId)->where('enable', 1)->inc('score', $commission)->update();
      // }
      // // 合作商加积分
      // foreach ($providerIncomeData as $income) {
      //   $this->db->name('provider')->where('id', $income['provider_id'])->where('enable', 1)
      //     ->inc('score', intval($income['summary']))->update();
      // }
      Db::commit();
      return $this->successJson();
    } catch (Exception) {
      Db::rollback();
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

    if (empty($this->db->name('qrcode')->where(['id' => $qrcodeId, 'user_id' => $this->user['id']])->value('id'))) {
      return $this->errorJson(2, '该活码为引用，无法修改');
    }

    $this->db->startTrans();
    try {
      $this->db->name('qrcode')->where(['id' => $qrcodeId, 'user_id' => $this->user['id']])->update([
        'company' => $company,
        'tags' => implode(',', $tags),
        'sub_status' => $subStatus,
        'province' => $area[0],
        'city' => $area[1],
        'district' => $area[2]
      ]);
      $this->db->name('entrance')->where(['qrcode_id' => $qrcodeId, 'user_id' => $this->user['id']])->update([
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

  /*public function foo()
    {
        $a = [1];
        halt(array_replace($a, [1 => null]));
    }*/
}
