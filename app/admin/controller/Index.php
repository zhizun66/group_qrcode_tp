<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\CommonController;
use Exception;
use think\response\Json;

class Index extends CommonController
{
    public function index(): Json
    {
        $info = $this->db->name('admin')->where('id', $this->admin['id'])->field('id,username')->findOrEmpty();
        return $this->successJson($info);
    }

    public function repwd(): Json
    {
        $password = input('post.password');
        if (empty($password)) {
            return $this->errorJson();
        }

        try {
            $this->db->name('admin')->where('id', $this->admin['id'])->update(['password' => md5($password)]);
            return $this->successJson();
        } catch (Exception) {
            return $this->errorJson();
        }
    }

    public function visualization(): Json
    {

        $fun = function(string $table) {
            $days = 7;
            $data = $this->db->name($table)
                ->whereRaw("DATE(add_time) >= DATE_SUB(CURDATE(),INTERVAL {$days} DAY)")
                ->group('`date`')
                ->column('DATE(add_time) `date`,COUNT(id) quantity');

            $data = array_reduce($data, function($carry, $item) {
                $carry[$item['date']] = intval($item['quantity']);
                return $carry;
            }, []);

            for ($d = 0; $d < $days; $d++) {
                $date = date('Y-m-d', strtotime("-{$d} day"));
                if (!isset($data[$date])) {
                    $data[$date] = 0;
                }
            }
            ksort($data);
            return $data;
        };

        return $this->successJson([
            'qrcode'    => $fun('qrcode'),
            'entrance'  => $fun('entrance'),
            'buy'       => $fun('buy')
        ]);
    }
}
