<?php

use WHMCS\Billing\Invoice;
use WHMCS\Database\Capsule;
use WHMCS\User\Admin;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

/** @return bool */
function extcontabilidade_notafiscal_can_manage()
{
    $admin = Admin::getAuthenticatedUser();

    if (!$admin || !$admin->hasPermission('Manage Invoice')) {
        return false;
    }

    return in_array('extcontabilidade_notafiscal', $admin->getModulePermissions(), true);
}

/**
 * @param string $action
 * @param int $invoiceId
 * @param int $noteId
 * @param string|null $description
 * @return array{message?: string, content?: string, content_type?: string, filename?: string}
 */
function extcontabilidade_notafiscal_invoice_action($action, $invoiceId, $noteId = 0, $description = null)
{
    if (!extcontabilidade_notafiscal_can_manage() || !extcontabilidade_notafiscal_is_active()) {
        throw new DomainException('Você não tem permissão para gerenciar as notas fiscais.');
    }

    $invoice = Invoice::find($invoiceId);

    if (!$invoice) {
        throw new DomainException('Fatura não encontrada.');
    }

    if ($action === 'create') {
        return extcontabilidade_notafiscal_issue($invoice);
    }

    if (!in_array($action, ['refresh', 'cancel', 'pdf', 'xml', 'delete'], true)) {
        throw new DomainException('Ação inválida.');
    }

    $note = Capsule::table('tblnotafiscal_ext')->where('id', $noteId)
        ->where('invoice_id', $invoiceId)->where('user_id', $invoice->clientId)->first();

    if (!$note) {
        throw new DomainException('Nota fiscal não encontrada para esta fatura.');
    }

    if ($action === 'delete') {
        Capsule::connection()->transaction(function () use ($note) {
            Capsule::table('tblnotafiscal_ext_logs')->where('notafiscal_id', $note->id)->delete();
            Capsule::table('tblnotafiscal_ext')->where('id', $note->id)->delete();
        });
        logActivity('EXT NotaFiscal: local record ' . $note->id . ' removed from invoice ' . $invoiceId . '. EXT id: ' . $note->ext_id);

        return ['message' => 'Registro local apagado. A nota fiscal na EXT não foi alterada.'];
    }

    if (!$note->ext_id && ($action !== 'refresh' || !$note->reference)) {
        throw new DomainException('A nota ainda não possui um identificador na EXT.');
    }

    $config = extcontabilidade_notafiscal_get_config();
    $config['production_mode'] = $note->livemode ? 'on' : '';
    $api = ExtApiClient::fromConfig($config);

    if ($action === 'refresh') {
        Capsule::table('tblnotafiscal_ext')->where('id', $note->id)->update(['last_checked_at' => date('Y-m-d H:i:s')]);

        try {
            $response = $note->ext_id
                ? $api->getNotaFiscal($note->ext_id)
                : $api->findNotaFiscalByReference($note->reference);

            if (!$note->ext_id && $api->getLastResponse()->getStatusCode() === 200 && !isset($response['error'])) {
                $result = extcontabilidade_notafiscal_reference_result($response);

                if ($result === null) {
                    extcontabilidade_notafiscal_log($note->id, $note->status, $response, 200,
                        $api->getLastResponse()->getHeaderLine('Request-Id') ?: null, 'Nota fiscal ainda não encontrada na EXT.', 'refresh');

                    return ['message' => 'Nota fiscal ainda não encontrada na EXT.'];
                }

                $response = $result;
            }

            extcontabilidade_notafiscal_save_response($note, $response, $api, 'refresh');
        } catch (Throwable $exception) {
            if (!$exception instanceof DomainException) {
                extcontabilidade_notafiscal_log($note->id, $note->status, null, null, null, $exception->getMessage(), 'refresh');
            }

            throw $exception;
        }

        return ['message' => 'Status da nota fiscal atualizado.'];
    }

    if ($action === 'cancel') {
        if ($note->status !== 'issued') {
            throw new DomainException('A nota precisa estar emitida e sem cancelamento pendente.');
        }

        $description = trim((string) $description);

        if (mb_strlen($description) < 15 || mb_strlen($description) > 255) {
            throw new DomainException('A justificativa do cancelamento deve ter entre 15 e 255 caracteres.');
        }

        $response = $api->getNotaFiscal($note->ext_id);
        extcontabilidade_notafiscal_save_response($note, $response, $api, 'cancel_check');

        if ($note->status !== 'issued' || !empty($response['cancellation']['status'])) {
            throw new DomainException('A nota não está disponível para cancelamento. Confira o status e os logs.');
        }

        if (empty($response['cancelable_until']) || strtotime($response['cancelable_until']) <= time()) {
            throw new DomainException('O prazo de cancelamento da nota fiscal expirou. Consulte o suporte da EXT.');
        }

        $response = $api->cancelNotaFiscal($note->ext_id, 'other', $description);
        extcontabilidade_notafiscal_save_response($note, $response, $api, 'cancel');

        return ['message' => 'Cancelamento solicitado. O cron acompanhará a confirmação.'];
    }

    if (!in_array($note->status, ['issued', 'cancel_pending', 'canceled'], true)) {
        throw new DomainException('O documento ainda não está disponível para download.');
    }

    $content = $action === 'pdf' ? $api->downloadNotaFiscalPdf($note->ext_id) : $api->downloadNotaFiscalXml($note->ext_id);
    $http = $api->getLastResponse();
    extcontabilidade_notafiscal_log($note->id, $note->status, is_array($content) ? $content : null,
        $http->getStatusCode(), $http->getHeaderLine('Request-Id') ?: null, null, 'download_' . $action);

    if (!is_string($content) || $http->getStatusCode() !== 200) {
        throw new DomainException(is_array($content) ? ($content['error']['message'] ?? 'Documento indisponível.') : 'Documento indisponível.');
    }

    return [
        'content' => $content,
        'content_type' => $action === 'pdf' ? 'application/pdf' : 'application/xml',
        'filename' => 'NotaFiscal-' . $invoiceId . '.' . $action,
    ];
}

/**
 * @param int $invoiceId
 * @return string
 */
function extcontabilidade_notafiscal_invoice_output($invoiceId)
{
    if (!extcontabilidade_notafiscal_is_active() || !extcontabilidade_notafiscal_can_manage()) {
        return '';
    }

    $config = extcontabilidade_notafiscal_get_config();
    $livemode = ($config['production_mode'] ?? '') === 'on';
    $notes = Capsule::table('tblnotafiscal_ext')->where('invoice_id', $invoiceId)->orderBy('id', 'desc')->get();
    $labels = [
        'pending' => 'Pendente', 'queued' => 'Na fila', 'processing' => 'Em processamento',
        'issued' => 'Emitida', 'failed' => 'Falhou', 'indeterminate' => 'Resultado indeterminado',
        'cancel_pending' => 'Cancelamento pendente', 'canceled' => 'Cancelada',
    ];
    $escape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $token = $escape(generate_token('plain'));
    $hasCurrentNote = false;
    $html = '<div id="ext-notafiscal" class="panel panel-default" data-invoice-id="' . (int) $invoiceId
        . '" data-token="' . $token . '"><div class="panel-heading"><strong>NotaFiscal — EXT Contabilidade</strong></div>'
        . '<div class="panel-body"><div class="ext-notafiscal-message" role="status"></div>';

    foreach ($notes as $note) {
        $hasCurrentNote = $hasCurrentNote || (bool) $note->livemode === $livemode;
        $response = $note->response ? json_decode($note->response, true) : [];
        $html .= '<div class="ext-notafiscal-record" style="margin-bottom:10px"><strong>'
            . $escape($labels[$note->status] ?? $note->status) . '</strong> · '
            . ($note->livemode ? 'Produção' : 'Desenvolvimento');

        if ($note->ext_id) {
            $html .= ' · ' . $escape($note->ext_id);
        }

        $message = $response['error']['message'] ?? ($response['cancellation']['error']['message'] ?? null);

        if ($message) {
            $html .= '<div class="text-danger">' . $escape($message) . '</div>';
        }

        $html .= '<div style="margin-top:6px">';
        $buttons = [];

        if ($note->ext_id || $note->reference) {
            $buttons['refresh'] = ['Atualizar status', 'default'];
        }

        if (in_array($note->status, ['issued', 'cancel_pending', 'canceled'], true) && $note->ext_id) {
            $buttons['pdf'] = ['Baixar PDF', 'default'];
            $buttons['xml'] = ['Baixar XML', 'default'];
        }

        if ($note->status === 'issued' && empty($response['cancellation']['status'])
            && !empty($response['cancelable_until']) && strtotime($response['cancelable_until']) > time()) {
            $buttons['cancel'] = ['Cancelar NotaFiscal', 'warning'];
        }

        $buttons['delete'] = ['Apagar registro local', 'danger'];

        foreach ($buttons as $action => [$label, $style]) {
            $html .= '<button type="button" class="btn btn-' . $style . ' btn-xs" data-ext-action="'
                . $action . '" data-note-id="' . (int) $note->id . '">' . $label . '</button> ';
        }

        $html .= '</div></div>';
    }

    if (!$hasCurrentNote) {
        $html .= '<p>Nenhuma nota fiscal registrada em ' . ($livemode ? 'produção' : 'desenvolvimento') . '.</p>';
        $invoice = Invoice::find($invoiceId);

        if ($invoice && extcontabilidade_notafiscal_can_issue($invoice)) {
            $html .= '<button type="button" class="btn btn-primary btn-sm" data-ext-action="create" data-mode="'
                . ($livemode ? 'produção' : 'desenvolvimento') . '">Emitir NotaFiscal</button>';
        }
    }

    $html .= '</div></div>';
    $html .= <<<'HTML'
<script>
(function () {
    const panel = document.getElementById('ext-notafiscal');
    if (!panel || panel.dataset.bound) return;
    panel.dataset.bound = '1';
    panel.addEventListener('click', async function (event) {
        const button = event.target.closest('[data-ext-action]');
        if (!button || button.disabled) return;
        const action = button.dataset.extAction;
        const data = new URLSearchParams({
            action: action,
            invoice_id: panel.dataset.invoiceId,
            note_id: button.dataset.noteId || '0',
            token: panel.dataset.token
        });
        if (action === 'create' && !confirm('Emitir nota fiscal em ' + button.dataset.mode + ' para esta fatura?')) return;
        if (action === 'delete' && !confirm('Apagar o registro e os logs locais? A nota fiscal na EXT não será cancelada.')) return;
        if (action === 'cancel') {
            const description = prompt('Informe a justificativa do cancelamento (15 a 255 caracteres):');
            if (description === null) return;
            if (description.trim().length < 15 || description.trim().length > 255) {
                alert('A justificativa deve ter entre 15 e 255 caracteres.');
                return;
            }
            if (!confirm('Solicitar o cancelamento desta nota fiscal na EXT?')) return;
            data.set('description', description.trim());
        }
        const buttons = panel.querySelectorAll('button');
        const message = panel.querySelector('.ext-notafiscal-message');
        buttons.forEach(function (item) { item.disabled = true; });
        message.className = 'ext-notafiscal-message alert alert-info';
        message.textContent = 'Processando...';
        try {
            const response = await fetch('../modules/addons/extcontabilidade_notafiscal/actions.php', {
                method: 'POST', credentials: 'same-origin', body: data
            });
            const contentType = (response.headers.get('Content-Type') || '').split(';')[0].trim().toLowerCase();
            if ((action === 'pdf' || action === 'xml') && response.ok && contentType === (action === 'pdf' ? 'application/pdf' : 'application/xml')) {
                const url = URL.createObjectURL(await response.blob());
                const link = document.createElement('a');
                link.href = url;
                link.download = 'NotaFiscal-' + panel.dataset.invoiceId + '.' + action;
                document.body.appendChild(link);
                link.click();
                link.remove();
                setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
                message.textContent = 'Download concluído.';
            } else {
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message || 'Não foi possível concluir a operação.');
                message.textContent = result.message;
                location.reload();
            }
        } catch (error) {
            message.className = 'ext-notafiscal-message alert alert-danger';
            message.textContent = error instanceof SyntaxError ? 'Não foi possível concluir a operação. Atualize a página e tente novamente.' : error.message;
        } finally {
            buttons.forEach(function (item) { item.disabled = false; });
        }
    });
})();
</script>
HTML;

    return $html;
}

/**
 * @param array<string, mixed> $vars
 * @return string
 */
function extcontabilidade_notafiscal_dashboard($vars)
{
    if (!extcontabilidade_notafiscal_is_active() || !extcontabilidade_notafiscal_can_manage()) {
        return '<div class="alert alert-warning">Você não tem permissão para consultar as notas fiscais.</div>';
    }

    $escape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $mode = $_GET['mode'] ?? (($vars['production_mode'] ?? '') === 'on' ? 'production' : 'development');
    $mode = in_array($mode, ['all', 'production', 'development'], true) ? $mode : 'all';
    $errorsOnly = ($_GET['errors'] ?? '') === '1';
    $notes = Capsule::table('tblnotafiscal_ext');
    $logs = Capsule::table('tblnotafiscal_ext_logs as logs')
        ->leftJoin('tblnotafiscal_ext as notes', 'notes.id', '=', 'logs.notafiscal_id');

    if ($mode !== 'all') {
        $notes->where('livemode', $mode === 'production');
        $logs->where('notes.livemode', $mode === 'production');
    }

    $labels = [
        'pending' => 'Pendente', 'queued' => 'Na fila', 'processing' => 'Em processamento',
        'issued' => 'Emitida', 'failed' => 'Falhou', 'indeterminate' => 'Resultado indeterminado',
        'cancel_pending' => 'Cancelamento pendente', 'canceled' => 'Cancelada',
    ];
    $counts = (clone $notes)->select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->all();
    $errorLogs = (clone $logs)->where(function ($query) {
        $query->where('logs.http_status', '>=', 400)->orWhereIn('logs.status', ['failed', 'indeterminate'])
            ->orWhere(function ($query) {
                $query->whereNotNull('logs.message')->where(function ($query) {
                    $query->whereNull('logs.http_status')->orWhere('logs.status', 'issued');
                });
            });
    });
    $totalLogs = (clone $logs)->count();
    $totalErrors = (clone $errorLogs)->count();
    $logs = $errorsOnly ? $errorLogs : $logs;
    $perPage = 25;
    $notesPages = max(1, (int) ceil(array_sum($counts) / $perPage));
    $logsPages = max(1, (int) ceil(($errorsOnly ? $totalErrors : $totalLogs) / $perPage));
    $notesPage = min($notesPages, max(1, (int) filter_var($_GET['notes_page'] ?? 1, FILTER_VALIDATE_INT)));
    $logsPage = min($logsPages, max(1, (int) filter_var($_GET['logs_page'] ?? 1, FILTER_VALIDATE_INT)));
    $noteRows = $notes->orderBy('id', 'desc')->offset(($notesPage - 1) * $perPage)->limit($perPage)->get();
    $logRows = $logs->select('logs.*', 'notes.invoice_id', 'notes.livemode')
        ->orderBy('logs.id', 'desc')->offset(($logsPage - 1) * $perPage)->limit($perPage)->get();
    $moduleLink = $vars['modulelink'] ?? 'addonmodules.php?module=extcontabilidade_notafiscal';
    $params = ['mode' => $mode, 'errors' => $errorsOnly ? '1' : '0', 'notes_page' => $notesPage, 'logs_page' => $logsPage];
    $link = static fn ($changes) => $escape($moduleLink . '&' . http_build_query(array_merge($params, $changes)));
    $html = '<h2>Notas fiscais — EXT Contabilidade</h2><p>Ambiente: ';

    foreach (['all' => 'Todos', 'production' => 'Produção', 'development' => 'Desenvolvimento'] as $value => $label) {
        $html .= '<a class="btn btn-' . ($mode === $value ? 'primary' : 'default') . ' btn-sm" href="'
            . $link(['mode' => $value, 'notes_page' => 1, 'logs_page' => 1]) . '">' . $label . '</a> ';
    }

    $html .= '</p><div class="row">';
    $stats = ['Total de notas' => array_sum($counts)];

    foreach ($labels as $status => $label) {
        $stats[$label] = (int) ($counts[$status] ?? 0);
    }

    $stats['Logs registrados'] = $totalLogs;
    $stats['Logs com erro'] = $totalErrors;

    foreach ($stats as $label => $total) {
        $html .= '<div class="col-sm-3"><div class="panel panel-default"><div class="panel-body">'
            . $escape($label) . '<br><strong>' . number_format($total, 0, ',', '.') . '</strong></div></div></div>';
    }

    $html .= '</div><h3>Notas fiscais</h3><p>Quantidades referentes aos registros locais do ambiente selecionado.</p>'
        . '<div class="table-responsive"><table class="table table-striped table-bordered"><thead><tr>'
        . '<th>ID</th><th>Fatura</th><th>Cliente</th><th>Ambiente</th><th>Status</th><th>Valor da fatura</th><th>ID EXT</th><th>Criada em</th><th>Atualizada em</th>'
        . '</tr></thead><tbody>';

    foreach ($noteRows as $note) {
        $html .= '<tr><td>' . (int) $note->id . '</td><td><a href="invoices.php?action=edit&amp;id=' . (int) $note->invoice_id
            . '">#' . (int) $note->invoice_id . '</a></td><td><a href="clientssummary.php?userid=' . (int) $note->user_id
            . '">#' . (int) $note->user_id . '</a></td><td>' . ($note->livemode ? 'Produção' : 'Desenvolvimento')
            . '</td><td>' . $escape($labels[$note->status] ?? $note->status) . '</td><td>'
            . $escape($note->currency) . ' ' . number_format((float) $note->amount, 2, ',', '.') . '</td><td>'
            . $escape($note->ext_id ?: '—') . '</td><td>' . $escape($note->created_at)
            . '</td><td>' . $escape($note->updated_at) . '</td></tr>';
    }

    if ($noteRows->isEmpty()) {
        $html .= '<tr><td colspan="9">Nenhuma nota fiscal registrada neste ambiente.</td></tr>';
    }

    $html .= '</tbody></table></div><p>Página ' . $notesPage . ' de ' . $notesPages . ' ';

    if ($notesPage > 1) {
        $html .= '<a href="' . $link(['notes_page' => $notesPage - 1]) . '">Anterior</a> ';
    }

    if ($notesPage < $notesPages) {
        $html .= '<a href="' . $link(['notes_page' => $notesPage + 1]) . '">Próxima</a>';
    }

    $html .= '</p><h3>Erros e logs</h3><p><a class="btn btn-default btn-sm" href="'
        . $link(['errors' => $errorsOnly ? '0' : '1', 'logs_page' => 1]) . '">'
        . ($errorsOnly ? 'Mostrar todos os logs' : 'Mostrar somente erros') . '</a></p>'
        . '<div class="table-responsive"><table class="table table-striped table-bordered"><thead><tr>'
        . '<th>Data</th><th>NotaFiscal</th><th>Fatura</th><th>Ambiente</th><th>Ação</th><th>Status</th><th>HTTP</th><th>Request ID</th><th>Mensagem / resposta</th>'
        . '</tr></thead><tbody>';

    foreach ($logRows as $log) {
        $html .= '<tr><td>' . $escape($log->created_at) . '</td><td>#' . (int) $log->notafiscal_id . '</td><td>'
            . ($log->invoice_id ? '<a href="invoices.php?action=edit&amp;id=' . (int) $log->invoice_id . '">#' . (int) $log->invoice_id . '</a>' : '—')
            . '</td><td>' . ($log->livemode === null ? '—' : ($log->livemode ? 'Produção' : 'Desenvolvimento'))
            . '</td><td>' . $escape($log->action) . '</td><td>' . $escape($labels[$log->status] ?? $log->status)
            . '</td><td>' . $escape($log->http_status ?? '—') . '</td><td>' . $escape($log->request_id ?: '—')
            . '</td><td>' . $escape($log->message ?: '—');

        if ($log->response !== null) {
            $html .= '<details><summary>Ver resposta da API</summary><pre style="max-height:300px;overflow:auto;white-space:pre-wrap">'
                . $escape($log->response) . '</pre></details>';
        }

        $html .= '</td></tr>';
    }

    if ($logRows->isEmpty()) {
        $html .= '<tr><td colspan="9">Nenhum log encontrado.</td></tr>';
    }

    $html .= '</tbody></table></div><p>Página ' . $logsPage . ' de ' . $logsPages . ' ';

    if ($logsPage > 1) {
        $html .= '<a href="' . $link(['logs_page' => $logsPage - 1]) . '">Anterior</a> ';
    }

    if ($logsPage < $logsPages) {
        $html .= '<a href="' . $link(['logs_page' => $logsPage + 1]) . '">Próxima</a>';
    }

    return $html . '</p>';
}
