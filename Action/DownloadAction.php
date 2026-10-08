<?php

namespace Omnisign\Docusign\Action;

use Omnisign\Model\Download;
use Omnisign\Model\File;
use Omnisign\Request\Download as DownloadRequest;
use Omnisign\Request\Request;

/** GET /envelopes/{id}/documents/combined (certificate=false) and /documents/certificate: the certificate of completion. */
final class DownloadAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof DownloadRequest;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof DownloadRequest);
        $path = $this->api->envelopes().'/'.$request->envelope->reference().'/documents';
        $request->setResult(new Download(
            [new File($this->api->file($path.'/combined', ['certificate' => 'false'])->body, 'signed.pdf')],
            new File($this->api->file($path.'/certificate')->body, 'certificate-of-completion.pdf'),
        ));
    }
}
