<?php

namespace Omnisign\Docusign\Action;

use Omnisign\Request\Request;
use Omnisign\Request\Send;

/** PUT /envelopes/{id}, status "sent". */
final class SendAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof Send;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Send);
        $this->api->json('PUT', $this->api->envelopes().'/'.$request->envelope->reference(), ['status' => 'sent']);
        $request->setResult(self::read($request->envelope, $this->api->json('GET', $this->api->envelopes().'/'.$request->envelope->reference(), [], ['include' => 'recipients'])));
    }
}
