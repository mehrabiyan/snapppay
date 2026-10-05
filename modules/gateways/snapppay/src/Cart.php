<?php
declare(strict_types=1);
namespace SnappPay;

final class Cart
{
    public static function invoice(array $invoice, int $amount, string $category, int $commission): array
    {
        $items = [];
        $weights = [];
        foreach ($invoice['items']['item'] ?? [] as $item) {
            $raw = (string) $item['amount'];
            if (str_starts_with($raw, '-')) {
                continue;
            }
            $weight = Money::rials($raw, $invoice['currency']);
            if ($weight > 0) {
                $items[] = ['id' => (int) $item['id'], 'name' => mb_substr(strip_tags((string) $item['description']), 0, 200),
                    'category' => $category, 'commissionType' => $commission, 'count' => 1];
                $weights[] = $weight;
            }
        }
        $alloc = Money::allocate($amount, $weights);
        foreach ($items as $i => &$item) {
            $item['amount'] = $alloc[$i];
        }
        unset($item);
        $items = array_values(array_filter($items, static fn(array $item): bool => $item['amount'] > 0));
        $tax = Money::rials((string) ($invoice['tax'] ?? '0'), $invoice['currency'])
            + Money::rials((string) ($invoice['tax2'] ?? '0'), $invoice['currency']);
        $invoiceTotal = Money::rials((string) $invoice['total'], $invoice['currency']);
        $tax = min($amount, (int) bcdiv(bcmul((string) $tax, (string) $amount, 0), (string) max(1, $invoiceTotal), 0));
        return [['cartId' => (int) $invoice['invoiceid'], 'cartItems' => $items, 'isShipmentIncluded' => true,
            'isTaxIncluded' => true, 'shippingAmount' => 0, 'taxAmount' => $tax, 'totalAmount' => $amount]];
    }

    public static function reduce(array $cart, int $target): array
    {
        if (count($cart) !== 1 || $target <= 0 || $target >= $cart[0]['totalAmount']) {
            throw new Failure('invalid_refund');
        }
        $old = $cart[0]['totalAmount'];
        $weights = array_column($cart[0]['cartItems'], 'amount');
        $alloc = Money::allocate($target, $weights);
        foreach ($cart[0]['cartItems'] as $i => &$item) {
            $item['amount'] = $alloc[$i];
        }
        unset($item);
        $cart[0]['cartItems'] = array_values(array_filter($cart[0]['cartItems'], static fn(array $item): bool => $item['amount'] > 0));
        $cart[0]['totalAmount'] = $target;
        $cart[0]['taxAmount'] = (int) bcdiv(bcmul((string) $cart[0]['taxAmount'], (string) $target, 0), (string) $old, 0);
        return $cart;
    }
}
