<?php

declare(strict_types=1);
//
namespace App\Controller;
use Hyperf\DbConnection\Db;
use  Binance;
use Hyperf\Logger\LoggerFactory;
class CeshiController extends AbstractController
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;
    protected $interval;    // k线 周期.
    protected $price_dian;
    public function __construct(LoggerFactory $loggerFactory)
    {
        // 第一个参数对应日志的 name, 第二个参数对应 config/autoload/logger.php 内的 key
        $this->logger = $loggerFactory->get('binance.log', 'binance');
    }
    public function index(){
        $this->logger->info('########################################################');
        //1m, 3m, 5m, 15m, 30m, 1h, 2h, 4h, 6h, 8h, 12h, 1d, 3d, 1w, 1M

        //$this->xiaDan('LINKUPUSDT', 1, '1h', 3);
        $this->xiaDan('ADAUPUSDT', 1, '1h', 3);
        // 获取订单状态
//        $orderstatus = $this->getOrderStatus('ADAUPUSDT', '95798810');
//        var_dump($orderstatus);
//        $balances = $this->getBalances('ADAUPUSDT');
//        var_dump($balances);

    }
    public function xiaDan($bname, $quantity, $interval, $price_dian)
    {
        $this->interval = $interval;
        $this->price_dian = $price_dian;

        //$api = new Binance\API(env('KEY'), env('SECRET'));
        try {
            // 获取K线
            $ticks = $this->klines($bname,  $this->interval, 1);

            // 检查记录是否已存在
            $jl_bool = Db::table('klines')->where([['openTime', '=', $ticks[0]['openTime']], ['symbol', '=', $bname]])->exists();
            if($jl_bool){
                var_dump('记录存在');
                //var_dump($bname+': 本期订单已处理');
                //$getBalances = getBalances($bname);
                // 挂 止盈止损单.
                $this->guaDan($bname, $quantity);
                return '本期订单已处理';
            } else {
                var_dump('记录不存在');
                // 记录不存在, 新的开始全部删除
                // 已完成. 删除记录
                Db::table('guadan')->where([['symbol', '=', $bname], ['type', '=', 'TAKE_PROFIT_LIMIT']])->delete();
                Db::table('guadan')->where([['symbol', '=', $bname], ['type', '=', 'STOP_LOSS_LIMIT']])->delete();
                //Db::table('guadan')->where([['symbol', '=', $bname], ['type', '=', 'open']])->delete();
                // 撤销订单
                $cancelOrder = $this->CancelOrderAll($bname);

                $guadan = Db::table('guadan')->where([['type', '=', 'open'], ['symbol', '=', $bname]])->first();
                if($guadan){
                    // 下单成功, 写入数据库
                    $ticks[0]['symbol'] = $bname;
                    $id = Db::table('klines')->insertGetId($ticks[0]);
                    var_dump('k线记录, 写入数据库');
                    return;
                }


                // 撤销订单成功
                if($cancelOrder){
//                    // 获取资产信息
//                    $balances = $this->getBalances($bname);
//
//                    if($balances[$bname]['available'] >= $quantity){
//                        // 下单成功, 挂单信息写入数据库
//                        $data = array('symbol' => $order['symbol'], 'orderId' => $order['orderId'], 'price' => $order['price'], 'type' => 'open', 'mm' => 'buy');
//                        $id = Db::table('guadan')->insertGetId($data);
//                    }
                    // 执行下单
                    $order = $this->xianJiaOrder($bname, $quantity, $ticks[0]['open']);
                    if($order == false){
                        var_dump('下开盘单失败');
                        return;
                    }
                    if($order['orderId'] > 0){
                        // 下单成功, 挂单信息写入数据库
                        $data = array('symbol' => $order['symbol'], 'orderId' => $order['orderId'], 'price' => $order['price'], 'type' => 'open', 'mm' => 'buy');
                        $id = Db::table('guadan')->insertGetId($data);
                        $ticks[0]['symbol'] = $bname;
                        // 下单成功, 写入数据库
                        $id = Db::table('klines')->insertGetId($ticks[0]);
                        // 检查记录是否已存在
                        $jl_bool = Db::table('ok')->where('symbol', $bname)->exists();
                        if(!$jl_bool){
                            // 初始化 ok 表
                            Db::table('ok')->insert(['error' => 1, 'succes' => 1, 'symbol' => $bname]);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            //$this->logger->error("查询K线错误", $e);
            return $e->getMessage();
        }
    }

    public function guaDan($bname, $quantity){
        $this->logger->info('--开始挂单--').PHP_EOL;
        $api = new Binance\API(env('KEY'), env('SECRET'));
        $guadan= Db::table('guadan')->orderBy('id', 'desc')->where([['type', '=', 'open'], ['symbol', '=', $bname]])->first();
        if($guadan){

            // 获取订单状态
            $orderstatus = $this->getOrderStatus($bname, $guadan['orderId']);
            $this->logger->info('--开盘订单状态: '.$orderstatus['status'].' --').PHP_EOL;
            // FILLED 订单完成 CANCELED 已撤销 NEW 新订单
            if($orderstatus['status'] == 'CANCELED'){
                // 同时也删除 开盘订单记录
                Db::table('guadan')->where([['symbol', '=', $bname], ['type', '=', 'open']])->delete();
            }
            if($orderstatus['status'] == 'FILLED') {

                // 获取最新价格 大于 完成订单价格. 止盈
                if($this->newPrice($bname) > $orderstatus['price']){
                    $this->logger->info('--最新价格 > 开盘价格-').PHP_EOL;

                    // 检查止盈单是否已存在,
                    $first = Db::table('guadan')->where([['symbol', '=', $bname], ['type', '=', 'TAKE_PROFIT_LIMIT']])->first();

                    if($first){
                        // 获取订单状态
                        $orderstatus = $this->getOrderStatus($bname, $first['orderId']);
                        $this->logger->info('--上涨止盈单状态: '.$orderstatus['status'] .'--').PHP_EOL;
                        // 判断挂单是否已完成
                        if($orderstatus['status'] == 'FILLED') {
                            $ticks = $this->klines($bname, $this->interval, 1);
                            // 增长百分比计算
                            $b = ($orderstatus['price'] - $ticks[0]['open']) / $ticks[0]['open'];
                            $bfb = bcmul((string)$b, '100', 0);
                            // 盈利的点, - 掉亏损的点
                            Db::table('ok')->where('symbol', '=', $bname)->decrement('error', (int)$bfb);
                            $first = Db::table('ok')->where([['symbol', '=', $bname]])->first();
                            if($first['error'] < 1){
                                Db::table('ok')->updateOrInsert(['symbol' => $bname], ['error' => 1, 'succes' => 1] );
                            }

                            // 已完成. 删除记录
                            $j = Db::table('guadan')->where([['symbol', '=', $bname], ['type', '=', 'TAKE_PROFIT_LIMIT']])->delete();
                            $this->logger->info('--删除下单下跌止损纪录: '.$j.'--').PHP_EOL;

                            // 同时也删除 开盘订单记录
                            $open = Db::table('guadan')->where([['symbol', '=', $bname], ['type', '=', 'open']])->delete();
                            $this->logger->info('--删除开盘订单纪录: '.$open.'--').PHP_EOL;
                        }

                    } else {
                        $this->logger->info('--订单不存在, 开始挂单 上涨止盈--').PHP_EOL;
                        // 撤销 全部挂单
                        $this->CancelOrderAll($bname);
                        $first = Db::table('ok')->where([['symbol', '=', $bname]])->first();

                        $zhiYingPrice = $orderstatus['price'] + bcdiv($orderstatus['price'], '100', $this->price_dian);

                        //var_dump($first);
                        if($first['error'] > 1){
                            $zhiYingPrice = bcadd(bcmul(bcdiv((string)$zhiYingPrice, '100', $this->price_dian), (string)$first['error'], $this->price_dian), (string)$zhiYingPrice, $this->price_dian);
                        }
                        // 挂上涨止盈单, 就删除下跌止损纪录
                        Db::table('guadan')->where([['symbol', '=', $bname], ['type', '=', 'STOP_LOSS_LIMIT']])->delete();
                        // 上涨止盈
                        $type = "TAKE_PROFIT_LIMIT"; // Set the type STOP_LOSS (market) or STOP_LOSS_LIMIT, and TAKE_PROFIT (market) or TAKE_PROFIT_LIMIT
                        //$quantity = 1;
                        $price = $zhiYingPrice; // Try to sell it for 0.5 btc
                        $stopPrice = $zhiYingPrice; // Sell immediately if price goes below 0.4 btc
                        try {

                            $order = $api->sell($bname, $quantity, $price, $type, ["stopPrice"=>$stopPrice]);
                            $data = array('symbol' => $order['symbol'], 'orderId' => $order['orderId'], 'mm' => 'sell', 'type' => 'TAKE_PROFIT_LIMIT', 'status'=>'on');
                            $id = Db::table('guadan')->insertGetId($data);
                            $this->logger->info('--挂单成功写入数据库ID: '.$id.'--').PHP_EOL;
                        } catch (\Exception $e){
                            var_dump($e->getMessage());
                            $this->logger->error($e->getMessage());
                        }

                    }
                    //var_dump($order);

                } else {
                    $this->logger->info('--最新价格 < 开盘价格--').PHP_EOL;

                    // 检查记录是否已存在
                    $first = Db::table('guadan')->where([['symbol', '=', $bname], ['type', '=', 'STOP_LOSS_LIMIT']])->first();

                    if($first){

                        // 获取订单状态
                        $orderstatus = $this->getOrderStatus($bname, $first['orderId']);
                        $this->logger->info('--下跌止损单状态: '.$orderstatus['status'] .'--').PHP_EOL;

                        // 判断挂单是否已完成
                        if($orderstatus['status'] == 'FILLED') {
                            // 亏损增加1
                            Db::table('ok')->where('symbol', '=', $bname)->increment('error', 1);
                            // 已完成. 删除止损单
                            $j = Db::table('guadan')->where([['symbol', '=', $bname], ['type', '=', 'STOP_LOSS_LIMIT']])->delete();
                            $this->logger->info('--删除下单下跌止损纪录: '.$j.'--').PHP_EOL;

                            // 同时也删除 开盘订单记录
                            $open = Db::table('guadan')->where([['symbol', '=', $bname], ['type', '=', 'open']])->delete();
                            $this->logger->info('--删除开盘订单纪录: '.$open.'--').PHP_EOL;
                            // 下跌止损单已被吃, 重新下开盘买入单.
                            $this->createOpenOrder($bname, $quantity);
                        }
                    } else {
                        $this->logger->info('--订单不存在, 开始挂单 下跌止损单--').PHP_EOL;
                        // 撤销 全部挂单
                        $this->CancelOrderAll($bname);
                        // 下跌止损单
                        $zhiSunPrice =  $orderstatus['price'] - bcdiv($orderstatus['price'], '100', $this->price_dian);

                        // 挂下跌止损单, 就删除上涨止盈单纪录
                        Db::table('guadan')->where([['symbol', '=', $bname], ['type', '=', 'TAKE_PROFIT_LIMIT']])->delete();

                        // 下跌止损
                        $type = "STOP_LOSS_LIMIT"; // Set the type STOP_LOSS (market) or STOP_LOSS_LIMIT, and TAKE_PROFIT (market) or TAKE_PROFIT_LIMIT
                        //$quantity = 1;
                        $price = $zhiSunPrice; // Try to sell it for 0.5 btc
                        $stopPrice = $zhiSunPrice; // Sell immediately if price goes below 0.4 btc
                        try {
                            $order = $api->sell($bname, $quantity, $price, $type, ["stopPrice"=>$stopPrice]);
                            $data = array('symbol' => $order['symbol'], 'orderId' => $order['orderId'], 'mm' => 'sell', 'type' => 'STOP_LOSS_LIMIT', 'status'=>'on');
                            $id = Db::table('guadan')->insertGetId($data);
                            $this->logger->info('--挂单成功写入数据库ID: '.$id.'--').PHP_EOL;
                            //var_dump($order);
                        } catch (\Exception $e){
                            var_dump($e->getMessage());
                            $this->logger->error($e->getMessage());
                        }

                    }
                }
            }
        } else {
            // 没有记录, 执行挂开盘价单
            $this->createOpenOrder($bname, $quantity);
            $this->logger->error("err:挂单为空");
            return;
        }


    }
    // 获取最新价格
    public function newPrice($bname){
        $api = new Binance\API(env('KEY'), env('SECRET'));
        try {
            return $api->price($bname);
        } catch (\Exception $e) {
            $this->logger->error("err:最新价格". $e->getMessage());
        }
        //echo "Price of BNB: {$price} BTC.".PHP_EOL;
    }

    // 创建开盘价订单, 市价低于 买入价. 用: STOP_LOSS_LIMIT
    public function createOpenOrder($bname, $quantity){
        $this->logger->info('--重新创建开盘价订单, 因为止损单陪吃掉--').PHP_EOL;
        // 已完成. 删除开盘单
        Db::table('guadan')->where([['symbol', '=', $bname], ['type', '=', 'open']])->delete();
        $api = new Binance\API(env('KEY'), env('SECRET'));
        $ticks = $this->klines($bname, $this->interval, 1);
        // 撤销 全部挂单
        $this->CancelOrderAll($bname);
        // 获取最新价格 大于 完成订单价格. 止盈
        if($this->newPrice($bname) > $ticks[0]['open']){
            var_dump('最新价格 > 开盘价');
            //$zhiYingPrice = $orderstatus['price'] + $orderstatus['price'] / 100;
            $zhiYingPrice = $ticks[0]['open'];
            // 上涨止盈
            $type = "TAKE_PROFIT_LIMIT"; // Set the type STOP_LOSS (market) or STOP_LOSS_LIMIT, and TAKE_PROFIT (market) or TAKE_PROFIT_LIMIT
            //$quantity = 1;
            $price = $zhiYingPrice; // Try to sell it for 0.5 btc
            $stopPrice = $zhiYingPrice; // Sell immediately if price goes below 0.4 btc
            $this->logger->info('--挂单价格: '.$price.'--').PHP_EOL;
            var_dump('--挂单价格: '.$price.'--');
            try {
                $order = $api->buy($bname, $quantity, $price, $type, ["stopPrice"=>$stopPrice]);
                // 下单成功, 挂单信息写入数据库
                $data = array('symbol' => $order['symbol'], 'orderId' => $order['orderId'], 'type' => 'open', 'mm' => 'sell');
                $id = Db::table('guadan')->insertGetId($data);
                $this->logger->info('--挂单成功写入数据库ID: '.$id.'--').PHP_EOL;
            } catch (\Exception $e){
                var_dump($e->getMessage().'#1');
                $this->logger->error($e->getMessage());
            }


        } else {
            var_dump('最新价格 < 开盘价');
            //$zhiSunPrice =  $orderstatus['price'] - $orderstatus['price'] / 100;
            $zhiSunPrice = $ticks[0]['open'];

            // 下跌止损;  市价低于 买入价. 用: STOP_LOSS_LIMIT
            $type = "STOP_LOSS_LIMIT"; // Set the type STOP_LOSS (market) or STOP_LOSS_LIMIT, and TAKE_PROFIT (market) or TAKE_PROFIT_LIMIT
            //$quantity = 1;
            $price = $zhiSunPrice; // Try to sell it for 0.5 btc
            $stopPrice = $zhiSunPrice; // Sell immediately if price goes below 0.4 btc
            $this->logger->info('--挂单价格: '.$price.'--').PHP_EOL;
            var_dump('--挂单价格: '.$price.'--');
            try {
                $order = $api->buy($bname, $quantity, $price, $type, ["stopPrice"=>$stopPrice]);
                // 下单成功, 挂单信息写入数据库
                $data = array('symbol' => $order['symbol'], 'orderId' => $order['orderId'], 'type' => 'open', 'mm' => 'buy');
                $id = Db::table('guadan')->insertGetId($data);
                $this->logger->info('--挂单成功写入数据库ID: '.$id.'--').PHP_EOL;

            } catch (\Exception $e){
                var_dump($e->getMessage().'#2');
                $this->logger->error($e->getMessage());
            }

        }
    }

    // 获取订单状态
    public function getOrderStatus($bname, $orderId){
        $api = new Binance\API(env('KEY'), env('SECRET'));
        // 获取订单状态
        try {
            return $api->orderStatus($bname, $orderId);
        } catch (\Exception $e) {
            $this->logger->error("err:获取挂单状态" . $e->getMessage());
        }
    }
    // 资产查询
    public function getBalances($bname){
        $api = new Binance\API(env('KEY'), env('SECRET'));
        // 资产查询
        try {
            return $api->balances($bname);
        } catch (\Exception $e) {
            $this->logger->error("err:查询资产" . $e->getMessage());
        }
    }

    // 下 限价单
    public function xianJiaOrder($bname, $quantity, $price){
        $api = new Binance\API(env('KEY'), env('SECRET'));
        try {
            return $api->buy($bname, $quantity, $price);
        } catch (\Exception $e){
            var_dump($e->getMessage().'这里有问题');
            $this->logger->error($e->getMessage());
            return false;
        }
    }

    // 获取K线数据
    public function klines($bname, $interval, $limit){
        $api = new Binance\API(env('KEY'), env('SECRET'));
        $ticks = $api->candlesticks($bname, $interval , $limit);
        $i = 0;
        foreach ($ticks as $k => $v){
            $arr[$i] = $ticks[$k];
            $i++;
        }
        return $arr;
    }
    // 撤销全部挂单
    public function CancelOrderAll($bname){
        $api = new Binance\API(env('KEY'), env('SECRET'));
        try {
            $openorders = $api->openOrders($bname);
            foreach ($openorders as $key => $value){
                try {
                    $response = $api->cancel($bname, $openorders[$key]['orderId']);
                    $this->logger->info("取消订单:", $response);
                } catch (\Exception $e) {
                    $this->logger->error("err:撤销订单" . $e->getMessage());
                }
            }
            return true;
        } catch (\Exception $e) {
            $this->logger->error("获取挂单信息" . $e->getMessage());
        }

    }
}
