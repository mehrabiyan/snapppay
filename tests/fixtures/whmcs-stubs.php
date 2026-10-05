<?php
// Static-analysis contracts only, never loaded by production.
namespace WHMCS\Database {
    class Capsule {
        public static function connection(): Connection { throw new \LogicException(); }
        public static function table(string $table): Query { throw new \LogicException(); }
    }
    class Connection { public function getPdo(): \PDO { throw new \LogicException(); } }
    class Query {
        public function where(string $key,$value): self { return $this; }
        public function first(): ?object { throw new \LogicException(); }
        public function get(): \ArrayAccess&\Countable { throw new \LogicException(); }
        public function value(string $key) { throw new \LogicException(); }
    }
}
namespace WHMCS\Authentication {
    class CurrentUser {
        public function client(): ?object { throw new \LogicException(); }
        public function user(): ?object { throw new \LogicException(); }
        public function admin(): ?object { throw new \LogicException(); }
        public function isAuthenticatedUser(): bool { return false; }
        public function isAuthenticatedAdmin(): bool { return false; }
        public function isMasqueradingAdmin(): bool { return false; }
    }
}
namespace {
    function localAPI(string $operation,array $params): array { throw new \LogicException(); }
    function encrypt(string $value): string { throw new \LogicException(); }
    function decrypt(string $value): string { throw new \LogicException(); }
    function generate_token(string $type): string { throw new \LogicException(); }
    function getGatewayVariables(string $module): array { throw new \LogicException(); }
    function checkCbInvoiceID(int $id,string $module): int { throw new \LogicException(); }
    function checkCbTransID(string $id): void { throw new \LogicException(); }
    function addInvoicePayment(int $id,string $transid,string $amount,string $fees,string $gateway): void { throw new \LogicException(); }
    function logTransaction(string $module,array $data,string $state): void { throw new \LogicException(); }
    function add_hook(string $hook,int $priority,callable $callback): void { }
    define('WHMCS',true);
    define('ROOTDIR','/whmcs');
}
