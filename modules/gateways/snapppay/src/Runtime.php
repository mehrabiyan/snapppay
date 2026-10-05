<?php
declare(strict_types=1);
namespace SnappPay;

use WHMCS\Database\Capsule;
use WHMCS\Authentication\CurrentUser;

final class Runtime
{
    public Config $config;
    public Store $store;
    public Api $api;
    public Billing $billing;
    public Service $service;
    public function __construct(array $params)
    {
        if (PHP_INT_SIZE!==8) {
            throw new Failure('unsupported_php');
        }
        if (empty($params['type'])) {
            throw new Failure('gateway_inactive');
        }
        foreach (['curl','bcmath','mbstring','pdo'] as $extension) {
            if (!extension_loaded($extension)) {
                throw new Failure('missing_extension');
            }
        }
        $this->config=new Config($params);
        $this->store=self::store();
        $this->api=new Api($this->config,new CurlTransport());
        $this->billing=new WhmcsBilling();
        $this->service=new Service($this->config,$this->api,$this->store,$this->billing);
    }

    public static function store(): Store
    {
        return new Store(Capsule::connection()->getPdo(),static fn(string $v): string=>encrypt($v),static fn(string $v): string=>decrypt($v));
    }

    public static function clientId(): int
    {
        $user=new CurrentUser();
        $client=$user->client();
        if (!$client || (!$user->isAuthenticatedUser() && !$user->isMasqueradingAdmin())) {
            throw new Failure('login_required');
        }
        // Selected account alone is insufficient for associated users with limited permissions.
        $auth=$user->user();
        if (!$user->isMasqueradingAdmin() && $auth) {
            $association=$auth->clients()->where('tblclients.id',$client->id)->first();
            if (!$association) {
                throw new Failure('access_denied');
            }
            if (!(bool)$association->pivot->owner) {
                $permissions=$association->pivot->permissions;
                if (is_string($permissions)) {
                    $permissions=json_decode($permissions,true);
                }
                if (!is_array($permissions) || !in_array('invoices',$permissions,true)) {
                    throw new Failure('access_denied');
                }
            }
        }
        return (int)$client->id;
    }

    public static function adminId(bool $addon = true): int
    {
        $user=new CurrentUser();
        $admin=$user->admin();
        if (!$user->isAuthenticatedAdmin() || !$admin) {
            throw new Failure('admin_required');
        }
        if ($addon) {
            $access=Capsule::table('tbladdonmodules')->where('module','snapppay_ops')->where('setting','access')->value('value');
            if (!in_array((string)$admin->roleId,explode(',',(string)$access),true)) {
                throw new Failure('admin_access_denied');
            }
        }
        return (int)$admin->id;
    }

    public static function csrf(): void
    {
        $supplied=Security::scalar($_POST,'token',256);
        $expected=(string)generate_token('plain');
        if ($expected==='' || !hash_equals($expected,$supplied)) {
            throw new Failure('csrf_failed');
        }
    }

    public static function base(array $params): string
    {
        $base=rtrim(Security::url((string)($params['systemurl']??'')),'/') . '/';
        if (parse_url($base,PHP_URL_QUERY)!==null) {
            throw new Failure('invalid_system_url');
        }
        return $base;
    }

    public static function log(\Throwable $e, string $operation): void
    {
        if (function_exists('logTransaction')) {
            logTransaction('snapppay',['operation'=>$operation,'error'=>Security::reason($e)],'Error');
        }
    }
}
