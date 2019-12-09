<?php
namespace app\index\controller;

use think\cache\driver\Redis;
use think\Request;

/**
 * 队列
 * Class Queue
 * @package app\index\controller
 */
class Queue
{
    //抽奖接口
    public function index(Request $request)
    {
        //频道标识可以前端传递过来,确保唯一性
        $channel = $request->channel;
        //实例化redis
        $redis = new Redis();
        //设定并发数
        $num = 10;
        //键
        $key = 'tp_check_list_info';
        //当前队列长度
        $len = $redis->Llen($key);
        //大于设定并发数,终止程序执行
        if($len >= $num) die('哎呦喂, 活动太火爆啦, 请稍后参与!!!');
        //设定ID值
        $id = rand(1, 9999999);
        //入列(从下到上)
        $redis->lpush($key, $id);
        //订阅频道
        $redis->psubscribe($channel);
    }

    //异步处理队列---实时执行
    public function asyn() {
        $redis = new Redis();
        //键
        $key = 'tp_check_list_info';
        //当前队列长度
        $len = $redis->Llen($key);
        //循环队列
        for($i=0; $i<$len; $i++) {
            //获取头部信息
            $data = $redis->lrange($key);

            //用mysql事务执行中奖的相关操作


            //出列(从头部出)
            $redis->lpop($key);
        }
        echo 'ok';
    }

    /**
     * 获取用户中奖信息
     * @param Request $request
     */
    public function getAjaxInfo(Request $request) {
        $channel = $request->channel;
        //获取该用户中奖信息,发布消息指定频道
        //自己查数据库
        $data = [];

        $redis = new Redis();

        //发布消息到指定频道
        $redis->subscribe($channel, $data);

    }
}
