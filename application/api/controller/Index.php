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
        //ini_set('memory_limit', '2560M');
        /*$redisConfig = Config::get('redis');
        p($redisConfig);*/

        /*$createTime = '2019-11-27 11:38:56';//用户创建时间
        $where['dates'] = date('Ymd', strtotime($createTime));
        p($where);*/

        $userModel = new ThirdPartyUserModel();
        $data = $userModel->find()->toArray();
        p($data);


        die('Hi,My world is none');
    }




}
