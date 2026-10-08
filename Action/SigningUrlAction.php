<?php

namespace Omnisign\Docusign\Action;

use Omnisign\Exception\InvalidConfigException;
use Omnisign\Model\SigningUrl;
use Omnisign\Request\Request;
use Omnisign\Request\SigningUrl as SigningUrlRequest;

/**
 * POST /envelopes/{id}/views/recipient: a signing session for an embedded
 * signer (the clientUserId the envelope was created with). Use it at once.
 */
final class SigningUrlAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof SigningUrlRequest;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof SigningUrlRequest);
        $envelope = $request->envelope;
        if (!$envelope->embedded) {
            throw new InvalidConfigException('DocuSign gives a signing view to a signer of an envelope created embedded only (clientUserId).');
        }
        $signer = $envelope->signer($request->signer);
        $view = $this->api->json('POST', $this->api->envelopes().'/'.$envelope->reference().'/views/recipient', [
            'returnUrl' => $request->returnUrl ?? $envelope->redirectUrl ?? throw new InvalidConfigException('DocuSign sends the signer back somewhere: give a returnUrl, or the envelope a redirectUrl.'),
            'authenticationMethod' => 'none',
            'email' => $signer->email,
            'userName' => $signer->name,
            'clientUserId' => $signer->key,
        ]);
        $request->setResult(new SigningUrl((string) $view['url']));
    }
}
