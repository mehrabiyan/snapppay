<?php
declare(strict_types=1);
namespace SnappPay;

final class Config
{
    public array $values;
    public function __construct(array $values)
    {
        foreach (['clientId','clientSecret','username','password','apiUrl','paymentHosts'] as $key) {
            $env = getenv('SNAPPPAY_' . strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key)));
            if ($env !== false && $env !== '') {
                $values[$key] = $env;
            }
            if (empty($values[$key]) || !is_string($values[$key]) || preg_match('/[\x00-\x1f\x7f]/', $values[$key])) {
                throw new Failure('missing_configuration');
            }
        }
        $values['apiUrl'] = rtrim(Security::url($values['apiUrl']), '/');
        $parts = parse_url($values['apiUrl']);
        if (!empty($parts['query']) || !empty($parts['path'])) {
            throw new Failure('invalid_api_origin');
        }
        $values['paymentHosts'] = array_values(array_unique(array_map('trim', explode(',', strtolower($values['paymentHosts'])))));
        foreach ($values['paymentHosts'] as $host) {
            Security::url('https://' . $host);
        }
        $values['environment'] = $values['environment'] ?? 'stage';
        if (!in_array($values['environment'], ['stage', 'production'], true)) {
            throw new Failure('invalid_environment');
        }
        if ($values['environment'] === 'production' && empty($values['certified'])) {
            throw new Failure('production_not_certified');
        }
        $methods = $values['methods'] ?? '';
        $values['methods'] = $methods === '' ? [] : explode(',', $methods);
        if (array_diff($values['methods'], ['POSTPAID','INSTALLMENT','FINANCING'])) {
            throw new Failure('invalid_methods');
        }
        $commission = (string) ($values['commission'] ?? '100');
        if (!ctype_digit($commission) || (int) $commission < 1 || (int) $commission > 10000) {
            throw new Failure('invalid_commission');
        }
        $values['commission'] = (int) $commission;
        $values['category'] = mb_substr((string) ($values['category'] ?? 'Services'), 0, 100);
        // Fixed fee is whole rials; percentage is stored as basis points (2.5% => 250).
        $feeType = (string) ($values['feeType'] ?? '');
        $feeValue = trim((string) ($values['feeValue'] ?? ''));
        if ($feeType === '' || $feeType === 'none') {
            $values['feeType'] = '';
            $values['feeValue'] = 0;
        } elseif ($feeType === 'fixed' && preg_match('/^(0|[1-9][0-9]{0,12})$/D', $feeValue) && (int) $feeValue <= Money::MAX) {
            $values['feeValue'] = (int) $feeValue;
        } elseif ($feeType === 'percent' && preg_match('/^(100|[1-9]?[0-9])(?:\.([0-9]{1,2}))?$/D', $feeValue, $m)
            && ($m[1] !== '100' || (int) ($m[2] ?? 0) === 0)) {
            $values['feeValue'] = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        } else {
            throw new Failure('invalid_fee');
        }
        $description = trim(strip_tags((string) ($values['feeDescription'] ?? '')));
        $values['feeDescription'] = mb_substr($description === '' ? 'SnappPay payment fee' : $description, 0, 200);
        $this->values = $values;
    }

    /** Fee in rials for a payable base. Percentage rounds half-up to a whole invoice-currency unit. */
    public function fee(int $base, string $currency): int
    {
        if ($base <= 0 || $this->values['feeType'] === '') {
            return 0;
        }
        if ($this->values['feeType'] === 'fixed') {
            return $this->values['feeValue'];
        }
        $unit = Money::rials('1', $currency);
        return intdiv($base * $this->values['feeValue'] + $unit * 5000, $unit * 10000) * $unit;
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->values['environment'] . '|' . $this->values['apiUrl'] . '|' . $this->values['clientId'] . '|' . $this->values['username']);
    }
}
