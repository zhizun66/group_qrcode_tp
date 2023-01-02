<?php

namespace app\api;

use app\BaseController;
use think\App;

class CommonController extends BaseController
{
    public function __construct(App $app)
    {
        parent::__construct($app);

        $apiKey = input('key');
        if (!$this->db->name('setting')->where(['key' => 'api_key', 'str_val' => $apiKey])->value('key')) {
            header('Content-Type: application/json; charset=utf-8');
            echo $this->errorJson(-999, 'API秘钥错误')->getContent();
            exit;
        }
    }
}