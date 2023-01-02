<?php

namespace app\index\controller;

use app\index\CommonController;
use Exception;
use think\response\Json;

class Invite extends CommonController
{
  public function index(): Json
  {
    $query = $this->db->name('commission')->where('invite_user_id', $this->user['id']);
    $total = $query->count();
    $data = $query->page($this->page, $this->pageSize)->column('buy_user_id,pay,commission,rate,add_time');
    foreach ($data as &$item) {
      $item['username'] = $this->db->name('user')->where('id', $item['buy_user_id'])->value('username');
    }

    return $this->successJson($this->paginate($data, $total));
  }

  public function inviteList(): Json
  {
    $query = $this->db->name('user')->where('invite_user_id', $this->user['id']);
    $total = $query->count();
    $data = $query->column('username,add_time');
    return $this->successJson($this->paginate($data, $total));
  }

  public function inviteUrl(): Json
  {
    $outerId = $this->db->name('user')->where('id', $this->user['id'])->value('outer_id');
    $url = $this->request->domain(true) . '/rest/index/user/invite?p=' . $outerId;
    return $this->successJson(['url' => $url]);
  }

  public function alipay(): Json
  {
    $info = $this->db->name('user')->where('id', $this->user['id'])->field('alipay,score')->findOrEmpty();
    return $this->successJson($info);
  }

  public function withdraw(): Json
  {
    $alipay = input('alipay/s');
    $score = input('score/d');
    $this->assertNotEmpty($alipay, $score);

    $min = $this->getSysSetting('min_withdraw');
    if ($score < $min) {
      return $this->errorJson(1, '提现积分最低 ' . $min);
    }

    $scoreLeft = $this->db->name('user')->where('id', $this->user['id'])->value('score');
    if ($score > $scoreLeft) {
      return $this->errorJson(2, '积分不足');
    }

    $this->db->startTrans();
    try {
      $this->db->name('withdraw')->insert([
        'user_id' => $this->user['id'],
        'score' => $score,
        'alipay' => $alipay
      ]);
      $this->db->name('user')->where('id', $this->user['id'])->dec('score', $score)->update(['alipay' => $alipay]);
      $this->db->commit();
      return $this->successJson();
    } catch (Exception) {
      $this->db->rollback();
      return $this->errorJson();
    }
  }

  public function exchange(): Json
  {
    $score = input('score/d');
    $this->assertNotEmpty($score);

    $min = $this->getSysSetting('min_exchange');
    if ($score < $min) {
      return $this->errorJson(1, '兑换积分最低 ' . $min);
    }

    $scoreLeft = $this->db->name('user')->where('id', $this->user['id'])->value('score');
    if ($score > $scoreLeft) {
      return $this->errorJson(2, '积分不足');
    }

    $this->db->startTrans();
    try {
      $this->db->name('deposit')->insert([
        'user_id' => $this->user['id'],
        'value' => $score,
        'way' => 2
      ]);
      $this->db->name('user')->where('id', $this->user['id'])->dec('score', $score)->inc('balance', $score)->update();
      $this->db->commit();
      return $this->successJson();
    } catch (Exception) {
      $this->db->rollback();
      return $this->errorJson();
    }
  }

  public function withdrawList(): Json
  {
    $query = $this->db->name('withdraw')->where('user_id', $this->user['id']);
    $total = $query->count();
    $data = $query->order('id', 'DESC')->column('id,score,alipay,status,FROM_UNIXTIME(op_time) op_time,add_time');

    return $this->successJson($this->paginate($data, $total));
  }
}
