<?php

namespace lib;

use think\Config;
use think\log;
use Curl\Curl;


class Facebook
{
    private $appid = '';
    private $token = '';
    private function init(){
        $this->appid = Config::get('facebook')['appid'];
        $this->token = Config::get('facebook')['token'];
    }

    // private $appid = '642299609555471';
    // private $token = 'EAAJIKwgRIg8BAJXHZA4jCo3ENxuj2WcCG8O1tKWF8bUpKVZCkODb2lCIGleMl5g1TQ9RJ5wsfThPCBk6QMiHFTpeZCFACvs4hZCNIZBCq6CZC53eZCAxraktVidacmnM9xIQNHPw5AxuLLRovP7ZAmyFJbpOt03UsUkuhU4pnB0ybfRtWeJHsy5zAL0biJkmai7XcyKl4bItgOhA1JDXXYC9vZANrbhJZC5wELrXrKzkaU9gZDZD';
    //购买业务
    public function s2sBuy($advertiserId, $money, $currency, $usersId, $orderNum, $payType)
    {
        Log::write('进入s2s购买业务的获取到订单号='.$orderNum);
        //过滤测试账号
        if( $usersId == 18) {
            return false;
        }
        $appid = $this->appid;
        $token = $this->token;
        $advertiserId = $advertiserId; //用户识别ID
        switch ($payType) {
            case '4':
                $paystring = 'googlePlay';
                break;
            case '5':
                $paystring = 'applePay';
                break;
            default:
                $paystring = 'otherPay';
                break;
        }

        $param = [
            '_eventName' => 'fb_mobile_purchase', //根据事件定义：购买为fb_mobile_purchase
            // 'fb_content_type'=>$desc,//APP内用户ID，订单ID，支付方式
            '_valueToSum' => $money, //"金额"
            'fb_currency' => $currency, //货币代码ISO
            "userId" => $usersId, //APP内用户ID
            "payid" => $orderNum, //订单ID
            "channel" => $paystring, //"googlePlay"    //支付方式
        ];
        Log::write('s2s购买业务的获取到订单号='.$orderNum.',传递的参数信息='.var_export($param, true));
        $url = 'https://graph.facebook.com/v4.0/' . $appid . '/activities?access_token=' . $token;
        Log::write('s2s购买业务的获取到订单号='.$orderNum.',Url='.$url);
        $curl = new Curl();
        $event = json_encode($param);
        $data = [
            'advertiser_id' => $advertiserId
            , 'advertiser_tracking_enabled' => 1
            , 'application_tracking_enabled' => 1
            , 'custom_events' => '[' . $event . ']'
            /*,debug=>all*/
            , 'event' => 'CUSTOM_APP_EVENTS', 'format' => 'json', 'pretty' => 0
            , 'suppress_http_code' => 1
            , 'ransport' => 'cors',
        ];
        Log::write('s2s购买业务的获取到订单号='.$orderNum.',data='.var_export($data, true));
        $ret = $curl->post($url, $data);
        Log::write('s2s购买业务的获取到订单号='.$orderNum.',返回信息='.var_export($ret, true));
        return $ret;

    }

    //订阅业务
    public function s2sSub($advertiserId, $money, $currency, $usersId, $orderNum, $payType)
    {
        Log::write('进入s2s订阅业务的获取到订单号='.$orderNum);
         if($usersId == 18) {
             return false;
         }
        $appid = $this->appid;
        $token = $this->token;
        $advertiser_id = $advertiserId; //用户识别ID
        switch ($payType) {
            case '4':
                $paystring = 'googlePlay';
                break;
            case '5':
                $paystring = 'applePay';
                break;
            default:
                $paystring = 'otherPay';
                break;
        }
        $param = [
            '_eventName' => 'Subscribe', //根据事件定义：购买为fb_mobile_purchase
            // 'fb_content_type'=>$desc,//APP内用户ID，订单ID，支付方式
            '_valueToSum' => $money, //"金额"
            'fb_currency' => $currency, //货币代码ISO
            "userId" => $usersId, //APP内用户ID
            "payid" => $orderNum, //订单ID
            "channel" => $paystring, //"googlePlay"    //支付方式
        ];
        Log::write('s2s订阅业务的获取到订单号='.$orderNum.',传递的参数信息='.var_export($param, true));
        $url = 'https://graph.facebook.com/v4.0/' . $appid . '/activities?access_token=' . $token;
        Log::write('s2s订阅业务的获取到订单号='.$orderNum.',Url='.$url);
        $curl = new Curl();
        $event = json_encode($param);
        $data = [
            'advertiser_id' => $advertiser_id
            , 'advertiser_tracking_enabled' => 1
            , 'application_tracking_enabled' => 1
            , 'custom_events' => '[' . $event . ']'
            /*,debug=>all*/
            , 'event' => 'CUSTOM_APP_EVENTS', 'format' => 'json', 'pretty' => 0
            , 'suppress_http_code' => 1
            , 'ransport' => 'cors',
        ];
        Log::write('s2s订阅业务的获取到订单号='.$orderNum.',data='.var_export($data, true));
        $ret = $curl->post($url, $data);
        Log::write('s2s订阅业务的获取到订单号='.$orderNum.',返回信息='.var_export($ret, true));
        return $ret;

    }

    //注册业务
    public function s2sReg($advertiserId, $usersId, $regType)
    {
        Log::write('进入s2s注册业务的uid='.$usersId);
        if($usersId == 18) {
            return false;
        }
        $appid = $this->appid;
        $token = $this->token;
        $advertiser_id = $advertiserId; //用户识别ID
        $paystring = $regType;
        $param = [
            '_eventName' => 'fb_mobile_complete_registration', //根据事件定义：购买为fb_mobile_purchase
            "userId" => $usersId, //APP内用户ID
            "channel" => $paystring, //"googlePlay"    //支付方式
        ];
        Log::write('s2s注册业务的uid='.$usersId.',传递的参数信息='.var_export($param, true));
        $url = 'https://graph.facebook.com/v4.0/' . $appid . '/activities?access_token=' . $token;
        Log::write('s2s注册业务的uid='.$usersId.',Url='.$url);
        $curl = new Curl();
        $event = json_encode($param);
        $data = [
            'advertiser_id' => $advertiser_id
            , 'advertiser_tracking_enabled' => 1
            , 'application_tracking_enabled' => 1
            , 'custom_events' => '[' . $event . ']'
            , 'event' => 'CUSTOM_APP_EVENTS', 'format' => 'json', 'pretty' => 0
            , 'suppress_http_code' => 1
            , 'ransport' => 'cors',
        ];
        Log::write('s2s注册业务的uid='.$usersId.',data='.var_export($data, true));
        $ret = $curl->post($url, $data);
        Log::write('s2s注册业务的uid='.$usersId.',返回信息='.var_export($ret, true));
        return $ret;
    }
}
