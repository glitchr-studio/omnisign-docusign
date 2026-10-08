---
title: omnisign/docusign
order: 1
---

# omnisign/docusign

## Installation

```sh
composer require omnisign/docusign
```

PHP 8.2 or later, `ext-openssl`, `glitchr/omnisign` and `symfony/http-client`. A DocuSign
integration (its key, an RSA key pair whose public half is on the integration), the user it acts as
- who granted it consent once -, and the account's id.

## The sources

Read on 2026-10-08: the eSignature REST API v2.1 OpenAPI
([docusign/OpenAPI-Specifications](https://github.com/docusign/OpenAPI-Specifications),
`esignature.rest.swagger-v2.1.json`, MIT) - envelopes, recipients, recipient views, documents
`combined` and `certificate`; DocuSign's guides on the JWT grant (`account-d.docusign.com` /
`account.docusign.com`, `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer`, claims `iss`,
`sub`, `aud`, `iat`, `exp`, `scope: signature impersonation`, RS256) and on Connect's HMAC
(`X-DocuSign-Signature-1`, `-2`..., base64 HMAC-SHA-256 of the raw body, one per active key).

## Options

| Option | Default | |
|---|---|---|
| `integration_key`, `user_id`, `account_id` | required | |
| `private_key` | required | the RSA private key's PEM, or its path |
| `sandbox` | `true` | the demo environment (`account-d.docusign.com`, `demo.docusign.net`); `false`: production |
| `base_uri` | | the account's `https://<host>/restapi`; production: read from `/oauth/userinfo` when left empty |
| `hmac_keys` | | Connect's keys (a list, or comma-separated); without them, `notify()` is not supported |

## From an envelope to DocuSign's

| | |
|---|---|
| `create()` | `POST /v2.1/accounts/{id}/envelopes`, `status: created`: the documents in base64 (`documentId` 1, 2...), the signers (`recipientId` 1, 2..., `routingOrder` their turn when ordered, `clientUserId` their key when embedded) and their tabs - `SIGNATURE` `signHereTabs`, `INITIALS` `initialHereTabs`, `DATE` `dateSignedTabs`, `TEXT` and `MENTION` `textTabs`; a position (`pageNumber`, `xPosition`, `yPosition`) or an `anchorString`; an expiry in days |
| `send()` | `PUT .../envelopes/{id}`, `status: sent` |
| `fetch()` | `GET .../envelopes/{id}?include=recipients` |
| `remind()` | `PUT .../envelopes/{id}/recipients?resend_envelope=true` with the signers who have not signed |
| `cancel()` | `PUT .../envelopes/{id}`, `status: voided` with its `voidedReason` |
| `download()` | `GET .../documents/combined?certificate=false`, `GET .../documents/certificate` |
| `signingUrl()` | `POST .../views/recipient` (`returnUrl`, `authenticationMethod: none`, e-mail, name, `clientUserId`) - an envelope created embedded only; use the URL at once |
| `notify()` | Connect in JSON: the HMAC checked against each key, `event` read (`recipient-completed`: `SIGNED`, `envelope-completed`: `COMPLETED`...) |

Only the **simple** level, and no signer authentication: advanced and qualified signatures, an
access code, a phone check are the DocuSign account's settings, not written here - such an
envelope is refused.

| DocuSign | Status | | Recipient | SignerStatus |
|---|---|---|---|---|
| `created` | `DRAFT` | | `created` | `WAITING` |
| `sent`, `delivered` | `SENT` | | `sent` | `NOTIFIED` |
| `completed` | `COMPLETED` | | `delivered` | `OPENED` |
| `declined` | `DECLINED` | | `signed`, `completed` | `SIGNED` |
| `voided` | `CANCELED` (`EXPIRED` when voided for expiry) | | `declined` | `DECLINED` |

## Verified, and not

| | |
|---|---|
| Against DocuSign | **not verified in real: no developer account.** The answers in `Tests/Fixtures` are written from the OpenAPI and the guides |
| The JWT: its header, claims and RS256 signature | by the tests, checked with the key pair's public half |
| The envelope's body, the tabs, the recipient view, the HMAC | by the tests, against the OpenAPI and the guides |
