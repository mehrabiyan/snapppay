<?php
declare(strict_types=1);
namespace SnappPay;

interface Billing
{
    public function invoice(int $id): array;
    public function paymentExists(array $row): bool;
    public function credit(array $row): void;
    public function refundExists(string $refundId, array $row, int $amount): bool;
}
