<?php

namespace app\provider\controller;

use app\provider\CommonController;
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

    $query = Db::name('qrcode')->alias('q')
      ->leftJoin('tag t', 'FIND_IN_SET(t.id,q.tags)')
      ->where(['q.provider_id' => $this->provider['id']])
      ->group('q.id')
      ->page($this->page, $this->pageSize)
      ->fieldRaw("q.id,q.code,q.company,q.province,q.city,q.district,q.remark,q.status,q.sub_status,q.valid,q.valid_time,q.err_msg,GROUP_CONCAT(t.name) tags,q.add_time");

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

    $data = $query->select()->toArray();

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
        ->where('e.qrcode_id', $item['id'])
        ->where('e.expire_date', '>=', $this->db->raw('NOW()'))
        ->column("e.id,e.avatar,e.name,e.members,e.qr,e.expire_date,e.joinable,e.reported,e.add_time");
      $item['entrance'] = $subData;
    }

    $total = Db::name('qrcode')->where('provider_id', $this->provider['id'])->count();

    return $this->successJson($this->paginate($data, $total));
  }

  public function add(): Json
  {
    $qrcode = input('qrcode/a');
    $company = input('company');
    $tags = input('tags/a') ?? [];
    $area = input('area/a') ?? [];
    $remark = input('remark');
    $price = input('price/d') ?? 0;
    $limit = input('limit/d') ?? 0;
    $subStatus = input('sub_status/d') ?? 0;

    $data = [
      'qrcode' => $qrcode,
      'company' => $company,
      // 'tags' => $tags
    ];

    $rule = [
      'qrcode' => 'require',
      'company' => 'require',
      // 'tags' => 'require'
    ];

    $message = [
      'qrcode.require' => '活码必填',
      'company.require' => '公司必填',
      // 'tags.require' => '标签必填'
    ];

    try {
      $this->validate($data, $rule, $message);
    } catch (ValidateException $e) {
      return $this->errorJson(-100, $e->getMessage());
    }

    if (empty($area)) {
      $area = [null, null, null];
    }

    // if (Db::name('qrcode')->whereIn('code', $qrcode)->value('id')) {
    //   return $this->errorJson(3, '活码已存在');
    // }

    $area = array_map(function ($item) {
      return $item === 'all' ? null : $item;
    }, $area);

    // if ($price <= 0) {
    //   $price = $this->getSysSetting('entrance_price');
    // }
    // if ($limit <= 0) {
    //   $limit = $this->getSysSetting('max_buy_times');
    // }

    if (!in_array($subStatus, [0, 1, 2])) {
      $subStatus = 1;
    }

    // 标签去重
    $tags = array_unique($tags);
    $data['failed'] = 0;
    $data['success'] = 0;
    $qrcodeData = [];
    foreach ($qrcode as $qr) {
      $id = Db::name('qrcode')->where('code', $qr)->value('id');
      if (empty($id)) {
        $data['success']++;
        $qrcodeData[] = [
          'provider_id'   => $this->provider['id'],
          'code'          => $qr,
          'company'       => $company,
          'province'      => $area[0] ?? null,
          'city'          => $area[1] ?? null,
          'district'      => $area[2] ?? null,
          'remark'        => empty($remark) ? null : $remark,
          'tags'          => implode(',', $tags),
          'sub_status'    => $subStatus
        ];
      } else {
        $data['failed']++;
      }
    }

    try {
      // Db::name('provider')->where('id', $this->provider['id'])->inc('score', 30)->update();
      Db::name('qrcode')->insertAll($qrcodeData);
      return $this->successJson(null, '成功' . $data['success'] . '个,失败' . $data['failed'] . '个');
    } catch (Exception $e) {
      return $this->errorJson(2, $e->getMessage());
    }
  }

  public function del(): Json
  {
    $qrcodeId = input('qid');
    $this->assertNotEmpty($qrcodeId);

    $this->db->startTrans();
    try {
      $this->db->name('entrance')->where(['qrcode_id' => $qrcodeId, 'provider_id' => $this->provider['id']])
        ->update(['provider_id' => null]);
      $this->db->name('qrcode')->where(['id' => $qrcodeId, 'provider_id' => $this->provider['id']])
        ->update(['provider_id' => null]);
      $this->db->commit();
      return $this->successJson();
    } catch (Exception) {
      $this->db->rollback();
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
      $this->db->name('qrcode')->where(['id' => $qrcodeId, 'provider_id' => $this->provider['id']])->update([
        'company' => $company,
        'tags' => implode(',', $tags),
        'sub_status' => $subStatus,
        'province' => $area[0],
        'city' => $area[1],
        'district' => $area[2]
      ]);
      $this->db->name('entrance')->where(['qrcode_id' => $qrcodeId, 'provider_id' => $this->provider['id']])->update([
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
