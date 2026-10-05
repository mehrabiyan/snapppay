<?php
if (!defined('WHMCS')) {
    exit('Direct access denied');
}
function snapppay_ops_render(array $rows,string $base,\SnappPay\Runtime $runtime,string $notice,?array $preview,int $page,?int $filter,string $moduleLink,string $token): void
{
$e=static fn(string $v): string=>\SnappPay\Security::escape($v);
$counts=['paid'=>0,'attention'=>0];
foreach ($rows as $row) {
    if ($row['state']==='paid') { ++$counts['paid']; }
    if ($row['error'] || $row['refund']) { ++$counts['attention']; }
}
?>
<link rel="stylesheet" href="<?= $e($base) ?>modules/gateways/snapppay/assets/style.css">
<div class="sp-admin">
    <div class="sp-brand"><span class="sp-mark">S</span> SnappPay <span class="sp-tag"><?= $e(strtoupper($runtime->config->values['environment'])) ?></span></div>
    <h1>Payment operations</h1>
    <p class="sp-subtitle">Follow payments from checkout to settlement. Review exceptions before taking action.</p>
    <div class="sp-metrics">
        <div class="sp-metric">Payments on this page<strong><?= count($rows) ?></strong>Latest 25 attempts per page</div>
        <div class="sp-metric">Recorded payments<strong><?= $counts['paid'] ?></strong>Provider settled, invoice credited</div>
        <div class="sp-metric">Needs attention<strong><?= $counts['attention'] ?></strong>Recovery or accounting pending</div>
    </div>
    <?php if ($notice): ?><div class="sp-notice" role="status"><?= $e($notice) ?></div><?php endif; ?>
    <form class="sp-toolbar" method="get" action="addonmodules.php">
        <input type="hidden" name="module" value="snapppay_ops">
        <label>Invoice ID<input name="invoice" inputmode="numeric" value="<?= $filter??'' ?>" placeholder="All invoices"></label>
        <button class="sp-button" type="submit">Filter payments</button>
    </form>
    <div class="sp-scroll"><table><thead><tr><th>Invoice / reference</th><th>Amount</th><th>Status</th><th>Last updated (UTC)</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($rows as $row): ?>
        <tr><td><a href="invoices.php?action=edit&amp;id=<?= $row['invoice_id'] ?>">Invoice #<?= $row['invoice_id'] ?></a><br><code><?= $e($row['transaction_id']) ?></code></td>
        <td><?= $e(\SnappPay\Money::decimal($row['amount'],$row['currency'])) ?> <?= $e($row['currency']) ?></td>
        <td><span class="sp-state sp-state-<?= $e($row['state']) ?>"><?= $e($row['state']) ?></span><?php if ($row['error']): ?><br><small><?= $e($row['error']) ?></small><?php endif; ?>
        <?php if ($row['refund']): ?><br><small>Refund: <?= $e($row['refund']['state']) ?> · accounting check required<br><?= $e($row['refund']['id']) ?><br><?= $e(\SnappPay\Money::decimal($row['refund']['amount'],$row['currency'])) ?> <?= $e($row['currency']) ?></small><?php endif; ?></td>
        <td><?= gmdate('Y-m-d H:i',$row['updated_at']) ?></td><td>
        <form method="post" action="<?= $e($moduleLink) ?>" class="sp-actions">
            <input type="hidden" name="token" value="<?= $e($token) ?>"><input type="hidden" name="transaction" value="<?= $e($row['transaction_id']) ?>">
            <button class="sp-button" name="action" value="reconcile">Reconcile</button>
            <button class="sp-button" name="action" value="status">Provider status</button>
        </form>
        <?php if ($row['state']==='paid' && !$row['refund']): ?>
        <details><summary>Refund preview</summary><form method="post" action="<?= $e($moduleLink) ?>">
            <input type="hidden" name="token" value="<?= $e($token) ?>"><input type="hidden" name="transaction" value="<?= $e($row['transaction_id']) ?>">
            <label>Refund amount (<?= $e($row['currency']) ?>)<input name="refundAmount" inputmode="decimal" required></label><button class="sp-button" name="action" value="preview">Preview reduced cart</button>
        </form></details><?php endif; ?>
        <?php if (!$row['credited'] && !empty($runtime->config->values['allowRevert']) && !in_array($row['state'],['cancelled','reverted','creating','token_unknown'],true)): ?>
        <details><summary>Pre-settlement reversal</summary><form method="post" action="<?= $e($moduleLink) ?>">
            <input type="hidden" name="token" value="<?= $e($token) ?>"><input type="hidden" name="transaction" value="<?= $e($row['transaction_id']) ?>">
            <label class="sp-check"><input type="checkbox" name="confirm" value="yes" required>Confirm irreversible reversal; services will not be delivered.</label>
            <button class="sp-button sp-danger" name="action" value="revert">Revert payment</button>
        </form></details><?php endif; ?>
        </td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5">No payment attempts found.</td></tr><?php endif; ?>
    </tbody></table></div>
    <nav class="sp-pagination" aria-label="Payment pages"><a href="<?= $e($moduleLink.'&page='.max(1,$page-1).($filter?'&invoice='.$filter:'')) ?>">← Previous</a><span>Page <?= $page ?></span><a href="<?= $e($moduleLink.'&page='.($page+1).($filter?'&invoice='.$filter:'')) ?>">Next →</a></nav>
    <?php if ($preview): ?><div class="sp-notice"><strong>Refund preview · Invoice #<?= $preview['invoice'] ?></strong>
        <p>Refund <?= $e(\SnappPay\Money::decimal($preview['amount'],$preview['currency'])) ?> <?= $e($preview['currency']) ?>. Remaining <?= $e(\SnappPay\Money::decimal($preview['remaining'],$preview['currency'])) ?> <?= $e($preview['currency']) ?>.</p>
        <?php if (!$preview['cart']): ?>Full cancellation through Cancel API.<?php else: ?><ul><?php foreach ($preview['cart'][0]['cartItems'] as $item): ?><li><?= $e($item['name']) ?> — <?= $e(\SnappPay\Money::decimal($item['amount'],$preview['currency'])) ?> <?= $e($preview['currency']) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <p>Execute through <a href="invoices.php?action=edit&amp;id=<?= $preview['invoice'] ?>">invoice Refund tab</a>, choose original SnappPay transaction and Refund through Gateway. SnappPay returns funds; do not send another manual payment. Partial refunds proportionally reduce saved line amounts.</p></div><?php endif; ?>
    <details><summary>Deployment readiness &amp; recovery</summary><div class="sp-notice">
        <p>Environment: <?= $e($runtime->config->values['environment']) ?> · API: <?= $e($runtime->config->values['apiUrl']) ?> · Production acknowledgment: <?= empty($runtime->config->values['certified'])?'pending':'configured' ?>.</p>
        <p>Register exact return URL: <code><?= $e($base) ?>modules/gateways/callback/snapppay.php</code>. Whitelist server egress IP with SnappPay. Enable WHMCS cron and inspect Gateway Log. Acceptance acknowledgment is configuration, not proof of provider certification.</p>
        <p>Review: inspect provider portal and invoice ledger before correction. token_unknown: never recreate same transaction blindly. Refund provider_done: complete native WHMCS refund accounting using same refund ID; never send another manual transfer. Keep environment credentials unchanged while payments remain unresolved.</p>
    </div></details>
</div>

<?php
}
