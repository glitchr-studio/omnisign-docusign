<?php

namespace Omnisign\Docusign\Tests;

use Omnisign\Docusign\DocusignGatewayFactory;
use Omnisign\Exception\InvalidConfigException;
use Omnisign\Exception\InvalidKeyException;
use Omnisign\Exception\InvalidNotificationException;
use Omnisign\Exception\ProviderException;
use Omnisign\GatewayInterface;
use Omnisign\Model\Document;
use Omnisign\Model\Envelope;
use Omnisign\Model\Event;
use Omnisign\Model\Field;
use Omnisign\Model\FieldType;
use Omnisign\Model\File;
use Omnisign\Model\Level;
use Omnisign\Model\Signer;
use Omnisign\Model\SignerStatus;
use Omnisign\Model\Status;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Answers written from DocuSign's eSignature REST API v2.1 OpenAPI
 * (github.com/docusign/OpenAPI-Specifications) and its guides on the JWT
 * grant and Connect's HMAC: no developer account was used.
 */
final class DocusignGatewayTest extends TestCase
{
    private const ENVELOPE = '7c1d2e3f-4a5b-4c6d-8e7f-9a0b1c2d3e4f';
    private const ACCOUNT = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

    private static string $privateKey = '';
    private static string $publicKey = '';

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $calls = [];

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, self::$privateKey);
        self::$publicKey = (string) openssl_pkey_get_details($key)['key'];
    }

    /** @param list<string|MockResponse> $answers */
    private function gateway(array $answers, array $options = []): GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$answers): MockResponse {
            $this->calls[] = [$method, $url, $options];
            if (str_ends_with((string) parse_url($url, \PHP_URL_PATH), '/oauth/token')) {
                return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/token.json'));
            }
            $answer = array_shift($answers) ?? throw new \LogicException('No answer left for '.$method.' '.$url);
            if ($answer instanceof MockResponse) {
                return $answer;
            }
            [$file, $status] = explode(':', $answer.':200');

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$file.'.json'), ['http_code' => (int) $status]);
        });

        return (new DocusignGatewayFactory($http))->create($options + ['integration_key' => 'ik-1234', 'user_id' => '4f0a1b2c-3d4e-4f5a-8b6c-7d8e9f0a1b2c', 'private_key' => self::$privateKey, 'account_id' => self::ACCOUNT]);
    }

    private static function lease(): Envelope
    {
        return new Envelope(
            'Bail - Maison Érable',
            [new Document('lease', new File('%PDF-1.7 lease', 'bail.pdf'))],
            [new Signer('landlord', 'Marco Meyer', 'marco@meyers.example', order: 1), new Signer('tenant', 'Camille Érable', 'camille@erable.example', order: 2)],
            [new Field('landlord', 'lease', page: 3, x: 77, y: 581), new Field('tenant', 'lease', FieldType::DATE, 3, 330, 620), new Field('tenant', 'lease', anchor: '/sign-tenant/')],
            ordered: true,
            embedded: true,
            redirectUrl: 'https://meyers.example/leases/42/signed',
        );
    }

    public function testTheJwtGrantIsSignedWithTheIntegrationsKey(): void
    {
        $this->gateway(['envelope-created:201'])->create(self::lease());

        [$method, $url, $options] = $this->calls[0];
        self::assertSame(['POST', 'https://account-d.docusign.com/oauth/token'], [$method, $url]);
        parse_str((string) $options['body'], $form);
        self::assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);
        [$header, $claims, $signature] = explode('.', $form['assertion']);
        $decode = static fn (string $part) => base64_decode(strtr($part, '-_', '+/'));
        self::assertSame(['alg' => 'RS256', 'typ' => 'JWT'], json_decode($decode($header), true));
        $claims = json_decode($decode($claims), true);
        self::assertSame(['ik-1234', '4f0a1b2c-3d4e-4f5a-8b6c-7d8e9f0a1b2c', 'account-d.docusign.com', 'signature impersonation', 3600], [$claims['iss'], $claims['sub'], $claims['aud'], $claims['scope'], $claims['exp'] - $claims['iat']]);
        self::assertSame(1, openssl_verify($header.'.'.explode('.', $form['assertion'])[1], $decode($signature), self::$publicKey, \OPENSSL_ALGO_SHA256));
    }

    public function testAnEnvelopeIsCreatedAsADraftWithItsTabsThenSent(): void
    {
        $gateway = $this->gateway(['envelope-created:201', 'envelope-update', 'envelope-sent']);
        $created = $gateway->create(self::lease());

        self::assertSame([self::ENVELOPE, Status::DRAFT], [$created->reference, $created->status]);
        self::assertSame('https://demo.docusign.net/restapi/v2.1/accounts/'.self::ACCOUNT.'/envelopes', $this->calls[1][1]);
        $body = json_decode((string) $this->calls[1][2]['body'], true);
        self::assertSame(['emailSubject', 'documents', 'recipients', 'status'], array_keys($body));
        self::assertSame(['documentBase64' => base64_encode('%PDF-1.7 lease'), 'name' => 'bail.pdf', 'fileExtension' => 'pdf', 'documentId' => '1'], $body['documents'][0]);
        self::assertSame('created', $body['status']);
        [$landlord, $tenant] = $body['recipients']['signers'];
        self::assertSame(['marco@meyers.example', 'Marco Meyer', '1', '1', 'landlord'], [$landlord['email'], $landlord['name'], $landlord['recipientId'], $landlord['routingOrder'], $landlord['clientUserId']]);
        self::assertSame([['documentId' => '1', 'pageNumber' => '3', 'xPosition' => '77', 'yPosition' => '581']], $landlord['tabs']['signHereTabs']);
        self::assertSame([['documentId' => '1', 'pageNumber' => '3', 'xPosition' => '330', 'yPosition' => '620']], $tenant['tabs']['dateSignedTabs']);
        self::assertSame([['documentId' => '1', 'anchorString' => '/sign-tenant/', 'anchorUnits' => 'pixels', 'anchorXOffset' => '0', 'anchorYOffset' => '0']], $tenant['tabs']['signHereTabs']);
        self::assertSame('2', $tenant['routingOrder']);

        $sent = $gateway->send($created);
        self::assertSame(['PUT', ['status' => 'sent']], [$this->calls[2][0], json_decode((string) $this->calls[2][2]['body'], true)]);
        self::assertSame(['include' => 'recipients'], $this->calls[3][2]['query']);
        self::assertSame([Status::SENT, SignerStatus::NOTIFIED, SignerStatus::WAITING], [$sent->status, $sent->state('landlord')->status, $sent->state('tenant')->status]);
    }

    public function testSigningInThePageRemindingCompletingDownloading(): void
    {
        $gateway = $this->gateway(['envelope-created:201', 'envelope-update', 'envelope-sent', 'view:201', 'recipients-update', 'envelope-completed', new MockResponse('%PDF signed'), new MockResponse('%PDF certificate')]);
        $sent = $gateway->send($gateway->create(self::lease()));

        self::assertSame('https://demo.docusign.net/Signing/MTRedeem/v1/4b5c6d7e?slt=eyJ0eXAi', $gateway->signingUrl($sent, 'tenant')->url);
        self::assertSame(['returnUrl' => 'https://meyers.example/leases/42/signed', 'authenticationMethod' => 'none', 'email' => 'camille@erable.example', 'userName' => 'Camille Érable', 'clientUserId' => 'tenant'], json_decode((string) $this->calls[4][2]['body'], true));

        $gateway->remind($sent);
        self::assertSame(['resend_envelope' => 'true'], $this->calls[5][2]['query']);
        self::assertSame(['signers' => [['recipientId' => '1', 'email' => 'marco@meyers.example', 'name' => 'Marco Meyer'], ['recipientId' => '2', 'email' => 'camille@erable.example', 'name' => 'Camille Érable']]], json_decode((string) $this->calls[5][2]['body'], true));

        $done = $gateway->fetch($sent);
        self::assertSame([Status::COMPLETED, SignerStatus::SIGNED, '2026-10-09T17:30:00+00:00'], [$done->status, $done->state('tenant')->status, $done->state('tenant')->signedAt?->format(\DATE_ATOM)]);

        $download = $gateway->download($done);
        self::assertSame(['%PDF signed', '%PDF certificate', 'certificate-of-completion.pdf'], [$download->documents[0]->content, $download->evidence?->content, $download->evidence?->filename]);
        self::assertStringEndsWith('/envelopes/'.self::ENVELOPE.'/documents/combined', (string) parse_url($this->calls[7][1], \PHP_URL_PATH));
        self::assertSame(['certificate' => 'false'], $this->calls[7][2]['query']);
    }

    public function testVoidingAndConnectsHmac(): void
    {
        $gateway = $this->gateway(['envelope-update'], ['hmac_keys' => 'old-key,new-key']);
        $canceled = $gateway->cancel(self::lease()->with(reference: self::ENVELOPE), 'Changed our minds');
        self::assertSame(Status::CANCELED, $canceled->status);
        self::assertSame(['status' => 'voided', 'voidedReason' => 'Changed our minds'], json_decode((string) $this->calls[1][2]['body'], true));

        $body = (string) file_get_contents(__DIR__.'/Fixtures/connect-recipient-completed.json');
        $notification = $gateway->notify($body, ['X-DocuSign-Signature-1' => base64_encode(hash_hmac('sha256', $body, 'unknown', true)), 'X-DocuSign-Signature-2' => base64_encode(hash_hmac('sha256', $body, 'new-key', true))]);
        self::assertSame([Event::SIGNED, 'recipient-completed', self::ENVELOPE, '2'], [$notification->event, $notification->type, $notification->reference, $notification->signer]);

        $this->expectException(InvalidNotificationException::class);
        $gateway->notify($body, ['X-DocuSign-Signature-1' => base64_encode(hash_hmac('sha256', $body, 'unknown', true))]);
    }

    public function testWhatDocusignIsNotAskedHereAndItsErrors(): void
    {
        foreach ([new Envelope('x', [new Document('d', new File('%PDF', 'd.pdf'))], [new Signer('s', 'S', 's@example.org')], level: Level::QUALIFIED), new Envelope('x', [new Document('d', new File('%PDF', 'd.pdf'))], [new Signer('s', 'S', 's@example.org', authentication: 'sms')])] as $envelope) {
            try {
                $this->gateway([])->create($envelope);
                self::fail();
            } catch (InvalidConfigException) {
                self::addToAssertionCount(1);
            }
        }
        try {
            $this->gateway(['error-400:400'])->create(self::lease());
            self::fail();
        } catch (ProviderException $e) {
            self::assertSame('INVALID_EMAIL_ADDRESS_FOR_RECIPIENT', $e->providerCode);
        }
        try {
            $this->gateway([], ['sandbox' => false])->signingUrl(self::lease()->with(reference: self::ENVELOPE), 'tenant');
        } catch (\Throwable $e) {
            self::assertSame('https://account.docusign.com/oauth/userinfo', $this->calls[array_key_last($this->calls)][1], 'production: the account\'s base URI from userinfo');
        }

        $http = new MockHttpClient(new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/consent-required.json'), ['http_code' => 400]));
        $this->expectException(InvalidKeyException::class);
        $this->expectExceptionMessage('consent_required');
        (new DocusignGatewayFactory($http))->create(['integration_key' => 'ik', 'user_id' => 'u', 'private_key' => self::$privateKey, 'account_id' => self::ACCOUNT])->fetch(self::lease()->with(reference: self::ENVELOPE));
    }
}
