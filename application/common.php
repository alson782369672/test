<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: 流年 <liu21st@gmail.com>
// +----------------------------------------------------------------------
use think\Config;
use think\cache\driver\Redis;


// 应用公共文件
//返回信息
function returnMsg($msg = '', $code = 1, $data = []) {
    $result = [
        "code" => $code,
        "msg" => $msg,
        "result" =>  $data
    ];
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    die;
}

//打印数组
function p($arr = []) {
    print_r($arr);
}

function redisServiceInfo() {
    $redisConfig = Config::get('redis');
    //实例化redis
    $redis = new Redis($redisConfig);
    return $redis;
}

//入列
function lPush($key = '', $data = []) {
    if(empty($key) || empty($data)) return false;
    $redis = redisServiceInfo();
    //设置键
    //$key = 'aikan_google_pay_backcall_1';
    //获取参数
    $pramarsJson = json_encode($data);
    //入列
    $redis->lpush($key, $pramarsJson);
}

/** 对象转数组
 * @param $object
 * @return mixed
 */
function objectToArray($object) {
    if(empty($object)) return [];
    //先编码成json字符串，再解码成数组
    return json_decode(json_encode($object), true);
}

function writeLog($log) {
    file_put_contents(dirname(__FILE__).'/log.txt', $log, FILE_APPEND);
}