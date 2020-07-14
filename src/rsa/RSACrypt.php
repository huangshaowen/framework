<?php

namespace framework\rsa;

use framework\core\Config;

/**
 *  RSA加解密类
 *
  1.生成私钥
  -- 生成 RSA 私钥（传统格式的）
  openssl genrsa -out rsa_private_key.pem 1024
  -- 将传统格式的私钥转换成 PKCS#8 格式的（JAVA需要使用的私钥需要经过PKCS#8编码，PHP程序不需要，可以直接略过）
  openssl pkcs8 -topk8 -inform PEM -in rsa_private_key.pem -outform PEM -nocrypt
  2.生成公钥
  -- 生成 RSA 公钥(php和java都用转换前私钥生成公钥)
  openssl rsa -in rsa_private_key.pem -pubout -out rsa_public_key.pem
 *
 *
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
     * 参数签名  私钥加密
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
        $encrypted = $this->urlsafe_b64encode($encrypted);
        return $encrypted;
    }

    /**
     * 验证签名 公钥解密
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
     * 内容加密 公钥加密
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
        $encrypted = $this->urlsafe_b64encode($encrypted);
        return $encrypted;
    }

    /**
     * 内容解密 私钥解密
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
