<?php
declare(strict_types=1);
namespace SnappPay;

interface Transport
{
    public function send(string $method, string $url, array $headers, ?string $body): array;
}
