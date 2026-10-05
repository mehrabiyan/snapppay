<?php
declare(strict_types=1);
namespace SnappPay;

use WHMCS\Database\Capsule;

final class WhmcsBilling implements Billing
{
    public function invoice(int $id): array
    {
        $invoice = localAPI('GetInvoice', ['invoiceid'=>$id]);
        if (($invoice['result']??null)!=='success') {
            throw new Failure('invoice_unavailable');
        }
        $client = Capsule::table('tblclients')->where('id',(int)$invoice['userid'])->first();
        $currency = $client ? Capsule::table('tblcurrencies')->where('id',(int)$client->currency)->first() : null;
        if (!$currency) {
            throw new Failure('unsupported_currency');
        }
        $invoice['currency'] = $currency->code;
        return $invoice;
    }

    public function paymentExists(array $row): bool
    {
        $records = Capsule::table('tblaccounts')->where('transid',$row['transaction_id'])->get();
        if (count($records)===0) {
            return false;
        }
        if (count($records)!==1) {
            throw new Failure('ledger_collision');
        }
        $record=$records[0];
        if ((int)$record->invoiceid!==$row['invoice_id'] || (int)$record->userid!==$row['client_id'] || $record->gateway!=='snapppay'
            || Money::rials((string)$record->amountin,$row['currency'])!==$row['original_amount']) {
            throw new Failure('ledger_collision');
        }
        return true;
    }

    public function credit(array $row): void
    {
        checkCbInvoiceID($row['invoice_id'],'SnappPay');
        checkCbTransID($row['transaction_id']);
        addInvoicePayment($row['invoice_id'],$row['transaction_id'],Money::decimal($row['original_amount'],$row['currency']),'0.00','snapppay');
        logTransaction('snapppay',['transactionId'=>$row['transaction_id'],'invoiceId'=>$row['invoice_id'],'operation'=>'credit'],'Successful');
    }

    public function setFee(array $invoice, ?string $amount, string $description): void
    {
        $id=(int)$invoice['invoiceid'];
        Capsule::connection()->transaction(static function () use ($id,$invoice,$amount,$description): void {
            Capsule::table('tblinvoiceitems')->where('invoiceid',$id)->where('type',self::FEE_ITEM)->delete();
            if ($amount!==null) {
                Capsule::table('tblinvoiceitems')->insert(['invoiceid'=>$id,'userid'=>(int)$invoice['userid'],'type'=>self::FEE_ITEM,'relid'=>0,
                    'description'=>$description,'amount'=>$amount,'taxed'=>0,'duedate'=>(string)($invoice['duedate']??date('Y-m-d')),
                    'paymentmethod'=>'snapppay','notes'=>'']);
            }
            updateInvoiceTotal($id);
        });
    }

    public function refundExists(string $refundId, array $row, int $amount): bool
    {
        $records=Capsule::table('tblaccounts')->where('transid',$refundId)->get();
        if (!count($records)) {
            return false;
        }
        if (count($records)!==1) {
            throw new Failure('refund_ledger_collision');
        }
        $record=$records[0];
        if ((int)$record->invoiceid!==$row['invoice_id'] || $record->gateway!=='snapppay'
            || Money::rials((string)$record->amountout,$row['currency'])!==$amount) {
            throw new Failure('refund_ledger_collision');
        }
        return true;
    }
}
