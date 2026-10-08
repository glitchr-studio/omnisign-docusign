<?php

namespace Omnisign\Docusign;

use Omnisign\Exception\InvalidConfigException;
use Omnisign\Exception\InvalidKeyException;
use Omnisign\Exception\InvalidNotificationException;
use Omnisign\Exception\ProviderException;
use Omnisign\Http\Answer;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * DocuSign's eSignature REST API v2.1, authenticated by the JWT grant: a
 * JWT signed (RS256) with the integration's private key, for the user it
 * acts as, exchanged at the account server for an access token - kept until
 * a minute before it lapses. The account's base URI is the userinfo's when
 * none is configured.
 */
final class Api
{
    public const PROVIDER = 'docusign';
    public const DEMO_OAUTH = 'account-d.docusign.com';
    public const OAUTH = 'account.docusign.com';

    private ?string $token = null;
    private int $expires = 0;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $integrationKey,
        private readonly string $userId,
        #[\SensitiveParameter] private readonly string $privateKey,
        public readonly string $accountId,
        private ?string $baseUri = null,
        private readonly string $oauthHost = self::DEMO_OAUTH,
        /** @var list<string> */
        #[\SensitiveParameter] private readonly array $hmacKeys = [],
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function json(string $method, string $path, array $json = [], array $query = []): array
    {
        $answer = $this->call($method, $path, array_filter(['json' => $json ?: null, 'query' => $query ?: null]));

        return '' === trim($answer->body) ? [] : $answer->json();
    }

    public function file(string $path, array $query = []): Answer
    {
        return $this->call('GET', $path, ['query' => $query, 'headers' => ['Accept' => 'application/pdf']]);
    }

    public function envelopes(): string
    {
        return '/v2.1/accounts/'.rawurlencode($this->accountId).'/envelopes';
    }

    /**
     * A Connect message, its HMAC checked: X-DocuSign-Signature-1, -2...
     * each the base64 HMAC-SHA-256 of the raw body under one of the
     * account's Connect keys.
     *
     * @param array<string, string|null> $signatures
     *
     * @return array<string, mixed>
     */
    public function verify(string $body, array $signatures): array
    {
        if (!$this->hmacKeys) {
            throw new InvalidNotificationException(self::PROVIDER, 'No Connect HMAC key is configured.');
        }
        $valid = false;
        foreach ($this->hmacKeys as $key) {
            $expected = base64_encode(hash_hmac('sha256', $body, $key, true));
            foreach ($signatures as $signature) {
                $valid = $valid || (null !== $signature && hash_equals($expected, $signature));
            }
        }
        if (!$valid) {
            throw new InvalidNotificationException(self::PROVIDER, 'The signature does not hold.');
        }
        $data = json_decode($body, true);

        return \is_array($data) ? $data : throw new InvalidNotificationException(self::PROVIDER, 'The message is not JSON: configure Connect to send JSON.');
    }

    /** The JWT grant's assertion: RS256, for the user the integration acts as. */
    public function assertion(int $now): string
    {
        $encode = static fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        $header = $encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $encode((string) json_encode(['iss' => $this->integrationKey, 'sub' => $this->userId, 'aud' => $this->oauthHost, 'iat' => $now, 'exp' => $now + 3600, 'scope' => 'signature impersonation']));
        $key = openssl_pkey_get_private(is_file($this->privateKey) ? (string) file_get_contents($this->privateKey) : $this->privateKey);
        if (false === $key || !openssl_sign($header.'.'.$claims, $signature, $key, \OPENSSL_ALGO_SHA256)) {
            throw new InvalidConfigException('The "docusign" gateway cannot sign with its private_key: a PEM RSA key, or the path of one.');
        }

        return $header.'.'.$claims.'.'.$encode($signature);
    }

    private function token(): string
    {
        $now = $this->clock ? ($this->clock)() : time();
        if (null !== $this->token && $now < $this->expires) {
            return $this->token;
        }
        $answer = Answer::send($this->http, self::PROVIDER, 'POST', 'https://'.$this->oauthHost.'/oauth/token', ['body' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $this->assertion($now)]]);
        $data = json_decode($answer->body, true);
        if ($answer->status >= 400 || !\is_array($data) || !isset($data['access_token'])) {
            // consent_required: the user never allowed the integration to act for them.
            throw new InvalidKeyException(self::PROVIDER, \sprintf('The JWT grant was refused (HTTP %d): %s', $answer->status, \is_array($data) ? ($data['error_description'] ?? $data['error'] ?? 'no reason') : 'no reason'), \is_array($data) ? ($data['error'] ?? null) : null);
        }
        $this->token = (string) $data['access_token'];
        $this->expires = $now + max(0, (int) ($data['expires_in'] ?? 3600) - 60);

        return $this->token;
    }

    /** The account's base URI: configured, or the userinfo's for the account. */
    private function base(): string
    {
        if (null !== $this->baseUri) {
            return $this->baseUri;
        }
        $answer = Answer::send($this->http, self::PROVIDER, 'GET', 'https://'.$this->oauthHost.'/oauth/userinfo', ['headers' => ['Authorization' => 'Bearer '.$this->token()]]);
        foreach ((array) ($answer->json()['accounts'] ?? []) as $account) {
            if (($account['account_id'] ?? null) === $this->accountId) {
                return $this->baseUri = rtrim((string) $account['base_uri'], '/').'/restapi';
            }
        }

        throw new InvalidConfigException(\sprintf('The user has no DocuSign account "%s".', $this->accountId));
    }

    /** @param array<string, mixed> $options */
    private function call(string $method, string $path, array $options): Answer
    {
        $options['headers'] = ['Authorization' => 'Bearer '.$this->token()] + ($options['headers'] ?? []);
        $answer = Answer::send($this->http, self::PROVIDER, $method, $this->base().$path, $options);
        if ($answer->status >= 400) {
            $error = json_decode($answer->body, true);
            $message = \sprintf('%s %s: HTTP %d, %s', $method, $path, $answer->status, \is_array($error) ? trim(($error['errorCode'] ?? '').' '.($error['message'] ?? '')) : mb_substr(trim($answer->body), 0, 200));
            throw \in_array($answer->status, [401, 403], true) ? new InvalidKeyException(self::PROVIDER, $message, (string) $answer->status) : new ProviderException(self::PROVIDER, $message, \is_array($error) ? ($error['errorCode'] ?? null) : (string) $answer->status);
        }

        return $answer;
    }
}
