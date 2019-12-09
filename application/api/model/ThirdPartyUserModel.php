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

class ThirdPartyUserModel extends Model
{
    //设置当前模型对应的完整数据表名称
    protected $table = 'tp_third_party_user';

    /**
     * 获取充值信息
     * @param array $where
     * @return array|false|\PDOStatement|string|Model
     */
    public function getUserInfo($where = []) {
        if(empty($where)) return [];
        return objectToArray($this->field('id,remainder,charge_all,create_time,invite_id,isnew')->where($where)->find());
    }


}