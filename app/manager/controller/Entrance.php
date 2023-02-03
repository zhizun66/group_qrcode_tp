<?php

namespace app\manager\controller;

use app\manager\CommonController;
use think\response\Json;

class Entrance extends CommonController
{
    public function index(): Json
    {
        $company = input('company');
        $remark = input('remark');
        $area = input('area/a') ?? [];
        $tags = input('tags/a');
        $username = input('username');

        $query = $this->db->name('entrance')->alias('e')
            ->leftJoin('buy b', 'b.entrance_id=e.id')
            ->leftJoin('tag t', 'FIND_IN_SET(t.id,e.tags)')
            ->group('e.id');

        if (!empty($area)) {
            array_walk($area, function (&$elem) {
                if ($elem === 'all') {
                    $elem = null;
                }
            });
            if (!empty($area[0])) {
                $query->where('e.province', $area[0]);
            }
            if (!empty($area[1])) {
                $query->where('e.city', $area[1]);
            }
            if (!empty($area[2])) {
                $query->where('e.district', $area[2]);
            }
        }

        if (!empty($company)) {
            $query->whereLike('e.company', "%{$company}%");
        }
        if (!empty($remark)) {
            $query->whereLike('e.remark', "%{$company}%");
        }
        if (!empty($tags)) {
            $where = array_reduce($tags, function ($carry, $tag) {
                return $carry . "FIND_IN_SET({$tag},e.tags) AND ";
            }, '');
            $where = rtrim($where, ' AND');
            $query->whereRaw($where);
        }
        if (!empty($username)) {
            $providerId = $this->db->name('provider')->where('username', $username)->value('id');
            $query->where('provider_id', $providerId);
        }

        $data = $query->whereIn('provider_id', function($q) {
                $q->name('provider')->where('manager_id', $this->manager['id'])->field('id');
            })
            ->order('e.id', 'DESC')
            ->page($this->page, $this->pageSize)
            ->column("e.id,e.qrcode_id,e.name,e.avatar,e.expire_date,e.members,IF(e.source=1,IFNULL(e.im,e.im2),NULL) im,e.hide,e.add_time,e.company,e.remark,e.province,e.city,e.district,GROUP_CONCAT(DISTINCT t.name) tags,e.type,COUNT(DISTINCT b.id) buy_cnt,e.status");

        foreach ($data as &$item) {
            $item['im'] = $item['im'] ? $this->request->domain(true) . '/storage/' . $item['im'] : null;
            $item['tags'] = empty($item['tags']) ? [] : explode(',', $item['tags']);

            $item['area'] = array_filter([$item['province'], $item['city'], $item['district']], function ($elem) {
                return !is_null($elem);
            });
            if (count($item['area']) === 0) {
                $item['area'] = null;
            } elseif (count($item['area']) < 3) {
                $item['area'][] = 'all';
            }

            unset($item['province']);
            unset($item['city']);
            unset($item['district']);
        }

        $total = $query->count();

        return $this->successJson($this->paginate($data, $total));
    }
}