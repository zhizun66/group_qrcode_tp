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

  //获取链接
  public function setWxid($wxid): bool
  {
    // $wxid = input('wxid');
    // $this->assertNotEmpty($wxid);
    // $this->qrcode($wxid);
    // ['interval', '<', time() - $data['last_use_time']],
    // ['uplimit', '>', $data['use_times']]
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
  public function qrcode(): Json
  {
    $wxid = input('wxid');
    $this->assertNotEmpty($wxid);

    try {
      $fetchSql = $this->db->name('fetch')->group('qrcode_id')->fieldRaw('COUNT(*) cnt,qrcode_id')->buildSql();
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


  // 生成二维码
  public function encode(): Json
  {
    $data = $this->db->name('entrance')->where('im2', 'gen/.png')->column('id,qr');
    try {
      foreach ($data as &$item) {
        $qr = $item['qr'];
        // 生成二维码
        // $path = @tempnam($this->app->getRootPath() . 'public/storage/gen', '') . '.png';
        $path = $this->app->getRootPath() . 'public/storage/gen/' . Str::substr($qr, 23) . '.png';
        var_dump($item['id'] . ' ' . $item['qr'] . ' ' . $path);
        $options = new QROptions(['scale' => 10, 'imageTransparent' => false]);
        (new QRcode($options))->render($qr, $path);

        $this->db->name('entrance')->where('id', $item['id'])->update(['im2' => 'gen/' . basename($path)]);
        die;
      }
    } catch (\Exception $e) {
      return $this->errorJson(3, '生成二维码失败' . $e->getMessage());
    }
    return $this->successJson();
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
        ->field('company,remark,province,city,district,tags,user_id,provider_id')
        ->findOrFail();
    } catch (Exception) {
      return $this->errorJson(1, '活码ID不存在');
    }

    $this->db->name('fetch')->insert(['qrcode_id' => $qrcodeId, 'wxid' => $wxid, 'use_time' => time()]);
    // https://c.weixin.com/g/niqgg3m-SLHcXXFM
    try {
      // 生成二维码
      $path = $this->app->getRootPath() . 'public/storage/gen/' . Str::substr($qr, 23) . '.png';
      // $path = @tempnam($this->app->getRootPath() . 'public/storage/gen', '') . '.png';
      $options = new QROptions(['scale' => 10]);
      (new QRcode($options))->render($qr, $path);
    } catch (Exception) {
      return $this->errorJson(3, '生成二维码失败');
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
            'im2'           => 'gen/' . basename($path),
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
          'im2'           => 'gen/' . basename($path),
          'expire_date'   => $expire,
          'company'       => $info['company'],
          'province'      => $info['province'],
          'city'          => $info['city'],
          'district'      => $info['district'],
          'tags'          => $info['tags'],
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


  // 进群软件api 获取企微进群信息
  public function getUinJoinInfo(): Json
  {
    $uin = input('uin');
    $status = input('status');
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
  public function getEntrance1(): Json
  {
    $uin = input('uin');
    $modle = input('modle');
    $members_min = input('members_min/d');
    $members_max = input('members_max/d');
    if (empty($modle)) {
      return $this->errorJson(1, '模式错误');
    }
    $this->db->startTrans();
    try {

      $query = $this->db->name('entrance'); //->lock(true);
      if ($modle == 1) {
        // $query->whereTime('get_time', '<', time() - 21600)
        //   ->where('status', 0)
        //   ->where('joinable', 0)
        //   ->order('id', 'desc');
        $query->whereTime('get_time', '<', time() - 21600)
          ->whereIn('status', [0, 1])
          ->whereIn('joinable', [0, 1])
          ->order(['status', 'joinable' => 'asc']);
        //->order(['members' => 'DESC']);
      } elseif ($modle == 2) {
        $query
          ->whereBetween('members', [$members_min, $members_max])
          ->where(['status' => 1, 'joinable' => 1])
          ->whereTime('join_time', '<', time() - 7200)
          ->order(['id' => 'DESC']);
      } elseif ($modle == 3) {
        $query
          ->whereTime('get_time', '<', time() - 86400)
          ->order(['id' => 'DESC']);
      }

      $info = $query->where('expire_date', '>=', $this->db->raw('NOW()')) //没到期的
        ->field('id,qr,name,members,room_id,joinable,status')

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
    $this->assertNotEmpty($id, $rid);
    $this->setEmptyStringToNull($members, $status);

    try {
      $info = $this->db->name('entrance')->where('id', $id)
        // ->where('status', 0)
        ->field('tags,user_id,provider_id')
        ->findOrFail();
    } catch (Exception) {
      return $this->errorJson(1, '群码ID不存在');
    }

    $this->db->startTrans();
    try {
      $this->db->name('entrance')->where('id', $id)->update([
        'members'       => $members,
        'joinable'       => $joinable,
        'status'        => $status,
        'room_id'       => $room_id,
        'update_time'       => date('Y-m-d H:i:s', time()),
        'error_msg'       => $error,
      ]);
      $this->db->name('record')->where('id', $rid)->update(['status' =>  $isjoin]);
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
  public function addQrcode(): Json
  {
    $qrcode = input('qrcode');
    $company = input('company');
    $data = [
      'qrcode' => $qrcode,
      'company' => $company,
    ];
    $rule = [
      'qrcode' => 'require',
      'company' => 'require',
    ];

    $message = [
      'qrcode.require' => '活码必填',
      'company.require' => '公司必填',
    ];

    try {
      $this->validate($data, $rule, $message);
    } catch (\Exception $e) {
      return $this->errorJson(-100, $e->getMessage());
    }


    if ($this->db->name('qrcode')->where('code', $qrcode)->value('id')) {
      return $this->errorJson(3, '活码已存在');
    }

    try {
      $data = [
        'provider_id'   => 1,
        'code'          => $qrcode,
        'company'       => $company,
      ];
      // Db::name('provider')->where('id', $this->provider['id'])->inc('score', 30)->update();
      $this->db->name('qrcode')->insert($data);
      return $this->successJson();
    } catch (\Exception $e) {
      return $this->errorJson(2, $e->getMessage());
    }
  }
  public function test()
  {


    $data = $this->db->name('qrcode')->where(['provider_id' => 2])->column('id,valid');
    try {
      var_dump('活码数' . count($data));
      // var_dump($data);
      $c = 0;
      foreach ($data as &$item) {
        $c++;
        $groups =  $this->db->name('entrance')->where('qrcode_id', $item['id'])->column('id,members,joinable,status');
        // var_dump('群码数' . count($groups));
        $n = 0;
        foreach ($groups as &$group) {
          if ($group['members'] >= 60 && $group['joinable'] == 1 && $group['status'] == 1) {
            $this->db->name('qrcode')->where('id', $item['id'])->update(['valid' => 1, 'valid_time' => date('Y-m-d H:i:s', time())]);
            var_dump($c . '成功 群码ID' . $group['id'] . '对应的活码ID' . $item['id']);
          } else {
            $n++;
          }
        }
        if (count($groups) == $n) {
          $this->db->name('qrcode')->where('id', $item['id'])->update(['valid' => 0, 'valid_time' => null]);
          var_dump($c . '失败 对应的活码ID' . $item['id']);
        }
      }
      die;
    } catch (\Exception $e) {
      return $this->errorJson(3, '生成二维码失败' . $e->getMessage());
    }
    // $this->db->name('entrance')->where('expire_date', '<', $this->db->raw('NOW()'))->delete();
    // $count = $this->db->name('qrcode')->where(['provider_id' => 2, 'valid' => 1])->count();
    // $count =  $this->db->name('entrance')->whereBetween('members', [80, 190])->where(['joinable' => 1, 'status' => 1])->where('join_time', '<', time() - 21600)->where('expire_date', '>=', $this->db->raw('NOW()'))->count();
    // var_dump($count);
    // $this->db->name('record')->where(1, 1)->update(['add_time' => "2022-11-27 15:22:29"]);
    // $this->db->name('entrance')->where('members', null)->update(['joinable' => 0, 'status' => 0, 'get_time' => 0]);
    // $this->db->name('entrance')->where('members', '>', 0)->where(['joinable' => 0, 'status' => 2])->update(['joinable' => 1, 'status' => 1]);
    // $this->db->name('entrance')->where(1, 1)->update(['join_cnt' => 0]);
    // return $this->successJson();
    // $this->db->name('qrcode')->where(1, 1)->update(['status' => 0]);

    // } catch (\Exception $e) {
    //   return $this->errorJson(4, '插入数据失败' . ($e->getMessage()));
    // }
  }
  public function test1()
  {

    // try {
    //   $data = $this->db->name('qrcode')->where(['provider_id' => 2, 'status' => 1])->update(['valid' => 2, 'valid_time' =>  date('Y-m-d H:i:s', time())]);
    //   return $this->successJson();
    // } catch (\Exception $e) {
    //   return $this->errorJson(3, '生成二维码失败' . $e->getMessage());
    // }
    // $this->db->name('entrance')->where('expire_date', '<', $this->db->raw('NOW()'))->delete();
    // $count = $this->db->name('qrcode')->where(['provider_id' => 2, 'valid' => 1])->count();
    // ->where('join_time', '<', time() - 21600)
    $count =  $this->db->name('entrance')->whereBetween('members', [50, 199])->where(['joinable' => 1, 'status' => 1])->where('join_time', '<', time() - 7200)->where('expire_date', '>=', $this->db->raw('NOW()'))->count();
    // $count = $this->db->name('entrance')->where('expire_date', '>=', $this->db->raw('NOW()'))->count();

    var_dump($count);
    // $this->db->name('record')->where(1, 1)->update(['add_time' => "2022-11-27 15:22:29"]);
    // $this->db->name('entrance')->where('members', null)->update(['joinable' => 0, 'status' => 0, 'get_time' => 0]);
    // $this->db->name('entrance')->where('members', '>', 0)->where(['joinable' => 0, 'status' => 2])->update(['joinable' => 1, 'status' => 1]);
    // $name = '军队';
    // $data = $this->db->name('entrance')->whereLike('name', "%{$name}%")->column('name,qrcode_id');
    // foreach ($data as &$item) {
    //   var_dump($item['name'] . '    qr_id:' . $item['qrcode_id']);
    // }

    // $this->db->name('entrance')->where(1, 1)->update(['join_cnt' => 0]);
    return $this->successJson();
    // $this->db->name('qrcode')->where(1, 1)->update(['status' => 0]);

    // } catch (\Exception $e) {
    //   return $this->errorJson(4, '插入数据失败' . ($e->getMessage()));
    // }
  }
}
