<?php
declare(strict_types=1);
namespace SnappPay;

final class Security
{
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function mobile(string $value): string
    {
        $value = strtr(trim($value), ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        $value = str_replace([' ', '-', '(', ')'], '', $value);
        if (str_starts_with($value, '+98')) {
            $value = '0' . substr($value, 3);
        } elseif (str_starts_with($value, '0098')) {
            $value = '0' . substr($value, 4);
        }
        if (!preg_match('/^09[0-9]{9}$/D', $value)) {
            throw new Failure('invalid_mobile');
        }
        return $value;
    }

    public static function url(string $url, array $hosts = []): string
    {
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/D', strtolower($parts['host']))) {
            throw new Failure('unsafe_url');
        }
        if ($hosts && !in_array(strtolower($parts['host']), $hosts, true)) {
            throw new Failure('unsafe_host');
        }
        return $url;
    }

    public static function scalar(array $data, string $key, int $max = 128): string
    {
        if (!isset($data[$key]) || !is_string($data[$key]) || strlen($data[$key]) > $max || preg_match('/[\x00-\x1f\x7f]/', $data[$key])) {
            throw new Failure('invalid_input');
        }
        return $data[$key];
    }

    public static function id(string $value): int
    {
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $value)) {
            throw new Failure('invalid_id');
        }
        return (int) $value;
    }

    public static function reason(\Throwable $e): string
    {
        return $e instanceof Failure ? $e->reason : 'internal_error';
    }

    public static function publicIp(string $ip): bool
    {
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $packed=inet_pton($ip);
        if ($packed===false) {
            return false;
        }
        if (strlen($packed)===4) {
            $a=ord($packed[0]);$b=ord($packed[1]);
            return !($a===0 || $a>=224 || ($a===100 && $b>=64 && $b<=127) || ($a===192 && $b===0) || ($a===198 && ($b===18 || $b===19)));
        }
        // Permit global unicast only; exclude mapped IPv4, translation, documentation and multicast.
        return (ord($packed[0]) & 0xe0)===0x20
            && substr($packed,0,4)!==hex2bin('20010db8')
            && substr($packed,0,4)!==hex2bin('20010000');
    }
}
