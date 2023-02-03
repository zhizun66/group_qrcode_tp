<?php

namespace app\manager\controller;

use app\manager\CommonController;
use Exception;
use think\response\Json;

class Exchange extends CommonController
{
    public function exchange(): Json
    {
        $username = input('username');
        $quantity = input('quantity/d');

        if (empty($username) || empty($quantity)) {
            return $this->errorJson();
        }

        if ($username === $this->manager['username']) {
            return $this->errorJson(5, '无法与自己交换群码');
        }

        $toManagerId = $this->db->name('manager')->where('username', $username)->value('id');
        if (empty($toManagerId)) {
            return $this->errorJson(2, '用户名不存在');
        }

        $arrFrom = $this->db->name('entrance')
            ->whereIn('provider_id', function($query) {
                $query->name('provider')->where('manager_id', $this->manager['id'])->field('id');
            })
            ->whereNotIn('id', function($query) use($toManagerId) {
                $query->name('exchange')->where(['from_manager_id' => $this->manager['id'], 'to_manager_id' => $toManagerId])->field('entrance_id');
            })
            ->order('id', 'desc')
            ->limit(0, $quantity)
            ->column('id');
        if ($quantity > count($arrFrom)) {
            return $this->errorJson(1, '我方群码数量不足');
        }

        $arrTo = $this->db->name('entrance')
            ->whereIn('provider_id', function($query) use($toManagerId) {
                $query->name('provider')->where('manager_id', $toManagerId)->field('id');
            })
            ->whereNotIn('id', function($query) use($toManagerId) {
                $query->name('exchange')->where(['to_manager_id' => $this->manager['id'], 'from_manager_id' => $toManagerId])->field('entrance_id');
            })
            ->order('id', 'desc')
            ->limit(0, $quantity)
            ->column('id');
        if ($quantity > count($arrTo)) {
            return $this->errorJson(1, '对方群码数量不足');
        }

        $this->db->startTrans();
        try {
            for ($i = 0; $i < $quantity; $i++) {
                if (!empty($this->db->name('exchange')->where(['to_manager_id' => $toManagerId, 'entrance_id' => $arrFrom[$i]])->value('id'))) {
                    $this->db->rollback();
                    return $this->errorJson(3, '这些群码已经交换过');
                }
                $this->db->name('exchange')->insert([
                    'from_manager_id' => $this->manager['id'],
                    'to_manager_id' => $toManagerId,
                    'entrance_id' => $arrFrom[$i],
                ]);
                $this->db->name('exchange')->insert([
                    'from_manager_id' => $toManagerId,
                    'to_manager_id' => $this->manager['id'],
                    'entrance_id' => $arrTo[$i],
                ]);
            }
            $this->db->commit();
            return $this->successJson();
        } catch (Exception $e) {
            return $this->errorJson(4, $e->getMessage());
        }
    }

    public function index(): Json
    {
        $company = input('company');
        $remark = input('remark');
        $area = input('area/a') ?? [];
        $tags = input('tags/a');
        $username = input('username');

        $query = $this->db->name('exchange')->alias('ex')
            ->leftJoin('entrance e', 'e.id=ex.entrance_id')
            ->leftJoin('manager m', 'm.id=ex.from_manager_id')
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

        $data = $query
            ->where('ex.to_manager_id', $this->manager['id'])
            ->order('e.id', 'DESC')
            ->page($this->page, $this->pageSize)
            ->column("e.id,e.qrcode_id,e.name,e.avatar,e.expire_date,e.members,IF(e.source=1,IFNULL(e.im,e.im2),NULL) im,e.hide,e.add_time,e.company,e.remark,e.province,e.city,e.district,GROUP_CONCAT(DISTINCT t.name) tags,e.type,COUNT(DISTINCT b.id) buy_cnt,e.status,MAX(m.username) from_username");

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