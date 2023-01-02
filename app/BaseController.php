<?php

declare(strict_types=1);

namespace app;

use JetBrains\PhpStorm\ArrayShape;
use think\App;
use think\exception\ValidateException;
use think\Db;
use think\response\Json;
use think\Validate;

/**
 * 控制器基础类
 */
abstract class BaseController
{
  /**
   * Request实例
   * @var \think\Request
   */
  protected $request;

  /**
   * 应用实例
   * @var \think\App
   */
  protected $app;

  /**
   * 是否批量验证
   * @var bool
   */
  protected $batchValidate = false;

  /**
   * 控制器中间件
   * @var array
   */
  protected $middleware = [];

  protected int $pageSize = 20;
  protected int $page = 1;
  protected Db $db;

  /**
   * 构造方法
   * @access public
   * @param  App  $app  应用对象
   */
  public function __construct(App $app)
  {
    $this->app     = $app;
    $this->request = $this->app->request;
    $this->db      = $app->db;

    $this->page = max(input('page/d', 1), 1);

    // 控制器初始化
    $this->initialize();
  }

  // 初始化
  protected function initialize()
  {
  }

  /**
   * 验证数据
   * @access protected
   * @param  array        $data     数据
   * @param  string|array $validate 验证器名或者验证规则数组
   * @param  array        $message  提示信息
   * @param  bool         $batch    是否批量验证
   * @return array|string|true
   * @throws ValidateException
   */
  protected function validate(array $data, $validate, array $message = [], bool $batch = false)
  {
    if (is_array($validate)) {
      $v = new Validate();
      $v->rule($validate);
    } else {
      if (strpos($validate, '.')) {
        // 支持场景
        [$validate, $scene] = explode('.', $validate);
      }
      $class = false !== strpos($validate, '\\') ? $validate : $this->app->parseClass('validate', $validate);
      $v     = new $class();
      if (!empty($scene)) {
        $v->scene($scene);
      }
    }

    $v->message($message);

    // 是否批量验证
    if ($batch || $this->batchValidate) {
      $v->batch(true);
    }

    return $v->failException(true)->check($data);
  }

  /**
   * 成功时返回json
   * @param array|null $data
   * @param string $message
   * @return Json
   */
  public function successJson(array $data = null, string $message = '操作成功'): Json
  {
    $res = ['code' => 0, 'message' => $message];
    if ($data !== null) {
      $res['data'] = $data;
    }
    return json($res);
  }

  /**
   * 失败时返回json
   * @param int $code
   * @param string $message
   * @return Json
   */
  public function errorJson(int $code = -1, string $message = '操作失败'): Json
  {
    return json(['code' => $code, 'message' => $message]);
  }

  /**
   * 数据分页格式化
   * @param array $data 当前页数据
   * @param int $total 总行数
   * @return array
   */
  public function paginate(array $data, int $total): array
  {
    return [
      'page' => $this->page,
      'page_size' => $this->pageSize,
      'total' => $total,
      'data' => $data
    ];
  }

  /**
   * 断言参数不为空
   * @param ...$params
   * @return void
   */
  public function assertNotEmpty(...$params): void
  {
    foreach ($params as $p) {
      if (empty($p)) {
        header('Content-Type: application/json; charset=utf-8');
        echo self::errorJson(-99, '参数错误')->getContent();
        exit;
      }
    }
  }

  /**
   * 断言参数不为NULL
   * @param ...$params
   * @return void
   */
  public function assertNotNull(...$params): void
  {
    foreach ($params as $p) {
      if (is_null($p)) {
        header('Content-Type: application/json; charset=utf-8');
        echo self::errorJson(-99, '参数错误')->getContent();
        exit;
      }
    }
  }

  /**
   * 将空字符串替换成null
   * @param ...$params
   * @return void
   */
  public function setEmptyStringToNull(&...$params): void
  {
    foreach ($params as &$p) {
      $p = empty($p) ? null : $p;
    }
  }

  /**
   * 获取系统设置
   * @param string $key 设置名称
   * @param bool $isInt 值类型是否为整数
   * @return string|int
   */
  public function getSysSetting(string $key, bool $isInt = true): string|int
  {
    return $this->db->name('setting')->where('key', $key)->value($isInt ? 'int_val' : 'str_val');
  }

  /**
   * 根据标签获取群码价格
   * @param string $key 设置名称
   * @param bool $isInt 值类型是否为整数
   * @return string|int
   */
  public function getTagsPrice(string $tags): int
  {

    $tags_id = empty($tags) ? [] : explode(',', $tags);
    $price = $this->db->name('tag')->whereIn('id', $tags_id)->max('price');
    // var_dump('价格' . $price);
    return intval($price);
  }
}
