<?php

namespace NoriaLabs\Mail\Facades;

use Illuminate\Support\Facades\Facade;
use NoriaLabs\Mail\MailClient;

/**
 * @method static array<string, mixed> send(array<string, mixed> $email, ?string $idempotencyKey = null)
 * @method static array<string, mixed> sendBatch(array<int, array<string, mixed>> $emails)
 * @method static array<string, mixed> get(string $id)
 * @method static array<string, mixed> list(array<string, mixed> $filters = [])
 * @method static array<string, mixed> events(string $id)
 * @method static array<string, mixed> cancel(string $id)
 * @method static array<string, mixed> requeue(string $id)
 * @method static array<string, mixed> addDomain(string $name, bool $customReturnPath = true)
 * @method static array<string, mixed> domains()
 * @method static array<string, mixed> verifyDomain(string $id)
 * @method static void removeDomain(string $id)
 * @method static array<string, mixed> upsertTemplate(array<string, mixed> $template)
 * @method static array<string, mixed> templates()
 * @method static void removeTemplate(string $slug)
 * @method static array<string, mixed> suppress(string $email, string $reason = 'manual', ?string $detail = null)
 * @method static array<string, mixed> suppressions(?string $email = null)
 * @method static void unsuppress(string $email)
 * @method static bool isSuppressed(string $email)
 * @method static array<string, mixed> addWebhookEndpoint(string $url, array<int, string> $eventTypes = [], ?string $description = null)
 * @method static array<string, mixed> webhookEndpoints()
 * @method static void removeWebhookEndpoint(string $id)
 *
 * @see MailClient
 */
class NoriaMail extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MailClient::class;
    }
}
