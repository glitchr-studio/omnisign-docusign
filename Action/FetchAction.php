<?php

namespace Omnisign\Docusign\Action;

use Omnisign\Request\Fetch;
use Omnisign\Request\Request;

/** GET /envelopes/{id}?include=recipients. */
final class FetchAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof Fetch;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Fetch);
        $request->setResult(self::read($request->envelope, $this->api->json('GET', $this->api->envelopes().'/'.$request->envelope->reference(), [], ['include' => 'recipients'])));
    }
}
