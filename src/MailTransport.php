<?php

namespace NoriaLabs\Mail;

use NoriaLabs\Mail\Exceptions\MailException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

class MailTransport extends AbstractTransport
{
    public const TAG_HEADER = 'X-Noria-Tag';

    public const IDEMPOTENCY_HEADER = 'X-Noria-Idempotency-Key';

    public const TEMPLATE_HEADER = 'X-Noria-Template';

    public const VARIABLES_HEADER = 'X-Noria-Variables';

    public const SCHEDULE_HEADER = 'X-Noria-Scheduled-At';

    /**
     * @var array<int, string>
     */
    protected const RESERVED_HEADERS = [
        'from', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'sender',
        'mime-version', 'content-type', 'content-transfer-encoding',
        'date', 'message-id', 'return-path',
    ];

    public function __construct(
        protected readonly MailClient $client,
        protected readonly bool $failOnSuppressed = false,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();

        if (! $original instanceof Message) {
            throw new MailException('validation_error', 0, 'Noria Mail cannot send a raw MIME message');
        }

        $email = MessageConverter::toEmail($original);
        $payload = $this->payload($email);
        $idempotencyKey = $this->headerValue($email, self::IDEMPOTENCY_HEADER);

        try {
            $result = $this->client->send($payload, $idempotencyKey);
        } catch (MailException $exception) {
            if ($exception->isSuppressed() && ! $this->failOnSuppressed) {
                return;
            }

            throw $exception;
        }

        if (is_string($result['id'] ?? null)) {
            $message->setMessageId($result['id']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Email $email): array
    {
        $payload = array_filter([
            'from' => $this->address($email->getFrom()),
            'to' => $this->addresses($email->getTo()),
            'cc' => $this->addresses($email->getCc()),
            'bcc' => $this->addresses($email->getBcc()),
            'reply_to' => $this->addresses($email->getReplyTo()),
            'subject' => $email->getSubject(),
            'html' => $this->body($email->getHtmlBody()),
            'text' => $this->body($email->getTextBody()),
            'attachments' => $this->attachments($email),
            'headers' => $this->headers($email),
            'tags' => $this->tags($email),
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');

        $template = $this->headerValue($email, self::TEMPLATE_HEADER);

        if ($template !== null) {
            $payload['template'] = $template;
            $payload['variables'] = $this->variables($email);
        }

        $scheduledAt = $this->headerValue($email, self::SCHEDULE_HEADER);

        if ($scheduledAt !== null) {
            $payload['scheduled_at'] = $scheduledAt;
        }

        return $payload;
    }

    /**
     * @param  array<array-key, Address>  $addresses
     * @return array<int, string>
     */
    protected function addresses(array $addresses): array
    {
        return array_values(array_map(
            static fn (Address $address): string => $address->getName() === ''
                ? $address->getAddress()
                : $address->toString(),
            $addresses,
        ));
    }

    /**
     * @param  array<array-key, Address>  $addresses
     */
    protected function address(array $addresses): ?string
    {
        return $this->addresses($addresses)[0] ?? null;
    }

    protected function body(mixed $body): ?string
    {
        if (is_string($body)) {
            return $body;
        }

        if (is_resource($body)) {
            $contents = stream_get_contents($body);

            return $contents === false ? null : $contents;
        }

        return null;
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function attachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $attachments[] = array_filter([
                'filename' => $this->filename($attachment),
                'content' => base64_encode($attachment->getBody()),
                'content_type' => $attachment->getMediaType().'/'.$attachment->getMediaSubtype(),
                'content_id' => $attachment->hasContentId() ? $attachment->getContentId() : null,
                'disposition' => $attachment->getDisposition() === 'inline' ? 'inline' : 'attachment',
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $attachments;
    }

    protected function filename(DataPart $attachment): string
    {
        $name = $attachment->getFilename();

        return is_string($name) && $name !== '' ? $name : 'attachment';
    }

    /**
     * @return array<string, string>
     */
    protected function headers(Email $email): array
    {
        $headers = [];

        foreach ($this->allHeaders($email) as $header) {
            $name = strtolower($header->getName());

            if (in_array($name, self::RESERVED_HEADERS, true) || str_starts_with($name, 'x-noria-')) {
                continue;
            }

            $headers[$header->getName()] = $header->getBodyAsString();
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    protected function tags(Email $email): array
    {
        $tags = [];
        $prefix = strtolower(self::TAG_HEADER).'-';

        foreach ($this->allHeaders($email) as $header) {
            $name = strtolower($header->getName());

            if (str_starts_with($name, $prefix)) {
                $tags[substr($name, strlen($prefix))] = $header->getBodyAsString();
            }
        }

        return $tags;
    }

    /**
     * @return array<string, mixed>
     */
    protected function variables(Email $email): array
    {
        $raw = $this->headerValue($email, self::VARIABLES_HEADER);

        if ($raw === null) {
            return [];
        }

        $decoded = json_decode($raw, true);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<int, HeaderInterface>
     */
    protected function allHeaders(Email $email): array
    {
        $headers = [];

        foreach ($email->getHeaders()->all() as $header) {
            if ($header instanceof HeaderInterface) {
                $headers[] = $header;
            }
        }

        return $headers;
    }

    protected function headerValue(Message $message, string $name): ?string
    {
        $header = $message->getHeaders()->get($name);

        return $header === null ? null : $header->getBodyAsString();
    }

    public function __toString(): string
    {
        return 'noria';
    }
}
