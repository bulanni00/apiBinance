<?php


namespace App\Controller;
use  Binance;

class ApiBinance extends AbstractController
{
    public function authBinance(){
        $this->yue('ADADOWNUSDT', 0.00160, 64516.12, 100, 2);
    }

    public function yue($bname = "ADADOWNUSDT", $opens = 0.00160, $quantity = 64516.12, $usdt = 100 , $shuliangweishu = 2){
        $api = new Binance\API(env('KEY'), env('SECRET'));
        $ticks = $api->candlesticks($bname, "1M",1);

        $open = array_column($ticks,'open');
        // 自定义开盘价格,
        if($opens == 0){
            $opens = $open[0];
        }

        $close = array_column($ticks, 'close');


        //exit;
        var_dump($opens);
        var_dump($close);
        if($close[0] > $opens){
            // 买入操作
            print('买入--------------------').PHP_EOL;
            // 资产查询
            $balances = $api->balances($bname);
            $assets_total = bcadd($balances['ADADOWN']['available'], $balances['ADADOWN']['onOrder'], $shuliangweishu);
            if($assets_total >= $quantity){
                var_dump('资产已大于等于, 购买数量, 无需购买');
                return;
            }
            $price = $api->price($bname);   // 最新价格
            //$quantity = bcdiv($usdt, $price, $shuliangweishu);  // 金额除以价格=数量

            $openorders = $api->openOrders($bname);     // 获取已挂单信息

            $quan = 0;
            foreach ($openorders as $key => $value){
                // 验证是否挂买单
                if($openorders[$key]['side'] == "BUY"){
                    $quan += $openorders[$key]['origQty'];
                    $quan = bcadd($quan, 0, $shuliangweishu);
                }
            }

            if($quan >= $quantity){
                print('买单已下: 无需重复下单').PHP_EOL;
                return;
            }

            $order = $api->buy($bname, $quantity, $price);
            print_r($order);
            return;
        }else{
            // 卖出操作
            print("--------------------卖出").PHP_EOL;
            $price = $api->price($bname);   // 最新价格

            $openorders = $api->openOrders($bname);     // 获取已挂单信息

            $quan = 0;
            foreach ($openorders as $key => $value){
                // 验证是否挂卖单
                if($openorders[$key]['side'] == "SELL"){
                    $quan += $openorders[$key]['origQty'];
                    $quan = bcadd($quan, 0, $shuliangweishu);
                }
            }

            if($quan >= $quantity){
                print('卖单已下: 无需重复下单').PHP_EOL;
                return;
            }

            // 资产查询
            $balances = $api->balances($bname);
            //$assets_total = bcadd($balances['ADADOWN']['available'], $balances['ADADOWN']['onOrder'], $shuliangweishu);

            // 判断价值..
            $jiazhi = bcmul($balances['ADADOWN']['available'], $price, 2);
            if($jiazhi < 100){
                print('资产价值不足 100 $ 无法下单').PHP_EOL;
                return;
            }
            $order = $api->sell($bname, $balances['ADADOWN']['available'], $price);
            print_r($order);
            return;
        }

//        Array
//        (
//            [1612137600000] => Array
//            (
//                [open] => 0.34464000
//                [high] => 1.20000000
//                [low] => 0.33214000
//                [close] => 1.07146000
//                [volume] => 13895504516.96805240
//                [openTime] => 1612137600000
//                [closeTime] => 1614556799999
//                [assetVolume] => 18846940068.36000000
//                [baseVolume] => 13895504516.96805240
//                [trades] => 17045582
//                [assetBuyVolume] => 9394159369.59000000
//                [takerBuyVolume] => 6928556218.13364670
//                [ignored] => 0
//            )
//        )
//        while(true) {
//            $api->openOrders("BNBBTC"); // rate limited
//        }
//        return [
//            'method' => '1',
//            'message' => "Hello {}.",
//        ];
    }

    public function test(){
        print('1111zzzzzzzzzzzzzzzzzzzzzz,id:');
//        $api = new Binance\API(env('KEY'), env('SECRET'));
//        // 获取最新价格.
//        $ticker = $api->prices();
//        print_r($ticker);

        // 公共历史成交
//        $orders = $api->orders("BNBBTC");
//        print_r($orders);

        // "code":-1013,"msg":"Filter failure: MIN_NOTIONAL"    // 最小下单数量不够
        // "code":-2010,"msg":"Account has insufficient balance for requested action." //余额不足
    }

}