<?php
// +----------------------------------------------------------------------
// | ThinkCMF [ WE CAN DO IT MORE SIMPLE ]
// +----------------------------------------------------------------------
// | Copyright (c) 2013-2018 http://www.thinkcmf.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: 老猫 <thinkcmf@126.com>
// +----------------------------------------------------------------------
namespace app\api\logic;

use think\Db;
use think\Log;
use think\Lang;
use app\api\model\RechargeModel;
use app\api\model\OrderModel;
use app\api\model\ThirdPartyUserModel;
use app\api\model\UActionLogModel;
use app\api\model\UserAgentModel;
use app\api\model\TaskRewardCountModel;
use app\api\model\ChargeModel;
use app\api\model\UsersIconDetailModel;
use app\api\model\NDayRechargeModel;
use app\api\model\NDayTaskModel;
use app\api\model\NDayBookModel;
use app\api\model\BookModel;
use app\api\model\CartoonModel;
use app\api\model\BookOtherModel;
use app\api\model\CartoonOtherModel;
use app\api\model\NDoTaskModel;
use app\api\model\NCountModel;
use app\api\model\NCoinDetailModel;
use app\api\model\PayAutoScriptModel;
use app\api\model\PayAutoScriptLogModel;
use lib\Facebook;
use think\cache\driver\Redis;


class PayLogic
{
    //vip时间
    protected $productTime = [
        'com_vip_half_year' => 6,
        'com_vip_one_year' => 12
    ];

    //支付类型
    protected $payType = [
         4 => 'android',
         5 => 'iphone',
         6 => 'wap',
    ];

    /**
     * 处理业务参数(代充和加金币)
     * @param string $orderId
     * @param string $tradeNum
     * @param string $syntony
     * @param int $ptime
     */
    public function dealRecharge($request = [], $orderId = '', $tradeNum = '', $syntony = '', $ptime = 0, $header = []) {
        try{
            log::write(get_called_class() . '::' . __FUNCTION__ . '进入支付回调, 客户端传递的业务参数：orderId='.$orderId.',tradeNum='. $tradeNum.', syntony='.$syntony);
            if(empty($orderId) || empty($tradeNum) || empty($syntony) || empty($ptime)) {
                returnMsg('请求参数不能为空', 6002);
            }

            // 启动事务
            Db::startTrans();
            //获取订单信息
            $orderModel = new OrderModel();
            $where['order_num'] = $orderId;
            $orderInfo = objectToArray($orderModel->where($where)->lock(true)->find());//加锁
            Log::write(get_called_class() . '::' . __FUNCTION__ . '订单号='.$orderId.',订单信息='.var_export($orderInfo, true));
            if(empty($orderInfo)){
                Log::write(get_called_class() . '::' . __FUNCTION__ . '订单号='.$orderId.',订单不存在');
                Db::rollback();
                returnMsg('订单不存在', 6003);
            }
            if($orderInfo['pay_status'] != 0) {
                Log::write(get_called_class() . '::' . __FUNCTION__ . '订单号='.$orderId.',订单不是待支付状态,不能操作');
                Db::rollback();
                returnMsg('订单不是待支付状态,不能操作', 6004);
            }

            //订单用户和商品ID
            $uid = $orderInfo['users_id'] ? (int)$orderInfo['users_id'] : 0;
            $pid = $orderInfo['create_syntony'] ? $orderInfo['create_syntony'] : '';
            if(empty($uid) || empty($pid))  {
                Log::write(get_called_class() . '::' . __FUNCTION__ . '订单号='.$orderId.'的订单用户和商品ID为空');
                Db::rollback();
                returnMsg('订单用户或商品ID为空', 6005);
            }

            //校验首充
            $firstWhere = ['users_id' => $uid, 'pay_status' => 1];
            $firstInfo = objectToArray($orderModel->where($firstWhere)->find());
            Log::write(get_called_class() . '::' . __FUNCTION__ . '订单号='.$orderId.',校验首充信息='.var_export($firstInfo, true));
            //实例化RechargeModel
            $rechargeModel = new RechargeModel();
            $rechargeWhere['applepayId'] = $pid;//支持Id
            $recharge = $rechargeModel->getRechargeInfo($rechargeWhere);
            Log::write(get_called_class() . '::' . __FUNCTION__ . '订单号='.$orderId.',根据支付ID获取信息='.var_export($recharge, true));
            if ($firstInfo) {
                //充值过的订单
                $rechargeWhere['isfrist'] = 0;
                $recharge = $rechargeModel->getRechargeInfo($rechargeWhere);
            }

            if (!$recharge) {
                Log::write(get_called_class() . '::' . __FUNCTION__ . '订单号='.$orderId.',购买商品不存在或者已下架');
                Db::rollback();
                returnMsg(get_called_class() . '::' . __FUNCTION__ . '购买商品不存在或者已下架', 6006);
            }

            //获取用户信息
            $userModel = new ThirdPartyUserModel();
            $userWhere = ['id' => $uid];
            $userInfo = $userModel->getUserInfo($userWhere);
            Log::write(get_called_class() . '::' . __FUNCTION__ . '订单号='.$orderId.",获取用户信息=".var_export($userInfo, true));
            if(empty($userInfo)) {
                Log::write(get_called_class() . '::' . __FUNCTION__ . '订单号='.$orderId.",用户信息不存在");
                Db::rollback();
                returnMsg('用户信息不存在', 6007);
            }

            //添加充值记录
            $flag = $this->addUserLog($header, $uid, $recharge['recharge_price'], $tradeNum, $orderId, $ptime, 5, $pid, $syntony);
            if(!$flag) {
                Log::write(get_called_class() . '::' . __FUNCTION__ . '订单号='.$orderId.",添加充值记录失败1");
                Db::rollback();
                returnMsg('添加充值记录失败', 6008);
            }
            Log::write(get_called_class() . '::' . __FUNCTION__ . '获取到订单号='.$orderId.',添加充值记录成功');

            //判断是否代充订单
            $sendCoin = 0;//用户充值记录赠送金币
            $payUid = $orderInfo['proxy_id'];//代充充值人ID
            if ($payUid > 0) {
                Log::write(get_called_class() . '::' . __FUNCTION__ . '获取到订单号='.$orderId.',进入代充');
                $cztype = 3; //类型 1充值 2人工送 3好友代充
                //代充记录
                $payCoin = $recharge['dummy_icon'] * 0.2;
                Log::write(get_called_class() . '::' . __FUNCTION__ . '获取到订单号='.$orderId.',进入代充支付记录');
                $deviceType = isset($header['devicetype']) ? $header['devicetype'] : 'android';
                $flag = $this->agentPayLog($deviceType, $payUid, $uid, $recharge['recharge_price'], $payCoin);
                if(!$flag) {
                    Log::write(get_called_class() . '::' . __FUNCTION__ . '订单号='.$orderId.",添加代充记录失败2");
                    Db::rollback();
                    returnMsg('添加代充记录失败', 6009);
                }

                //代充充值金币
                $flag = $this->change($payUid, $payCoin);
                if(!$flag) {
                    Log::write(get_called_class() . '::' . __FUNCTION__ . '订单号='.$orderId.",代充充值金币失败");
                    Db::rollback();
                    returnMsg('代充充值金币失败', 6010);
                }

                //任务奖励统计
                $flag = $this->taskRewardCount($uid, $payCoin, 4);
                if(!$flag) {
                    Log::write(get_called_class() . '::' . __FUNCTION__ . '订单号='.$orderId.',代充任务奖励统计失败');
                    Db::rollback();
                    returnMsg('代充任务奖励统计失败', 6011);
                }

                //每日任务(统计不做事务处理)
                $this->everydayTask($uid, 4, $payCoin);
                Log::write('订单号='.$orderId.',到达每日任务底部');

            }else {
                $sendCoin = $recharge['first_send'];
                $cztype = 1; //类型 1充值 2人工送 3好友代充
                Log::write(get_called_class() . '::' . __FUNCTION__ . '订单号='.$orderId.',进入非代充');

                if ($userInfo['invite_id']) {
                    Log::write(get_called_class() . '::' . __FUNCTION__ . '订单号='.$orderId.',进入用户邀请');
                    //给邀请者加入
                    $flag = $this->divided($uid, $userInfo['invite_id'], $recharge['dummy_icon']);
                    if(!$flag) {
                        Log::write(get_called_class() . '::' . __FUNCTION__ . '订单号='.$orderId.',非代充邀请人添加下属充值奖励失败');
                        Db::rollback();
                        returnMsg('非代充邀请人添加下属充值奖励失败', 6012);
                    }
                }
            }

            Log::write(get_called_class() . '::' . __FUNCTION__ . '订单号='.$orderId.',到操作日志');
            $payUserid = $payUid ? $payUid : $orderInfo['users_id'];
            $flag = $this->chargeLog($payUserid, $cztype, $recharge['recharge_price'], $recharge['dummy_icon'], $sendCoin);
            if(!$flag) {
                Log::write(get_called_class() . '::' . __FUNCTION__ . '订单号='.$orderId.',chargeLog日志操作失败');
                Db::rollback();
                returnMsg('chargeLog日志操作失败', 6013);
            }

            //加金币明细操作
            $flag = $this->addCoinLog($uid, $orderId, $recharge['dummy_icon'] + $sendCoin);

            //更新用户余额和明细
            $flag = $this->rechargeUserRecode($uid, $recharge['dummy_icon'] + $sendCoin, $recharge['recharge_price']);
            if(!$flag) {
                Log::write(get_called_class() . '::' . __FUNCTION__ . '订单号='.$orderId.',更新用户余额和明细失败');
                Db::rollback();
                returnMsg('更新用户余额和明细失败', 6014);
            }

            //返回是否首次充值
            $addCoinStatus = $flag ? 1 : 2;//是否加金币状态
            $flag = $this->sure($orderId, $uid, $tradeNum, $ptime, $pid, $syntony, $addCoinStatus);
            if(!$flag) {
                Log::write(get_called_class() . '::' . __FUNCTION__ . '订单号='.$orderId.',更新订单信息失败');
                Db::rollback();
                returnMsg('更新订单信息失败', 6015);
            }

            //统计(统计不做事务处理)
            //今日统计
            $flag = $this->dayRecharge($userInfo, $recharge['recharge_price'], $orderInfo['pay_type'], $orderInfo['plat']);
            Log::write(get_called_class() . '::' . __FUNCTION__ . '进入订单号='.$orderId.',今日统计');

            //书籍漫画统计
            Log::write(get_called_class() . '::' . __FUNCTION__ . '进入订单号='.$orderId.',书籍漫画购买统计');
            $type = $orderInfo['book_id'] ? 1 : 2;
            $bookid = $orderInfo['book_id'] ? $orderInfo['book_id'] : $orderInfo['cartoon_id'];
            $payCountType = $orderInfo['book_id'] ? 1 : 0;
            //暂时不做统计(注释)
            //$this->orderPay($type, $bookid, $orderInfo['section_id']);
            if(!empty($bookid)) {
                $this->bPayCounts($bookid, $recharge['recharge_price'], $payCountType);
                Log::write(get_called_class() . '::' . __FUNCTION__ . '进入订单号='.$orderId.',到达bPayCounts底部');
            }

            // 提交事务
            Db::commit();

            //FB的S2S(S2S有异常不做回滚处理)
            $flag = $this->fBStos($orderId);
            Log::write(get_called_class() . '::' . __FUNCTION__ . '进入订单号='.$orderId.',到达fBStos底部');

            //获取用户
            $result = objectToArray($userModel->field('remainder')->where(['id' => $uid])->find());
            return $result['remainder'] ? $result['remainder'] : 0;
        } catch (\Exception $e) {
            Log::write(get_called_class() . '::' . __FUNCTION__ . ': 行数line='.$e->getLine().' 异常信息 = ' . $e->getMessage());
            Db::rollback();
            returnMsg('服务器繁忙,请联系管理员', 6015);
        }

    }

    public function vipRecharge($request = [], $orderNum = '', $transactionId = '',  $productId = 'com_vip_half_year')
    {
        try{
            log::write(get_called_class() . '::' . __FUNCTION__ . '进入支付回调, 客户端传递的业务参数：orderNum='.$orderNum.',transactionId='. $transactionId.', productId='.$productId);
            if(empty($orderNum) || empty($transactionId)) {
                returnMsg('必传参数不能为空', 7005);
            }
            //开启事务
            Db::startTrans();

            //获取订单信息
            $orderModel = new OrderModel();
            $where = ['order_num' => $orderNum];
            $orderInfo = objectToArray($orderModel->where($where)->lock(true)->find());
            if(empty($orderInfo)) {
                Log::write(get_called_class() . '::' . __FUNCTION__ . '进入订单号='.$orderNum.',订单不存在');
                Db::rollback();
                returnMsg('订单不存在', 7001);
            }
            if($orderInfo['pay_status'] == 1) {
                Log::write(get_called_class() . '::' . __FUNCTION__ . '进入订单号='.$orderNum.',订单已支付成功,不能重复操作');
                Db::rollback();
                returnMsg('订单已支付成功,不能重复操作', 7002);
            }

            //更新订单信息
            $orderUpdate = [
                'trade_num' => $transactionId,
                'pay_status' => 1,
                'pay_time' => date('Y-m-d H:i:s'),
                'local_time' => date('Y-m-d H:i:s'),
            ];
            $flag = $orderModel->where($where)->update($orderUpdate);
            if(!$flag) {
                Log::write(get_called_class() . '::' . __FUNCTION__ . '进入订单号='.$orderNum.',订单更新失败');
                Db::rollback();
                returnMsg('订单更新失败', 7003);
            }

            //用户id
            $uid = $orderInfo['users_id'] ? (int)$orderInfo['users_id'] : 0;
            if(empty($uid)) {
                Log::write(get_called_class() . '::' . __FUNCTION__ . '进入订单号='.$orderNum.',用户不存在');
                Db::rollback();
                returnMsg('用户不存在', 7004);
            }
            $userModel = new ThirdPartyUserModel();
            $userWhere = ['id' => $uid];
            $userinfo = objectToArray($userModel->where($userWhere)->lock(true)->find());
            if(empty($userinfo)) {
                Log::write(get_called_class() . '::' . __FUNCTION__ . '进入订单号='.$orderNum.',用户信息不存在');
                Db::rollback();
                returnMsg('用户信息不存在', 7006);
            }
            //VIP开通时间段
            $time = $this->productTime[$productId];
            $update['isvip'] = 1;
            $update['open_vip_time'] = date("Y-m-d H:i:s", time());
            $vtime = strtotime($userinfo['vip_end_time']);
            if (time() >= $vtime) {
                //新的vip时间
                $vipTime = strtotime("+$time month");
                $update['vip_end_time'] = date("Y-m-d H:i:s", $vipTime);
            } else {
                //累加
                $vipTime = strtotime("+$time month", $vtime);
                $update['vip_end_time'] = date("Y-m-d H:i:s", $vipTime);
            }
            $update['viptime'] = $vipTime;
            $flag = $userModel->where($userWhere)->update($update);
            if(!$flag) {
                Log::write(get_called_class() . '::' . __FUNCTION__ . '进入订单号='.$orderNum.',更新用户信息失败');
                Db::rollback();
                returnMsg('更新用户信息失败', 7007);
            }

            //统计不做事务操作
            $payType = $orderInfo['pay_type'] ? $this->payType[$orderInfo['pay_type']] : 'android';
            $flag = $this->dayRecharge($userinfo, $orderInfo['fact_price'], 0, $payType);
            $flag = $this->chargelog($orderNum);

            //提交事务
            Db::commit();

            //FB的S2S(S2S有异常不做回滚处理)
            $this->fBStos($orderNum);
            Log::write(get_called_class() . '::' . __FUNCTION__ . '进入订单号='.$orderNum.',到达fBStos底部');

            //返回信息
            return [
                'isvip' => 1,
                'vip_end_time' => date('d/m/Y H:i:s', strtotime($update['vip_end_time']))
            ];

        }catch (\Exception $e) {
            Log::write(get_called_class() . '::' . __FUNCTION__ . ': 行数line='.$e->getLine().' 异常信息 = ' . $e->getMessage());
            Db::rollback();
            returnMsg('服务器繁忙,请联系管理员', 6015);
        }

    }


    /**
     * 任务奖励统计
     * @param $uid
     * @param $coin
     * @param $type
     */
    public function taskRewardCount($uid = 0, $coin = 0, $type = 0)
    {
        try{
            Log::write(get_called_class() . '::' . __FUNCTION__ . "进入任务奖励统计");
            if(empty($uid)) return false;
            $tcount['users_id'] = $uid;
            $tcount['task_time'] = date('Y-m-d H:i:s');
            $tcount['task_type'] = $type;
            $tcount['treward_coin'] = $coin;
            Log::write(get_called_class() . '::' . __FUNCTION__ . "进入任务奖励统计,data=".var_export($tcount, true));
            $taskRewardCountModel = new TaskRewardCountModel();
            return $taskRewardCountModel->insert($tcount); //任务奖励记录
        }catch (\Exception $e) {
            Log::write(get_called_class() . '::' . __FUNCTION__ . ': 行数line='.$e->getLine().' 异常信息 = ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 每日任务
     * @param $uid
     * @param $type
     * @param $coin
     * @return bool
     */
    public function everydayTask($uid, $type, $coin)
    {
        try{
            Log::write(get_called_class() . '::' . __FUNCTION__ . "每日任务统计,uid=".$uid.',type='.$type.',coin='.$coin);
            $nDotaskModel = new NDoTaskModel();
            $nDayTaskModel = new NDayTaskModel();
            switch ($type) {
                case '6':
                    $k1 = 'qdsr';//签到人数
                    $k2 = 'qdjbs';//签到金币数
                    break;
                case '3':
                    $k1 = 'yqcgs';//邀请成功数
                    $k2 = 'yqjlzjb';//邀请奖励金币数
                    break;
                case '1':
                    $k1 = 'fqfxs';//发起分享数
                    $k2 = 'fxjlzjb';//分享奖励金币数
                    break;
                case '5':
                    $k1 = 'kspcgs'; //成功次数
                    $k2 = 'kspjljb';//看视频奖励金币数
                    $taskWhere['uid'] = $uid;
                    $taskWhere['dates'] = '-'.date('Ymd'); //减号
                    $dotaskInfo = objectToArray($nDotaskModel->where($taskWhere)->find());
                    Log::write(get_called_class() . '::' . __FUNCTION__ . "每日任务统计,type=5返回的每日用户做任务排重data=".var_export($dotaskInfo, true));
                    if (!$dotaskInfo) {
                        $readad = $nDotaskModel->insert($taskWhere);
                    }
                    break;
                case '2':
                    $k1 = 'ydrs';//阅读人数
                    $k2 = 'ydjbs';//阅读奖励金币
                    break;
                case '8':
                    $k1 = 'wjdtrs';//问卷调查人数
                    $k2 = 'wjdtjb';//问卷金币数
                    break;
                default:
                    //不存在的任务
                    return false;
                    break;
            }

            $is = $readad = false;
            $ntaskWhere = [
                'dates' => date('Ymd'),
                'uid' => $uid
            ];
            $dotaskInfo = objectToArray($nDotaskModel->where($ntaskWhere)->find());
            Log::write(get_called_class() . '::' . __FUNCTION__ . "每日任务统计,返回的每日用户做任务排重dotaskInfo=".var_export($dotaskInfo, true));
            if (!$dotaskInfo) {
                $is = $nDotaskModel->insert($dotaskInfo);
            }

            $where['dates'] = date('Ymd');
            $have = objectToArray($nDayTaskModel->where($where)->find());
            Log::write(get_called_class() . '::' . __FUNCTION__ . "每日任务统计,返回的每日用户做任务排重have=".var_export($have, true));
            if (!$have) {
                $where[$k1] = 1;
                $where[$k2] = $coin;
                $where['zjb'] = $coin;//总金币
                if ($is) $where['zrs'] = 1;//总人数
                if($readad ) $where['kspzrs'] = 1;//看视频总人数
                $where['addtime'] = time();
                Log::write(get_called_class() . '::' . __FUNCTION__ . "每日任务统计,插入tp_n_day_task的数组=".var_export($where, true));
                $flag = $nDayTaskModel->insert($where);
                Log::write(get_called_class() . '::' . __FUNCTION__ . "每日任务统计,插入tp_n_day_task数组,返回状态的ID=".$flag);
            } else {
                $u[$k1] = $have[$k1] + 1;
                $u[$k2] = $have[$k2] + $coin;
                if ($is) $u['zrs'] = 1 + $have['zrs'];
                if($readad) $u['kspzrs'] = 1 + $have['kspzrs'];
                Log::write(get_called_class() . '::' . __FUNCTION__ . "每日任务统计,更新tp_n_day_task的数组=".var_export($u, true));
                $u['zjb'] = $coin + $have['zjb'];//总金币
                $flag = $nDayTaskModel->where($where)->update($u);
                Log::write(get_called_class() . '::' . __FUNCTION__ . "每日任务统计,更新tp_n_day_task数组,返回状态的状态=".$flag);
            }
            return true;
        }catch (\Exception $e) {
            Log::write(get_called_class() . '::' . __FUNCTION__ . ': 行数line='.$e->getLine().' 异常信息 = ' . $e->getMessage());
            return true;
        }
    }

    /**
     * 小说漫画支付统计
     * @param int $bookId 书本id
     * @param int $money  支付金额
     * @param int $type   类型 0 漫画  1 书籍
     * @return bool
     */
    public function bPayCounts($bookId = 0, $money = 0, $type = 1)
    {
        try{
            Log::write(get_called_class() . '::' . __FUNCTION__ . "bPayCounts传递的参数type=".$type.',bookId='.$bookId.',money='.$money);
            $bookOtherModel = new BookOtherModel();
            $cartoonOtherModel = new CartoonOtherModel();
            $model = $type ? $bookOtherModel : $cartoonOtherModel;
            $field = $type ? 'book_id' : 'cartoon_id';
            $where = [$field => $bookId];
            Log::write(get_called_class() . '::' . __FUNCTION__ . "bPayCounts的where=".var_export($where, true));
            $have = objectToArray($model->where($where)->find());
            Log::write(get_called_class() . '::' . __FUNCTION__ . "bPayCounts的have=".var_export($have, true));
            if (!$have) {
                $where['i_recharge'] = $money;
                $flag = $model->insert($where);
                Log::write(get_called_class() . '::' . __FUNCTION__ . "bPayCounts的插入状态flag=".$flag);
            } else {
                $update['i_recharge'] = $have['i_recharge'] + $money;
                $flag = $model->where($where)->update($update);
                Log::write(get_called_class() . '::' . __FUNCTION__ . "bPayCounts的更新状态flag=".$flag);
            }
            return true;
        }catch (\Exception $e) {
            Log::write(get_called_class() . '::' . __FUNCTION__ . ': 行数line='.$e->getLine().' 异常信息 = ' . $e->getMessage());
            return true;
        }
    }

    public function orderPay($type,$bookId,$fee) {
        try{
            Log::write(get_called_class() . '::' . __FUNCTION__ . "orderPay传递的参数type=".$type.',bookId='.$bookId.',fee='.$fee);
            $condition = $where = [
                'dates' => date('Ymd'),
                'bid' => $bookId
            ];
            Log::write(get_called_class() . '::' . __FUNCTION__ . "orderPay的condition=".var_export($condition, true));
            $nDayBookModel = new NDayBookModel();
            $data = objectToArray($nDayBookModel->where($where)->find());
            Log::write(get_called_class() . '::' . __FUNCTION__ . "orderPay的data=".var_export($data, true));
            if (!$data) {
                if ($type == 1) {
                    $bookModel = new BookModel();
                    $book = objectToArray($bookModel->field('other_name,create_time')->where(['book_id' => $bookId])->find());
                } else {
                    $cartoonModel = new CartoonModel();
                    $book = objectToArray($cartoonModel->field('other_name,create_time')->where(['cartoon_id' => $bookId])->find());
                }
                Log::write(get_called_class() . '::' . __FUNCTION__ . "orderPay的book=".var_export($book, true));
                $where['name'] = $book['other_name'];
                $where['sjtime'] = $book['create_time'];
                $flag = $nDayBookModel->insert($where);
                Log::write(get_called_class() . '::' . __FUNCTION__ . "orderPay的插入状态flag=".$flag);
                $data = objectToArray($nDayBookModel->where($condition)->find());
            }

            $recharge = $data['czje'] ? round($data['czje'] + $fee, 2) : 0;
            $flag = $nDayBookModel->update(['czje' => $recharge], $condition);
            Log::write(get_called_class() . '::' . __FUNCTION__ . "orderPay的更新状态flag=".$flag);
            return true;
        }catch (\Exception $e) {
            Log::write(get_called_class() . '::' . __FUNCTION__ . ': 行数line='.$e->getLine().' 异常信息 = ' . $e->getMessage());
            return true;
        }
    }

    //充值统计
    public function dayRecharge($user = [], $money = 0, $payType = 0, $type = 'android', $isbacll = false)
    {
        try{
            Log::write(get_called_class() . '::' . __FUNCTION__ . "的dayRecharge传递的参数=".var_export($user, true));
            $nDayRechargeModel = new NDayRechargeModel;
            //$is = false;
            $day = 86400;
            $time = time();

            //用户注册时充值统计
            $createTime = strtotime($user['create_time']);//用户创建时间
            $where['dates'] = date('Ymd', $createTime);
            $info = objectToArray($nDayRechargeModel->where($where)->find());
            Log::write(get_called_class() . '::' . __FUNCTION__ . "dayRecharge的info=".var_export($info, true));
            if (!$info) {
                $where['addtime'] = $createTime;
                $flag = $nDayRechargeModel->insert($where);
                if(!$flag) return false;
                $info = objectToArray($nDayRechargeModel->where($where)->find());
            }

            //当前时间段充值统计
            $condition['dates'] = date('Ymd');
            $data = objectToArray($nDayRechargeModel->where($condition)->find());

            Log::write(get_called_class() . '::' . __FUNCTION__ . "dayRecharge的data=".var_export($data, true));
            if (!$data) {
                $condition['addtime'] = $time;
                $flag = $nDayRechargeModel->insert($condition);
                if(!$flag) return false;
                $data = objectToArray($nDayRechargeModel->where($condition)->find());
            }
            if ($type == 'android') {
                $key = "a";
            }
            if ($type == 'iphone') {
                $key = "i";
            }
            if ($type == 'wap') {
                $key = "w";
            }
            if ($time >= $createTime && $time <= ($createTime + 30 * $day)) {

                $update[$key . '30cz'] = $info[$key . '30cz'] + $money;//30天充值数
                $update[$key . 'nzcz'] = $info[$key . 'nzcz'] + $money; //总充值
            }
            if ($time >= $createTime && $time <= ($createTime + 7 * $day)) {
                $update[$key . '7cz'] = $info[$key . '7cz'] + $money;//7天充值数
            }
            if ($time >= $createTime && $time <= ($createTime + $day)) {
                $update[$key . '1cz'] = $info[$key . '1cz'] + $money;//一天充值数
                $is = true;
            }
            $update[$key . 'nzcz'] = $info[$key . 'nzcz'] + $money; //总充值
            $update2[$key . 'cz'] = $data[$key . 'cz'] + $money; //充值
            $update2[$key . 'cgdd'] = $data[$key . 'cgdd'] + 1; //成功订单数量
            if ($isbacll) {
                //充值订单数量加一
                $update2[$key . 'zdd'] = $data[$key . 'zdd'] + 1;//总订单
            }
            //实例化用户model
            $userModel = new ThirdPartyUserModel();
            if ($user['isnew']) {
                //$this->_newrecharge();
                $update[$key . 'xyhfcz'] = $info[$key . 'xyhfcz'] + 1;//新用户充值数
                //变成老用户
                $userModel->update(['isnew' => 0], ['id' => $user['id']]);
            } else {
                $update[$key . 'xyhecz'] = $info[$key . 'xyhecz'] + 1;//新用户充值数
            }
            Log::write(get_called_class() . '::' . __FUNCTION__ . "dayRecharge的update=".var_export($update, true));
            Log::write(get_called_class() . '::' . __FUNCTION__ . "dayRecharge的update2=".var_export($update2, true));
            $this->countNew($money, $user['isnew'] ? 1 : 0, $key);
            //用户注册时间
            Log::write(get_called_class() . '::' . __FUNCTION__ . "dayRecharge的更新用户注册时间");

            $nDayRechargeModel->update($update, ['dates' => $where['dates']]);
            //今日付费总人数
            $iss = false;
            $taskWhere = ['dates' => date('Ymd') . '2', 'uid' => $user['id']];

            $nDoTaskModel = new NDoTaskModel();
            $nDayTaskModel = new NDayTaskModel();
            $taskInfo = objectToArray($nDoTaskModel->where($taskWhere)->find());
            Log::write(get_called_class() . '::' . __FUNCTION__ . "dayRecharge的taskInfo=".var_export($taskInfo, true));
            if (!$taskInfo) {
                $iss = $nDoTaskModel->insert($taskWhere);
                //每日付费人数统计点
                $taskCondition = ['dates' => date('Ymd')];
                $info = objectToArray($nDayTaskModel->where($taskCondition)->find());
                Log::write(get_called_class() . '::' . __FUNCTION__ . "dayRecharge的info=".var_export($info, true));
                if ($info) {
                    $flag = $nDayTaskModel->where($taskCondition)->update(['ffrs' => 1 + $info['ffrs']]);//付费人数
                    Log::write(get_called_class() . '::' . __FUNCTION__ . "dayRecharge的info更新状态flag=".$flag);
                } else {
                    $taskCondition['ffrs'] = 1;
                    $taskCondition['addtime'] = time();
                    $flag = $nDayTaskModel->insert($taskCondition);
                    Log::write(get_called_class() . '::' . __FUNCTION__ . "dayRecharge的info插入状态flag=".$flag);
                }
            }

            $flag = $nDayRechargeModel->update($update2, ['dates' => $condition['dates']]);
            Log::write(get_called_class() . '::' . __FUNCTION__ . "dayRecharge的update2更新状态flag=".$flag);

            return true;
        }catch (\Exception $e) {
            Log::write(get_called_class() . '::' . __FUNCTION__ . ': 行数line='.$e->getLine().' 异常信息 = ' . $e->getMessage());
            return true;
        }

    }

    /**
     * 最新统计
     * @param int $money
     * @param int $isNewUser
     * @param int $key
     */
    public function countNew($money = 0, $isNewUser = 0, $key = 0)
    {
        try{
            $nCountModel = new NCountModel();
            $where['dates'] = date('Ymd');
            $data = objectToArray($nCountModel->where($where)->find());
            if (!$data) {
                $where['addtime'] = time();
                $flag = $nCountModel->insertGetId($where);
                $data = objectToArray($nCountModel->where([
                    'id' => $flag
                ])->find());
            }
            $update['zcz'] = $data['zcz'] + $money;//总充值
            if ($isNewUser) {
                $update[$key . 'xyhcz'] = $data[$key . 'xyhcz'] + 1;//新用户充值数
            }
            $update[$key . 'yfk'] = $data[$key . 'yfk'] + 1;//已付款
            $nCountModel->update($update, ['dates' => date('Ymd')]);
        }catch (\Exception $e) {
            Log::write(get_called_class() . '::' . __FUNCTION__ . ': 行数line='.$e->getLine().' 异常信息 = ' . $e->getMessage());
        }
    }



    //返回是否首次充值
    public function sure($orderNum, $uid, $tradeNum, $thirdPayTime, $createSyntony, $paySyntony, $addCoinStatus)
    {
        try{
            Log::write(get_called_class() . '::' . __FUNCTION__ . 'sure传递参数orderNum='.$orderNum.',uid='.$uid.',tradeNum='.$tradeNum
            .',thirdPayTime='.$thirdPayTime.',createSyntony='.$createSyntony.',paySyntony='.$paySyntony.',addCoinStatus='.$addCoinStatus
            );

            $orderModel = new OrderModel();
            $userOrder = objectToArray($orderModel->field('first_charge')->where([
                'users_id' => $uid,
                'proxy_id' => 0,
                'pay_status' => 1
            ])->find());
            Log::write(get_called_class() . '::' . __FUNCTION__ . 'sure的userOrder='.var_export($userOrder, true));

            $arr['first_charge'] = $userOrder ? 1 : 2;
            $arr['trade_num'] = $tradeNum;
            $arr['pay_status'] = 1;
            $arr['pay_time'] = $thirdPayTime;
            $arr['local_time'] = date('Y-m-d H:i:s');
            $arr['create_syntony'] = $createSyntony;
            $arr['pay_syntony'] = $paySyntony;
            $arr['addcoin'] = $addCoinStatus;
            Log::write(get_called_class() . '::' . __FUNCTION__ . 'sure的arr='.var_export($arr, true));
            return $orderModel->update($arr, ['order_num' => $orderNum]);
        }catch (\Exception $e) {
            Log::write(get_called_class() . '::' . __FUNCTION__ . ': 行数line='.$e->getLine().' 异常信息 = ' . $e->getMessage());
            return false;
        }
    }

    //充值
    public function rechargeUserRecode($uid = 0, $coin = 0, $money = 0)
    {
        try{
            Log::write(get_called_class() . '::' . __FUNCTION__ . 'rechargeUserRecode传递参数uid='.$uid.',coin='.$coin.',money='.$money);
            if(empty($uid)) return false;
            $userModel = new ThirdPartyUserModel();
            $where = ['id' => $uid];
            $userInfo = objectToArray($userModel->where($where)->find());
            Log::write(get_called_class() . '::' . __FUNCTION__ . 'rechargeUserRecode的userInfo='.var_export($userInfo, true));
            $goldCoin = $userInfo['remainder'] + $coin;//书币余额
            $chargeTotal = $userInfo['charge_all'] + $money;//充值总额
            //用户不存在
            if(empty($userInfo)) return false;
            //更新数据
            $updateData = ['remainder' => $goldCoin, 'charge_all' => $chargeTotal];
            Log::write(get_called_class() . '::' . __FUNCTION__ . 'rechargeUserRecode的updateData='.var_export($updateData, true));
            if($userInfo['remainder'] != $goldCoin || $userInfo['charge_all'] != $chargeTotal){
                $flag = $userModel->update($updateData, $where); //奖励书币
                if(!$flag) {
                    Log::write(get_called_class() . '::' . __FUNCTION__ . 'rechargeUserRecode的奖励书币更新失败');
                    return false;
                }
            }

            $type = 'charge_icon';
            $condition = ['users_id' => $uid];
            //用户金币明细表
            $usersIconDetailModel = new UsersIconDetailModel();
            $userDetail = objectToArray($usersIconDetailModel->where($condition)->find());
            //p($userDetail);die;
            Log::write(get_called_class() . '::' . __FUNCTION__ . 'rechargeUserRecode的用户金币明细userDetail='.var_export($userDetail, true));
            if (!$userDetail) {
                $iconDetail['charge_icon'] = $coin;
                $iconDetail['users_id'] = $uid;
                $flag = $usersIconDetailModel->insert($iconDetail);
                Log::write(get_called_class() . '::' . __FUNCTION__ . 'rechargeUserRecode的插入用户金币明细状态flag='.$flag);
            } else {
                $detailUpdate = [$type => $userDetail['charge_icon'] + $coin];
                Log::write(get_called_class() . '::' . __FUNCTION__ . 'rechargeUserRecode的更新用户金币明细条件detailUpdate='.var_export($detailUpdate, true));
                $flag = $usersIconDetailModel->update($detailUpdate, $condition);
                Log::write(get_called_class() . '::' . __FUNCTION__ . 'rechargeUserRecode的更新用户金币明细状态flag='.$flag);
            }
            return $flag;
        }catch (\Exception $e) {
            Log::write(get_called_class() . '::' . __FUNCTION__ . ': 行数line='.$e->getLine().' 异常信息 = ' . $e->getMessage());
            return false;
        }
    }

    //
    public function chargeLog($payUid = 0, $type = 0, $money = 0, $coin = 0, $sendCoin = 0)
    {
        try{
            Log::write(get_called_class() . '::' . __FUNCTION__ . 'chargeLog传递参数payuid='.$payUid.',type='.$type.',money='.$money.',coin='.$coin.',sendCoin='.$sendCoin);
            $chargesd['users_id'] = $payUid;
            $chargesd['charge_icon'] = $coin;
            $chargesd['charge_type'] = $type;
            $chargesd['charge_time'] = date('Y-m-d H:i:s');
            $chargesd['local_time'] = date('Y-m-d H:i:s');
            $chargesd['charge_price'] = $money;
            $chargesd['send_coin'] = $sendCoin;
            Log::write(get_called_class() . '::' . __FUNCTION__ . 'chargeLog的chargesd='.var_export($chargesd, true));
            $chargeModel = new ChargeModel();
            return $chargeModel->insert($chargesd);
        }catch (\Exception $e) {
            Log::write(get_called_class() . '::' . __FUNCTION__ . ': 行数line='.$e->getLine().' 异常信息 = ' . $e->getMessage());
            return false;
        }
    }

    //下属充值奖励
    public function divided($uid = 0, $pid = 0, $coin = 0)
    {
        try{
            Log::write(get_called_class() . '::' . __FUNCTION__ . '下属充值奖励uid='.$uid.',pid='.$pid.',coin='.$coin);
            if(empty($uid) || empty($pid)) return false;
            $coins = $coin / 10;

            //Log::write(get_called_class() . '::' . __FUNCTION__ . '代理充值奖励用户id'.$uid.',邀请用户ID'. $pid.'奖励金币数量'.$coin);
            return $this->change($pid, $coins);
        }catch (\Exception $e) {
            Log::write(get_called_class() . '::' . __FUNCTION__ . ': 行数line='.$e->getLine().' 异常信息 = ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 代充记录
     * @param int $payuid
     * @param int $uid
     * @param int $money
     * @param int $coin
     * @return bool
     */
    public function agentPayLog($deviceType, $payUid = 0, $uid = 0, $money = 0, $coin = 0)
    {
        try{
            $agent['users_id'] = $payUid;//充值人
            $agent['u_id'] = $uid;//好友id
            $agent['return_coin'] = $coin;
            $agent['agent_time'] = date('Y-m-d H:i:s');
            $agent['agent_date'] = date('Y-m-d');
            $agent['plat'] = $deviceType;
            Log::write(get_called_class() . '::' . __FUNCTION__ . '代充记录插入的数据agent='.var_export($agent, true));
            // $agent['price'] = $money;
            $userAgentModel = new UserAgentModel();
            return $userAgentModel->insert($agent);
        }catch (\Exception $e) {
            Log::write(get_called_class() . '::' . __FUNCTION__ . ': 行数line='.$e->getLine().' 异常信息 = ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 充值金币到余额表
     * @param $uid
     * @param $coin
     * @return bool
     */
    public function change($uid = 0, $coin = 0)
    {
        try{
            Log::write(get_called_class() . '::' . __FUNCTION__ . '传递参数,uid='.$uid.",coin=".$coin.",进入充值金币到余额表");
            if(empty($uid)) {
                return false;
            }
            $where = ['id' => $uid];
            $userModel = new ThirdPartyUserModel();
            $userData = $userModel->getUserInfo($where);
            if(empty($userData)) {
                Log::write(get_called_class() . '::' . __FUNCTION__ . "进入充值金币到余额表,用户信息为空");
                return false;
            }
            $nowCoin = $userData['remainder'] + $coin;
            //更新金币余额
            $update = ['remainder' => $nowCoin];
            return $userModel->update($update, $where);
        }catch (\Exception $e) {
            Log::write(get_called_class() . '::' . __FUNCTION__ . ': 行数line='.$e->getLine().' 异常信息 = ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 添加用户日志
     * @param int $uid
     * @param int $price
     * @param string $tradeNum
     * @param string $orderNum
     * @param string $payTime
     * @param string $payType
     * @param string $createSyntony
     * @param string $paySyntony
     */
    public function addUserLog($header = [], $uid = 0, $price = 0, $tradeNum = '', $orderNum = '', $payTime = '', $payType = '', $createSyntony = '', $paySyntony = '') {
        try{
            //获取头部信息
            Log::write('进入添加用户日志header='.json_encode($header, JSON_UNESCAPED_UNICODE).',uid='.$uid.',price='.$price
                .',tradeNum='.$tradeNum.',orderNum='.$orderNum.',payTime='.$payTime.',payType='.$payType.',createSyntony='.$createSyntony);
            $plat = isset($header['devicetype']) ? $header['devicetype'] : 'android';
            $payStatus = 1;
            $data = [
                'u_id' => $uid,
                'action' => $plat,
                'price' => $price,
                'trade_num' => $tradeNum,
                'order_num' => $orderNum,
                'pay_status' => $payStatus,
                'pay_time' => $payTime,
                'local_time' => date('Y-m-d H:i:s'),
                'pay_type' => $payType,
                'fact_price' => $price,
                'create_syntony' => $createSyntony,
                'pay_syntony' => $paySyntony,
                'plat' => $plat,
                'bank_code' => '',
                'account_payment' => '',
            ];
            Log::write(get_called_class() . '::' . __FUNCTION__ . '订单号='.$orderNum.',添加用户日志data='.var_export($data, true));
            $uActionLogModel = new UActionLogModel();
            return $uActionLogModel->insert($data);
        }catch (\Exception $e) {
            Log::write(get_called_class() . '::' . __FUNCTION__ . ': 行数line='.$e->getLine().' 异常信息 = ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 加金币日志
     * @param int $uid
     * @param string $orderId
     * @param $addCoin
     * @return bool|int|string
     */
    public function addCoinLog($uid = 0, $orderId = '', $addCoin) {
        try{
            if(empty($uid) || empty($orderId) || empty($addCoin)) {
                return false;
            }
            $userModel = new ThirdPartyUserModel();
            $where = ['id' => $uid];
            $userInfo = $userModel->field('remainder, nickname')->where($where)->find();
            if(empty($userInfo)) return false;
            //金币明细表
            $nCoinDetailModel = new NCoinDetailModel();
            $insert = [
                'uid' => $uid,
                'ordernum' => $orderId,
                'before_coin' => isset($userInfo['remainder']) ? $userInfo['remainder'] : 0,
                'after_coin' => isset($userInfo['remainder']) ? $userInfo['remainder'] : 0,
                'add_coin' => $addCoin,
                'addtime' => time(),
                'name' => isset($userInfo['nickname']) ? $userInfo['nickname'] : '',
            ];
            return $nCoinDetailModel->insertGetId($insert);
        }catch (\Exception $e) {
            Log::write(get_called_class() . '::' . __FUNCTION__ . ': 行数line='.$e->getLine().' 异常信息 = ' . $e->getMessage());
            return false;
        }
    }

    /**
     * FB数据交换接口
     * @param string $orderNum
     * @return bool
     */
    public function fBStos($orderNum = '')
    {
        try{
            if (!$orderNum) {
                return false;
            }
            //实例化orderModel
            $orderModel = new OrderModel();
            $where = ['order_num' => $orderNum];
            //获取订单信息
            $orderInfo = objectToArray($orderModel->where($where)->find());
            if(empty($orderInfo)) return false;
            //实例化userModel
            $userModel = new ThirdPartyUserModel();
            //获取用户信息
            $userInfo = objectToArray($userModel->where([
                'id' => $orderInfo['users_id']
            ])->find());
            if(empty($userInfo)) return false;

            $detoken = $userInfo['deviceToken'];//手机设备号
            $uid = $orderInfo['users_id'];//用户ID
            $currency = "THB";//币种编码
            $ordernum = $orderNum;//订单编号
            $payType = $orderInfo['pay_type'];//支付类型

            $rechargeModel = new RechargeModel();
            $recharge = objectToArray($rechargeModel->field('yuenan_icon')->where(['applepayId' => $orderInfo['create_syntony']])->find());
            $yuan = $recharge['yuenan_icon'];
            $facebook = new Facebook();
            if ($orderInfo['trade_type'] == 1) {
                $facebook->s2sBuy($detoken, $yuan, $currency, $uid, $orderNum, $payType);
            }
            if ($orderInfo['trade_type'] == 2) {
                $facebook->s2sSub($detoken, $yuan, $currency, $uid, $orderNum, $payType);
            }
        }catch (\Exception $e) {
            Log::write(get_called_class() . '::' . __FUNCTION__ . ': 行数line='.$e->getLine().' 异常信息 = ' . $e->getMessage());
        }
    }

    /**
     * 插入异常订单数据
     * @param string $orderNum
     * @param string $script
     * @param int $type
     */
    /*public function insertPayScriptRecord($orderNum = '', $script = '', $type = 1) {
        if(!empty($orderNum) && !empty($script) || empty($type)) {
           $where = [
               'type' => $type,
               'order_num' => $orderNum
           ];
           $payAutoScriptModel = new PayAutoScriptModel();
           $info = objectToArray($payAutoScriptModel->where($where)->find());

           if(empty($info)) {
               $insert = [
                   'type' => $type,
                   'order_num' => $orderNum,
                   'script_content' => json_encode($script),
                   'create_time' => time()
               ];
               $flag = $payAutoScriptModel->insertGetId($insert);
           }
        }

    }*/


}