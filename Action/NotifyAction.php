<?php

namespace Omnisign\Docusign\Action;

use Omnisign\Model\Event;
use Omnisign\Model\Notification;
use Omnisign\Request\Notify;
use Omnisign\Request\Request;

/** A Connect message (JSON): X-DocuSign-Signature-n checked, its event read. */
final class NotifyAction extends AbstractAction
{
    private const EVENTS = [
        'envelope-sent' => Event::SENT, 'envelope-delivered' => Event::OPENED, 'envelope-completed' => Event::COMPLETED,
        'envelope-declined' => Event::DECLINED, 'envelope-voided' => Event::CANCELED,
        'recipient-delivered' => Event::OPENED, 'recipient-completed' => Event::SIGNED, 'recipient-declined' => Event::DECLINED,
    ];

    public function supports(Request $request): bool
    {
        return $request instanceof Notify;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Notify);
        $signatures = [];
        for ($i = 1; $i <= 100 && null !== ($signature = $request->header('X-DocuSign-Signature-'.$i)); ++$i) {
            $signatures[] = $signature;
        }
        $message = $this->api->verify($request->body, $signatures);
        $type = (string) ($message['event'] ?? '');
        $data = (array) ($message['data'] ?? []);
        $request->setResult(new Notification(
            self::EVENTS[$type] ?? Event::OTHER,
            $type,
            $data['envelopeId'] ?? null,
            isset($data['recipientId']) ? (string) $data['recipientId'] : null,
            isset($message['generatedDateTime']) ? new \DateTimeImmutable($message['generatedDateTime']) : null,
            ($data['envelopeId'] ?? '').'@'.$type.'@'.($data['recipientId'] ?? '').'@'.($message['generatedDateTime'] ?? ''),
            $message,
        ));
    }
}
