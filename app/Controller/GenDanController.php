<?php

declare(strict_types=1);
//
namespace App\Controller;
use Hyperf\DbConnection\Db;
use  Binance;
use Hyperf\Logger\LoggerFactory;
use App\Utils\Response;
class GenDanController extends AbstractController
{
    protected $logger;
    protected $interval;    // k线 周期.
    protected $price_dian;
    protected $quantity_dian;
    protected $res;
    public $api;
    public function __construct(LoggerFactory $loggerFactory, Response $res)
    {

        $this->api = new Binance\API(env('KEY'), env('SECRET'));
        $this->res = $res;
        // 第一个参数对应日志的 name, 第二个参数对应 config/autoload/logger.php 内的 key
//        [open] => 0.00019691
//            [high] => 0.00019695
//            [low] => 0.00019502
//            [close] => 0.00019503
//            [volume] => 0.13712290
        $this->logger = $loggerFactory->get('gendan_binance.log', 'gendan_binance');
    }

    public function index(){
        //$this->xiaDan('ADAUPUSDT', 'ADAUP', 1, '1m', 3, 2);
        $this->xiaDan('LINKDOWNUSDT', 'LINKDOWN', 10, '1m', 3, 2);
        //$this->xiaDan('LINKUPUSDT', 'LINKUP', 5, '1m', 3, 2);
    }
    public function xiaDan($bname, $name, $quantity, $interval, $price_dian, $quantity_dian){
        $this->interval = $interval;
        $this->price_dian = $price_dian;
        $this->quantity_dian = $quantity_dian;
        try {
            // 获取K线
            $ticks = $this->klines($bname,  $this->interval, 1);
            // 检查记录是否存在
            $bool = Db::table('gendan')->where('bname', $bname)->exists();
            if(!$bool){
                Db::table('gendan')->updateOrInsert(['bname' => $bname], ['gao_price' => $ticks[0]['close'], 'di_price' => $ticks[0]['close']]
                );
            }
            $newPrice = $this->newPrice($bname);
            var_dump('最新价格:'. $newPrice);
            //Db::table('gendan')->where('bname', $bname)->update(['gao_price' => $newPrice,'di_price' => $newPrice ]);


            // 获取资产信息
            $balances = $this->getBalances($name);
            var_dump($balances[$name]);
            var_dump("总资产:" . ($balances[$name]['available'] + $balances[$name]['onOrder']));

            $first = Db::table('gendan')->where([['bname', '=', $bname]])->first();
//            if($first['count'] >){
//
//            }
            $zichan = bcadd($balances[$name]['available'], $balances[$name]['onOrder'], $this->quantity_dian);

            if($zichan >= $quantity){
                var_dump('资产 >= 数量');
                if($this->newPrice($bname) > $first['gao_price']){
                    var_dump('最新价格 > 最高价格');
                    Db::table('gendan')->where('bname', $bname)->update(['gao_price' => $newPrice,'di_price' => $newPrice ]);

                    $res = $this->CancelOrderAll($bname);
                    if($res['res']){
                        var_dump('执行挂卖单$1');
                        // 执行 止盈止损 挂卖单 STOP_LOSS_LIMIT <=
                        $this->zCrateOrderSell($bname, $newPrice , $quantity, 'STOP_LOSS_LIMIT');
                    }


                } else {
                    var_dump('市价 < 最高价 - 已挂单就无需卖出');
                    // 已挂单 就不操作
                    $bool = $this->getOpenOrder($bname, "SELL");
                    if(!$bool){
                        Db::table('gendan')->where('bname', $bname)->update(['gao_price' => $newPrice ,'di_price' => $newPrice]);
                        $res = $this->CancelOrderAll($bname);
                        if($res['res']){
                            var_dump('执行挂卖单$2');
                            // 执行 止盈止损 挂卖单
                            $this->zCrateOrderSell($bname, $first['gao_price'] , $quantity,'STOP_LOSS_LIMIT');
                        }
                    }
                }
            } else {
                if($this->newPrice($bname) < $first['di_price']){
                    var_dump('最新价格 < 最低价格');
                    Db::table('gendan')->where('bname', $bname)->update(['gao_price' => $newPrice ,'di_price' => $newPrice]);
                    // 撤销一挂订单
                    $res = $this->CancelOrderAll($bname);
                    if($res['res']){
                        var_dump('执行挂买单#1');
                        // 执行 止盈止损 挂买单 TAKE_PROFIT_LIMIT >=
                        $this->zCreateOrderBuy($bname, $this->newPrice($bname) , $quantity, 'STOP_LOSS_LIMIT');
                    }

                } else {
                    var_dump('市价 > 最低价 - 已挂单就无需买入');
                    // 检查是否已挂单, 已挂单就不操作
                    $bool = $this->getOpenOrder($bname, "BUY");
                    var_dump('挂单状态#2'.$bool);
                    if(!$bool){
                        Db::table('gendan')->where('bname', $bname)->update(['gao_price' => $newPrice ,'di_price' => $newPrice]);
                        $res = $this->CancelOrderAll($bname);
                        if($res['res']){
                            var_dump('执行挂买单#2');
                            // 执行 止盈止损 挂买单
                            $this->zCreateOrderBuy($bname, $first['di_price'] , $quantity, 'STOP_LOSS_LIMIT');
                        }
                    }
                }
            }
            unset($zichan);
        } catch (\Exception $e) {
            var_dump('err:'. $e->getMessage());
            $this->logger->error("err:". $e->getMessage()).PHP_EOL;
        }
    }
    public function zCreateOrderBuy($bname, $di_price, $quantity, $type){
        $zhiYingPrice = $di_price + bcdiv($di_price, '100', $this->price_dian);
        //$type = "TAKE_PROFIT_LIMIT"; // Set the type STOP_LOSS (market) or STOP_LOSS_LIMIT, and TAKE_PROFIT (market) or TAKE_PROFIT_LIMIT
        //$quantity = 1;
        $price = $zhiYingPrice; // Try to sell it for 0.5 btc
        $stopPrice = $zhiYingPrice; // Sell immediately if price goes below 0.4 btc
        $this->logger->info('--挂单价格: '.$price.'--').PHP_EOL;
        $this->logger->info('--下单数量: '.$quantity.'--').PHP_EOL;
        var_dump('--#4挂单价格: '.$price.'--');
        var_dump('--#4下单数量: '.$quantity.'--');
        try {
            $this->api->buy($bname, $quantity, $price, $type, ["stopPrice"=>$stopPrice]);
            $this->logger->info('--挂单成功#4: '.'--').PHP_EOL;

        } catch (\Exception $e){
            var_dump($e->getMessage().'#4');
            $this->logger->error($e->getMessage());
        }
        return;


//        var_dump($zhiYingPrice).PHP_EOL;
//        var_dump('最新价格:'. $this->newPrice($bname) );
//        var_dump('最低价格:'.$di_price);
        if($this->newPrice($bname) > $di_price){
            var_dump('最新价格 大于 最低价格 无需操作');
//            var_dump('最新价格 > 下单价');
//            // 上涨止盈
//            $type = "STOP_LOSS_LIMIT"; // Set the type STOP_LOSS (market) or STOP_LOSS_LIMIT, and TAKE_PROFIT (market) or TAKE_PROFIT_LIMIT
//            //$quantity = 1;
//            $price = $zhiYingPrice; // Try to sell it for 0.5 btc
//            $stopPrice = $zhiYingPrice; // Sell immediately if price goes below 0.4 btc
//            $this->logger->info('--#3挂单价格: '.$price.'--').PHP_EOL;
//            $this->logger->info('--#3下单数量: '.$quantity.'--').PHP_EOL;
//            var_dump('--#3挂单价格: '.$price.'--');
//            var_dump('--#3下单数量: '.$quantity.'--');
//            try {
//                $order = $api->buy($bname, $quantity, $price, $type, ["stopPrice"=>$stopPrice]);
//                $this->logger->info('--挂单成功#3: '.'--').PHP_EOL;
//            } catch (\Exception $e){
//                var_dump($e->getMessage().'#3');
//                $this->logger->error($e->getMessage());
//            }

        } else {
            var_dump('最新价格 小于 最低价格 挂BUY单');

            // 下跌止损;  市价低于 买入价. 用: STOP_LOSS_LIMIT
            $type = "STOP_LOSS_LIMIT"; // Set the type STOP_LOSS (market) or STOP_LOSS_LIMIT, and TAKE_PROFIT (market) or TAKE_PROFIT_LIMIT
            //$quantity = 1;
            $price = $zhiYingPrice; // Try to sell it for 0.5 btc
            $stopPrice = $zhiYingPrice; // Sell immediately if price goes below 0.4 btc
            $this->logger->info('--挂单价格: '.$price.'--').PHP_EOL;
            $this->logger->info('--下单数量: '.$quantity.'--').PHP_EOL;
            var_dump('--#4挂单价格: '.$price.'--');
            var_dump('--#4下单数量: '.$quantity.'--');
            try {
                $this->api->buy($bname, $quantity, $price, $type, ["stopPrice"=>$stopPrice]);
                $this->logger->info('--挂单成功#4: '.'--').PHP_EOL;

            } catch (\Exception $e){
                var_dump($e->getMessage().'#4');
                $this->logger->error($e->getMessage());
            }
        }
    }

    public function zCrateOrderSell($bname, $gao_price, $quantity, $type){

        $zhiYingPrice = $gao_price - bcdiv($gao_price, '100', $this->price_dian);


        //$type = "STOP_LOSS_LIMIT"; // Set the type STOP_LOSS (market) or STOP_LOSS_LIMIT, and TAKE_PROFIT (market) or TAKE_PROFIT_LIMIT
        //$quantity = 1;
        $price = $zhiYingPrice; // Try to sell it for 0.5 btc
        $stopPrice = $zhiYingPrice; // Sell immediately if price goes below 0.4 btc
        var_dump('卖出数量:'.$quantity);
        var_dump('卖出价格:'. $price);

        try {
            $this->api->sell($bname, $quantity, $price, $type, ["stopPrice"=>$stopPrice]);
            $this->logger->info('--挂单成功#1: '.'--').PHP_EOL;

        } catch (\Exception $e){
            var_dump($e->getMessage());
            $this->logger->error($e->getMessage());
        }
        return;
        if($this->newPrice($bname) > $gao_price){
            var_dump('最新价格 > 最高价格, 刷新卖单.');
//            LIMIT 限价单
//            MARKET 市价单
//            STOP_LOSS 止损单
//            STOP_LOSS_LIMIT 限价止损单 <=
//            TAKE_PROFIT 止盈单
//            TAKE_PROFIT_LIMIT 限价止盈单   >=
//            LIMIT_MAKER 限价做市单

            // 上涨止盈
            $type = "STOP_LOSS_LIMIT"; // Set the type STOP_LOSS (market) or STOP_LOSS_LIMIT, and TAKE_PROFIT (market) or TAKE_PROFIT_LIMIT
            //$quantity = 1;
            $price = $zhiYingPrice; // Try to sell it for 0.5 btc
            $stopPrice = $zhiYingPrice; // Sell immediately if price goes below 0.4 btc
            var_dump('卖出数量:'.$quantity);
            var_dump('卖出价格:'. $price);

            try {
                $this->api->sell($bname, $quantity, $price, $type, ["stopPrice"=>$stopPrice]);
                $this->logger->info('--挂单成功#1: '.'--').PHP_EOL;

            } catch (\Exception $e){
                var_dump($e->getMessage());
                $this->logger->error($e->getMessage());
            }
        } else {
            var_dump('最新价格 小于 最高价格, 无需操作');
//            // 下跌止损
//            $type = "STOP_LOSS_LIMIT"; // Set the type STOP_LOSS (market) or STOP_LOSS_LIMIT, and TAKE_PROFIT (market) or TAKE_PROFIT_LIMIT
//            //$quantity = 1;
//            $price = $zhiYingPrice; // Try to sell it for 0.5 btc
//            $stopPrice = $zhiYingPrice; // Sell immediately if price goes below 0.4 btc
//            var_dump('2卖出数量:'.$quantity);
//            var_dump('2卖出价格:'. $price);
//            try {
//                $order = $this->api->sell($bname, $quantity, $price, $type, ["stopPrice"=>$stopPrice]);
//                $this->logger->info('--挂单成功#2: '.'--').PHP_EOL;
//                //var_dump($order);
//            } catch (\Exception $e){
//                var_dump($e->getMessage());
//                $this->logger->error($e->getMessage());
//            }
        }


    }



    // 获取未结订单
    public function getOrderStart($bname){
        try {
           return $this->api->openOrders($bname);
        } catch (\Exception $e) {
            $this->res->error('获取订单状态错误:'.$e->getMessage());
        }
    }
    // 通过订单状态 检查买卖单
    public function getOpenOrder($bname, $side){
        $t = false;

        try {
            $openorders = $this->api->openOrders($bname);
            //var_dump('挂单状态:',$openorders);
        } catch (\Exception $e) {
            $this->logger->error("查询挂单错误:".$e->getMessage());
        }

        if($side == 'BUY'){
            foreach ($openorders as $key => $value){
                // 验证是否挂卖单
                if($openorders[$key]['side'] == "BUY"){
                   $t = true;
                }
            }
        } else {
            foreach ($openorders as $key => $value){
                // 验证是否挂卖单
                if($openorders[$key]['side'] == "SELL"){
                    $t = true;
                }
            }
        }

        return $t;
    }

    // 获取最新价格
    public function newPrice($bname){

        try {
            return $this->api->price($bname);
        } catch (\Exception $e) {
            $this->logger->error("err:最新价格". $e->getMessage());
        }
        //echo "Price of BNB: {$price} BTC.".PHP_EOL;
    }

    // 获取K线数据
    public function klines($bname, $interval, $limit){

        try {
            $arr = array();
            $ticks = $this->api->candlesticks($bname, $interval , $limit);
            $i = 0;
            foreach ($ticks as $k => $v){
                $arr[$i] = $ticks[$k];
                $i++;
            }
            return $arr;
        } catch (\Exception $e) {
            $this->logger->error("err:获取k线错误:" . $e->getMessage());
        }


    }

    // 资产查询
    public function getBalances($name){

        // 资产查询
        try {
            return $this->api->balances($name);
        } catch (\Exception $e) {
            $this->logger->error("err:查询资产" . $e->getMessage());
        }
    }
    // 撤销全部挂单
    public function CancelOrderAll($bname){
        try {
            $openorders = $this->api->openOrders($bname);
            //var_dump('状态:',$openorders);
            foreach ($openorders as $key => $value){
                try {
                    $this->api->cancel($bname, $openorders[$key]['orderId']);
                } catch (\Exception $e) {
                    $this->res->error('撤销订单失败:'.$e->getMessage());
                }
            }
            return $this->res->success('撤销订单成功');
        } catch (\Exception $e) {
            return $this->res->error('获取挂单信息失败'. $e->getMessage());

        }

    }
}