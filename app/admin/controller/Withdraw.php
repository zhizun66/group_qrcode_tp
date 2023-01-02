<?php

namespace app\admin\controller;

use app\admin\CommonController;
use Exception;
use think\response\Json;

class Withdraw extends CommonController
{
    public function index(): Json
    {
        $status = input('status/d');
        $role = input('role');
        $this->assertNotNull($status);

        //$role = $role === 'user' ? 'user' : 'provider';

        $query = $this->db->name('withdraw')->alias('w')
            ->where('status', $status)
            ->leftJoin('user u', 'u.id=w.user_id')
            ->leftJoin('provider p', 'p.id=w.provider_id');
//            ->leftJoin("{$role} up", "up.id=w.{$role}_id")
//            ->whereNotNull($role . '_id');

        if (!empty($role)) {
            $role === 'user' ? $query->whereNotNull('u.id') : $query->whereNotNull('p.id');
        }

        $total = $query->count();
        $data = $query->order('w.id', 'DESC')->column("w.id,IF(u.id,'user','provider') role,IF(u.id,u.username,p.username) username,w.score,w.alipay,w.status,FROM_UNIXTIME(w.op_time) op_time,w.add_time");

        return $this->successJson($this->paginate($data, $total));
    }

    public function permit(): Json
    {
        $withdrawId = input('post.wid/d');
        $this->assertNotEmpty($withdrawId);

        try {
            $this->db->name('withdraw')->where(['id' => $withdrawId, 'status' => 0])->update(['status' => 1, 'op_time' => time()]);
            return $this->successJson();
        } catch (Exception) {
            return $this->errorJson();
        }
    }

    public function refuse(): Json
    {
        $withdrawId = input('post.wid/d');
        $this->assertNotEmpty($withdrawId);

        $this->db->startTrans();
        try {
            $info = $this->db->name('withdraw')->where('id', $withdrawId)
                ->fieldRaw("score,IF(user_id,user_id,provider_id) pk,IF(user_id,'user','provider') 'role'")
                ->findOrEmpty();

            $this->db->name($info['role'])->where('id', $info['pk'])->inc('score', $info['score'])->update();
            $this->db->name('withdraw')->where(['id' => $withdrawId, 'status' => 0])
                ->update(['status' => -1, 'op_time' => time()]);

            $this->db->commit();
            return $this->successJson();
        } catch (Exception) {
            $this->db->rollback();
            return $this->errorJson();
        }
    }
}