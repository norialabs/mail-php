<?php

namespace NoriaLabs\Mail;

use NoriaLabs\Mail\Exceptions\MailException;

class WebhookVerifier
{
    public function __construct(
        protected readonly string $secret,
        protected readonly int $toleranceSeconds = 300,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function verify(string $payload, string $signature, ?int $now = null): array
    {
        [$timestamp, $provided] = $this->parse($signature);

        if (abs(($now ?? time()) - $timestamp) > $this->toleranceSeconds) {
            throw new MailException('validation_error', 400, 'Signature timestamp is outside the tolerance window');
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        if (! hash_equals($expected, $provided)) {
            throw new MailException('unauthorized', 401, 'Invalid webhook signature');
        }

        $event = json_decode($payload, true);

        if (! is_array($event)) {
            throw new MailException('validation_error', 400, 'Webhook payload is not a JSON object');
        }

        /** @var array<string, mixed> $event */
        return $event;
    }

    /**
     * @return array{int, string}
     */
    protected function parse(string $signature): array
    {
        $parts = [];

        foreach (explode(',', $signature) as $pair) {
            $split = explode('=', trim($pair), 2);
            if (count($split) === 2) {
                $parts[$split[0]] = $split[1];
            }
        }

        if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t'])) {
            throw new MailException('validation_error', 400, 'Malformed Noria-Signature header');
        }

        return [(int) $parts['t'], $parts['v1']];
    }
}
