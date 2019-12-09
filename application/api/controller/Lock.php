<?php
namespace app\index\controller;

use think\cache\driver\Redis;
use think\Request;
use think\Db;

/**
 * 锁
 * Class Lock
 * @package app\index\controller
 */
class Lock
{
    //抽奖接口(按照原型图的逻辑编写，不需要分开)
    public function index(Request $request)
    {
        //逻辑判断自己写，下面只是一个关于锁操作的demo
        //事务
        Db::transaction(function () {
            //锁是原子性的操作,而原子是最小单位，当遇到并发，也是按照先后顺序执行的;
            //锁是查询的时加的，因此要操作某些数据,必须先加锁查询
            $data = Db::name('user')->where('id',1)->lock(true)->find();
            //然后再做用户增、改的操作........
        });
    }


}
