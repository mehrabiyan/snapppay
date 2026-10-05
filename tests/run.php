<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

$tests=[];
function test(string $name,callable $fn): void { global $tests;$tests[$name]=$fn; }
function eq($actual,$expected): void { if ($actual!==$expected) { throw new RuntimeException('Expected '.var_export($expected,true).', got '.var_export($actual,true)); } }
function truth(bool $value): void { eq($value,true); }
function fails(callable $fn,string $reason): void
{
    try { $fn(); } catch (\SnappPay\Failure $e) { eq($e->reason,$reason);return; }
    throw new RuntimeException('Expected failure: '.$reason);
}

require __DIR__.'/unit/core.php';
require __DIR__.'/integration/lifecycle.php';
$passed=0;$failed=0;
foreach ($tests as $name=>$fn) {
    try { $fn();++$passed;echo "PASS $name\n"; }
    catch (Throwable $e) { ++$failed;fwrite(STDERR,"FAIL $name: ".$e->getMessage()."\n"); }
}
echo "$passed passed; $failed failed\n";
exit($failed?1:0);
