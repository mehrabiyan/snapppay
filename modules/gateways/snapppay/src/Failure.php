<?php
declare(strict_types=1);
namespace SnappPay;

final class Failure extends \RuntimeException
{
    public string $reason;
    public function __construct(string $reason)
    {
        $this->reason = $reason;
        parent::__construct($reason);
    }
}
