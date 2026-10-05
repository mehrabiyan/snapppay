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
        $this->values = $values;
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->values['environment'] . '|' . $this->values['apiUrl'] . '|' . $this->values['clientId'] . '|' . $this->values['username']);
    }
}
