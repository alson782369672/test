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
namespace app\api\model;

use think\Model;
use think\Db;
use think\Lang;

class RechargeModel extends Model
{
    //设置当前模型对应的完整数据表名称
    protected $table = 'tp_recharge';

    /**
     * 获取充值信息
     * @param array $where
     * @return array|false|\PDOStatement|string|Model
     */
    public function getRechargeInfo($where = []) {
        if(empty($where)) return [];
        return $this->field('recharge_price,dummy_icon,first_send')->where($where)->find()->toArray();
    }


}