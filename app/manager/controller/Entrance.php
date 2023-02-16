<?php

namespace app\manager\controller;

use app\manager\CommonController;
use think\response\Json;
use ZipArchive;

class Entrance extends CommonController
{
  public function index(): Json
  {
    $company = input('company');
    $username = input('username');

    $query = $this->db->name('entrance')->alias('e');
    // ->leftJoin('buy b', 'b.entrance_id=e.id')
    // ->group('e.id');


    if (!empty($company)) {
      $query->whereLike('e.company', "%{$company}%");
    }

    if (!empty($username)) {
      $providerId = $this->db->name('provider')->where('username', $username)->value('id');
      $query->where('provider_id', $providerId);
    }

    $data = $query->whereIn('provider_id', function ($q) {
      $q->name('provider')->where('manager_id', $this->manager['id'])->field('id');
    })
      ->where('e.expire_date', '>=', $this->db->raw('NOW()'))
      ->order('e.id', 'DESC')
      ->page($this->page, $this->pageSize)
      ->column("e.id,e.qrcode_id,e.name,e.avatar,e.expire_date,e.members,e.im,e.hide,e.add_time,e.company,e.type,e.joinable_wc");

    foreach ($data as &$item) {
      $item['im'] = $item['im'] ? $this->request->domain(true) . '/storage/' . $item['im'] : null;
    }

    $total = $query->count();

    return $this->successJson($this->paginate($data, $total));
  }

  public function download(): string
  {
    $s = input('ids/s', '');
    if (empty($s)) {
      return '请选择下载项';
    }
    $idArr = explode(',', $s);

    $data = $this->db->name('entrance')
      ->whereIn('id', $idArr)
      ->whereIn('provider_id', function ($q) {
        $q->name('provider')->where('manager_id', $this->manager['id'])->field('id');
      })
      ->column('id,im');
    if (empty($data)) {
      return '无可下载项';
    }

    $zip = new ZipArchive();
    $tempPath = tempnam(sys_get_temp_dir(), 'zip_') . '.zip';
    $zip->open($tempPath, ZipArchive::CREATE);
    foreach ($data as $item) {
      $path = $this->app->getRootPath() . 'public/storage/gen/' . $item['im'];
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
