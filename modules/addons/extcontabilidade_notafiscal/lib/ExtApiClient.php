<?php

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

/**
 * @phpstan-type CustomerAddress array{
 *     street: string, number: string, district: string, city: string,
 *     state: string, postal_code: string, country: string
 * }
 * @phpstan-type Customer array{
 *     type?: 'br'|'foreign', document?: string, name?: string, tax_id?: string,
 *     tax_id_absence_reason?: 'exempt'|'not_required', address?: CustomerAddress
 * }
 * @phpstan-type ForeignAmount array{currency: string, amount_in_cents: int}
 * @phpstan-type CreateNotaFiscalRequest array{
 *     amount_in_cents: int, description: string, customer?: Customer,
 *     foreign_amount?: ForeignAmount, reference?: string, competence?: string,
 *     cnae?: string, service_code?: string
 * }
 * @phpstan-type NotaFiscalCustomerAddress array{
 *     street?: string, number?: string, district?: string, city?: string,
 *     state?: string, postal_code?: string, country?: string
 * }
 * @phpstan-type NotaFiscalCustomer array{
 *     type?: 'br'|'foreign', document?: string, name?: string, tax_id?: string,
 *     tax_id_absence_reason?: 'exempt'|'not_required', address?: NotaFiscalCustomerAddress
 * }
 * @phpstan-type NotaFiscalError array{code: string, message: string}
 * @phpstan-type NotaFiscalCancellation array{
 *     status: 'pending'|'succeeded'|'failed', requested_at: string,
 *     reason?: 'issuance_error'|'service_not_provided'|'other', error?: NotaFiscalError
 * }
 * @phpstan-type NotaFiscal array{
 *     object: 'nfse', id: string, livemode: bool, status: string,
 *     amount_in_cents: int, failed_attempts: int|float, description?: string,
 *     competence?: string, customer?: NotaFiscalCustomer, foreign_amount?: ForeignAmount,
 *     reference?: string, service_code?: string, cnae?: string, access_key?: string,
 *     created_at?: string, issued_at?: string, cancelable_until?: string,
 *     cancellation?: NotaFiscalCancellation, failed_at?: string, error?: NotaFiscalError,
 *     links?: array{pdf: string, xml: string}
 * }
 * @phpstan-type NotaFiscalList array{object: 'list', has_more: bool, data: list<NotaFiscal>}
 * @phpstan-type Activity array{
 *     cnae: string, service_code: string, description: string, is_main: bool, is_default: bool
 * }
 * @phpstan-type ActivityList array{object: 'list', has_more: bool, data: list<Activity>}
 * @phpstan-type ApiErrorResponse array{
 *     error: array{
 *         type: 'invalid_request'|'authentication_error'|'permission_error'|'rate_limit_error'|'api_error',
 *         code: string, message: string, retryable: bool, request_id: string,
 *         param?: string, livemode?: bool, provider_code?: string,
 *         errors?: list<array{code: string, message: string}>
 *     }
 * }
 */
class ExtApiClient
{
    /** @var Client */
    private $client;

    /** @var ResponseInterface|null */
    private $lastResponse = null;

    /**
     * @param array{production_mode?: string, production_api_key?: string, development_api_key?: string} $config
     * @return self
     */
    public static function fromConfig($config)
    {
        $productionMode = ($config['production_mode'] ?? '') === 'on';
        $keyName = $productionMode ? 'production_api_key' : 'development_api_key';
        $apiKey = trim((string) ($config[$keyName] ?? ''));
        $prefix = $productionMode ? 'ext_sk_live_' : 'ext_sk_test_';

        if (strpos($apiKey, $prefix) !== 0) {
            throw new InvalidArgumentException('Informe uma chave de API válida para o modo selecionado.');
        }

        return new self($apiKey);
    }

    /** @param string $apiKey */
    public function __construct($apiKey)
    {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            throw new InvalidArgumentException('The EXT Contabilidade API key is required.');
        }

        $this->client = new Client([
            'base_uri' => 'https://api.extcontabilidade.com.br/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
            ],
            'connect_timeout' => 10,
            'timeout' => 30,
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
    }

    /**
     * @param string $method
     * @param string $path
     * @param array<string, mixed> $options
     * @return ResponseInterface
     */
    public function request($method, $path, $options = [])
    {
        $this->lastResponse = null;

        return $this->lastResponse = $this->client->request($method, $path, $options);
    }

    /**
     * @param CreateNotaFiscalRequest $request
     * @param string|null $idempotencyKey
     * @return NotaFiscal|ApiErrorResponse
     */
    public function createNotaFiscal($request, $idempotencyKey = null)
    {
        $options = ['json' => $request];

        if ($idempotencyKey !== null) {
            $options['headers']['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->requestJson('POST', 'nfse', $options);
    }

    /**
     * @param string $reference
     * @return NotaFiscalList|ApiErrorResponse
     */
    public function findNotaFiscalByReference($reference)
    {
        return $this->requestJson('GET', 'nfse', ['query' => ['reference' => $reference]]);
    }

    /**
     * @param string $id
     * @return NotaFiscal|ApiErrorResponse
     */
    public function getNotaFiscal($id)
    {
        return $this->requestJson('GET', 'nfse/' . rawurlencode($id));
    }

    /**
     * @param string $id
     * @param 'issuance_error'|'service_not_provided'|'other' $reason
     * @param string|null $description
     * @return NotaFiscal|ApiErrorResponse
     */
    public function cancelNotaFiscal($id, $reason = 'other', $description = null)
    {
        $request = ['reason' => $reason];

        if ($description !== null) {
            $request['description'] = $description;
        }

        return $this->requestJson('POST', 'nfse/' . rawurlencode($id) . '/cancel', ['json' => $request]);
    }

    /**
     * @param string $id
     * @return string|ApiErrorResponse PDF bytes or the API error envelope.
     */
    public function downloadNotaFiscalPdf($id)
    {
        return $this->download('nfse/' . rawurlencode($id) . '/pdf', 'application/pdf');
    }

    /**
     * @param string $id
     * @return string|ApiErrorResponse XML contents or the API error envelope.
     */
    public function downloadNotaFiscalXml($id)
    {
        return $this->download('nfse/' . rawurlencode($id) . '/xml', 'application/xml');
    }

    /** @return ActivityList|ApiErrorResponse */
    public function listActivities()
    {
        return $this->requestJson('GET', 'activities');
    }

    /** @return ResponseInterface|null */
    public function getLastResponse()
    {
        return $this->lastResponse;
    }

    /**
     * @param string $method
     * @param string $path
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function requestJson($method, $path, $options = [])
    {
        $body = (string) $this->request($method, $path, $options)->getBody();
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($response) || substr(ltrim($body), 0, 1) !== '{') {
            throw new UnexpectedValueException('The API returned an invalid JSON object.');
        }

        return $response;
    }

    /**
     * @param string $path
     * @param string $contentType
     * @return string|ApiErrorResponse
     */
    private function download($path, $contentType)
    {
        $response = $this->request('GET', $path, ['headers' => ['Accept' => $contentType]]);
        $body = (string) $response->getBody();

        if ($response->getStatusCode() >= 400) {
            $error = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($error) || !isset($error['error'])) {
                throw new UnexpectedValueException('The API returned an invalid error response.');
            }

            return $error;
        }

        $actualType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));

        if ($response->getStatusCode() !== 200 || $actualType !== $contentType || $body === '') {
            throw new UnexpectedValueException('The API returned an invalid document response.');
        }

        return $body;
    }
}
