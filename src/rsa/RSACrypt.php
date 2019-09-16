<?php

namespace framework\rsa;

use framework\core\Config;

/**
 *  RSA加解密类
 * “参数签名”用私钥加密，“验证签名”用公钥解密
 * “内容加密”用公钥加密，“内容解密”用私钥解密
 */
class RSACrypt {

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
     * 私钥加密
     * @param $data
     * @return mixed|string
     */
    public function encode($data) {
        $pi_key = openssl_pkey_get_private($this->privkey);
        $encrypted = "";
        openssl_private_encrypt($data, $encrypted, $pi_key, OPENSSL_PKCS1_PADDING); //私钥加密
        $encrypted = $this->urlsafe_b64encode($encrypted); //加密后的内容通常含有特殊字符，需要编码转换下，在网络间通过url传输时要注意base64编码是否是url安全的
        return $encrypted;
    }

    /**
     * 公钥解密
     * @param $data
     * @return string
     */
    public function decode($data) {
        $pu_key = openssl_pkey_get_public($this->pubkey);
        $decrypted = "";
        $data = $this->urlsafe_b64decode($data);

        openssl_public_decrypt($data, $decrypted, $pu_key); //公钥解密

        return $decrypted;
    }

    /**
     * 公钥加密
     * @param $data
     * @return mixed|string
     */
    public function encryptByPublicKey($data) {
        $pu_key = openssl_pkey_get_public($this->pubkey);
        $encrypted = "";
        openssl_public_encrypt($data, $encrypted, $pu_key, OPENSSL_PKCS1_PADDING); //公钥加密
        $encrypted = $this->urlsafe_b64encode($encrypted); //加密后的内容通常含有特殊字符，需要编码转换下，在网络间通过url传输时要注意base64编码是否是url安全的
        return $encrypted;
    }

    /**
     * 私钥解密
     * @param $data
     * @return string
     */
    public function decryptByPrivateKey($data) {
        $pi_key = openssl_pkey_get_private($this->privkey);
        $decrypted = "";
        $data = $this->urlsafe_b64decode($data);
        openssl_private_decrypt($data, $decrypted, $pi_key); //私钥解密
        return $decrypted;
    }

    /**
     * 安全的b64encode
     * @param $string
     * @return mixed|string
     */
    private function urlsafe_b64encode($string) {
        return str_replace('=', '', strtr(base64_encode($string), '+/', '-_'));
    }

    /**
     * 安全的b64decode
     * @param $string
     * @return string
     */
    private function urlsafe_b64decode($string) {
        $remainder = strlen($string) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $string .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($string, '-_', '+/'));
    }

}
