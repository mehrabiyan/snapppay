<?php
declare(strict_types=1);
namespace SnappPay;

final class Api
{
    private Config $config;
    private Transport $transport;
    public function __construct(Config $config, Transport $transport)
    {
        $this->config = $config;
        $this->transport = $transport;
    }

    public function call(string $operation, array $payload): array
    {
        $paths = ['eligible' => '/api/online/offer/v1/eligible'];
        foreach (['token','verify','settle','status','cancel','update','revert'] as $name) {
            $paths[$name] = '/api/online/payment/v1/' . $name;
        }
        if (!isset($paths[$operation])) {
            throw new Failure('invalid_operation');
        }
        $v = $this->config->values;
        $auth = $this->transport->send('POST', $v['apiUrl'] . '/api/online/v1/oauth/token',
            ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded', 'Authorization: Basic ' . base64_encode($v['clientId'] . ':' . $v['clientSecret'])],
            http_build_query(['grant_type'=>'password','scope'=>'online-merchant','username'=>$v['username'],'password'=>$v['password']], '', '&', PHP_QUERY_RFC3986));
        $token = $auth['access_token'] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 16384 || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new Failure('invalid_access_token');
        }
        $get = in_array($operation, ['eligible','status'], true);
        $url = $v['apiUrl'] . $paths[$operation] . ($get ? '?' . http_build_query($payload, '', '&', PHP_QUERY_RFC3986) : '');
        $response = $this->transport->send($get ? 'GET' : 'POST', $url,
            ['Accept: application/json','Content-Type: application/json','Authorization: Bearer ' . $token],
            $get ? null : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        if (($response['successful'] ?? null) !== true || !is_array($response['response'] ?? null)) {
            throw new Failure('provider_rejected');
        }
        return $response['response'];
    }

    public function eligible(int $amount): array
    {
        $payload = ['amount' => $amount];
        if ($this->config->values['methods']) {
            $payload['paymentMethodTypes'] = implode(',', $this->config->values['methods']);
        }
        $result = $this->call('eligible', $payload);
        if (!is_bool($result['eligible'] ?? null)) {
            throw new Failure('invalid_eligibility');
        }
        if ($result['eligible'] && (!is_string($result['title_message'] ?? null) || !is_string($result['description'] ?? null))) {
            throw new Failure('invalid_eligibility');
        }
        return $result;
    }
}
