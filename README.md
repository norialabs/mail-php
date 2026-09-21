# Noria Mail for Laravel

Send transactional email through [Noria Mail](https://github.com/norialabs/mail) instead of
wiring SES into every product. Registers a Laravel mail transport, so `Mail::send()` keeps
working exactly as it does today.

```bash
composer require norialabs/mail
```

This repository is a read-only split of `sdks/php` in
[`norialabs/mail`](https://github.com/norialabs/mail). Open pull requests there; the mirror
is force-pushed on every release and anything committed here is lost.

```env
MAIL_MAILER=noria
NORIA_MAIL_URL=https://mail.noria.internal
NORIA_MAIL_KEY=nm_live_…
```

```php
// config/mail.php
'mailers' => [
    'noria' => ['transport' => 'noria'],
],
```

That is the whole integration. Every `Mail::send()`, `Mail::to()->queue()`, notification and
mailable in the application now goes through the service and gets queueing, retries,
suppression, delivery events and per-project isolation without touching a call site.

## Beyond the transport

The client is bound in the container and available as a facade.

```php
use NoriaLabs\Mail\Facades\NoriaMail;

NoriaMail::addDomain('norialabs.com');          // returns the DNS records to publish
NoriaMail::suppress('bounced@example.com');
NoriaMail::isSuppressed('bounced@example.com');
NoriaMail::events($messageId);
NoriaMail::requeue($messageId);
```

## Per-message options

Set headers on the message; the transport strips them and maps them onto the API.

```php
use NoriaLabs\Mail\MailTransport;

Mail::html($body, function ($message) {
    $message->to($user->email)->subject('Your sign-in link');

    $headers = $message->getHeaders();
    $headers->addTextHeader(MailTransport::TAG_HEADER.'-product', 'zana');
    $headers->addTextHeader(MailTransport::IDEMPOTENCY_HEADER, "signin-{$token->id}");
    $headers->addTextHeader(MailTransport::SCHEDULE_HEADER, now()->addHour()->toIso8601String());
});
```

| Header | Effect |
| --- | --- |
| `X-Noria-Tag-<name>` | Becomes a tag on the message, queryable and forwarded to SES |
| `X-Noria-Idempotency-Key` | Re-sending with the same key returns the original message |
| `X-Noria-Template` | Render a stored template instead of the body |
| `X-Noria-Variables` | JSON variables for that template |
| `X-Noria-Scheduled-At` | ISO 8601 time to send at |

Any other custom header is passed through to the message itself.

## Suppressed recipients

By default a send to a suppressed address is a no-op rather than an exception, so one dead
address cannot fail a queued job or a batch notification. Set `fail_on_suppressed` on the
mailer to raise `MailException` instead.

## Webhooks

```php
use NoriaLabs\Mail\WebhookVerifier;

Route::post('/webhooks/mail', function (Request $request, WebhookVerifier $verifier) {
    $event = $verifier->verify($request->getContent(), $request->header('Noria-Signature', ''));

    // $event['type'] is delivered, bounced, complained, opened, clicked, failed, …
});
```

Set `NORIA_MAIL_WEBHOOK_SECRET` to the secret shown once when the endpoint was created.

## Tests

```bash
composer quality      # pint, phpstan level max, pest
```

`tests/LiveTest.php` runs against a real instance and is skipped unless you point it at one:

```bash
NORIA_MAIL_LIVE_KEY=nm_live_… vendor/bin/pest tests/LiveTest.php
```
