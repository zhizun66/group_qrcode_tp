<?php

namespace app\manager;

use app\BaseController;
use JetBrains\PhpStorm\ArrayShape;
use think\App;

class CommonController extends BaseController
{
    #[ArrayShape(['id' => 'int', 'username' => 'string'])]
    protected array $manager;

    function __construct(App $app)
    {
        parent::__construct($app);

        $manager = session('manager');
        if (empty($manager) || empty($this->db->name('manager')->where(['id' => $manager['id'], 'relogin' => 0])->value('id'))) {
            header('Content-Type: application/json; charset=utf-8');
            echo $this->errorJson(-999, '/login.html?manager')->getContent();
            exit;
        }
        $this->manager = $manager;
    }
}