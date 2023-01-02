<?php

namespace app\admin\controller;

use app\admin\CommonController;
use Exception;
use think\response\Json;

class Tag extends CommonController
{
  public function index(): Json
  {
    $data = $this->db->name('tag')->order('id', 'desc')->page($this->page, $this->pageSize)->column('id,name,price');
    $total = $this->db->name('tag')->count();
    return $this->successJson($this->paginate($data, $total));
  }

  public function edit(): Json
  {
    $tagId = input('id');
    $name = input('name');

    $this->assertNotEmpty($tagId, $name);

    try {
      $this->db->name('tag')->where('id', $tagId)->update(['name' => $name]);
      return $this->successJson();
    } catch (Exception) {
      return $this->errorJson();
    }
  }

  public function del(): Json
  {
    $tagId = input('id');
    $this->assertNotEmpty($tagId);
    $tagId = addslashes($tagId);

    $this->db->startTrans();
    try {
      $rawSql = $this->db->raw("TRIM(',' FROM REPLACE(CONCAT(',', tags, ','), CONCAT(',', {$tagId}, ','), ','))");
      $this->db->name('qrcode')->whereRaw('FIND_IN_SET(:id,`tags`)', ['id' => $tagId])->update(['tags' => $rawSql]);
      $this->db->name('entrance')->whereRaw('FIND_IN_SET(:id,`tags`)', ['id' => $tagId])->update(['tags' => $rawSql]);
      $this->db->name('tag')->where('id', $tagId)->delete();
      $this->db->commit();
      return $this->successJson();
    } catch (Exception) {
      $this->db->rollback();
      return $this->errorJson();
    }
  }

  public function add(): Json
  {
    $name = input('name');
    $this->assertNotEmpty($name);

    if ($this->db->name('tag')->where('name', $name)->value('id')) {
      return $this->errorJson(1, '该标签名称已存在');
    }

    try {
      $this->db->name('tag')->insert(['name' => $name]);
      return $this->successJson();
    } catch (Exception) {
      return $this->errorJson();
    }
  }
}
