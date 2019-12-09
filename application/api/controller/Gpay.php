<?php
namespace app\api\controller;

use think\cache\driver\Redis;
use think\Request;
use think\Db;
use think\Log;
use think\Config;
use app\api\logic\PayLogic;

/**
 * 谷歌支付回调
 * Class Lock
 * @package app\index\controller
 */
class Gpay
{
    //谷歌支付回调(代充和金币)
    public function pay(Request $request)
    {
        Log::write(get_called_class() . '::' . __FUNCTION__ . ': 进入谷歌支付回调(代充和金币), 业务参数='. var_export($_REQUEST, true));
        try{
        if(!Request::instance()->isPost()) {
            returnMsg('非法操作！！！', 6001);
        }
        //获取头部信息
        $header = Request::instance()->header();
        //验证凭证
        $purToken = input('purtoken', '');
        //本系统商品ID
        $syntony = input('applepayId', '');
        //类型
        /*$type = input('type', 0);
        //章节ID
        $sectionId = input('section_id', 0);
        //书本ID
        $bookId = input('book_id', 0);*/
        //本系统订单号
        $orderNum = input('order_num', '');
        //谷歌订单号
        $tradeNum = $thirdpayid = input('thirdpayid', '');
        //回调时间
        $ptime = date('Y-m-d H:i:s');

        if(empty($syntony) || empty($orderNum) || empty($tradeNum)) {
            Log::write(get_called_class() . '::' . __FUNCTION__ . ': order_num='. $orderNum.',applepayId='.$syntony.',thirdpayid='.$tradeNum);
            returnMsg('必传参数不能为空', 6002);
        }

        //请求的业务参数
        $respond = $this->getPayBusParame($header, input());

        //实例化
        $payLogic = new PayLogic();
        //返回金币数
        $remainder = $payLogic->dealRecharge($respond, $orderNum, $tradeNum, $syntony, $ptime, $header);

        returnMsg('回调成功', 1, [
            'remainder' => $remainder
        ]);

        }catch (\Exception $e) {
            Log::write(get_called_class() . '::' . __FUNCTION__ . ': 异常信息 = ' . $e->getMessage());
        }

    }


    //本支付回调业务涉及VIP充值
    public function sub(Request $request)
    {
        Log::write(get_called_class() . '::' . __FUNCTION__ . ': 进入谷歌支付回调(VIP), 业务参数='. var_export($request, true));
        try{
            if(!Request::instance()->isPost()) {
                returnMsg('非法操作！！！', 6001);
            }
            //获取头部信息
            $header = Request::instance()->header();
            //本系统订单号
            $orderNum = input('ordernum', '');
            //验证凭证
            $syntony =  input('purtoken', '');
            //商品号
            $productId = input('product_id', '');
            //谷歌订单号
            $tradeNum = input('thirdpayid', '');

            if(empty($orderNum) || empty($syntony) || empty($productId) || empty($tradeNum)) {
                Log::write(get_called_class() . '::' . __FUNCTION__ . ': ordernum='. $orderNum.',purtoken='.$syntony.',product_id='.$productId.',thirdpayid='.$tradeNum);
                returnMsg('必传参数不能为空', 6002);
            }

            //请求的业务参数
            $respond = $this->getVipBusParame($header, input());

            //实例化
            $payLogic = new PayLogic();
            //返回信息
            $result = $payLogic->vipRecharge($respond, $orderNum, $tradeNum, $productId);

            returnMsg('回调成功', 1, $result);

        }catch (\Exception $e) {
            Log::write(get_called_class() . '::' . __FUNCTION__ . ': 异常信息 = ' . $e->getMessage());
        }

    }

    /**
     * 支付传递的业务参数(代充和加金币)
     * @param array $header
     * @param array $post
     * @return array
     */
    public function getPayBusParame($header = [], $post = []) {
        return [
            'header' => $header,
            'parame' => [
                'purtoken' => $post['purtoken'],
                'applepayId' => $post['applepayId'],
                'order_num' => $post['order_num'],
                'thirdpayid' => $post['thirdpayid'],
            ]
        ];
    }

    /**
     * 支付传递的业务参数(VIP)
     * @param array $header
     * @param array $post
     * @return array
     */
    public function getVipBusParame($header = [], $post = []) {
        return [
            'header' => $header,
            'parame' => [
                'order_num' => $post['ordernum'],
                'purtoken' => $post['purtoken'],
                'product_id' => $post['product_id'],
                'thirdpayid' => $post['thirdpayid'],
            ]
        ];
    }
}
