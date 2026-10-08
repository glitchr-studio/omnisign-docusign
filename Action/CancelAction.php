<?php

namespace Omnisign\Docusign\Action;

use Omnisign\Model\Status;
use Omnisign\Request\Cancel;
use Omnisign\Request\Request;

/** PUT /envelopes/{id}, status "voided" with its reason (required by DocuSign). */
final class CancelAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof Cancel;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Cancel);
        $this->api->json('PUT', $this->api->envelopes().'/'.$request->envelope->reference(), ['status' => 'voided', 'voidedReason' => mb_substr($request->reason ?? 'Cancelled by the sender', 0, 200)]);
        $request->setResult($request->envelope->with(status: Status::CANCELED));
    }
}
