<?php

namespace app\manager\controller;

use app\manager\CommonController;
use think\facade\Db;
use think\response\Json;

class Qrcode extends CommonController
{
    public function index(): Json
    {
        $company = input('company');
        $tags = input('tags/a');
        $area = input('area/a');
        $remark = input('remark');
        $username = input('username');

        $query = Db::name('qrcode')->alias('q')
            ->leftJoin('tag t', 'FIND_IN_SET(t.id,q.tags)')
            ->whereIn('q.provider_id', function($q) {
                $q->name('provider')->where('manager_id', $this->manager['id'])->field('id');
            })
            ->group('q.id')
            ->page($this->page, $this->pageSize)
            ->fieldRaw("q.id,q.code,q.company,q.province,q.city,q.district,q.remark,q.status,q.sub_status,q.valid,q.valid_time,q.err_msg,GROUP_CONCAT(t.name) tags,q.add_time");

        if (!empty($company)) {
            $query->whereLike('q.company', "%{$company}%");
        }
        if (!empty($remark)) {
            $query->whereLike('q.remark', "%{$remark}%");
        }

        if (!empty($tags)) {
            $where = array_reduce($tags, function ($carry, $tag) {
                return $carry . "FIND_IN_SET({$tag},q.tags) AND ";
            }, '');
            $where = rtrim($where, ' AND');
            $query->whereRaw($where);
        }

        if (!empty($area)) {
            array_walk($area, function (&$elem) {
                if ($elem === 'all') {
                    $elem = null;
                }
            });
            if (!empty($area[0])) {
                $query->where('q.province', $area[0]);
            }
            if (!empty($area[1])) {
                $query->where('q.city', $area[1]);
            }
            if (!empty($area[2])) {
                $query->where('q.district', $area[2]);
            }
        }

        if (!empty($username)) {
            $providerId = $this->db->name('provider')->where('username', $username)->value('id');
            $query->where('provider_id', $providerId);
        }

        $data = $query->select()->toArray();

        foreach ($data as &$item) {
            $item['tags'] = empty($item['tags']) ? [] : explode(',', $item['tags']);

            $item['area'] = array_filter([$item['province'], $item['city'], $item['district']], function ($elem) {
                return !is_null($elem);
            });
            if (count($item['area']) === 0) {
                $item['area'] = null;
            } elseif (count($item['area']) < 3) {
                $item['area'][] = 'all';
            }

            $subData = Db::name('entrance')->alias('e')
                ->where('e.qrcode_id', $item['id'])
                ->where('e.expire_date', '>=', $this->db->raw('NOW()'))
                ->column("e.id,e.avatar,e.name,e.members,e.qr,e.expire_date,e.joinable,e.reported,e.add_time");
            $item['entrance'] = $subData;
        }

        $total = Db::name('qrcode')
            ->whereIn('provider_id', function($q) {
                $q->name('provider')->where('manager_id', $this->manager['id'])->field('id');
            })
            ->count();

        return $this->successJson($this->paginate($data, $total));
    }
}