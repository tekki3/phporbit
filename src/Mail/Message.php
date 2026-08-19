<?php

declare(strict_types=1);

namespace PhpOrbit\Mail;

use InvalidArgumentException;

/**
 * An email, built up immutably.
 *
 *     Message::to('ada@example.test')
 *         ->subject('Welcome')
 *         ->text('Thanks for signing up.');
 *
 * Every `with`-style call returns a copy, so a half-built message is safe to
 * reuse as a template for several recipients — the shape that otherwise invites
 * one request's data into another's mail.
 */
final class Message
{
    /** @var list<Address> */
    public readonly array $to;

    /** @var list<Address> */
    public readonly array $cc;

    /** @var list<Address> */
    public readonly array $bcc;

    /** @var list<Attachment> */
    public readonly array $attachments;

    /** @var array<string, string> */
    public readonly array $headers;

    /**
     * @param list<Address>    $to
     * @param list<Address>    $cc
     * @param list<Address>    $bcc
     * @param list<Attachment> $attachments
     * @param array<string, string> $headers
     */
    private function __construct(
        array $to = [],
        public readonly ?Address $from = null,
        public readonly ?Address $replyTo = null,
        array $cc = [],
        array $bcc = [],
        public readonly string $subjectLine = '',
        public readonly ?string $textBody = null,
        public readonly ?string $htmlBody = null,
        array $attachments = [],
        array $headers = [],
    ) {
        $this->to = $to;
        $this->cc = $cc;
        $this->bcc = $bcc;
        $this->attachments = $attachments;
        $this->headers = $headers;
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * @param Address|string $address `ada@example.test` or `Ada <ada@example.test>`
     */
    public static function to(Address|string $address): self
    {
        return self::create()->addTo($address);
    }

    public function addTo(Address|string $address): self
    {
        return $this->with(to: [...$this->to, self::address($address)]);
    }

    public function addCc(Address|string $address): self
    {
        return $this->with(cc: [...$this->cc, self::address($address)]);
    }

    /**
     * Blind copies are stripped from the headers but kept in the envelope, so
     * the recipients never see one another.
     */
    public function addBcc(Address|string $address): self
    {
        return $this->with(bcc: [...$this->bcc, self::address($address)]);
    }

    public function from(Address|string $address): self
    {
        return $this->with(from: self::address($address));
    }

    public function replyTo(Address|string $address): self
    {
        return $this->with(replyTo: self::address($address));
    }

    public function subject(string $subject): self
    {
        Mime::assertNoInjection($subject);

        return $this->with(subjectLine: $subject);
    }

    public function text(string $body): self
    {
        return $this->with(textBody: $body);
    }

    /**
     * Sets the HTML body.
     *
     * Pass a plain-text alternative too where you can: some clients refuse
     * HTML, and a message with only an HTML part scores worse with spam
     * filters than one offering both.
     */
    public function html(string $body, ?string $alternativeText = null): self
    {
        return $this->with(
            htmlBody: $body,
            textBody: $alternativeText ?? $this->textBody,
        );
    }

    public function attach(Attachment $attachment): self
    {
        return $this->with(attachments: [...$this->attachments, $attachment]);
    }

    /**
     * Adds a custom header. `To`, `From` and friends are set by the builder.
     */
    public function header(string $name, string $value): self
    {
        $reserved = ['to', 'from', 'cc', 'bcc', 'subject', 'reply-to', 'content-type', 'mime-version'];

        if (in_array(strtolower($name), $reserved, true)) {
            throw new InvalidArgumentException(sprintf(
                'Set "%s" through the dedicated method rather than as a raw header.',
                $name,
            ));
        }

        return $this->with(headers: [...$this->headers, $name => $value]);
    }

    /**
     * Every address the message must actually be delivered to.
     *
     * @return list<Address>
     */
    public function envelopeRecipients(): array
    {
        return [...$this->to, ...$this->cc, ...$this->bcc];
    }

    /**
     * Fails now, with a readable reason, rather than mid-conversation with a
     * remote server.
     */
    public function assertSendable(): void
    {
        if ($this->from === null) {
            throw new InvalidArgumentException(
                'The message has no sender. Set one with from(), or give the mailer a default.',
            );
        }

        if ($this->envelopeRecipients() === []) {
            throw new InvalidArgumentException('The message has no recipients.');
        }

        if ($this->textBody === null && $this->htmlBody === null && $this->attachments === []) {
            throw new InvalidArgumentException('The message has no body.');
        }
    }

    private static function address(Address|string $address): Address
    {
        return $address instanceof Address ? $address : Address::parse($address);
    }

    /**
     * @param list<Address>|null    $to
     * @param list<Address>|null    $cc
     * @param list<Address>|null    $bcc
     * @param list<Attachment>|null $attachments
     * @param array<string, string>|null $headers
     */
    private function with(
        ?array $to = null,
        ?Address $from = null,
        ?Address $replyTo = null,
        ?array $cc = null,
        ?array $bcc = null,
        ?string $subjectLine = null,
        ?string $textBody = null,
        ?string $htmlBody = null,
        ?array $attachments = null,
        ?array $headers = null,
    ): self {
        return new self(
            $to ?? $this->to,
            $from ?? $this->from,
            $replyTo ?? $this->replyTo,
            $cc ?? $this->cc,
            $bcc ?? $this->bcc,
            $subjectLine ?? $this->subjectLine,
            $textBody ?? $this->textBody,
            $htmlBody ?? $this->htmlBody,
            $attachments ?? $this->attachments,
            $headers ?? $this->headers,
        );
    }
}
