<?php

namespace app\provider;

use app\BaseController;
use JetBrains\PhpStorm\ArrayShape;
use think\App;

class CommonController extends BaseController
{
    #[ArrayShape(['id' => 'int', 'username' => 'string', 'score' => 'int'])]
    protected array $provider;

    function __construct(App $app)
    {
        parent::__construct($app);

        $provider = session('provider');
        if (empty($provider) || empty($this->db->name('provider')->where(['id' => $provider['id'], 'relogin' => 0])->value('id'))) {
            header('Content-Type: application/json; charset=utf-8');
            echo $this->errorJson(-999, '/login.html?provider')->getContent();
            exit;
        }
        $this->provider = $provider;
    }
}