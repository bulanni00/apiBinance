<?php
namespace App\Utils;

use Hyperf\Logger\LoggerFactory;
use Hyperf\Utils\Context;
class Response  {

    protected $logger;

    public function __construct(LoggerFactory $loggerFactory)
    {
        $this->logger = $loggerFactory->get('gendan_binance.log', 'gendan_binance');
    }
    //成功返回
    public function success($msg, $data = []){
        $data = [
            "res" => true,
            "msg" => $msg,
            "data" => $data,
            "code" => 0
        ];
        $this->logger->info($msg).PHP_EOL;
        return $data;
    }
    //失败返回
    public function error($msg, $data = [], $code = 0){
        $data = [
            "res" => 0,
            "msg" => $msg,
            "data" => $data,
            "code" => $code
        ];
        $this->logger->error($code.':'.$msg).PHP_EOL;
        return $data;
    }
    //分页数据
    public function pagelist($msg, $data, $code = 0){
        $datas = [
            "result" => 0,
            "message" => $msg,
            "data" => [
                "list" => $data->data,
                "paging" => [
                    "total" => "",
                    "page" => $data->current_page,
                    "per_page" => $data->per_page,
                ]
            ],
            "status_code" => $code
        ];
        return $datas;
    }

    //获取数据并带用户id
    public function userResAll(){
        $all = $this->request->all();
        $user_id = Context::get('user_id', 0);
        //var_dump("uuuuu::", $user_id);
        $all["user_id"] = $user_id;
        return $all;
    }
}