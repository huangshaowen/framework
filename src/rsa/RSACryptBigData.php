<?php

namespace framework\rsa;

use framework\core\Config;

/**
 * RSA大长度数据加解密类，把内容分段加解密，解决RSA加解密长度限制
 */
class RSACryptBigData {

    public $pubkey; //公钥
    public $privkey; //私钥

    function __construct() {
        /* 解密公钥 */
        $rsa_public_key_file = Config::getInstance()->get('rsa_public_key');
        if (is_file($rsa_public_key_file) && file_exists($rsa_public_key_file)) {
            $this->pubkey = file_get_contents($rsa_public_key_file);
        }
        /* 加密私钥 */
        $rsa_private_key_file = Config::getInstance()->get('rsa_private_key');
        if (is_file($rsa_private_key_file) && file_exists($rsa_private_key_file)) {
            $this->privkey = file_get_contents($rsa_private_key_file);
        }
    }

    public static function getInstance() {
        static $obj;
        if (!$obj) {
            $obj = new self();
        }
        return $obj;
    }

    /**
     * 公钥加密
     * @param $data
     * @param string $publickey
     * @return string
     */
    public function encryptByPublicKey_data($data, $publickey = '') {
        if ($publickey != "") {
            RSACrypt::getInstance()->pubkey = $publickey;
        }
        $crypt_res = "";
        for ($i = 0; $i < ((strlen($data) - strlen($data) % 117) / 117 + 1); $i++) {
            $crypt_res = $crypt_res . (RSACrypt::getInstance()->encryptByPublicKey(mb_strcut($data, $i * 117, 117, 'utf-8')));
        }
        return $crypt_res;
    }

    /**
     * 私钥解密
     * @param $data
     * @param string $privatekey
     * @return string
     */
    public function decryptByPrivateKey_data($data, $privatekey = '') {
        if ($privatekey != "") {  // if null use default
            RSACrypt::getInstance()->privkey = $privatekey;
        }
        $decrypt_res = "";
        $datas = explode('@', $data);
        foreach ($datas as $value) {
            $decrypt_res = $decrypt_res . RSACrypt::getInstance()->decryptByPrivateKey($value);
        }
        return $decrypt_res;
    }

    /**
     * 私钥加密
     * @param $data
     * @param string $privatekey
     * @return string
     */
    public function encode($data, $privatekey = '') {
        if ($privatekey != "") {
            RSACrypt::getInstance()->privkey = $privatekey;
        }
        $crypt_res = "";
        for ($i = 0; $i < ((strlen($data) - strlen($data) % 117) / 117 + 1); $i++) {
            $crypt_res = $crypt_res . (RSACrypt::getInstance()->encode(mb_strcut($data, $i * 117, 117, 'utf-8')));
        }
        return $crypt_res;
    }

    /**
     * 公钥解密
     * @param $data
     * @param string $publickey
     * @return string
     */
    public function decode($data, $publickey = '') {
        if ($publickey != "") {
            RSACrypt::getInstance()->pubkey = $publickey;
        }
        $decrypt_res = "";
        $datas = explode('@', $data);
        foreach ($datas as $value) {
            $decrypt_res = $decrypt_res . RSACrypt::getInstance()->decode($value);
        }
        return $decrypt_res;
    }

}
