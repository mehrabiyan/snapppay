<?php
declare(strict_types=1);
namespace SnappPay;

final class Store
{
    private const SCHEMA_VERSION = 2;
    private const URL_PREFIX = 'spurl1:';
    private \PDO $db;
    private \Closure $encrypt;
    private \Closure $decrypt;
    private array $locks = [];
    public function __construct(\PDO $db, callable $encrypt, callable $decrypt)
    {
        $this->db = $db;
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->encrypt = \Closure::fromCallable($encrypt);
        $this->decrypt = \Closure::fromCallable($decrypt);
    }

    public function install(): void
    {
        $mysql = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $this->db->exec("CREATE TABLE IF NOT EXISTS mod_snapppay (id $id, transaction_id VARCHAR(64) NOT NULL UNIQUE,
            invoice_id BIGINT NOT NULL, client_id BIGINT NOT NULL, nonce VARCHAR(64) NOT NULL UNIQUE,
            environment VARCHAR(64) NOT NULL, currency VARCHAR(8) NOT NULL, original_amount BIGINT NOT NULL,
            amount BIGINT NOT NULL, cart TEXT NOT NULL, token TEXT NULL, token_hash VARCHAR(64) NULL UNIQUE,
            payment_url TEXT NULL, state VARCHAR(32) NOT NULL, verify_count INTEGER NOT NULL DEFAULT 0,
            credited INTEGER NOT NULL DEFAULT 0, refund TEXT NULL, error VARCHAR(64) NULL,
            created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)$engine");
        $this->db->exec("CREATE TABLE IF NOT EXISTS mod_snapppay_audit (id $id, transaction_id VARCHAR(64) NOT NULL,
            operation VARCHAR(32) NOT NULL, outcome VARCHAR(64) NOT NULL, admin_id BIGINT NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL)$engine");
        $this->db->exec("CREATE TABLE IF NOT EXISTS mod_snapppay_meta (name VARCHAR(32) PRIMARY KEY, value VARCHAR(32) NOT NULL)$engine");
        $version=$this->query('SELECT value FROM mod_snapppay_meta WHERE name=?',['schema_version'])->fetchColumn();
        if ($version!==false && (int)$version>self::SCHEMA_VERSION) {
            throw new Failure('newer_schema_installed');
        }
        foreach (['sp_invoice_created'=>'invoice_id,created_at','sp_state_updated'=>'state,updated_at'] as $name=>$columns) {
            if ($mysql) {
                $exists=(int)$this->query('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',['mod_snapppay',$name])->fetchColumn();
                if (!$exists) {
                    $this->db->exec("CREATE INDEX $name ON mod_snapppay ($columns)");
                }
            } else {
                $this->db->exec("CREATE INDEX IF NOT EXISTS $name ON mod_snapppay ($columns)");
            }
        }
        // URL paths/queries can carry the payment token. Encrypt legacy URLs too.
        // Each conditional update commits independently: interrupted activation is resumable.
        // Deployment must drain old workers before migration; old writers store plaintext.
        $lastId=0;
        do {
            $rows=$this->query('SELECT id,payment_url FROM mod_snapppay WHERE id>? AND payment_url IS NOT NULL ORDER BY id LIMIT 100',[$lastId])->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $lastId=(int)$row['id'];
                $url=(string)$row['payment_url'];
                if (str_starts_with($url,self::URL_PREFIX)) {
                    $this->decodeUrl($url);
                    continue;
                }
                $encrypted=$this->encodeUrl($url);
                $this->query('UPDATE mod_snapppay SET payment_url=? WHERE id=? AND payment_url=?',[$encrypted,$lastId,$url]);
            }
        } while (count($rows)===100);
        if ($version===false) {
            $this->query('INSERT INTO mod_snapppay_meta (name,value) VALUES (?,?)',['schema_version',(string)self::SCHEMA_VERSION]);
        } else {
            $this->query('UPDATE mod_snapppay_meta SET value=? WHERE name=?',[(string)self::SCHEMA_VERSION,'schema_version']);
        }
        // Future releases add explicit forward migrations; never drop records.
    }

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function locked(int $invoice, callable $fn)
    {
        $name = 'snapppay:' . $invoice;
        if (isset($this->locks[$name])) {
            return $fn();
        }
        $mysql = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $handle = null;
        if ($mysql) {
            if ((int) $this->query('SELECT GET_LOCK(?, 5)', [$name])->fetchColumn() !== 1) {
                throw new Failure('payment_busy');
            }
        } else {
            $handle = fopen(sys_get_temp_dir() . '/snapppay-test-' . hash('sha256', $name) . '.lock', 'c');
            if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
                if ($handle) {
                    fclose($handle);
                }
                throw new Failure('payment_busy');
            }
        }
        $this->locks[$name] = true;
        try {
            return $fn();
        } finally {
            unset($this->locks[$name]);
            if ($mysql) {
                $this->query('SELECT RELEASE_LOCK(?)', [$name]);
            } elseif ($handle) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    public function create(array $row): array
    {
        $now = time();
        $this->query('INSERT INTO mod_snapppay (transaction_id,invoice_id,client_id,nonce,environment,currency,original_amount,amount,cart,state,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [$row['transaction_id'],$row['invoice_id'],$row['client_id'],$row['nonce'],$row['environment'],$row['currency'],
                $row['amount'],$row['amount'],json_encode($row['cart'], JSON_THROW_ON_ERROR),'creating',$now,$now]);
        return $this->get($row['transaction_id']);
    }

    public function get(string $id): array
    {
        $row = $this->query('SELECT * FROM mod_snapppay WHERE transaction_id=?', [$id])->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Failure('unknown_transaction');
        }
        return $this->decode($row);
    }

    private function decode(array $row): array
    {
        foreach (['id','invoice_id','client_id','original_amount','amount','verify_count','credited','created_at','updated_at'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['cart'] = json_decode($row['cart'], true, 32, JSON_THROW_ON_ERROR);
        $row['refund'] = $row['refund'] ? json_decode($row['refund'], true, 32, JSON_THROW_ON_ERROR) : null;
        if ($row['payment_url']!==null) {
            $row['payment_url']=$this->decodeUrl($row['payment_url']);
        }
        return $row;
    }

    private function encodeUrl(string $url): string
    {
        Security::url($url);
        $encrypted=($this->encrypt)($url);
        if (!is_string($encrypted) || $encrypted==='') {
            throw new Failure('url_encryption_failed');
        }
        return self::URL_PREFIX.$encrypted;
    }

    private function decodeUrl(string $stored): string
    {
        // Compatibility reader supports v1 rows until activation completes migration.
        if (!str_starts_with($stored,self::URL_PREFIX)) {
            return Security::url($stored);
        }
        try {
            $url=($this->decrypt)(substr($stored,strlen(self::URL_PREFIX)));
        } catch (\Throwable $e) {
            throw new Failure('invalid_encrypted_url');
        }
        if (!is_string($url) || $url==='') {
            throw new Failure('invalid_encrypted_url');
        }
        return Security::url($url);
    }

    public function nonce(string $nonce): ?array
    {
        $id = $this->query('SELECT transaction_id FROM mod_snapppay WHERE nonce=?', [$nonce])->fetchColumn();
        return $id ? $this->get((string) $id) : null;
    }

    public function save(string $id, array $changes): array
    {
        $allowed = ['state','verify_count','credited','refund','error','amount','cart','token','token_hash','payment_url'];
        $sets = [];
        $args = [];
        foreach ($changes as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                throw new Failure('invalid_storage_field');
            }
            $sets[] = "$key=?";
            if (in_array($key, ['cart','refund'], true) && $value !== null) {
                $value = json_encode($value, JSON_THROW_ON_ERROR);
            }
            if ($key === 'token' && $value !== null) {
                $value = ($this->encrypt)($value);
            }
            if ($key === 'payment_url' && $value !== null) {
                $value=$this->encodeUrl($value);
            }
            $args[] = $value;
        }
        $sets[] = 'updated_at=?';
        $args[] = time();
        $args[] = $id;
        $this->query('UPDATE mod_snapppay SET ' . implode(',', $sets) . ' WHERE transaction_id=?', $args);
        return $this->get($id);
    }

    public function token(array $row): string
    {
        if (empty($row['token'])) {
            throw new Failure('missing_payment_token');
        }
        $token = ($this->decrypt)($row['token']);
        if (!is_string($token) || $token === '' || !hash_equals($row['token_hash'], hash('sha256', $token))) {
            throw new Failure('invalid_encrypted_token');
        }
        return $token;
    }

    public function audit(string $id, string $operation, string $outcome, int $admin = 0): void
    {
        $this->query('INSERT INTO mod_snapppay_audit (transaction_id,operation,outcome,admin_id,created_at) VALUES (?,?,?,?,?)', [$id,$operation,$outcome,$admin,time()]);
    }

    public function recent(int $page = 1, ?int $invoice = null): array
    {
        $page = max(1, min(100000, $page));
        $where = $invoice ? ' WHERE invoice_id=?' : '';
        $rows = $this->query('SELECT * FROM mod_snapppay' . $where . ' ORDER BY id DESC LIMIT 25 OFFSET ' . (($page - 1) * 25), $invoice ? [$invoice] : [])->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn(array $row): array => $this->decode($row), $rows);
    }

    public function pending(): array
    {
        return $this->query("SELECT transaction_id FROM mod_snapppay WHERE (state IN ('pending','verifying','verified','settling','settled','refunding') OR refund IS NOT NULL) AND updated_at < ? ORDER BY updated_at LIMIT 25", [time() - 60])->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function attempts(int $invoice): int
    {
        return (int) $this->query('SELECT COUNT(*) FROM mod_snapppay WHERE invoice_id=? AND created_at>?', [$invoice,time()-3600])->fetchColumn();
    }
}
