<?php

use Illuminate\Support\Facades\Mail;
use NoriaLabs\Mail\MailClient;
use NoriaLabs\Mail\MailTransport;

beforeEach(function () {
    $key = getenv('NORIA_MAIL_LIVE_KEY');
    $url = getenv('NORIA_MAIL_LIVE_URL') ?: 'http://localhost:3000';

    if ($key === false || $key === '') {
        test()->markTestSkipped('Set NORIA_MAIL_LIVE_KEY to run against a running Noria Mail instance.');
    }

    config()->set('noria-mail.key', $key);
    config()->set('noria-mail.url', $url);
    config()->set('mail.from', ['address' => 'platform@norialabs.com', 'name' => 'Noria Platform']);
});

it('delivers a Laravel mailable through the running service', function () {
    $client = app(MailClient::class);
    $subject = 'Your zana studio sign-in link '.bin2hex(random_bytes(4));

    Mail::html('<p>Your sign-in link: <a href="https://zana.test/x">open</a></p>', function ($message) use ($subject) {
        $message->to('operator@example.com', 'Ops Team')
            ->subject($subject)
            ->replyTo('support@norialabs.com');
        $message->getHeaders()->addTextHeader(MailTransport::TAG_HEADER.'-product', 'zana');
        $message->getHeaders()->addTextHeader(MailTransport::TAG_HEADER.'-kind', 'signin');
    });

    $listed = collect($client->list(['limit' => 25])['data'])->firstWhere('subject', $subject);

    expect($listed)->not->toBeNull()
        ->and($listed['to'])->toBe(['"Ops Team" <operator@example.com>'])
        ->and($listed['reply_to'])->toBe(['support@norialabs.com'])
        ->and($listed['tags'])->toEqualCanonicalizing(['product' => 'zana', 'kind' => 'signin']);

    $id = $listed['id'];

    $status = retry(20, function () use ($client, $id) {
        $message = $client->get($id);
        if ($message['status'] !== 'sent') {
            throw new RuntimeException('still '.$message['status']);
        }

        return $message['status'];
    }, 250);

    expect($status)->toBe('sent');

    $events = collect($client->events($id)['data'])->pluck('type')->sort()->values()->all();
    expect($events)->toContain('queued', 'sent', 'delivered');
});

it('replays an idempotent send instead of sending twice', function () {
    $client = app(MailClient::class);
    $key = 'signin-'.bin2hex(random_bytes(6));
    $subject = 'Idempotent sign-in '.bin2hex(random_bytes(4));

    $send = function () use ($subject, $key) {
        Mail::html('<p>link</p>', function ($message) use ($subject, $key) {
            $message->to('operator@example.com')->subject($subject);
            $message->getHeaders()->addTextHeader(MailTransport::IDEMPOTENCY_HEADER, $key);
        });
    };

    $send();
    $send();

    $matching = collect($client->list(['limit' => 50])['data'])->where('subject', $subject);

    expect($matching)->toHaveCount(1);
});

it('carries attachments through to the service', function () {
    $client = app(MailClient::class);
    $subject = 'Invoice '.bin2hex(random_bytes(4));

    Mail::html('<p>Invoice attached.</p>', function ($message) use ($subject) {
        $message->to('billing@example.com')->subject($subject);
        $message->attachData('%PDF-1.4 fake invoice bytes', 'invoice-0042.pdf', ['mime' => 'application/pdf']);
    });

    $listed = collect($client->list(['limit' => 25])['data'])->firstWhere('subject', $subject);
    expect($listed)->not->toBeNull();

    $id = $listed['id'];
    retry(20, function () use ($client, $id) {
        if ($client->get($id)['status'] !== 'sent') {
            throw new RuntimeException('not sent yet');
        }
    }, 250);

    expect($client->get($id)['status'])->toBe('sent');
});

it('swallows a suppressed recipient rather than failing the job', function () {
    $client = app(MailClient::class);
    $address = 'bounced-'.bin2hex(random_bytes(4)).'@example.com';
    $client->suppress($address, 'bounce', 'hard bounce');

    expect($client->isSuppressed($address))->toBeTrue();

    Mail::html('<p>Nobody home</p>', function ($message) use ($address) {
        $message->to($address)->subject('Should be swallowed');
    });

    $sent = collect($client->list(['limit' => 50])['data'])->firstWhere('subject', 'Should be swallowed');
    expect($sent)->toBeNull();

    $client->unsuppress($address);
    expect($client->isSuppressed($address))->toBeFalse();
});
