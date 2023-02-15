<?php

namespace app\provider\controller;

use app\provider\CommonController;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Exception;
use think\facade\Filesystem;
use think\response\Json;
use think\helper\Str;

class Entrance extends CommonController
{
  public function index(): Json
  {
    $company = input('company');
    $joinable_wc = input('joinable_wc/d');
    $expire = input('expire/d');
    $query = $this->db->name('entrance')->alias('e')
      // ->leftJoin('buy b', 'b.entrance_id=e.id')
      // ->leftJoin('tag t', 'FIND_IN_SET(t.id,e.tags)')
      ->group('e.id');

    if (!empty($company)) {
      $query->whereLike('e.company', "%{$company}%");
    }

    if (!empty($joinable_wc)) {
      $joinable_wc = $joinable_wc - 1;
      $query->where('joinable_wc', $joinable_wc);
    }
    if (!empty($expire)) {
      if ($expire == 1) {
        $query->where('e.expire_date', '>=', $this->db->raw('NOW()'));
      } elseif ($expire == 2) {
        $query->where('e.expire_date', '<', $this->db->raw('NOW()'));
      }
    }
    $total = $query->where('provider_id', $this->provider['id'])->count();
    $data = $query->where('provider_id', $this->provider['id'])
      ->order('e.id', 'DESC')
      ->page($this->page, $this->pageSize)
      ->column("e.id,e.qrcode_id,e.name,e.avatar,e.expire_date,e.members,e.im,e.hide,e.add_time,e.company,e.type,e.joinable_wc,e.error_msg"); //COUNT(DISTINCT b.id) buy_cnt

    foreach ($data as &$item) {
      $item['im'] = $item['im'] ? $this->request->domain(true) . '/storage/' . $item['im'] : null;
    }



    return $this->successJson($this->paginate($data, $total));
  }

  public function upload(): Json
  {

    $company = input('company');
    $type = min(max(input('type/d', 1), 1), 2);
    $qrArr = input('decode/a') ?? [];

    // 检测群码是否重复
    if (count(array_unique($qrArr)) < count($qrArr)) {
      return $this->errorJson(1, '包含重复群码');
    }

    // 检测群码是否存在
    // if ($this->db->name('entrance')->whereIn('qr', $qrArr)->value('id')) {
    //   return $this->errorJson(2, '群码已存在');
    // }

    // if (empty($price)) {
    //   $price = $this->getSysSetting('entrance_price');
    // }
    // if (empty($limit)) {
    //   $limit = $this->getSysSetting('max_buy_times');
    // }

    try {
      $data['failed'] = 0;
      $data['success'] = 0;
      $entranceData = [];
      $qrcodeData = [];
      foreach ($qrArr as $qr) {
        // var_dump($qr);
        // 生成二维码
        if (Str::contains($qr, 'c.weixin.com/g')) {

          $path = $this->app->getRootPath() . 'public/storage/gen/' . Str::substr($qr, 23) . '.png';
          $options = new QROptions(['scale' => 10, 'imageTransparent' => false]);
          (new QRcode($options))->render($qr, $path);

          $id = $this->db->name('entrance')->where('qr', $qr)->value('id');
          if (empty($id)) {
            $data['success']++;
            $entranceData[] = [
              'provider_id'   => $this->provider['id'],
              'qr'            => $qr,
              'im'           => 'gen/' . basename($path),
              'expire_date'   => date('Y-m-d H:i:s', time() + 604800),
              'company'       => empty($company) ? null : $company,
              'type'          => $type,
            ];
          } else {
            $data['failed']++;
          }
        } elseif (Str::contains($qr, 'work.weixin.qq.com/gm')) {

          $id = $this->db->name('qrcode')->where('code', $qr)->value('id');
          if (empty($id)) {
            $data['success']++;
            $qrcodeData[] = [
              'provider_id'   => $this->provider['id'],
              'code'          => $qr,
              'company'       => $company,
            ];
          } else {
            $data['failed']++;
          }
        }
      }
      $this->db->name('entrance')->insertAll($entranceData);
      $this->db->name('qrcode')->insertAll($qrcodeData);
      return $this->successJson(null, '成功' . $data['success'] . '个,失败' . $data['failed'] . '个');
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
    // $name = input('name');
    // $members = input('members/d');
    // $expire = input('expire');
    $company = input('company');
    // $area = input('area/a') ?? [];
    // $remark = input('remark');

    // $area = array_map(function ($item) {
    //   return $item === 'all' ? null : $item;
    // }, $area);

    if (!$this->db->name('entrance')->where(['id' => $entranceId, 'provider_id' => $this->provider['id']])->value('id')) {
      return $this->errorJson();
    }

    try {
      $this->db->name('entrance')
        ->where(['id' => $entranceId, 'provider_id' => $this->provider['id']])
        ->update([
          // 'name'          => empty($name) ? null : $name,
          // 'members'       => empty($members) ? null : $members,
          // 'expire_date'   => empty($expire) ? null : $expire,
          'company'       => empty($company) ? null : $company,
          // 'remark'        => empty($remark) ? null : $remark,
          // 'province'      => $area[0] ?? null,
          // 'city'          => $area[1] ?? null,
          // 'district'      => $area[2] ?? null
        ]);
      return $this->successJson();
    } catch (Exception $e) {
      return $this->errorJson(-1, $e->getMessage());
    }
  }
}
