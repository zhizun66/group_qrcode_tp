<?php

namespace app\provider\controller;

use app\provider\CommonController;
use Exception;
use think\response\Json;

class Withdraw extends CommonController
{
    public function index(): Json
    {
        $query = $this->db->name('withdraw')->where('provider_id', $this->provider['id']);
        $total = $query->count();
        $data = $query->order('id', 'DESC')->column('id,score,alipay,status,FROM_UNIXTIME(op_time) op_time,add_time');

        return $this->successJson($this->paginate($data, $total));
    }

    public function alipay(): Json
    {
        $info = $this->db->name('provider')->where('id', $this->provider['id'])->field('alipay,score')->findOrEmpty();
        return $this->successJson($info);
    }

    public function withdraw(): Json
    {
        $alipay = input('alipay');
        $this->assertNotEmpty($alipay);

        $score = $this->db->name('provider')->where('id', $this->provider['id'])->value('score');
        if ($score < 500) {
            $this->errorJson(1, '提现积分最低500');
        }

        $this->db->startTrans();
        try {
            $this->db->name('withdraw')->insert([
                'provider_id' => $this->provider['id'],
                'score' => $score,
                'alipay' => $alipay
            ]);
            $this->db->name('provider')->where('id', $this->provider['id'])->update(['alipay' => $alipay, 'score' => 0]);
            $this->db->commit();
            return $this->successJson();
        } catch (Exception) {
            $this->db->rollback();
            return $this->errorJson();
        }
    }

    public function withdrawList(): Json
    {
        $query = $this->db->name('withdraw')->where('provider_id', $this->provider['id']);
        $total = $query->count();
        $data = $query->order('id', 'DESC')->column('id,score,alipay,status,FROM_UNIXTIME(op_time) op_time,add_time');

        return $this->successJson($this->paginate($data, $total));
    }

    public function income(): Json
    {
        $serviceCharge = $this->getSysSetting('service_charge') / 100;

        $days = 20;
        $data = $this->db->name('buy')->alias('b')
            ->leftJoin('entrance e', 'e.id=b.entrance_id')
            ->where('e.provider_id', $this->provider['id'])
            ->whereRaw("DATE(b.add_time) >= DATE_SUB(CURDATE(),INTERVAL {$days} DAY)")
            ->group('date')
            ->column("SUM(FLOOR(b.price*{$serviceCharge})) income,DATE(b.add_time) `date`");

        $data = array_reduce($data, function($carry, $item) {
            $carry[$item['date']] = intval($item['income']);
            return $carry;
        }, []);

        for ($d = 0; $d < $days; $d++) {
            $date = date('Y-m-d', strtotime("-{$d} day"));
            if (!isset($data[$date])) {
                $data[$date] = 0;
            }
        }
        ksort($data);

        return $this->successJson($data);
    }
}