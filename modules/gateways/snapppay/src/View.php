<?php
declare(strict_types=1);
namespace SnappPay;

final class View
{
    public static function card(array $offer, int $invoiceId, string $base, string $mobile, bool $fa): string
    {
        if (!$offer['eligible']) {
            return '';
        }
        $e=static fn(string $v): string=>Security::escape($v);
        $nonce=bin2hex(random_bytes(32));
        $_SESSION['snapppay_forms']=array_filter($_SESSION['snapppay_forms']??[],static fn(array $v): bool=>$v['expires']>time());
        if (count($_SESSION['snapppay_forms'])>=30) {
            array_shift($_SESSION['snapppay_forms']);
        }
        $_SESSION['snapppay_forms'][$nonce]=['invoice'=>$invoiceId,'expires'=>time()+1800];
        $dir=$fa?'rtl':'ltr';
        $label=$fa?'شماره موبایل اسنپ‌پی':'SnappPay mobile number';
        $button=$fa?'ادامه در اسنپ‌پی':'Continue with SnappPay';
        $note=$fa?'پرداخت در صفحه امن اسنپ‌پی انجام می‌شود.':'Complete payment on SnappPay’s secure checkout.';
        $token=$e((string)generate_token('plain'));
        return '<link rel="stylesheet" href="'.$e($base).'modules/gateways/snapppay/assets/style.css">'
            .'<section class="sp-card" dir="'.$dir.'" aria-label="SnappPay"><div class="sp-brand"><span class="sp-mark" aria-hidden="true">S</span><span>SnappPay</span><span class="sp-tag">BNPL</span></div>'
            .'<h3>'.$e($offer['title_message']).'</h3><p class="sp-description">'.$e($offer['description']).'</p>'
            .'<form method="post" action="'.$e($base).'modules/gateways/snapppay/start.php">'
            .'<input type="hidden" name="token" value="'.$token.'"><input type="hidden" name="invoice" value="'.$invoiceId.'">'
            .'<input type="hidden" name="nonce" value="'.$nonce.'"><label for="sp-mobile-'.$nonce.'">'.$label.'</label>'
            .'<input id="sp-mobile-'.$nonce.'" name="mobile" type="tel" inputmode="tel" autocomplete="tel" maxlength="24" required value="'.$e($mobile).'" dir="ltr">'
            .'<button class="sp-button" type="submit">'.$button.' <span aria-hidden="true">↗</span></button></form><small>'.$note.'</small></section>';
    }

    public static function outcome(string $state, ?string $invoiceUrl = null, bool $fa = false): string
    {
        $good=$state==='paid';
        $title=$good?($fa?'پرداخت تکمیل شد':'Payment complete'):($fa?'وضعیت پرداخت':'Payment status');
        $messages=['paid'=>['Invoice payment recorded.','پرداخت فاکتور ثبت شد.'],
            'pending'=>['Payment has not completed. Return to your invoice to continue.','پرداخت تکمیل نشده است. به فاکتور خود برگردید.'],
            'reverted'=>['Payment was reversed by SnappPay.','پرداخت توسط اسنپ‌پی برگشت داده شد.'],
            'cancelled'=>['Payment was cancelled.','پرداخت لغو شد.']];
        $message=($messages[$state]??['Payment requires confirmation. Check your invoice or contact support before paying again.','پرداخت نیاز به بررسی دارد. پیش از پرداخت دوباره، فاکتور را بررسی کنید یا با پشتیبانی تماس بگیرید.'])[$fa?1:0];
        $css=file_get_contents(dirname(__DIR__).'/assets/style.css');
        $link=$invoiceUrl?'<a class="sp-button" href="'.Security::escape($invoiceUrl).'">'.($fa?'مشاهده فاکتور':'View invoice').'</a>':'';
        return '<!doctype html><html lang="'.($fa?'fa':'en').'" dir="'.($fa?'rtl':'ltr').'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.'</title><style>'.$css.'</style></head><body class="sp-page"><main class="sp-card"><div class="sp-mark">'.($good?'✓':'S').'</div><h1>'.$title.'</h1><p>'.Security::escape($message).'</p>'.$link.'</main></body></html>';
    }
}
