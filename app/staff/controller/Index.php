<?php
declare (strict_types = 1);

namespace app\staff\controller;

use app\staff\CommonController;
use Exception;
use think\facade\Filesystem;
use think\response\Json;

class Index extends CommonController
{
    public function index(): Json
    {
        $info = $this->db->name('staff')->where('id', $this->staff['id'])->field('username,name')->findOrEmpty();
        return $this->successJson($info);
    }

    public function tags(): Json
    {
        $data = $this->db->name('tag')->order('id')->column('id,name');
        return $this->successJson($data);
    }

    public function repwd(): Json
    {
        $password = input('post.password');
        if (empty($password)) {
            return $this->errorJson();
        }

        try {
            $this->db->name('staff')->where('id', $this->staff['id'])->update(['password' => md5($password)]);
            return $this->successJson();
        } catch (Exception) {
            return $this->errorJson();
        }
    }

    public function entrance(): Json
    {
        $company = input('company');
        $remark = input('remark');
        $area = input('area/a') ?? [];
        $tags = input('tags/a');

        $query = $this->db->name('entrance')->alias('e')
            ->leftJoin('tag t', 'FIND_IN_SET(t.id,e.tags)')
            ->group('e.id');

        if (!empty($area)) {
            array_walk($area, function(&$elem) {
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
            $where = array_reduce($tags, function($carry, $tag) {
                return $carry . "FIND_IN_SET({$tag},e.tags) AND ";
            }, '');
            $where = rtrim($where, ' AND');
            $query->whereRaw($where);
        }

        $data = $query->where('staff_id', $this->staff['id'])
            ->order('e.id', 'DESC')
            ->page($this->page, $this->pageSize)
            ->column("e.id,e.name,e.avatar,e.expire_date,e.members,e.qr,e.im,e.price,e.hide,e.add_time,e.company,e.remark,e.province,e.city,e.district,GROUP_CONCAT(t.name) tags,e.type");

        foreach ($data as &$item) {
            $item['im'] = $this->request->domain(true) . '/storage/' . $item['im'];
            $item['tags'] = empty($item['tags']) ? [] : explode(',', $item['tags']);

            $item['area'] = array_filter([$item['province'], $item['city'], $item['district']], function($elem) {
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

    public function upload(): Json
    {
        $uid = input('uid');
        $tags = input('tags');
        $company = input('company');
        $area = input('area') ?? [];
        $name = input('name');
        $remark = input('remark');
        $members = input('members');
        $expire = input('expire');
        $price = input('price/d', 1);
        $type = min(max(input('type/d', 1), 1), 2);

        $area = empty($area) ? [] : explode(',', $area);
        $area = array_map(function($item) {
            return $item === 'all' ? null : $item;
        }, $area);

        $tags = empty($tags) ? [] : explode(',', $tags);
        $tags = array_unique($tags);

        $uidArr = explode(',', $uid);

        $qrArr = array_map(function($uid) {
            return input('decode_' . $uid);
        }, $uidArr);

        // ????????????????????????
        if (count(array_unique($qrArr)) < count($qrArr)) {
            return $this->errorJson(1, '??????????????????');
        }

        // ????????????????????????
        if ($this->db->name('entrance')->whereIn('qr', $qrArr)->value('id')) {
            return $this->errorJson(2, '???????????????');
        }

        try {
            $entranceData = array_map(function($uid) use($name, $members, $expire, $price, $company, $area, $remark, $tags, $type) {
                $file = $this->request->file('qrcode_' . $uid);
                $saveName = Filesystem::disk('public')->putFile('entrance', $file);
                return [
                    'staff_id'      => $this->staff['id'],
                    'name'          => empty($name) ? null : $name,
                    'members'       => empty($members) ? null : $members,
                    'qr'            => input('decode_' . $uid, ''),
                    'im'            => $saveName,
                    'expire_date'   => empty($expire) ? null : $expire,
                    'price'         => $price,
                    'company'       => empty($company) ? null : $company,
                    'province'      => $area[0] ?? null,
                    'city'          => $area[1] ?? null,
                    'district'      => $area[2] ?? null,
                    'remark'        => empty($remark) ? null : $remark,
                    'tags'          => implode(',', $tags),
                    'type'          => $type
                ];
            }, $uidArr);
            $this->db->name('entrance')->insertAll($entranceData);
            return $this->successJson();
        } catch (Exception $e) {
            return $this->errorJson(-1, $e->getMessage());
        }
    }

    public function itemDel(): Json
    {
        $entranceId = input('eid/d');
        if (empty($entranceId)) {
            return $this->errorJson(-99, '????????????');
        }

        if ($this->db->name('buy')->where('entrance_id', $entranceId)->value('id')) {
            return $this->errorJson(1, '??????????????????????????????????????????');
        }

        try {
            $this->db->name('entrance')->where(['id' => $entranceId, 'staff_id' => $this->staff['id']])->delete();
            return $this->successJson();
        } catch (Exception) {
            return $this->errorJson();
        }
    }

    public function itemHide(): Json
    {
        $entranceId = input('eid/d');
        $hide = input('hide/d');
        if (empty($entranceId)) {
            return $this->errorJson(-99, '????????????');
        }
        if ($hide !== 0 && $hide !== 1) {
            $hide = 0;
        }

        try {
            $this->db->name('entrance')->where(['id' => $entranceId, 'staff_id' => $this->staff['id']])->update(['hide' => $hide]);
            return $this->successJson();
        } catch (Exception) {
            return $this->errorJson();
        }
    }

    public function itemEdit(): Json
    {
        $entranceId = input('eid/d');
        $name = input('name');
        $members = input('members/d');
        $expire = input('expire');
        $company = input('company');
        $area = input('area/a') ?? [];
        $remark = input('remark');

        $area = array_map(function($item) {
            return $item === 'all' ? null : $item;
        }, $area);

        if (!$this->db->name('entrance')->where(['id' => $entranceId, 'staff_id' => $this->staff['id']])->value('id')) {
            return $this->errorJson();
        }

        try {
            $this->db->name('entrance')
                ->where(['id' => $entranceId, 'staff_id' => $this->staff['id']])
                ->update([
                    'name'          => empty($name) ? null : $name,
                    'members'       => empty($members) ? null :$members,
                    'expire_date'   => empty($expire) ? null : $expire,
                    'company'       => empty($company) ? null : $company,
                    'remark'        => empty($remark) ? null : $remark,
                    'province'      => $area[0] ?? null,
                    'city'          => $area[1] ?? null,
                    'district'      => $area[2] ?? null
                ]);
            return $this->successJson();
        } catch (Exception $e) {
            return $this->errorJson(-1, $e->getMessage());
        }
    }
}
