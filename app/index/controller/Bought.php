<?php

declare(strict_types=1);

namespace app\index\controller;

use app\index\CommonController;
use JetBrains\PhpStorm\Pure;
use think\response\Json;
use ZipArchive;

class Bought extends CommonController
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
    $down_cnt = input('down_cnt/d');

    $buyRecordSql = $this->db->name('buy')->field('id,entrance_id,user_id,price,down_cnt,add_time')->buildSql();
    $buyCountSql = $this->db->name('buy')->group('entrance_id')->fieldRaw('entrance_id,COUNT(entrance_id) buy_cnt_month')->buildSql();

    $query = $this->db->name('entrance')->alias('e')
      ->leftJoin([$buyRecordSql => 'b2'], 'b2.entrance_id=e.id')
      ->leftJoin([$buyCountSql => 'b1'], 'b1.entrance_id=e.id')
      ->leftJoin('tag t', 'FIND_IN_SET(t.id,e.tags)')
      ->group('e.id');


    //已购买
    $query->where('e.expire_date', '>=', $this->db->raw('NOW()')); //没到期的
    $query->whereExists(function ($query) {
      $query->name('buy')->alias('b3')->whereColumn('b3.entrance_id', 'e.id')
        ->where('b3.user_id', $this->user['id'])->field('id');
    });
    $query->order('b2.id', 'DESC');

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
      $query->where('type', $type);
    }
    if (!empty($joinable)) {
      $query->where('joinable', $joinable);
    }

    if (!empty($down_cnt)) {
      if ($down_cnt == 1) {
        $query->where('b2.down_cnt', 0);
      } else {
        $query->where('b2.down_cnt', '>', 0);
      }
    }
    $total = $query->count();

    $data = $query->page($this->page, $this->pageSize)
      // COUNT(DISTINCT b2.id)
      ->order('e.add_time', 'DESC')
      ->column("e.id,e.company,e.province,e.city,e.district,e.remark,e.avatar,e.name,e.members,e.expire_date,e.joinable,b2.add_time buy_time,b2.price,b2.down_cnt,GROUP_CONCAT(DISTINCT t.name) tags,e.qr,e.im,e.im2,e.type,IFNULL(b1.buy_cnt_month,0) buy_cnt");
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
      if (!empty($item['im'])) {
        $item['im'] = $this->request->domain(true) . '/storage/' . $item['im'];
      }
      $item['im2'] = $this->request->domain(true) . '/storage/' . $item['im2'];

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
}
