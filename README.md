# omnisign/docusign

**DocuSign eSignature** for [glitchr/omnisign](https://github.com/glitchr-studio/omnisign):
envelopes through the REST API v2.1, by e-mail or signed in the application's page, the JWT grant
for an integration acting as one of the account's users, Connect webhooks with their HMAC checked.

```php
use Omnisign\Docusign\DocusignGatewayFactory;

$gateway = (new DocusignGatewayFactory($httpClient))->create([
    'integration_key' => $integrationKey, 'user_id' => $userId,
    'private_key' => '/run/secrets/docusign.pem', 'account_id' => $accountId,
    'sandbox' => true,
]);

$envelope = $gateway->send($gateway->create($lease));     // POST /envelopes (created), then sent
$gateway->signingUrl($envelope, 'tenant', $returnUrl);     // a recipient view (embedded signers)
```

Written from DocuSign's eSignature REST API v2.1 OpenAPI and its guides. **Not verified in real:
no developer account.**

[Documentation](docs/index.md): the options and the JWT grant, from an envelope to DocuSign's,
Connect, what was verified.

License: LGPL-3.0-or-later.
