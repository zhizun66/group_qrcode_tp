<?php
declare (strict_types = 1);

namespace app\index;

use app\BaseController;
use JetBrains\PhpStorm\ArrayShape;
use think\App;

class CommonController extends BaseController
{
    #[ArrayShape(['id' => 'int', 'username' => 'string', 'balance' => 'int'])]
    protected array $user;

    function __construct(App $app)
    {
        parent::__construct($app);

        $user = session('user');
        if (empty($user) || empty($this->db->name('user')->where(['id' => $user['id'], 'relogin' => 0])->value('id'))) {
            header('Content-Type: application/json; charset=utf-8');
            echo $this->errorJson(-999, '/login.html')->getContent();
            exit;
        }
        $this->user = $user;
    }
}