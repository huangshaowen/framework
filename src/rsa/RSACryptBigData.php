<?php

namespace framework\rsa;

/**
 * RSA大长度数据加解密类，把内容分段加解密，解决RSA加解密长度限制
 */
class RSACryptBigData {

    public static function getInstance() {
        static $obj;
        if (!$obj) {
            $obj = new self();
        }
        return $obj;
    }

    /**
     * 公钥加密
     * @param string $data
     * @return string
     * @throws \Exception
     */
    public function encryptByPublicKey_data(string $data, string $publickey = ''): string {
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
     * @param string $data
     * @return string
     * @throws \Exception
     */
    public function decryptByPrivateKey_data(string $data, string $privatekey = ''): string {
        if ($privatekey != "") {
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
     * @param string $data
     * @return string
     * @throws Exception
     */
    public function encode(string $data, string $privatekey = ''): string {
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
     * @param string $data
     * @return string
     * @throws Exception
     */
    public function decode(string $data, string $publickey = ''): string {
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
