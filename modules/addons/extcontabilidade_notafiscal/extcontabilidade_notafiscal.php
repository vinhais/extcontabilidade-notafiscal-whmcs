<?php

use WHMCS\Billing\Invoice;
use WHMCS\Database\Capsule;
use WHMCS\User\Client;
use WHMCS\Module\Addon\Setting as AddonSetting;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/lib/ExtApiClient.php';

/**
 * @param int $invoiceId
 * @param int $clientId
 * @return string
 */
function extcontabilidade_notafiscal_generate_idempotency_key($invoiceId, $clientId)
{
    return $invoiceId . '.' . $clientId;
}

function extcontabilidade_notafiscal_config(): array
{
    return [
        'name' => 'EXT Contabilidade - NotaFiscal',
        'description' => 'Módulo pra emissão de nota fiscal usando a API da EXT Contabilidade para WHMCS',
        'version' => '0.1.0',
        'author' => 'Gabriel',
        'language' => 'portuguese-br',
        'fields' => [
            'production_mode' => [
                'FriendlyName' => 'Modo de produção',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Marque para usar a chave de produção. Desmarcado, usa a chave de desenvolvimento.',
            ],
            'production_api_key' => [
                'FriendlyName' => 'Chave de API de produção',
                'Type' => 'password',
                'Size' => '120',
                'Default' => '',
                'Description' => 'Informe a chave ext_sk_live_... para emitir notas fiscais com validade fiscal.',
            ],
            'development_api_key' => [
                'FriendlyName' => 'Chave de API de desenvolvimento',
                'Type' => 'password',
                'Size' => '120',
                'Default' => '',
                'Description' => 'Informe a chave ext_sk_test_... para testes sem validade fiscal. As duas chaves podem ser preenchidas juntas.',
            ],
            'cnae' => [
                'FriendlyName' => 'CNAE',
                'Type' => 'text',
                'Size' => '15',
                'Default' => '',
                'Description' => 'Informe os 7 dígitos, sem máscara (ex.: 6202300). Em branco, usa a atividade principal da empresa na EXT.',
            ],
            'service_code' => [
                'FriendlyName' => 'Código de tributação nacional',
                'Type' => 'text',
                'Size' => '15',
                'Default' => '',
                'Description' => 'Opcional: 6 dígitos (ex.: 010501). Preencha junto ao CNAE para selecionar uma linha diferente da padrão, liberada pela EXT.',
            ],
        ],
    ];
}

function extcontabilidade_notafiscal_activate(): array
{
    try {
        if (!Capsule::schema()->hasTable('tblnotafiscal_ext')) {
            Capsule::schema()->create('tblnotafiscal_ext', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('invoice_id')->index();
                $table->unsignedInteger('user_id')->index();
                $table->string('currency', 3);
                $table->decimal('amount', 18, 2);
                $table->string('cnae', 7)->nullable();
                $table->string('status', 24)->default('pending')->index();
                $table->string('ext_id', 64)->nullable();
                $table->boolean('livemode')->default(false);
                $table->string('reference', 64)->nullable();
                $table->longText('response')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->timestamp('next_check_at')->nullable()->index();
                $table->timestamps();
                $table->unique(['ext_id', 'livemode']);
            });
        }

        if (!Capsule::schema()->hasTable('tblnotafiscal_ext_logs')) {
            Capsule::schema()->create('tblnotafiscal_ext_logs', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('notafiscal_id')->index();
                $table->string('action', 32);
                $table->string('status', 24)->nullable();
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->string('request_id', 64)->nullable();
                $table->text('message')->nullable();
                $table->longText('response')->nullable();
                $table->timestamps();
            });
        }
    } catch (Throwable $exception) {
        logActivity('EXT NotaFiscal: table creation failed: ' . $exception->getMessage());

        return ['status' => 'error', 'description' => 'Não foi possível criar as tabelas do módulo. Consulte o log de atividades.'];
    }

    return ['status' => 'success', 'description' => 'O módulo foi ativado.'];
}

function extcontabilidade_notafiscal_deactivate(): array
{
    return ['status' => 'success', 'description' => 'O módulo foi desativado.'];
}

/**
 * @param array<string, mixed> $vars
 * @return void
 */
function extcontabilidade_notafiscal_output($vars)
{
    require_once __DIR__ . '/lib/ExtAdmin.php';

    try {
        echo extcontabilidade_notafiscal_dashboard($vars);
    } catch (Throwable $exception) {
        logActivity('EXT NotaFiscal: dashboard failed: ' . $exception->getMessage());
        echo '<div class="alert alert-danger">Não foi possível carregar as estatísticas e os logs.</div>';
    }
}

/**
 * @param int $notaFiscalId
 * @param string $status
 * @param array<string, mixed>|null $response
 * @param int|null $httpStatus
 * @param string|null $requestId
 * @param string|null $message
 * @param string $action
 * @return void
 */
function extcontabilidade_notafiscal_log($notaFiscalId, $status, $response = null, $httpStatus = null, $requestId = null, $message = null, $action = 'poll')
{
    $now = date('Y-m-d H:i:s');

    try {
        Capsule::table('tblnotafiscal_ext_logs')->insert([
            'notafiscal_id' => $notaFiscalId,
            'action' => $action,
            'status' => $status,
            'http_status' => $httpStatus,
            'request_id' => $requestId,
            'message' => $message,
            'response' => $response === null ? null : json_encode($response, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    } catch (Throwable $exception) {
        logActivity('EXT NotaFiscal: local logging failed for record ' . $notaFiscalId . ': ' . $exception->getMessage());
    }

    try {
        logModuleCall(
            'extcontabilidade_notafiscal',
            $action,
            ['notafiscal_id' => $notaFiscalId],
            $response ?? [],
            [
                'status' => $status,
                'http_status' => $httpStatus,
                'request_id' => $requestId,
                'message' => $message,
            ],
            []
        );
    } catch (Throwable $exception) {
        logActivity('EXT NotaFiscal: module logging failed for record ' . $notaFiscalId . ': ' . $exception->getMessage());
    }
}

/** @return bool */
function extcontabilidade_notafiscal_is_active()
{
    $activeModules = Capsule::table('tblconfiguration')->where('setting', 'ActiveAddonModules')->value('value');

    return in_array('extcontabilidade_notafiscal', explode(',', (string) $activeModules), true);
}

/** @return array<string, string> */
function extcontabilidade_notafiscal_get_config()
{
    $config = [];

    foreach (AddonSetting::getForAddon('extcontabilidade_notafiscal') as $setting) {
        $config[$setting->setting] = $setting->value;
    }

    return $config;
}

/**
 * @param Invoice $invoice
 * @return bool
 */
function extcontabilidade_notafiscal_can_issue($invoice)
{
    return !in_array($invoice->status, ['Draft', 'Cancelled', 'Refunded'], true)
        && (float) $invoice->total > 0 && !$invoice->items()->where('type', 'Invoice')->exists();
}

/**
 * @param Invoice $invoice
 * @return array{message: string}
 */
function extcontabilidade_notafiscal_issue($invoice)
{
    if (!extcontabilidade_notafiscal_can_issue($invoice)) {
        throw new DomainException('Não é possível emitir para rascunhos, faturas canceladas, reembolsadas, de valor zero ou consolidadas pelo Mass Pay.');
    }

    $client = Client::find($invoice->clientId);

    if (!$client) {
        throw new DomainException('Cliente da fatura não encontrado.');
    }

    if ($client->country && strtoupper($client->country) !== 'BR') {
        throw new DomainException('A emissão para clientes estrangeiros ainda não está configurada neste módulo.');
    }

    $document = Capsule::table('tblcustomfieldsvalues as values')
        ->join('tblcustomfields as fields', 'fields.id', '=', 'values.fieldid')
        ->where('fields.type', 'client')
        ->where('fields.fieldname', 'CPF/CNPJ')
        ->where('values.relid', $client->id)
        ->value('values.value');
    $document = preg_replace('/\D/', '', (string) $document);

    if (!in_array(strlen($document), [11, 14], true)) {
        throw new DomainException('Preencha o campo CPF/CNPJ do cliente com um documento válido.');
    }

    $currency = $invoice->getCurrency();
    $amount = (float) $invoice->total;
    $amountInBrl = $amount;

    if ($currency['code'] !== 'BRL') {
        $brlRate = Capsule::table('tblcurrencies')->where('code', 'BRL')->value('rate');

        if ((float) $brlRate <= 0 || (float) $currency['rate'] <= 0) {
            throw new DomainException('Configure a moeda BRL e as taxas de câmbio no WHMCS para emitir esta nota.');
        }

        $amountInBrl = $amount / (float) $currency['rate'] * (float) $brlRate;
    }

    $amountInCents = (int) round($amountInBrl * 100);

    if ($amountInCents < 100) {
        throw new DomainException('O valor da nota fiscal deve ser de pelo menos R$ 1,00.');
    }

    $description = trim(implode("\n", $invoice->items->pluck('description')->all()));
    $reference = extcontabilidade_notafiscal_generate_idempotency_key($invoice->id, $client->id);
    $request = [
        'amount_in_cents' => $amountInCents,
        'description' => mb_substr($description ?: 'Serviços da fatura ' . $invoice->id, 0, 2000),
        'reference' => $reference,
        'customer' => [
            'type' => 'br',
            'document' => $document,
            'name' => trim($client->firstName . ' ' . $client->lastName),
        ],
    ];
    $config = extcontabilidade_notafiscal_get_config();

    foreach (['cnae' => 7, 'service_code' => 6] as $field => $length) {
        $value = trim($config[$field] ?? '');

        if ($value === '') {
            continue;
        }

        if (!preg_match('/\A[0-9]{' . $length . '}\z/', $value)) {
            throw new DomainException(($field === 'cnae' ? 'O CNAE' : 'O código de tributação nacional')
                . ' deve conter exatamente ' . $length . ' dígitos, sem máscara.');
        }

        $request[$field] = $value;
    }

    $api = ExtApiClient::fromConfig($config);
    $livemode = ($config['production_mode'] ?? '') === 'on';

    $noteId = Capsule::connection()->transaction(function () use ($invoice, $client, $currency, $amount, $reference, $livemode, $request) {
        $currentInvoice = Capsule::table('tblinvoices')->where('id', $invoice->id)->lockForUpdate()->first();

        if (!$currentInvoice || (int) $currentInvoice->userid !== (int) $client->id
            || (float) $currentInvoice->total !== $amount || $currentInvoice->status !== $invoice->status) {
            throw new DomainException('A fatura foi alterada. Atualize a página antes de emitir.');
        }

        if (Capsule::table('tblnotafiscal_ext')->where('invoice_id', $invoice->id)->where('livemode', $livemode)->exists()) {
            throw new DomainException('Esta fatura já possui uma nota fiscal neste ambiente. Atualize a página.');
        }

        $now = date('Y-m-d H:i:s');

        return Capsule::table('tblnotafiscal_ext')->insertGetId([
            'invoice_id' => $invoice->id,
            'user_id' => $client->id,
            'currency' => $currency['code'],
            'amount' => number_format($amount, 2, '.', ''),
            'cnae' => $request['cnae'] ?? null,
            'status' => 'pending',
            'livemode' => $livemode,
            'reference' => $reference,
            'next_check_at' => date('Y-m-d H:i:s', time() + 300),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    });
    $note = Capsule::table('tblnotafiscal_ext')->where('id', $noteId)->first();

    try {
        $existing = $api->findNotaFiscalByReference($reference);

        if ($api->getLastResponse()->getStatusCode() !== 200 || isset($existing['error'])) {
            extcontabilidade_notafiscal_save_response($note, $existing, $api, 'create');
            throw new UnexpectedValueException('The API returned an invalid reference response.');
        }

        $existing = extcontabilidade_notafiscal_reference_result($existing);

        if ($existing !== null) {
            extcontabilidade_notafiscal_save_response($note, $existing, $api, 'recover');

            return ['message' => 'A nota fiscal existente na EXT foi recuperada.'];
        }

        $response = $api->createNotaFiscal($request, $reference);

        if (in_array($api->getLastResponse()->getStatusCode(), [400, 401, 403, 413, 415, 422], true)) {
            Capsule::table('tblnotafiscal_ext')->where('id', $noteId)->update([
                'status' => 'failed',
                'response' => json_encode($response, JSON_THROW_ON_ERROR),
                'next_check_at' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $note->status = 'failed';
        }

        extcontabilidade_notafiscal_save_response($note, $response, $api, 'create');

        return ['message' => 'Nota fiscal enviada para emissão. O cron acompanhará o resultado.'];
    } catch (Throwable $exception) {
        if (!$exception instanceof DomainException) {
            extcontabilidade_notafiscal_log($noteId, $note->status, null, null, null, $exception->getMessage(), 'create');
        }
        throw $exception;
    }
}

/**
 * @param array<string, mixed> $response
 * @return array<string, mixed>|null
 */
function extcontabilidade_notafiscal_reference_result($response)
{
    if (($response['object'] ?? null) !== 'list' || !isset($response['data']) || !is_array($response['data'])
        || count($response['data']) > 1 || (!empty($response['data']) && !is_array($response['data'][0] ?? null))) {
        throw new UnexpectedValueException('The API returned an invalid reference list.');
    }

    return $response['data'][0] ?? null;
}

/**
 * @param int $invoiceId
 * @param string $description
 * @return void
 */
function extcontabilidade_notafiscal_cancel_invoice($invoiceId, $description)
{
    if (!extcontabilidade_notafiscal_is_active()) {
        return;
    }

    $notes = Capsule::table('tblnotafiscal_ext')->where('invoice_id', $invoiceId)
        ->where('status', 'issued')->whereNotNull('ext_id')->get();
    $config = extcontabilidade_notafiscal_get_config();

    foreach ($notes as $note) {
        $response = $note->response ? json_decode($note->response, true) : [];

        if (!empty($response['cancellation']['status'])) {
            continue;
        }

        if (!empty($response['cancelable_until']) && strtotime($response['cancelable_until']) <= time()) {
            continue;
        }

        try {
            $config['production_mode'] = $note->livemode ? 'on' : '';
            $api = ExtApiClient::fromConfig($config);
            $response = $api->getNotaFiscal($note->ext_id);
            extcontabilidade_notafiscal_save_response($note, $response, $api, 'auto_cancel_check');

            if ($response['status'] !== 'issued' || !empty($response['cancellation']['status'])) {
                continue;
            }

            if (!empty($response['cancelable_until']) && strtotime($response['cancelable_until']) <= time()) {
                continue;
            }

            $response = $api->cancelNotaFiscal($note->ext_id, 'other', $description);
            extcontabilidade_notafiscal_save_response($note, $response, $api, 'auto_cancel');
        } catch (Throwable $exception) {
            if (!$exception instanceof DomainException) {
                extcontabilidade_notafiscal_log($note->id, $note->status, null, null, null, $exception->getMessage(), 'auto_cancel');
            }

            logActivity('EXT NotaFiscal: automatic cancellation failed for invoice ' . $invoiceId . ': ' . $exception->getMessage());
        }
    }
}

/** @return void */
function extcontabilidade_notafiscal_sync_pending()
{
    if (!extcontabilidade_notafiscal_is_active()) {
        return;
    }

    $config = extcontabilidade_notafiscal_get_config();
    $now = date('Y-m-d H:i:s');
    $pendingStatuses = ['pending', 'queued', 'processing', 'cancel_pending'];
    $notes = Capsule::table('tblnotafiscal_ext')
        ->whereIn('status', $pendingStatuses)
        ->where(function ($query) {
            $query->whereNotNull('ext_id')->orWhereNotNull('reference');
        })
        ->where(function ($query) use ($now) {
            $query->whereNull('next_check_at')->orWhere('next_check_at', '<=', $now);
        })
        ->orderBy('next_check_at')
        ->orderBy('id')
        ->limit(100)
        ->get();
    $apiClients = [];
    $blockedModes = [];

    foreach ($notes as $note) {
        $mode = (int) $note->livemode;

        if (isset($blockedModes[$mode])) {
            continue;
        }

        $now = date('Y-m-d H:i:s');
        $nextCheckAt = date('Y-m-d H:i:s', time() + 300);
        $claimed = Capsule::table('tblnotafiscal_ext')
            ->where('id', $note->id)
            ->whereIn('status', $pendingStatuses)
            ->where(function ($query) use ($now) {
                $query->whereNull('next_check_at')->orWhere('next_check_at', '<=', $now);
            })
            ->update(['last_checked_at' => $now, 'next_check_at' => $nextCheckAt]);

        if (!$claimed) {
            continue;
        }

        try {
            if (!isset($apiClients[$mode])) {
                $config['production_mode'] = $mode ? 'on' : '';
                $apiClients[$mode] = ExtApiClient::fromConfig($config);
            }

            $api = $apiClients[$mode];
            $response = $note->ext_id
                ? $api->getNotaFiscal($note->ext_id)
                : $api->findNotaFiscalByReference($note->reference);
            $httpResponse = $api->getLastResponse();
            $httpStatus = $httpResponse->getStatusCode();
            $requestId = $httpResponse->getHeaderLine('Request-Id') ?: ($response['error']['request_id'] ?? null);
            $retryAfter = max(300, (int) $httpResponse->getHeaderLine('Retry-After'));
            $nextCheckAt = date('Y-m-d H:i:s', time() + $retryAfter);

            if ($httpStatus >= 400 || (isset($response['error']) && !isset($response['status']))) {
                Capsule::table('tblnotafiscal_ext')->where('id', $note->id)->update(['next_check_at' => $nextCheckAt]);
                extcontabilidade_notafiscal_log($note->id, $note->status, $response, $httpStatus, $requestId, $response['error']['message'] ?? 'The API request failed.');

                if (in_array($httpStatus, [401, 403, 429, 503], true)) {
                    if (in_array($httpStatus, [429, 503], true)) {
                        Capsule::table('tblnotafiscal_ext')
                            ->where('livemode', $mode)
                            ->whereIn('status', $pendingStatuses)
                            ->where(function ($query) use ($nextCheckAt) {
                                $query->whereNull('next_check_at')->orWhere('next_check_at', '<', $nextCheckAt);
                            })
                            ->update(['next_check_at' => $nextCheckAt]);
                    }

                    $blockedModes[$mode] = true;
                }

                continue;
            }

            if (!$note->ext_id) {
                if ($httpStatus !== 200) {
                    throw new UnexpectedValueException('The API returned an invalid reference status.');
                }

                $result = extcontabilidade_notafiscal_reference_result($response);

                if ($result === null) {
                    extcontabilidade_notafiscal_log($note->id, $note->status, $response, $httpStatus, $requestId, 'No NotaFiscal found for this reference yet.');
                    continue;
                }

                $response = $result;
            }

            extcontabilidade_notafiscal_save_response($note, $response, $api);
        } catch (Throwable $exception) {
            if ($exception instanceof InvalidArgumentException) {
                $blockedModes[$mode] = true;
            }

            try {
                extcontabilidade_notafiscal_log($note->id, $note->status, null, null, null, $exception->getMessage());
            } catch (Throwable $logException) {
                logActivity('EXT NotaFiscal: polling failed for record ' . $note->id . ': ' . $exception->getMessage());
            }
        }
    }
}

/**
 * @param stdClass $note
 * @param array<string, mixed> $response
 * @param ExtApiClient $api
 * @param string $action
 * @return void
 */
function extcontabilidade_notafiscal_save_response($note, $response, $api, $action = 'poll')
{
    $http = $api->getLastResponse();
    $httpStatus = $http->getStatusCode();
    $requestId = $http->getHeaderLine('Request-Id') ?: ($response['error']['request_id'] ?? null);

    if ($httpStatus >= 400 || (isset($response['error']) && !isset($response['status']))) {
        $message = $response['error']['message'] ?? 'Não foi possível concluir a operação na EXT.';
        extcontabilidade_notafiscal_log($note->id, $note->status, $response, $httpStatus, $requestId, $message, $action);
        throw new DomainException($message);
    }

    if (
        !in_array($httpStatus, [200, 202], true)
        || ($response['object'] ?? null) !== 'nfse'
        || empty($response['id']) || !is_string($response['id'])
        || !isset($response['status'], $response['livemode']) || !is_string($response['status']) || !is_bool($response['livemode'])
        || (bool) $response['livemode'] !== (bool) $note->livemode
        || ($note->ext_id && $note->ext_id !== $response['id'])
        || ($note->reference && ($response['reference'] ?? null) !== $note->reference)
    ) {
        throw new UnexpectedValueException('The API returned an invalid NotaFiscal response.');
    }

    $status = in_array($response['status'], ['queued', 'processing', 'issued', 'failed', 'indeterminate', 'canceled'], true)
        ? $response['status']
        : 'indeterminate';

    if ($status === 'issued' && ($response['cancellation']['status'] ?? null) === 'pending') {
        $status = 'cancel_pending';
    }

    $nextCheckAt = in_array($status, ['queued', 'processing', 'cancel_pending'], true)
        ? date('Y-m-d H:i:s', time() + max(300, (int) $http->getHeaderLine('Retry-After')))
        : null;

    $changes = Capsule::connection()->transaction(function () use ($note, $status, $response, $nextCheckAt) {
        $current = Capsule::table('tblnotafiscal_ext')->where('id', $note->id)->lockForUpdate()->first();

        if (!$current || $current->status !== $note->status || $current->ext_id !== $note->ext_id
            || $current->response !== $note->response) {
            throw new DomainException('O registro da nota foi alterado durante a consulta. Atualize o status novamente.');
        }

        $changes = [
            'ext_id' => $response['id'],
            'status' => $status,
            'cnae' => $response['cnae'] ?? $note->cnae,
            'response' => json_encode($response, JSON_THROW_ON_ERROR),
            'next_check_at' => $nextCheckAt,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        Capsule::table('tblnotafiscal_ext')->where('id', $note->id)->update($changes);

        return $changes;
    });

    foreach ($changes as $field => $value) {
        $note->$field = $value;
    }

    extcontabilidade_notafiscal_log($note->id, $status, $response, $httpStatus, $requestId,
        $response['error']['message'] ?? ($response['cancellation']['error']['message'] ?? null), $action);
}
