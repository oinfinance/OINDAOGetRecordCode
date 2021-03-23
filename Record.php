<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 */
namespace app\index\command;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\Cache;
use think\Loader;
use think\Db;

class Record extends Command{

    protected function configure(){
        $this->setName('Record')->setDescription("下单操作");//这里的setName和php文件名一致,setDescription随意
    }

    /*
     * 下单回调操作
     */
    protected function execute(Input $input, Output $output)
    {
        //业务逻辑
        while(true){
            $dir = date("Y_m", time());
            $file = date("Y_m_d", time());
            $name='Record';
            if (!file_exists(APP_PATH . 'logs/' . $dir.'/'.$name)) {
                mkdir(APP_PATH . 'logs/' . $dir.'/'.$name, 0755, true);
            }
            self::orderCallback($name,$dir,$file);
            usleep(300000);
        }
    }


    private static function record($name,$dir,$file)
    {
        //正式环境
        $domain='https://api.etherscan.io';
        $hy1='0x9aeb50f542050172359a0e1a25a9933bc8c01259';//oin合约地址
        $hy2='0x54d16d35ca16163bc681f39ec170cf2614492517';//Lptoken合约地址

        //获取缓存中的列表
        $res  = Cache::init();
        $redis = $res->handler();
        $count=$redis->Llen('makeLogtoken');
        if($count>0){
            $info=$redis->rpop('makeLogtoken');
            //将数据加入数据库中  数据库加入失败  重新加入
            $info1=json_decode($info,true);

            if(!$info1['tx_hash'] || !$info1['type']){
                    return false;
            }
            //判断当前hash是否存在
            $ten=db('***')->field('number')->where('tx_hash',$info1['tx_hash'])->find();
            if(empty($ten)){
                $id=db('***')->insertGetId($info1);
            }else{
                $id=1;
            }

            if($id){
                //获取交易状态
                $url=$domain."/api?module=transaction&action=getstatus&txhash=$info1[tx_hash]&apikey=*********";
                $body = array();
                $header = array();
                $result = self::curlPost($url, $body, 50, $header, '');
                $result=json_decode($result,true);

                if($result['result']['isError']==1){
                    return 1;
                }

                $data['wallet_address']='';
                $data['content']='';
                switch ($info1['type']){
                    case 1://申请质押
                        $url=$domain."/api?module=proxy&action=eth_getTransactionByHash&txhash=$info1[tx_hash]&apikey=*********";
                        $body = array();
                        $header = array();
                        $result = self::curlPost($url, $body, 50, $header, '');
                        $result=json_decode($result,true);
                        if($result['result']['from']){
                            $data['wallet_address']=$result['result']['from'];
                            $data['content']='Applied to Stake';
                        }
                        break;
                    case 2://质押
                        $url=$domain."/api?module=proxy&action=eth_getTransactionReceipt&txhash=$info1[tx_hash]&apikey=*********";
                        $body = array();
                        $header = array();
                        $result = self::curlPost($url, $body, 50, $header, '');
                        $result=json_decode($result,true);

                        if($result['result']['from'] && $result['result']['logs']){
                            $data['wallet_address']=$result['result']['from'];
                            $arr1=array_column($result['result']['logs'], 'address');
                            $key2 = array_search(strtolower($hy2),$arr1);
                            $oin=floor(hexdec($result['result']['logs'][$key2]['data'])/100000000*100000000)/100000000;
                            $data['content']='Staked: '.$oin.' Lptoken';
                        }
                        break;

                    case 3://赎回
                        $url=$domain."/api?module=proxy&action=eth_getTransactionReceipt&txhash=$info1[tx_hash]&apikey=*********";
                        $body = array();
                        $header = array();
                        $result = self::curlPost($url, $body, 50, $header, '');
                        $result=json_decode($result,true);
                        if($result['result']['from'] && $result['result']['logs']){
                            $data['wallet_address']=$result['result']['from'];
                            $arr1=array_column($result['result']['logs'], 'address');
                            $key2 = array_search(strtolower($hy2),$arr1);
                            $oin=floor(hexdec($result['result']['logs'][$key2]['data'])/100000000*100000000)/100000000;
                            $data['content']='Redeemed: '.$oin.' Lptoken';
                        }
                        break;

                    case 4://挖矿奖励
                        $url=$domain."/api?module=proxy&action=eth_getTransactionReceipt&txhash=$info1[tx_hash]&apikey=**********";
                        $body = array();
                        $header = array();
                        $result = self::curlPost($url, $body, 50, $header, '');
                        $result=json_decode($result,true);
                        if($result['result']['from'] && $result['result']['logs']){
                            $data['wallet_address']=$result['result']['from'];
                            $arr2 = array_search($hy1,array_column($result['result']['logs'], 'address'));
                            $wk=floor(hexdec($result['result']['logs'][$arr2]['data'])/100000000*100)/100;
                            $data['content']='Redeemed Mining Rewards: '.$wk.' oin';
                        }
                        break;
                }

                if($data['wallet_address']){
                    db('trade_record_lptoken')->where('tx_hash',$info1['tx_hash'])->update($data);
                }else{
                    if($ten['number']<5000){
                        $redis->lPush('makeLogtoken', $info);
                        db('trade_record_lptoken')->where('tx_hash',$info1['tx_hash'])->setInc('number');
                    }
                }

            }

        }

    }

    private static function curlPost($url, $post_data = array(), $timeout = 5, $header = "", $data_type = "") {
        $header = empty($header) ? '' : $header;
        if($data_type == 'json'){
            $post_string = json_encode($post_data);
        }elseif($data_type == 'array') {
            $post_string = $post_data;
        }elseif(is_array($post_data)){
            $post_string = http_build_query($post_data, '', '&');
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
        curl_setopt($ch, CURLOPT_POST, true); 
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }



}