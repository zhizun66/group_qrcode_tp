<?php

namespace app\admin\controller;

use app\admin\CommonController;
use Exception;
use think\response\Json;

class Staff extends CommonController
{
    public function index(): Json
    {
        $enable = input('enable/d', 1);

        $query = $this->db->name('staff')->where('enable', $enable);

        $data = $query->page($this->page, $this->pageSize)
            ->order('id', 'DESC')
            ->column('id,username,name,enable,add_time');

        $total = $query->count();

        return $this->successJson($this->paginate($data, $total));
    }

    public function enable(): Json
    {
        $staffId = input('sid');
        $enable = input('enable/d', 1);

        if ($enable !== 0 && $enable !== 1) {
            $enable = 1;
        }

        try {
            $this->db->name('staff')->where('id', $staffId)->update(['enable' => $enable]);
            return $this->successJson();
        } catch (Exception) {
            return $this->errorJson();
        }
    }

    public function del(): Json
    {
        $staffId = input('sid');
        try {
            $this->db->name('staff')->where('id', $staffId)->delete();
            return $this->successJson();
        } catch (Exception) {
            return $this->errorJson();
        }
    }

    public function edit(): Json
    {
        $staffId = input('sid');
        $password = input('password');

        if (empty($password)) {
            return $this->errorJson();
        }

        try {
            $this->db->name('staff')->where('id', $staffId)->update(['password' => md5($password)]);
            return $this->successJson();
        } catch (Exception) {
            return $this->errorJson();
        }
    }
}
