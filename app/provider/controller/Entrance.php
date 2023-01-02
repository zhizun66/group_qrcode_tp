<?php

namespace app\provider\controller;

use app\provider\CommonController;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Exception;
use think\facade\Filesystem;
use think\response\Json;

class Entrance extends CommonController
{
  public function index(): Json
  {
    $company = input('company');
    $remark = input('remark');
    $area = input('area/a') ?? [];
    $tags = input('tags/a');

    $query = $this->db->name('entrance')->alias('e')
      ->leftJoin('buy b', 'b.entrance_id=e.id')
      ->leftJoin('tag t', 'FIND_IN_SET(t.id,e.tags)')
      ->group('e.id');

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

    $data = $query->where('provider_id', $this->provider['id'])
      ->order('e.id', 'DESC')
      ->page($this->page, $this->pageSize)
      ->column("e.id,e.qrcode_id,e.name,e.avatar,e.expire_date,e.members,IF(e.source=1,IFNULL(e.im,e.im2),NULL) im,e.hide,e.add_time,e.company,e.remark,e.province,e.city,e.district,GROUP_CONCAT(DISTINCT t.name) tags,e.type,COUNT(DISTINCT b.id) buy_cnt,e.status");

    foreach ($data as &$item) {
      $item['im'] = $item['im'] ? $this->request->domain(true) . '/storage/' . $item['im'] : null;
      $item['tags'] = empty($item['tags']) ? [] : explode(',', $item['tags']);

      $item['area'] = array_filter([$item['province'], $item['city'], $item['district']], function ($elem) {
        return !is_null($elem);
      });
      if (count($item['area']) === 0) {
        $item['area'] = null;
      } elseif (count($item['area']) < 3) {
        $item['area'][] = 'all';
      }

      unset($item['province']);
      unset($item['city']);
      unset($item['district']);
    }

    $total = $query->count();

    return $this->successJson($this->paginate($data, $total));
  }

  public function upload(): Json
  {
    $uid = input('uid');
    $tags = input('tags');
    $company = input('company');
    $area = input('area') ?? [];
    $name = input('name');
    $remark = input('remark');
    $members = input('members');
    $expire = input('expire');
    $price = input('price/d');
    $type = min(max(input('type/d', 1), 1), 2);
    $limit = input('limit/d');

    $area = empty($area) ? [] : explode(',', $area);
    $area = array_map(function ($item) {
      return $item === 'all' ? null : $item;
    }, $area);

    $tags = empty($tags) ? [] : explode(',', $tags);
    $tags = array_unique($tags);

    $uidArr = explode(',', $uid);

    $qrArr = array_map(function ($uid) {
      return input('decode_' . $uid);
    }, $uidArr);

    // 检测群码是否重复
    if (count(array_unique($qrArr)) < count($qrArr)) {
      return $this->errorJson(1, '包含重复群码');
    }

    // 检测群码是否存在
    if ($this->db->name('entrance')->whereIn('qr', $qrArr)->value('id')) {
      return $this->errorJson(2, '群码已存在');
    }

    if (empty($price)) {
      $price = $this->getSysSetting('entrance_price');
    }
    if (empty($limit)) {
      $limit = $this->getSysSetting('max_buy_times');
    }

    try {
      $entranceData = [];
      foreach ($uidArr as $uid) {
        $qr = input('decode_' . $uid, '');
        // 生成二维码
        $path = tempnam($this->app->getRootPath() . 'public/storage/gen', '') . '.png';
        $options = new QROptions(['scale' => 10]);
        (new QRcode($options))->render($qr, $path);

        $file = $this->request->file('qrcode_' . $uid);
        try {
          validate(['image' => 'fileSize:1024|fileExt:jpg,jpeg,png'])->check($file);
        } catch (Exception $e) {
          return $this->errorJson(-3, $e->getMessage());
        }



        $saveName = Filesystem::disk('public')->putFile('entrance', $file);

        $entranceData[] = [
          'provider_id'   => $this->provider['id'],
          'name'          => empty($name) ? null : $name,
          'members'       => empty($members) ? null : $members,
          'qr'            => $qr,
          'im'            => $saveName,
          'im2'           => 'gen/' . basename($path),
          'expire_date'   => empty($expire) ? null : $expire,
          'price'         => $price,
          'company'       => empty($company) ? null : $company,
          'province'      => $area[0] ?? null,
          'city'          => $area[1] ?? null,
          'district'      => $area[2] ?? null,
          'remark'        => empty($remark) ? null : $remark,
          'tags'          => implode(',', $tags),
          'type'          => $type,
          'limit'         => $limit
        ];
      }

      $this->db->name('entrance')->insertAll($entranceData);
      return $this->successJson();
    } catch (Exception $e) {
      return $this->errorJson(-1, $e->getMessage());
    }
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
      $this->db->name('entrance')->where(['id' => $entranceId, 'provider_id' => $this->provider['id']])->delete();
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
      $this->db->name('entrance')->where(['id' => $entranceId, 'provider_id' => $this->provider['id']])->update(['hide' => $hide]);
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

    $area = array_map(function ($item) {
      return $item === 'all' ? null : $item;
    }, $area);

    if (!$this->db->name('entrance')->where(['id' => $entranceId, 'provider_id' => $this->provider['id']])->value('id')) {
      return $this->errorJson();
    }

    try {
      $this->db->name('entrance')
        ->where(['id' => $entranceId, 'provider_id' => $this->provider['id']])
        ->update([
          'name'          => empty($name) ? null : $name,
          'members'       => empty($members) ? null : $members,
          'expire_date'   => empty($expire) ? null : $expire,
          'company'       => empty($company) ? null : $company,
          'remark'        => empty($remark) ? null : $remark,
          'province'      => $area[0] ?? null,
          'city'          => $area[1] ?? null,
          'district'      => $area[2] ?? null
        ]);
      return $this->successJson();
    } catch (Exception $e) {
      return $this->errorJson(-1, $e->getMessage());
    }
  }
}
