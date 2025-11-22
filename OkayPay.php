<?php

class OkayPay
{
    protected $id;
    protected $token;
    protected $api_url_payLink;
    protected $api_url_transfer;
    protected $api_url_TransactionHistory;
    protected $api_url_checkTransferByTxid;


    public function __construct($id, $token)
    {
        $this->id = $id;
        $this->token = $token;

        $api_url = 'https://api.okaypay.me/shop/';
        $this->api_url_payLink    = $api_url . 'payLink';
        $this->api_url_transfer    = $api_url . 'transfer';
        $this->api_url_TransactionHistory    = $api_url . 'TransactionHistory';
        $this->api_url_checkTransferByTxid = $api_url . 'checkTransferByTxid';
    }


    /**
     * 获取支付链接
     * 参数
     * unique_id : 可选(唯一编号,防止重复下单)
     * name : 显示信息 可选
     * amount : 金额 必须
     * return_url : 返回链接 可选
     * coin : [USDT,TRX] 必须
     * */
    public function payLink(array $data)
    {
        $this->url = $this->api_url_payLink;
        return $this->post($data);
    }

    /**
     * 转账给用户
     * 参数
     * unique_id : 可选(唯一编号,防止重复下单)
     * name : 显示信息 可选
     * amount : 金额
     * to_user_id : 用户tgid 用户必须启动过钱包 必须
     * coin : [USDT,TRX] 必须
     * */
    public function transfer(array $data)
    {
        $this->url = $this->api_url_transfer;
        return $this->post($data);
    }

    /**
     * 商户帐变
     * 参数
     * unique_id : 可选(唯一编号,防止重复下单)
     * name : 显示信息 可选
     * amount : 金额
     * to_user_id : 用户tgid 用户必须启动过钱包 必须
     * coin : [USDT,TRX] 必须
     * */
    public function shop_transaction_history(array $data)
    {
        $this->url = $this->api_url_TransactionHistory;
        return $this->post($data);
    }
    
    /**
     * 检查订单是否完成
     * 参数
     * txid : payLink返回的order_id
     */
    public function checkTransferByTxid(array $data)
    {
        $this->url = $this->api_url_checkTransferByTxid;
        return $this->post($data);
    }

    /*
     * 异步接受通知：
     * id : 商户id
     * sign : 签名
     * data : {
     * order_id : api接口生成的订单
     * unique_id : 传入的订单
     * pay_user_id : 支付的用户tg id
     * amount : 充值金额
     * coin : 币种 [USDT,TRX]
     * }
     * * 按json格式返回：
     * [
     * 'status' => 'success',
     * ]
    */
    public function notify()
    {
        $data = $_POST;
        if($this->checkSign($data)){
            echo '验证成功';
            if($data['status'] == 'success' && isset($data['code']) && $data['code'] == 10000){{
                // 数据正常
            }
                // 数据不正常
            }
        }else{
            echo '验证失败';
        }
    }

    // 数据签名
    public function sign(array $data)
    {
        $data['id'] = $this->id;
        $data = array_filter($data);
        ksort($data);
        $data['sign'] = strtoupper(md5(urldecode(http_build_query($data) . '&token=' . $this->token)));
        return $data;
    }

    // 校验数据签名
    public function checkSign($data)
    {
        $in_sign = $data['sign'];
        unset($data['sign']);
        $data = array_filter($data);
        ksort($data);
        $sign = strtoupper(md5(urldecode(http_build_query($data) . '&token=' . $this->token)));
        return $in_sign == $sign ? true : false;
    }

    // 数据发送
    public function post($data)
    {
        $data   = $this->sign($data);
        // echo $this->url;
        // exit(json_encode($data));
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'HTTP CLIENT');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));

        $data = curl_exec($ch);
        curl_close($ch);
        return json_decode($data, true);
    }
}