<?php

namespace NoriaLabs\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use NoriaLabs\Mail\Exceptions\MailException;

class MailClient
{
    public function __construct(
        protected readonly Factory $http,
        protected readonly string $apiKey,
        protected readonly string $baseUrl = 'http://localhost:4800',
        protected readonly int $timeout = 15,
        protected readonly int $retries = 2,
    ) {
        if ($apiKey === '') {
            throw new MailException('validation_error', 0, 'A Noria Mail API key is required');
        }
    }

    /**
     * @param  array<string, mixed>  $email
     * @return array<string, mixed>
     */
    public function send(array $email, ?string $idempotencyKey = null): array
    {
        return $this->request(
            'POST',
            '/v1/emails',
            $email,
            $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $emails
     * @return array<string, mixed>
     */
    public function sendBatch(array $emails): array
    {
        return $this->request('POST', '/v1/emails/batch', ['emails' => $emails]);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->request('GET', '/v1/emails/'.rawurlencode($id));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(array $filters = []): array
    {
        return $this->request('GET', '/v1/emails'.$this->query($filters));
    }

    /**
     * @return array<string, mixed>
     */
    public function events(string $id): array
    {
        return $this->request('GET', '/v1/emails/'.rawurlencode($id).'/events');
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(string $id): array
    {
        return $this->request('POST', '/v1/emails/'.rawurlencode($id).'/cancel');
    }

    /**
     * @return array<string, mixed>
     */
    public function requeue(string $id): array
    {
        return $this->request('POST', '/v1/emails/'.rawurlencode($id).'/requeue');
    }

    /**
     * @return array<string, mixed>
     */
    public function addDomain(string $name, bool $customReturnPath = true): array
    {
        return $this->request('POST', '/v1/domains', ['name' => $name, 'custom_return_path' => $customReturnPath]);
    }

    /**
     * @return array<string, mixed>
     */
    public function domains(): array
    {
        return $this->request('GET', '/v1/domains');
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyDomain(string $id): array
    {
        return $this->request('POST', '/v1/domains/'.rawurlencode($id).'/verify');
    }

    public function removeDomain(string $id): void
    {
        $this->request('DELETE', '/v1/domains/'.rawurlencode($id));
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    public function upsertTemplate(array $template): array
    {
        return $this->request('POST', '/v1/templates', $template);
    }

    /**
     * @return array<string, mixed>
     */
    public function templates(): array
    {
        return $this->request('GET', '/v1/templates');
    }

    public function removeTemplate(string $slug): void
    {
        $this->request('DELETE', '/v1/templates/'.rawurlencode($slug));
    }

    /**
     * @return array<string, mixed>
     */
    public function suppress(string $email, string $reason = 'manual', ?string $detail = null): array
    {
        return $this->request('POST', '/v1/suppressions', array_filter([
            'email' => $email,
            'reason' => $reason,
            'detail' => $detail,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function suppressions(?string $email = null): array
    {
        return $this->request('GET', '/v1/suppressions'.$this->query(['email' => $email]));
    }

    public function unsuppress(string $email): void
    {
        $this->request('DELETE', '/v1/suppressions/'.rawurlencode($email));
    }

    public function isSuppressed(string $email): bool
    {
        $result = $this->suppressions($email);

        return is_array($result['data'] ?? null) && $result['data'] !== [];
    }

    /**
     * @param  array<int, string>  $eventTypes
     * @return array<string, mixed>
     */
    public function addWebhookEndpoint(string $url, array $eventTypes = [], ?string $description = null): array
    {
        return $this->request('POST', '/v1/webhook-endpoints', array_filter([
            'url' => $url,
            'event_types' => $eventTypes,
            'description' => $description,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function webhookEndpoints(): array
    {
        return $this->request('GET', '/v1/webhook-endpoints');
    }

    public function removeWebhookEndpoint(string $id): void
    {
        $this->request('DELETE', '/v1/webhook-endpoints/'.rawurlencode($id));
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $attempt = 0;
        $last = null;

        while ($attempt <= $this->retries) {
            if ($attempt > 0) {
                usleep(min(2_000_000, 200_000 * (2 ** ($attempt - 1))));
            }

            $attempt++;

            try {
                $response = $this->pending($headers, $body !== null)
                    ->send($method, $this->url($path), $body === null ? [] : ['json' => $body]);
            } catch (ConnectionException $exception) {
                $last = MailException::network($exception->getMessage(), $exception);

                continue;
            }

            if ($response->status() === 204) {
                return [];
            }

            /** @var array<string, mixed> $decoded */
            $decoded = $response->json() ?? [];

            if ($response->successful()) {
                return $decoded;
            }

            $last = MailException::fromResponse($response->status(), $decoded);

            if (! $last->isRetryable()) {
                throw $last;
            }
        }

        throw $last ?? MailException::network('Request failed');
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function pending(array $headers, bool $hasBody): PendingRequest
    {
        $request = $this->http
            ->withToken($this->apiKey)
            ->acceptJson()
            ->withHeaders($headers)
            ->timeout($this->timeout);

        return $hasBody ? $request->asJson() : $request;
    }

    protected function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected function query(array $parameters): string
    {
        $filtered = array_filter($parameters, static fn (mixed $value): bool => $value !== null && $value !== '');

        return $filtered === [] ? '' : '?'.http_build_query($filtered);
    }
}
