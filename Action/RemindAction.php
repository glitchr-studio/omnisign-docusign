<?php

namespace Omnisign\Docusign\Action;

use Omnisign\Model\SignerStatus;
use Omnisign\Request\Remind;
use Omnisign\Request\Request;

/** PUT /envelopes/{id}/recipients?resend_envelope=true, with the signers who have not signed. */
final class RemindAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof Remind;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Remind);
        $envelope = $request->envelope;
        $signers = [];
        foreach ($envelope->signers as $signer) {
            $state = $envelope->state($signer->key);
            if ((null === $request->signer || $request->signer === $signer->key) && null !== $state && !\in_array($state->status, [SignerStatus::SIGNED, SignerStatus::DECLINED], true)) {
                $signers[] = ['recipientId' => $state->reference, 'email' => $signer->email, 'name' => $signer->name];
            }
        }
        if ($signers) {
            $this->api->json('PUT', $this->api->envelopes().'/'.$envelope->reference().'/recipients', ['signers' => $signers], ['resend_envelope' => 'true']);
        }
        $request->setResult($envelope);
    }
}
