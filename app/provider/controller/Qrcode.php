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

    $query = Db::name('qrcode')->alias('q')
      // ->leftJoin('tag t', 'FIND_IN_SET(t.id,q.tags)')
      ->where(['q.provider_id' => $this->provider['id']])
      ->group('q.id')
      ->page($this->page, $this->pageSize)
      ->fieldRaw("q.id,q.code,q.company,q.status,q.valid,q.valid_time,q.err_msg,q.add_time");

    if (!empty($company)) {
      $query->whereLike('q.company', "%{$company}%");
    }

    $data = $query->select()->toArray();

    foreach ($data as &$item) {
      $subData = Db::name('entrance')->alias('e')
        ->where('e.qrcode_id', $item['id'])
        ->where('e.expire_date', '>=', $this->db->raw('NOW()'))
        ->column("e.id,e.avatar,e.name,e.members,e.qr,e.expire_date,e.joinable_wc,e.reported,e.add_time");
      $item['entrance'] = $subData;
    }

    $total = Db::name('qrcode')->where('provider_id', $this->provider['id'])->count();

    return $this->successJson($this->paginate($data, $total));
  }

  public function add(): Json
  {
    $qrcode = input('qrcode/a');
    $company = input('company');


    $data = [
      'qrcode' => $qrcode,
    ];

    $rule = [
      'qrcode' => 'require',
    ];

    $message = [
      'qrcode.require' => '活码必填',
    ];

    try {
      $this->validate($data, $rule, $message);
    } catch (ValidateException $e) {
      return $this->errorJson(-100, $e->getMessage());
    }


    // if (Db::name('qrcode')->whereIn('code', $qrcode)->value('id')) {
    //   return $this->errorJson(3, '活码已存在');
    // }


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

  // 编辑活码 (没用到)
  public function edit(): Json
  {
    $qrcodeId = input('id');
    $company = input('company');
    $this->assertNotEmpty($qrcodeId);
    $company = empty($company) ? null : $company;
    $this->db->startTrans();
    try {
      $this->db->name('qrcode')->where(['id' => $qrcodeId, 'provider_id' => $this->provider['id']])->update([
        'company' => $company,

      ]);
      $this->db->name('entrance')->where(['qrcode_id' => $qrcodeId, 'provider_id' => $this->provider['id']])->update([
        'company' => $company,
      ]);
      $this->db->commit();
      return $this->successJson();
    } catch (Exception $e) {
      $this->db->rollback();
      return $this->errorJson(1, $e->getMessage());
    }
  }
}
