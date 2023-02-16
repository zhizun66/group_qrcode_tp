<?php

declare(strict_types=1);

namespace app\api\controller;

use app\api\CommonController;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Exception;
use think\response\Json;
use think\helper\Str;

class server extends CommonController
{
  //获取链接
  public function setWxid($wxid): bool
  {
    $api_interval = $this->getSysSetting('api_interval');
    $api_day_limit = $this->getSysSetting('api_day_limit');
    try {
      $info = $this->db->name('wxid')->where('wxid', $wxid)->field('last_use_time,use_times')->findOrEmpty();
      if (empty($info)) {
        $this->db->name('wxid')->insert(['use_times' => 1, 'last_use_time' => time(), 'wxid' => $wxid]);
        // var_dump('第一次插入wxid');
        return true;
      } else {
        if (date("Y-m-d", intval($info['last_use_time'])) < date("Y-m-d")) {
          // var_dump('发现最后一次使用是昨天 重置使用次数和使用时间');
          $this->db->name('wxid')->where('wxid', $wxid)->update(['last_use_time' => time(), 'use_times' => 1]);
          return true;
        } else {
          if ($api_interval < time() - $info['last_use_time'] && $api_day_limit > $info['use_times']) {
            $this->db->name('wxid')->where('wxid', $wxid)->inc('use_times')->update(['last_use_time' => time()]);
            return true;
          }
          // var_dump('使用间隔为满足 或者 使用次数已达上限');
        }
      }
      return false;
    } catch (Exception) {
      return false;
    }
  }



  // 获取活码
  public function getQr(): Json
  {
    $wxid = input('wxid');
    $this->assertNotEmpty($wxid);

    try {
      $fetchSql = $this->db->name('fetch')
        ->where('use_time', '>', time() - 518400) //距离上一次检测超过6天
        ->group('qrcode_id')
        ->fieldRaw('COUNT(*) cnt,qrcode_id')->buildSql();
      $info = $this->db->name('qrcode')->alias('q')
        ->leftJoin([$fetchSql => 'f2'], 'f2.qrcode_id=q.id')
        ->where(function ($q) {
          $q->whereNull('cnt')->whereOr('f2.cnt', '<', 1);
        })
        ->where('status', 0)
        ->whereNotExists(function ($query) use ($wxid) {
          $query->name('fetch')->alias('f')->whereColumn('f.qrcode_id', 'q.id')->where('f.wxid', $wxid)->field('f.id');
        })
        ->field('q.id,q.code url')->findOrEmpty();
      if ($info) {
        if ($this->setWxid($wxid) == false) {
          return $this->errorJson(1, 'wxid更新失败');
        }
        $this->db->name('fetch')->insert(['qrcode_id' => $info['id'], 'wxid' => $wxid, 'use_time' => time()]);
      };
    } catch (\Exception $e) {
      return $this->errorJson(4, '插入数据失败' . $e->getMessage());
    }
    return $this->successJson($info);
  }

  // 上传从活码解析出来的群码
  public function upload(): Json
  {
    $qrcodeId   = input('id');      // 活码ID
    $wxid       = input('wxid');    // wxid
    $qr         = input('url');     // 群码url
    $expire     = input('expire');  // 到期日期（2022-10-30 10:11:32）
    $avatar     = input('avatar');  // 头像url
    $name       = input('name');    // 群名称
    $status    = input('status'); // 状态 0、解析中；1、群已满
    $errMsg    = input('errMsg');
    // var_dump($status);
    if (!$status == 0) { //活码群已满 无法再解析
      try {
        $a = $this->db->name('qrcode')->where('id', $qrcodeId)->update([
          'status'        => $status,
          'err_msg'       => $errMsg,
          'valid'         =>  2,
          'valid_time'    =>  date('Y-m-d H:i:s', time())
        ]);

        // $this->db->name('qrcode')->where('id', $qrcodeId)->update(['valid' =>  2, 'valid_time' =>  date('Y-m-d H:i:s', time())]);
        return $this->errorJson(0, '活码失效');
      } catch (Exception) {
        return $this->errorJson(1, '活码ID不存在');
      }
    }
    $this->assertNotEmpty($qrcodeId, $qr);
    $this->setEmptyStringToNull($expire, $avatar, $name);
    if (!Str::contains($qr, 'c.weixin.com/g')) {
      return $this->errorJson(1, '该二维码不是企微群码');
    }
    try {
      $info = $this->db->name('qrcode')->where('id', $qrcodeId)->where('status', 0)
        ->field('company,user_id,provider_id')
        ->findOrFail();
    } catch (\Exception $e) {
      return $this->errorJson(1, '活码ID不存在' . ($e->getMessage()));
    }

    // $this->db->name('fetch')->insert(['qrcode_id' => $qrcodeId, 'wxid' => $wxid, 'use_time' => time()]);
    // https://c.weixin.com/g/niqgg3m-SLHcXXFM
    try {
      // 生成二维码
      $path = $this->app->getRootPath() . 'public/storage/gen/' . Str::substr($qr, 23) . '.png';
      // $path = @tempnam($this->app->getRootPath() . 'public/storage/gen', '') . '.png';
      $options = new QROptions(['scale' => 10, 'imageTransparent' => false]);
      (new QRcode($options))->render($qr, $path);
    } catch (\Exception $e) {
      return $this->errorJson(3, '生成二维码失败' . ($e->getMessage()));
    }

    $this->db->startTrans();
    try {
      //同一个群 从不同活码里解析出来的url是不一样的 但可以通过头像url来判断唯一性
      if ($this->db->name('entrance')->where('avatar', $avatar)->findOrEmpty()) {
        $this->db->name('entrance')
          ->where('avatar', $avatar)
          ->update([
            'name'          => $name,
            'qr'            => $qr,
            'im'           => 'gen/' . basename($path),
            'expire_date'   => $expire,
          ]);
        $this->db->commit();
        return $this->errorJson(0, '更新成功');
      } else {

        $this->db->name('entrance')->insert([
          'qrcode_id'     => $qrcodeId,
          'user_id'       => $info['user_id'],
          'provider_id'   => $info['provider_id'],
          'avatar'        => $avatar,
          'name'          => $name,
          'qr'            => $qr,
          'im'           => 'gen/' . basename($path),
          'expire_date'   => $expire,
          'company'       => $info['company'],
          'status'        => 0,
          'source'        => 2
        ]);
        $this->db->commit();
        return $this->successJson();
      }
      // $this->db->name('fetch')->insert(['qrcode_id' => $qrcodeId, 'wxid' => $wxid]);
    } catch (\Exception $e) {
      $this->db->rollback();
      return $this->errorJson(4, '插入数据失败' . ($e->getMessage()));
    }
  }
}
