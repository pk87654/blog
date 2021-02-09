<?php

namespace App\Models;

use App\Http\Common\Code;
use App\Http\Common\RedisKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Redis;
use phpDocumentor\Reflection\DocBlock\Tags\Reference\Url;

class FrontUser extends Model
{
    protected $table = "blog_front_user";
    protected $dateFormat = 'U';
    protected $fillable = ["ip", "last_ip", "visits_time", "frequent_num", "province", "city", "adcode", "rectangle", "last_at", "black_list", "created_at"];

    public function visitsNum()
    {
        return $this->hasMany("App\Models\FrontUserVisitsInterval", "ip", "ip");
    }

    /**
     * 获取
     * @param int $start 起始
     * @param int $pageSize 显示多少条
     * @return array
     */
    public function getAll($start = 0, $pageSize = 15)
    {
        $user = FrontUser::with("visitsNum")->offset($start)->limit($pageSize)->get();
        if ($user->isEmpty()) {
            return ["code" => Code::$SUCCESS_NO_TIP, "msg" => "空空如也！", "total" => 0, "data" => []];
        }
        $total = FrontUser::count();
        return ["code" => Code::$SUCCESS_NO_TIP, "msg" => "success", "total" => $total, "data" => $user];
    }

    /**
     * 添加
     * @param $params
     * @return array
     */
    public function add($params)
    {
        $user = FrontUser::create($params);
        if (!$user) {
            return ["code" => Code::$WARNING, "msg" => "添加失败！"];
        }
        return ["code" => Code::$SUCCESS, "msg" => "添加成功！"];
    }

    /**
     * 删除
     * @param string $ids
     * @return array
     */
    public function del($ids)
    {
        $user = FrontUser::destroy($ids);
        if (!$user) {
            return ["code" => Code::$WARNING, "msg" => "删除失败！"];
        }
        return ["code" => Code::$SUCCESS, "msg" => "删除成功！"];
    }

//    /**
//     * 修改
//     * @param $params
//     * @return array
//     */
//    public function edit($params)
//    {
//        $user = FrontUser::where(["id" => $params["id"]])->update($params);
//        if (!$user) {
//            return ["code" => Code::$WARNING, "msg" => "修改失败！"];
//        }
//        return ["code" => Code::$SUCCESS, "msg" => "修改成功！"];
//    }

    /**
     * 黑名单
     * @param int $id id
     * @return array
     */
    public function release($id)
    {
        $user = FrontUser::where(["id" => $id])->first();
        if (!$user) {
            return ["code" => Code::$WARNING, "msg" => "该用户不存在！"];
        }
        if ($user->black_list === 1) {
            Redis::zrem(RedisKey::$USER_BLACK_LIST, $user->ip);
        } else {
            Redis::zadd(RedisKey::$USER_BLACK_LIST, 1, $user->ip);
        }
        $msg = $user->black_list === 1 ? "取消" : "设置";
        $user->black_list = $user->black_list === 1 ? 2 : 1;
        if (!$user->save()) {
            return ["code" => Code::$WARNING, "msg" => $msg . "失败！"];
        }
        return ["code" => Code::$SUCCESS, "msg" => $msg . "成功！"];
    }

    /**
     * 判断是否首次访问
     * @param string $ip ip
     * @return bool
     */
    public function isRegister($ip)
    {
        $isRegister = Redis::zscore(RedisKey::$USER_REGISTER_LIST, $ip);
        if ($isRegister) {
            return true;
        }
        return false;
    }

    /**
     * 判断是否当天首次访问
     * @param string $ip ip
     * @return bool
     */
    public function isLogin($ip)
    {
        $isLogin = Redis::zscore(RedisKey::$USER_LOGIN_LIST, $ip);
        if ($isLogin) {
            return true;
        }
        return false;
    }

    /**
     * 创建一条新的数据
     * @param string $ip ip
     * @return array
     */
    public function createLoginData($ip)
    {
        $isUser = FrontUser::where(["ip" => $ip])->first();
        if ($isUser) {
            return $this->updateLoginData($ip);
        }
        $data = $this->getAddress($ip);
        $data["ip"] = $ip;
        $user = FrontUser::create($data);
        if ($user) {
            Redis::zadd(RedisKey::$USER_REGISTER_LIST, 1, $ip);
            Redis::zadd(RedisKey::$USER_LOGIN_LIST, 1, $ip);
            Redis::expire(RedisKey::$USER_LOGIN_LIST, strtotime(date("y-m-d 23:59:59")) - time());
            $total = new FrontTotal();
            if ($total->isCreated()) {
                $total->createdPieceOfData(["visits_num" => 1, "visitors_num" => 1]);
            } else {
                $total->IncrementOfDecrement("visits_num", 1, true);
                $total->IncrementOfDecrement("visitors_num", 1, true);
            }
            $visits = new FrontUserVisitsInterval();
            $visits->add($ip);
            return ["code" => Code::$SUCCESS_NO_TIP, "msg" => "success", "time" => strtotime(date("y-m-d 23:59:59")) - time()];
        }
        return ["code" => Code::$SUCCESS_NO_TIP, "msg" => "添加数据失败！"];
    }

    /**
     * 更新最新一条数据
     * @param string $ip ip
     * @return array
     */
    public function updateLoginData($ip)
    {
        $user = FrontUser::where(["ip" => $ip])->first();
        if (!$user) {
            return $this->createLoginData($ip);
        }
        $user->last_ip = $ip;
        $user->last_at = strtotime($user->updated_at);
        $user->save();
        if ($user) {
            Redis::zadd(RedisKey::$USER_LOGIN_LIST, 1, $ip);
            Redis::expire(RedisKey::$USER_LOGIN_LIST, strtotime(date("y-m-d 23:59:59")) - time());
        }
        $total = new FrontTotal();
        if ($total->isCreated()) {
            $total->createdPieceOfData(["visits_num" => 1]);
        } else {
            $total->IncrementOfDecrement("visits_num", 1, true);
        }
        $visits = new FrontUserVisitsInterval();
        $visits->add($ip);
        return ["code" => Code::$SUCCESS_NO_TIP, "msg" => "success", "time" => strtotime(date("y-m-d 23:59:59")) - time()];
    }

    /**
     * 用户访问
     * @param string $ip ip
     * @return array
     */
    public function login($ip)
    {
        //是否首次访问
        if ($this->isRegister($ip)) {
            //是否当天首次访问
            if ($this->isLogin($ip)) {
                return ["code" => Code::$SUCCESS_NO_TIP, "msg" => "已访问！", "time" => strtotime(date("y-m-d 23:59:59")) - time()];
            }
            return $this->updateLoginData($ip);
        } else {
            $user = FrontUser::where(["ip" => $ip])->first();
            if ($user) {
                Redis::zadd(RedisKey::$USER_REGISTER_LIST, 1, $ip);
                return $this->updateLoginData($ip);
            }
            return $this->createLoginData($ip);
        }
    }

    /**
     * 获取IP所有区域地址
     * @param string $ip ip
     * @return array
     */
    public function getAddress($ip)
    {
        $key = env("MAP_KEY");
        $output = "JSON";
        $url = "https://restapi.amap.com/v3/ip?key=$key&ip=$ip&output=$output";
        $res = file_get_contents($url);
        $data = json_decode($res);
        if ($data->province === []) {
            return ["province" => 0, "city" => 0, "adcode" => 0, "rectangle" => 0];
        }
        return ["province" => $data->province, "city" => $data->city, "adcode" => $data->adcode, "rectangle" => $data->rectangle];
    }
}
