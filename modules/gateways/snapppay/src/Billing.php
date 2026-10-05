<?php
declare(strict_types=1);
namespace SnappPay;

interface Billing
{
    /** Invoice item type that marks the module-owned SnappPay fee line. */
    public const FEE_ITEM = 'SnappPayFee';
    public function invoice(int $id): array;
    public function paymentExists(array $row): bool;
    public function credit(array $row): void;
    public function refundExists(string $refundId, array $row, int $amount): bool;
    /** Replace every fee line with one line of $amount (invoice-currency decimal), or remove them when null. */
    public function setFee(array $invoice, ?string $amount, string $description): void;
}
