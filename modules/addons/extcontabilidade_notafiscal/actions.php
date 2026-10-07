<?php

define('ADMINAREA', true);
require_once dirname(__DIR__, 3) . '/init.php';
require_once __DIR__ . '/extcontabilidade_notafiscal.php';
require_once __DIR__ . '/lib/ExtAdmin.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Use uma requisição POST.']);
    exit;
}

if (!extcontabilidade_notafiscal_can_manage()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso não autorizado.']);
    exit;
}

check_token('WHMCS.admin.default');

try {
    $invoiceId = filter_var($_POST['invoice_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $noteId = filter_var($_POST['note_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $action = $_POST['action'] ?? '';
    $description = $_POST['description'] ?? null;

    if (!$invoiceId || $noteId === false || !is_string($action) || ($description !== null && !is_string($description))) {
        throw new DomainException('Dados da requisição inválidos.');
    }

    $result = extcontabilidade_notafiscal_invoice_action($action, $invoiceId, $noteId, $description);

    if (isset($result['content'])) {
        header('Content-Type: ' . $result['content_type']);
        header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
        header('Content-Length: ' . strlen($result['content']));
        header('X-Content-Type-Options: nosniff');
        echo $result['content'];
    } else {
        echo json_encode(['success' => true, 'message' => $result['message']], JSON_THROW_ON_ERROR);
    }
} catch (DomainException | InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    logActivity('EXT NotaFiscal: invoice action failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Não foi possível concluir a operação. Consulte o log de atividades.']);
}
