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
     * @param string $data
     * @return string
     * @throws Exception
     */
    public function encode(string $data): string {
        $pi_key = openssl_pkey_get_private($this->privkey);
        $encrypted = "";
        $rs = openssl_private_encrypt($data, $encrypted, $pi_key, OPENSSL_PKCS1_PADDING); //私钥加密
        if ($rs == false) {
            throw new \Exception("RSA私钥加密失败", 500);
        }
        $encrypted = $this->urlsafe_b64encode($encrypted); //加密后的内容通常含有特殊字符，需要编码转换下，在网络间通过url传输时要注意base64编码是否是url安全的
        return $encrypted;
    }

    /**
     * 公钥解密
     * @param string $data
     * @return string
     * @throws Exception
     */
    public function decode(string $data): string {
        $pu_key = openssl_pkey_get_public($this->pubkey);
        $decrypted = "";
        $data = $this->urlsafe_b64decode($data);

        $rs = openssl_public_decrypt($data, $decrypted, $pu_key); //公钥解密
        if ($rs == false) {
            throw new \Exception("RSA公钥解密失败", 500);
        }
        return $decrypted;
    }

    /**
     * 公钥加密
     * @param string $data
     * @return string
     * @throws \Exception
     */
    public function encryptByPublicKey(string $data): string {
        $pu_key = openssl_pkey_get_public($this->pubkey);
        $encrypted = "";
        $rs = openssl_public_encrypt($data, $encrypted, $pu_key, OPENSSL_PKCS1_PADDING); //公钥加密
        if ($rs == false) {
            throw new \Exception("RSA公钥加密失败", 500);
        }
        $encrypted = $this->urlsafe_b64encode($encrypted); //加密后的内容通常含有特殊字符，需要编码转换下，在网络间通过url传输时要注意base64编码是否是url安全的
        return $encrypted;
    }

    /**
     * 私钥解密
     * @param string $data
     * @return string
     * @throws \Exception
     */
    public function decryptByPrivateKey(string $data): string {
        $pi_key = openssl_pkey_get_private($this->privkey);
        $decrypted = "";
        $data = $this->urlsafe_b64decode($data);
        $rs = openssl_private_decrypt($data, $decrypted, $pi_key); //私钥解密
        if ($rs == false) {
            throw new \Exception("RSA私钥解密失败", 500);
        }
        return $decrypted;
    }

    /**
     * 安全的b64encode
     * @param string $string
     * @return string
     */
    private function urlsafe_b64encode(string $string): string {
        return str_replace('=', '', strtr(base64_encode($string), '+/', '-_'));
    }

    /**
     * 安全的b64decode
     * @param string $string
     * @return string
     */
    private function urlsafe_b64decode(string $string): string {
        $remainder = strlen($string) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $string .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($string, '-_', '+/'));
    }

}
