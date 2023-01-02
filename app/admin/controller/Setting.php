<?php

namespace app\admin\controller;

use app\admin\CommonController;
use Exception;
use think\response\Json;

class Setting extends CommonController
{
  public function index(): Json
  {
    $data = $this->db->name('setting')->column('str_val,int_val,desc', 'key');
    return $this->successJson($data);
  }

  public function save(): Json
  {
    $apiKey = input('post.api_key/s');
    $api_day_limit = input('post.api_day_limit/d');
    $api_interval = input('post.api_interval/d');
    $commonLevel1 = input('post.commission_level_1/d');
    // $entrancePrice = input('post.entrance_price/d');
    $maxBuyTimes = input('post.max_buy_times/d');
    $minWithdraw = input('post.min_withdraw/d');
    $minExchange = input('post.min_exchange/d');
    $serviceCharge = input('post.service_charge/d');
    $entrance_has_location = input('post.entrance_has_location/d');
    $entrance_status_0 = input('post.entrance_status_0/d');
    $entrance_work_joinable = input('post.entrance_work_joinable/d');
    $qrcode_decode_price = input('post.qrcode_decode_price/d');

    $this->assertNotEmpty($apiKey, $commonLevel1, $maxBuyTimes, $minWithdraw, $minExchange, $serviceCharge, $entrance_has_location, $entrance_status_0, $qrcode_decode_price, $entrance_work_joinable);

    $this->db->startTrans();
    try {
      $this->db->name('setting')->where('key', 'api_key')->update(['str_val' => $apiKey]);
      $this->db->name('setting')->where('key', 'api_day_limit')->update(['int_val' => $api_day_limit]);
      $this->db->name('setting')->where('key', 'api_interval')->update(['int_val' => $api_interval]);
      $this->db->name('setting')->where('key', 'commission_level_1')->update(['int_val' => $commonLevel1]);
      // $this->db->name('setting')->where('key', 'entrance_price')->update(['int_val' => $entrancePrice]);
      $this->db->name('setting')->where('key', 'max_buy_times')->update(['int_val' => $maxBuyTimes]);
      $this->db->name('setting')->where('key', 'min_withdraw')->update(['int_val' => $minWithdraw]);
      $this->db->name('setting')->where('key', 'min_exchange')->update(['int_val' => $minExchange]);
      $this->db->name('setting')->where('key', 'service_charge')->update(['int_val' => $serviceCharge]);
      $this->db->name('setting')->where('key', 'entrance_has_location')->update(['int_val' => $entrance_has_location]);
      $this->db->name('setting')->where('key', 'entrance_status_0')->update(['int_val' => $entrance_status_0]);
      $this->db->name('setting')->where('key', 'entrance_work_joinable')->update(['int_val' => $entrance_work_joinable]);
      $this->db->name('setting')->where('key', 'qrcode_decode_price')->update(['int_val' => $qrcode_decode_price]);
      $this->db->commit();
      return $this->successJson();
    } catch (\Exception $e) {
      $this->db->rollback();
      return $this->errorJson($e->getMessage());
    }
  }
}
