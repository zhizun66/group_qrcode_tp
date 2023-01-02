<?php

declare(strict_types=1);

namespace app\index\controller;

use app\index\CommonController;
use JetBrains\PhpStorm\Pure;
use think\response\Json;
use ZipArchive;
use Exception;

class Mall extends CommonController
{

  public function index(): Json
  {

    $company = input('company');
    $remark = input('remark');
    $group_name = input('group_name');
    $area = input('area/a') ?? [];
    $tags = input('tags/a');
    $type = input('type/d');
    $joinable = input('joinable/d');

    $buyRecordSql = $this->db->name('buy')->field('id,entrance_id,user_id,price')->buildSql();
    $buyCountSql = $this->db->name('buy')->buildSql();

    $query = $this->db->name('entrance')->alias('e')
      ->leftJoin([$buyRecordSql => 'b2'], 'b2.entrance_id=e.id')
      ->leftJoin([$buyCountSql => 'b1'], 'b1.entrance_id=e.id')
      ->leftJoin('tag t', 'FIND_IN_SET(t.id,e.tags)')
      ->group('e.id');


    // 可购买
    // $query->where('e.user_id', '<>', $this->user['id']); //不看自己活码解析出来的
    $query->where('e.hide', 0); //上架的
    $query->where('e.expire_date', '>=', $this->db->raw('NOW()')); //没到期的
    $query->whereNotExists(function ($query) {
      $query->name('buy')->alias('b3')->whereColumn('b3.entrance_id', 'e.id')
        ->where('b3.user_id', $this->user['id'])->field('id');
    });
    //$query->having("COUNT(DISTINCT b2.id) < distinct e.limit");
    // $query->whereRaw('e.limit > IFNULL(b1.buy_cnt_month,0)');


    if (!empty($area)) {
      array_walk($area, function (&$elem) {
        if ($elem === 'all') {
          $elem = null;
        }
      });
      if (!empty($area[0])) {
        $query->where('e.province', $area[0]);
      }
      if (!empty($area[1])) {
        $query->where('e.city', $area[1]);
      }
      if (!empty($area[2])) {
        $query->where('e.district', $area[2]);
      }
    }

    if (!empty($company)) {
      $query->whereLike('e.company', "%{$company}%");
    }
    if (!empty($remark)) {
      $query->whereLike('e.remark', "%{$company}%");
    }
    if (!empty($group_name)) {
      $query->whereLike('e.name', "%{$group_name}%");
    }
    if (!empty($tags)) {
      $where = array_reduce($tags, function ($carry, $tag) {
        return $carry . "FIND_IN_SET({$tag},e.tags) AND ";
      }, '');
      $where = rtrim($where, ' AND');
      $query->whereRaw($where);
    }
    if (!empty($type)) {
      $query->where('e.type', $type);
    }
    if (!empty($joinable)) {

      $query->where('e.joinable', $joinable);
    }

    // $query->where('e.user_id', '<>', 2);
    $query->whereIn('e.user_id', [1, 30, 29]);
    $total = $query->count();
    // 查询未验证群码的价格
    $price_entrance_status_0 = $this->getSysSetting('entrance_status_0');
    // 查询验证企微可进的价格
    $price_entrance_work_joinable = $this->getSysSetting('entrance_work_joinable');

    $data = $query->page($this->page, $this->pageSize)
      // COUNT(DISTINCT b2.id)
      ->order('e.update_time', 'DESC')

      ->column("e.id,e.company,e.province,e.city,e.district,e.remark,e.avatar,e.name,e.members,e.status,e.expire_date,e.joinable,e.update_time,e.add_time,GROUP_CONCAT(DISTINCT t.name) tags,GROUP_CONCAT(DISTINCT t.id) tags_id,e.qr,e.im,e.im2,e.type");
    foreach ($data as &$item) {
      $item['tags'] = empty($item['tags']) ? [] : explode(',', $item['tags']);


      $price_joinable = $item['joinable'] == 1 ? $price_entrance_work_joinable : 0;
      $item['price'] =  $item['status'] == 1 ? $price_joinable + $this->getTagsPrice($item['tags_id']) : $price_entrance_status_0;


      $item['area'] = array_filter([$item['province'], $item['city'], $item['district']], function ($elem) {
        return !is_null($elem);
      });
      if (count($item['area']) === 0) {
        $item['area'] = null;
      } elseif (count($item['area']) < 3) {
        $item['area'][] = 'all';
      }


      // if ($item['bought'] === 1) {
      //   if (!empty($item['im'])) {
      //     $item['im'] = $this->request->domain(true) . '/storage/' . $item['im'];
      //   }
      //   $item['im2'] = $this->request->domain(true) . '/storage/' . $item['im2'];
      // } else {
      unset($item['im'], $item['im2'], $item['qr']);
      // }

      unset($item['province'], $item['city'], $item['district']);
    }

    return $this->successJson($this->paginate($data, $total));
  }
  public function index1(): Json
  {
    $bought = input('bought/d', 0);
    $company = input('company');
    $remark = input('remark');
    $area = input('area/a') ?? [];
    $tags = input('tags/a');
    $type = input('type/d');
    $status = input('status/d');

    $buyRecordSql = $this->db->name('buy')->whereRaw('DATE(add_time) >= DATE_SUB(CURDATE(),INTERVAL 1 MONTH)')->field('id,entrance_id,user_id,price')->buildSql();
    $buyCountSql = $this->db->name('buy')->whereRaw('DATE(add_time) >= DATE_SUB(CURDATE(),INTERVAL 1 MONTH)')->group('entrance_id')->fieldRaw('entrance_id,COUNT(entrance_id) buy_cnt_month')->buildSql();

    $query = $this->db->name('entrance')->alias('e')
      ->leftJoin([$buyRecordSql => 'b2'], 'b2.entrance_id=e.id')
      ->leftJoin([$buyCountSql => 'b1'], 'b1.entrance_id=e.id')
      ->leftJoin('tag t', 'FIND_IN_SET(t.id,e.tags)')
      ->group('e.id');

    // if ($status === 2) {
    //   // 仅个微可进
    //   $query->where('e.status', 2);
    // } else {

    //   $query->where('e.status', 1);
    // }

    if ($bought === 0) {
      // 可购买
      $query->where('e.user_id', '<>', $this->user['id']); //不看自己活码解析出来的
      $query->where('e.hide', 0); //上架的
      $query->where('e.expire_date', '>=', $this->db->raw('NOW()')); //没到期的
      $query->whereNotExists(function ($query) {
        $query->name('buy')->alias('b3')->whereColumn('b3.entrance_id', 'e.id')
          ->where('b3.user_id', $this->user['id'])->field('id');
      });
      //$query->having("COUNT(DISTINCT b2.id) < distinct e.limit");
      // $query->whereRaw('e.limit > IFNULL(b1.buy_cnt_month,0)');
    } else {
      $query->whereExists(function ($query) {
        $query->name('buy')->alias('b3')->whereColumn('b3.entrance_id', 'e.id')
          ->where('b3.user_id', $this->user['id'])->field('id');
      });
      // $query->order('b2.id', 'DESC');

    }

    if (!empty($area)) {
      array_walk($area, function (&$elem) {
        if ($elem === 'all') {
          $elem = null;
        }
      });
      if (!empty($area[0])) {
        $query->where('e.province', $area[0]);
      }
      if (!empty($area[1])) {
        $query->where('e.city', $area[1]);
      }
      if (!empty($area[2])) {
        $query->where('e.district', $area[2]);
      }
    }

    if (!empty($company)) {
      $query->whereLike('e.company', "%{$company}%");
    }
    if (!empty($remark)) {
      $query->whereLike('e.remark', "%{$company}%");
    }
    if (!empty($tags)) {
      $where = array_reduce($tags, function ($carry, $tag) {
        return $carry . "FIND_IN_SET({$tag},e.tags) AND ";
      }, '');
      $where = rtrim($where, ' AND');
      $query->whereRaw($where);
    }
    if (!empty($type)) {
      $query->where('type', $type);
    }

    $total = $query->count();

    $data = $query->page($this->page, $this->pageSize)

      // COUNT(DISTINCT b2.id)
      ->column("e.id,e.company,e.province,e.city,e.district,e.remark,e.avatar,e.name,e.members,e.expire_date,b2.price,CONVERT(IF(b2.user_id,'1','0'),UNSIGNED) 'bought',GROUP_CONCAT(DISTINCT t.name) tags,e.qr,e.im,e.im2,e.type,IFNULL(b1.buy_cnt_month,0) buy_cnt");
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

      if ($item['bought'] === 1) {
        if (!empty($item['im'])) {
          $item['im'] = $this->request->domain(true) . '/storage/' . $item['im'];
        }
        $item['im2'] = $this->request->domain(true) . '/storage/' . $item['im2'];
      } else {
        unset($item['im'], $item['im2'], $item['qr']);
      }

      unset($item['province'], $item['city'], $item['district']);
    }

    return $this->successJson($this->paginate($data, $total));
  }

  public function download()
  {
    $str = input('eids/s', '');
    $entranceIdArr = explode(',', $str);
    if (empty($entranceIdArr)) {
      return '参数错误';
    }

    $data = $this->db->name('entrance')->alias('e')
      ->whereIn('e.id', $entranceIdArr)
      ->whereExists(function ($query) {
        $query->name('buy')->where('user_id', $this->user['id'])->whereIn('entrance_id', 'e.id');
      })
      ->column('e.id,e.im2');

    if (empty($data)) {
      return '下载错误';
    } else {
      $this->db->name('buy')->whereIn('entrance_id', $entranceIdArr)->inc('down_cnt')->update();
    }

    $zip = new ZipArchive();
    $tempPath = tempnam(sys_get_temp_dir(), 'zip_') . '.zip';
    $zip->open($tempPath, ZipArchive::CREATE);
    foreach ($data as $item) {
      $path = $this->app->getRootPath() . 'public/storage/' . $item['im2'];
      $ext = pathinfo($path, PATHINFO_EXTENSION);
      $entry = $item['id'] . '.' . $ext;
      $zip->addFile($path, $entry);
    }
    $zip->close();

    $downName = date('YmdHis_') . count($data) . '.zip';

    header('content-type: application/zip');
    header('accept-ranges: bytes');
    header('accept-length: ' . filesize($tempPath));
    header('content-disposition: attachment; filename=' . $downName);

    $bin = file_get_contents($tempPath);
    @unlink($tempPath);
    echo $bin;
    exit;
  }



  public function buy(): Json
  {
    $entranceIdArr = input('eids/a');
    if (empty($entranceIdArr)) {
      $this->errorJson(-2, '参数错误');
    }
    if ($this->db->name('buy')->whereIn('entrance_id', $entranceIdArr)->where('user_id', $this->user['id'])->value('id')) {
      return $this->errorJson(2, '包含已购买的码');
    }
    // 查询活未验证群码的价格
    $price_entrance_status_0 = $this->getSysSetting('entrance_status_0');
    // 查询活未验证群码的价格
    $price_entrance_work_joinable = $this->getSysSetting('entrance_work_joinable');

    $this->db->startTrans();
    try {
      $totalPrice = 0;
      foreach ($entranceIdArr as $item) {
        // var_dump('群码id' . $item);
        $info = $this->db->name('entrance')->alias('e')

          ->where('e.id', $item)
          ->field('e.id,e.status,e.joinable,e.tags')->findOrEmpty();
        // var_dump('joinable' . $info['joinable']);

        $price_joinable = $info['joinable'] == 1 ? $price_entrance_work_joinable : 0;
        $price =  $info['status'] == 1 ? $price_joinable + $this->getTagsPrice($info['tags']) : $price_entrance_status_0;
        // var_dump('价格' . $price);

        $this->db->name('buy')->insert([
          'entrance_id'     => $info['id'],
          'user_id'     => $this->user['id'],
          'price'     =>  $price,
        ]);
        $totalPrice = $totalPrice + $price;
      }
      // var_dump('总价格' . $totalPrice);
      //统计批量购买的群码费用
      // $totalPrice = $price; //count($entranceIdArr) * intval($price); //Db::name('entrance')->whereIn('id', $entranceIdArr)->sum('price');

      $info = $this->db->name('user')->where('id', $this->user['id'])->field('balance,invite_user_id')->findOrEmpty();
      if ($totalPrice > $info['balance']) {
        return $this->errorJson(1, '金币不足');
      }



      // 查询佣金比例
      $rate_percentage = $this->getSysSetting('commission_level_1');
      $rate = $rate_percentage / 100;

      // 查询邀请人是否存在
      $inviteUserId = empty($info['invite_user_id']) ? null : $this->db->name('user')->where('id', $info['invite_user_id'])->value('id');

      // // 查询合作商需要加的积分
      // $serviceCharge = $this->getSysSetting('service_charge') / 100;
      // $providerIncomeData = $this->db->name('entrance')->whereIn('id', $entranceIdArr)->whereNotNull('provider_id')
      //   ->group('provider_id')->column("provider_id,SUM(FLOOR({$price}*{$serviceCharge})) summary");
      $this->db->name('user')->where('id', $this->user['id'])->dec('balance', $totalPrice)->update();
      if ($inviteUserId) {
        // 邀请人加佣金
        $commission = intval($totalPrice * $rate);
        $this->db->name('commission')->insert([
          'buy_user_id' => $this->user['id'],
          'invite_user_id' => $inviteUserId,
          'pay' => $totalPrice,
          'rate' => $rate_percentage,
          'commission' => $commission
        ]);
        $this->db->name('user')->where('id', $inviteUserId)->where('enable', 1)->inc('score', $commission)->update();
      }
      // // 合作商加积分
      // foreach ($providerIncomeData as $income) {
      //   $this->db->name('provider')->where('id', $income['provider_id'])->where('enable', 1)
      //     ->inc('score', intval($income['summary']))->update();
      // }
      $this->db->commit();
      return $this->successJson();
    } catch (\Exception $e) {
      $this->db->rollback();
      return $this->errorJson(-1, $e->getMessage());
    }
  }
}
