<?php
declare(strict_types=1);
namespace SnappPay;

final class CurlTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        Security::url($url);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = [];
        foreach ($records ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? '';
            if (!Security::publicIp($ip)) {
                throw new Failure('unsafe_dns');
            }
            $ips[] = $ip;
        }
        if (!$ips) {
            throw new Failure('dns_failed');
        }
        $ip = $ips[0];
        $resolve = $host . ':443:' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip);
        return $this->request($method, $url, $headers, $body, [$resolve]);
    }

    private function request(string $method, string $url, array $headers, ?string $body, array $resolve): array
    {
        foreach ($headers as $header) {
            if (preg_match('/[\r\n]/', $header)) {
                throw new Failure('unsafe_header');
            }
        }
        $ch = curl_init($url);
        $data = '';
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => $resolve, CURLOPT_PROXY => '', CURLOPT_USERAGENT => 'SnappPay-WHMCS/1.0',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$data): int {
                if (strlen($data) + strlen($chunk) > 1048576) {
                    return 0;
                }
                $data .= $chunk;
                return strlen($chunk);
            }]);
        $ca=ini_get('curl.cainfo');
        if (is_string($ca) && $ca!=='') {
            curl_setopt($ch,CURLOPT_CAINFO,$ca);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $errno = curl_errno($ch);
        unset($ch);
        if ($ok === false) {
            throw new Failure($errno === CURLE_OPERATION_TIMEDOUT ? 'provider_timeout' : 'transport_error');
        }
        if ($code < 200 || $code >= 300) {
            throw new Failure('provider_http_' . $code);
        }
        if (!preg_match('~^application/(?:json|[a-z0-9.+-]+\+json)(?:;|$)~i', $type)) {
            throw new Failure('invalid_content_type');
        }
        try {
            $json = json_decode($data, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new Failure('invalid_json');
        }
        if (!is_array($json)) {
            throw new Failure('invalid_response');
        }
        return $json;
    }
}
