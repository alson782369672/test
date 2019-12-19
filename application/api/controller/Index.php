<?php
namespace app\api\controller;

use think\cache\driver\Redis;
use think\Request;
use think\Db;
use think\Config;
use think\Log;
use app\api\logic\PayLogic;
use app\api\model\ThirdPartyUserModel;

/**
 * 锁
 * Class Lock
 * @package app\index\controller
 */
class Index
{

    //抽奖接口(按照原型图的逻辑编写，不需要分开)
    public function index(Request $request)
    {
        $userModel = new ThirdPartyUserModel();
        $data = $userModel->find()->toArray();
        p($data);

    }


    public function ajaxList() {

    }




}
