<?php


namespace App\Controller;
use  Binance;

class ApiBinance extends AbstractController
{
    public function authBinance(){
        $this->yue('ADADOWNUSDT', 0.00130,  400, 2);
    }

    public function yue($bname = "ADADOWNUSDT", $opens = 0.00160, $usdt = 100 , $shuliangweishu = 2){
        $api = new Binance\API(env('KEY'), env('SECRET'));
        try {
            $ticks = $api->candlesticks($bname, "1M", 1);
        } catch (\Exception $e) {
            var_dump("查询K线错误", $e);
        }

        $open = array_column($ticks,'open');
        // 自定义开盘价格,
        if($opens == 0){
            $opens = $open[0];
        }

        $close = array_column($ticks, 'close');

        try {
            $price = $api->price($bname);
        } catch (\Exception $e) {
            var_dump("查询价格错误:", $e);
        }   // 最新价格
        $quantity = bcdiv($usdt, $price, $shuliangweishu);  // 金额除以价格=数量

        var_dump($opens);
        var_dump($close);
        if($close[0] > $opens){
            // 买入操作
            print('买入--------------------').PHP_EOL;

            // 取消卖单
            $this->CancelOrder($bname, "SELL");
            // 资产查询
            try {
                $balances = $api->balances($bname);
            } catch (\Exception $e) {
                var_dump("查询资产错误:", $e);
            }
            $assets_total = bcadd($balances['ADADOWN']['available'], $balances['ADADOWN']['onOrder'], $shuliangweishu);
            if($assets_total >= $quantity){
                var_dump('资产已大于等于, 购买数量, 无需购买');
                return;
            }

            $quan = $this->openOrders($bname, "SELL", $shuliangweishu);

            var_dump("资产:", $quan);
            var_dump("数量:", $quantity);
            if($quan >= $quantity){
                print('买单已下: 无需重复下单').PHP_EOL;
                return;
            }
            try {
                $order = $api->buy($bname, $quantity, $price);
            } catch (\Exception $e) {
                var_dump("下单买入错误:", $e);
            }

            print_r($order);
            return;
        }else{
            // 卖出操作
            print("--------------------卖出").PHP_EOL;

            // 取消买单
            $this->CancelOrder($bname, "BUY");

            $quan = $this->openOrders($bname, "SELL", $shuliangweishu);

            if($quan >= $quantity){
                print('卖单已下: 无需重复下单').PHP_EOL;
                return;
            }

            // 资产查询
            try {
                $balances = $api->balances($bname);
            } catch (\Exception $e) {
                var_dump("查询资产错误:", $e);
            }

            // 判断价值..
            $jiazhi = bcmul($balances['ADADOWN']['available'], $price, 2);

            if($jiazhi < 10){
                var_dump('资产数量:', $jiazhi);
                print('资产价值不足 100 $ 无法下单').PHP_EOL;
                return;
            }
            var_dump('什么鬼',bcadd($balances['ADADOWN']['available'], 0, 2));
            var_dump($bname);
            var_dump($price);
            try {
                $order = $api->sell($bname, bcadd($balances['ADADOWN']['available'], 0, 2), $price);
                print_r($order);
            } catch (\Exception $e) {
                var_dump("下单卖出错误:", $e);
            }
        }
    }


    // 查询是否已挂单
    public function openOrders($bname, $sale, $shuliangweishu){
        $api = new Binance\API(env('KEY'), env('SECRET'));
        try {
            $openorders = $api->openOrders($bname);
        } catch (\Exception $e) {
            var_dump("查询挂单错误:", $e);
        }     // 获取已挂单信息
        $quan = 0;
        if($sale == "BUY"){
            foreach ($openorders as $key => $value){
                // 验证是否挂卖单
                if($openorders[$key]['side'] == "BUY"){
                    $quan += $openorders[$key]['origQty'];
                    $quan = bcadd($quan, 0, $shuliangweishu);
                }
            }
        } else {
            foreach ($openorders as $key => $value){
                // 验证是否挂卖单
                if($openorders[$key]['side'] == "SELL"){
                    $quan += $openorders[$key]['origQty'];
                    $quan = bcadd($quan, 0, $shuliangweishu);
                }
            }
        }
        return $quan;
    }


    // 撤销挂单
    public function CancelOrder($bname, $sale){
        $api = new Binance\API(env('KEY'), env('SECRET'));
        try {
            $openorders = $api->openOrders($bname);
        } catch (\Exception $e) {
            var_dump("查询已挂单错误:", $e);
        }     // 获取已挂单信息
        if($sale == "BUY"){
            foreach ($openorders as $key => $value){
                // 验证是否挂卖单
                if($openorders[$key]['side'] == "BUY"){
                    // 取消订单
                    try {
                        $response = $api->cancel($bname, $openorders[$key]['orderId']);
                        var_dump("取消订单:", $response);
                    } catch (\Exception $e) {
                        var_dump("撤销订单错误!", $e);
                    }
                }
            }
        } elseif ($sale == "SELL"){
            foreach ($openorders as $key => $value){
                // 验证是否挂卖单
                if($openorders[$key]['side'] == "SELL"){
                    // 取消订单
                    try {
                        $response = $api->cancel($bname, openorders[$key]['orderId']);
                        var_dump("取消订单:", $response);
                    } catch (\Exception $e) {
                        var_dump("撤销订单错误!", $e);
                    }
                }
            }
        }
    }

    public function test(){
        // "code":-1013,"msg":"Filter failure: MIN_NOTIONAL"    // 最小下单数量不够
        // "code":-2010,"msg":"Account has insufficient balance for requested action." //余额不足
    }

}