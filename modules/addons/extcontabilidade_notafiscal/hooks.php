<?php

use WHMCS\Billing\Invoice;
use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/extcontabilidade_notafiscal.php';

add_hook('InvoicePaid', 1, function (array $vars): void {
    try {
        if (!extcontabilidade_notafiscal_is_active()) {
            return;
        }

        $invoice = Invoice::find((int) $vars['invoiceid']);
        $config = extcontabilidade_notafiscal_get_config();
        $livemode = ($config['production_mode'] ?? '') === 'on';

        if (!$invoice || $invoice->status !== 'Paid' || !extcontabilidade_notafiscal_can_issue($invoice)
            || Capsule::table('tblnotafiscal_ext')
            ->where('invoice_id', $invoice->id)->where('livemode', $livemode)->exists()) {
            return;
        }

        extcontabilidade_notafiscal_issue($invoice);
    } catch (Throwable $exception) {
        logActivity('EXT NotaFiscal: automatic issuance failed for invoice ' . (int) $vars['invoiceid'] . ': ' . $exception->getMessage());
    }
});

add_hook('InvoiceCancelled', 1, function (array $vars): void {
    try {
        extcontabilidade_notafiscal_cancel_invoice((int) $vars['invoiceid'], 'Fatura cancelada no WHMCS.');
    } catch (Throwable $exception) {
        logActivity('EXT NotaFiscal: invoice cancellation hook failed: ' . $exception->getMessage());
    }
});

add_hook('InvoiceRefunded', 1, function (array $vars): void {
    try {
        extcontabilidade_notafiscal_cancel_invoice((int) $vars['invoiceid'], 'Fatura reembolsada no WHMCS.');
    } catch (Throwable $exception) {
        logActivity('EXT NotaFiscal: invoice refund hook failed: ' . $exception->getMessage());
    }
});

add_hook('AdminInvoicesControlsOutput', 1, function (array $vars): string {
    try {
        require_once __DIR__ . '/lib/ExtAdmin.php';

        return extcontabilidade_notafiscal_invoice_output((int) $vars['invoiceid']);
    } catch (Throwable $exception) {
        logActivity('EXT NotaFiscal: invoice output failed: ' . $exception->getMessage());

        return '<div class="alert alert-danger">Não foi possível carregar as notas fiscais da EXT.</div>';
    }
});

add_hook('AfterCronJob', 1, function (): void {
    try {
        extcontabilidade_notafiscal_sync_pending();

        if (!extcontabilidade_notafiscal_is_active()) {
            return;
        }

        $invoices = Capsule::table('tblinvoices as invoices')
            ->join('tblnotafiscal_ext as notes', 'notes.invoice_id', '=', 'invoices.id')
            ->whereIn('invoices.status', ['Cancelled', 'Refunded'])
            ->where('notes.status', 'issued')
            ->select('invoices.id', 'invoices.status')->distinct()->get();

        foreach ($invoices as $invoice) {
            extcontabilidade_notafiscal_cancel_invoice($invoice->id, $invoice->status === 'Refunded'
                ? 'Fatura reembolsada no WHMCS.' : 'Fatura cancelada no WHMCS.');
        }
    } catch (Throwable $exception) {
        logActivity('EXT NotaFiscal: cron synchronization failed: ' . $exception->getMessage());
    }
});
