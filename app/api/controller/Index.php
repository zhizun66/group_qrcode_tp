<?php

declare(strict_types=1);

namespace app\api\controller;

use app\api\CommonController;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Exception;
use think\response\Json;
use think\helper\Str;

class Index extends CommonController
{
  public function login(): Json
  {
    $username = input('username');
    $password = input('password');

    if (empty($username) || empty($password)) {
      return $this->errorJson(-1, '请输入账号和密码');
    }

    $info = $this->db->name('manager')->where(['username' => $username, 'password' => md5($password)])
      ->field('id,username,enable')->findOrEmpty();
    if (empty($info)) {
      return $this->errorJson(1, '账号或密码错误');
    } elseif ($info['enable'] === 1) {
      unset($info['enable']);
      $this->db->name('manager')->where('id', $info['id'])->update(['relogin' => 0]);
      return $this->successJson($info, "登陆成功");
    } else {
      return $this->errorJson(2, '账号暂未生效');
    }
  }
  // 进群软件api 获取企微进群信息
  public function getUinInfo(): Json
  {
    $uin = input('uin');
    $manager_id = input('manager_id/d');
    $status = input('status');
    $info = $this->db->name('manager')->where(['id' => $manager_id])
      ->findOrEmpty();
    if (empty($info)) {
      return $this->errorJson(1, '账号不存在');
    }
    try {
      if ($status == "all") {
        $info = $this->db->name('record')->where(['uin' => $uin])->whereTime('add_time', 'today')->count();
      } else {
        $info = $this->db->name('record')->where(['status' => $status, 'uin' => $uin])->whereTime('add_time', 'today')->count();
      }
      $data['count'] = $info;
      return $this->successJson($data);
    } catch (Exception) {
      return $this->errorJson(1, '暂无数据');
    }
  }

  // 获取一条需要进群的群码
  public function getEntrance(): Json
  {

    $uin = input('uin');
    $modle = input('modle');
    $members_min = input('members_min/d');
    $members_max = input('members_max/d');
    $manager_id = input('manager_id/d');

    $info = $this->db->name('manager')->where(['id' => $manager_id])
      ->findOrEmpty();
    if (empty($info)) {
      return $this->errorJson(1, '账号不存在');
    }
    if (empty($modle)) {
      return $this->errorJson(1, '模式错误');
    }
    $this->db->startTrans();
    try {
      $query = $this->db->name('entrance') //->lock(true);
        ->whereTime('get_time', '<', time() - 21600)
        ->whereIn('provider_id', function ($q) use ($manager_id) {
          $q->name('provider')->where('manager_id', $manager_id)->field('id');
        });
      if ($modle == 1) {
        $query->whereIn('status', [0, 1])
          ->whereIn('joinable_wc', [0, 1])
          ->order(['status', 'joinable_wc' => 'asc']);
        //->order(['members' => 'DESC']);
      } elseif ($modle == 2) {
        $query
          ->whereBetween('members', [$members_min, $members_max])
          ->where(['status' => 1, 'joinable_wc' => 1])
          ->whereTime('join_time', '<', time() - 7200)
          ->order(['id' => 'DESC']);
      } elseif ($modle == 3) {
        $query
          ->whereTime('get_time', '<', time() - 86400)
          ->order(['id' => 'DESC']);
      }

      $info = $query
        ->where('expire_date', '>=', $this->db->raw('NOW()')) //没到期的
        ->field('id,qr,name,members,room_id,joinable_wc,status')

        // ->whereNotExists(function ($query) use ($uin) {
        //   $query->name('record')->alias('r')->whereColumn('r.entrance_id', 'e.id')->where('r.uin', $uin)->field('r.entrance_id');
        // })
        ->findOrEmpty();
      if ($info) {
        if ($modle == 1) {
          $this->db->name('entrance')->where('id', $info['id'])->update(['get_time' => time()]);
        } elseif ($modle == 2) {
          $this->db->name('entrance')->where('id', $info['id'])->update(['join_time' => time()]);
        } elseif ($modle == 3) {
          $this->db->name('entrance')->where('id', $info['id'])->update(['get_time' => time()]);
        }

        $record_id = $this->db->name('record')->insertGetId(['uin' => $uin, 'entrance_id' => $info['id']]);
        $info['record_id'] = $record_id;
      }

      $this->db->commit();
      return $this->successJson($info);
    } catch (\Exception $e) {
      $this->db->rollback();
      return $this->errorJson(1, '暂无数据' . $e->getMessage());
    }
  }
  // 上传解析出来的群码信息
  public function uploadEntranceByWk(): Json
  {
    $id   = input('id');      // 群码ID
    $rid = input('rid');
    // $uin       = input('uin');    // uin
    // $avatar     = input('avatar');  // 头像url
    // $name       = input('name');    // 群名称
    $members    = input('members'); // 成员数
    $room_id    = input('room_id'); //群ID
    $joinable    = input('joinable/d'); // 企微是否能进
    $status    = input('status/d'); // 状态 1、二维码到期 2、群满200人无法扫码进群
    $isjoin = input('isjoin/d');
    $error       = input('error');    // 错误信息
    $manager_id = input('manager_id/d');
    $this->assertNotEmpty($id, $rid);
    $this->setEmptyStringToNull($members, $status);


    $ManagerInfo = $this->db->name('manager')->where(['id' => $manager_id])
      ->findOrEmpty();
    if (empty($ManagerInfo)) {
      return $this->errorJson(1, '账号不存在');
    }
    $EntranceInfo = $this->db->name('entrance')->where('id', $id)
      // ->where('status', 0)
      ->field('user_id,provider_id')
      ->findOrFail();
    if (empty($EntranceInfo)) {
      return $this->errorJson(1, '群码ID不存在');
    }
    $this->db->startTrans();
    try {
      $this->db->name('entrance')->where('id', $id)->update([
        'members'       => $members,
        // 'name'       => $name,
        'joinable_wc'       => $joinable,
        'status'        => $status,
        'room_id'       => $room_id,
        'update_time'       => date('Y-m-d H:i:s', time()),
        'error_msg'       => $error,
      ]);
      $this->db->name('record')->where('id', $rid)->update(['status' =>  $isjoin]);

      //审核provider上传的活码
      if ($joinable == 1 && $members >= 60) {
        $qrcode_id = $this->db->name('entrance')->where('id', $id)->value('qrcode_id');
        if (!empty($qrcode_id)) {
          $this->db->name('qrcode')->where('id', $qrcode_id)->update(['valid' => $joinable, 'valid_time' =>  date('Y-m-d H:i:s', time())]);
        }
      }
      // $this->db->name('fetch')->insert(['qrcode_id' => $qrcodeId, 'wxid' => $wxid]);
      $this->db->commit();
      return $this->successJson();
    } catch (\Exception $e) {
      $this->db->rollback();
      return $this->errorJson(4, '插入数据失败' . ($e->getMessage()));
    }
  }
}
