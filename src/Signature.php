<?php

namespace Mitoop\ApiSignature;


use Cache;
use Mitoop\ApiSignature\Exception\InvalidSignatureException;

class Signature
{

    const TIME_OUT = 30;

    /**
     * @var array 签名用的key.
     */
    protected $signKeys = [
        'app_id',
        'timestamp',
        'nonce',
        'http_method',
        'http_path',
    ];

    /**
     * 生成签名.
     *
     * @param array $params
     * @param       $secret
     *
     * @return string
     */
    public function sign(array $params, $secret)
    {
        $params = array_filter($params, function ($value, $key) {
            return in_array($key, $this->signKeys);
        }, ARRAY_FILTER_USE_BOTH);

        ksort($params);

        return hash_hmac('sha256', http_build_query($params, null, '&'), $secret);
    }

    /**
     * 校验签名.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     * @throws InvalidSignatureException
     */
    public function validSign(\Illuminate\Http\Request $request)
    {
        $appId      = $request->query('app_id');
        $secret     = $this->validAppId($appId);
        $timestamp  = $request->query('timestamp', 0);
        $nonce      = $request->query('nonce');
        $sign       = $request->query('sign');
        $signParams = $request->query();
        $signParams = \array_merge($signParams, [
            'http_method' => $request->method(), // method() is always uppercase
            'http_path'   => $request->getPathInfo(),
        ]);

        $this->validTimestamp($timestamp)
             ->validNonce($nonce)
             ->validHmac($signParams, $secret, $sign);

        $this->setNonceCache($nonce);

        return true;
    }

    /**
     * 校验app id并根据app id返回密匙.
     *
     * @param $appId
     *
     * @return string
     * @throws InvalidSignatureException
     */
    private function validAppId($appId)
    {
        if (\is_null($appId)) {
            throw new InvalidSignatureException('app_id is lost.');
        }

        $clients = \config('api-signature.clients');

        $client = \current(array_filter($clients, function ($client) use ($appId) {
            return $client['app_id'] == $appId;
        }, ARRAY_FILTER_USE_BOTH));

        if ($client === false || ! isset($client['app_secret'])) {
            throw new InvalidSignatureException('Invalid app_id.');
        }

        return $client['app_secret'];
    }

    /**
     * 校验消息认证码.
     *
     * @param $params array 用来校验的参数
     * @param $secret string 密匙
     * @param $hmac   string 消息认证码
     *
     * @return $this
     * @throws InvalidSignatureException
     */
    private function validHmac($params, $secret, $hmac)
    {
        if (\is_null($hmac) || ! hash_equals($this->sign($params, $secret), $hmac)) {
            throw new InvalidSignatureException('Invalid Signature');
        }

        return $this;
    }

    /**
     * 校验时间.
     *
     * @param $time
     *
     * @return $this
     * @throws InvalidSignatureException
     */
    private function validTimestamp($time)
    {
        $time        = \intval($time);
        $currentTime = time();

        if ($time <= 0 || $time > $currentTime || $currentTime - $time > self::TIME_OUT) {
            throw new InvalidSignatureException('Time out.');
        }

        return $this;
    }

    /**
     * 校验nonce.
     *
     * @param $nonce
     *
     * @return $this
     * @throws InvalidSignatureException
     */
    private function validNonce($nonce)
    {
        if (\is_null($nonce) || Cache::has($nonce)) {
            throw new InvalidSignatureException('Not once');
        }

        return $this;
    }

    /**
     * 生成nonce缓存.
     *
     * @param $nonce
     */
    private function setNonceCache($nonce)
    {
        // redis driver is recommended
        Cache::add($nonce, 1, self::TIME_OUT / 60);
    }

}