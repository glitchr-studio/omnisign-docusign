<?php

namespace Omnisign\Docusign\Action;

use Omnisign\Action\ActionInterface;
use Omnisign\Action\ApiAwareInterface;
use Omnisign\Action\ApiAwareTrait;
use Omnisign\Docusign\Api;
use Omnisign\Model\Envelope;
use Omnisign\Model\SignerState;
use Omnisign\Model\SignerStatus;
use Omnisign\Model\Status;

/** An action on DocuSign's eSignature REST API v2.1. */
abstract class AbstractAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    protected const STATUSES = ['created' => Status::DRAFT, 'sent' => Status::SENT, 'delivered' => Status::SENT, 'signed' => Status::SENT, 'completed' => Status::COMPLETED, 'declined' => Status::DECLINED, 'voided' => Status::CANCELED];
    protected const SIGNER_STATUSES = ['created' => SignerStatus::WAITING, 'sent' => SignerStatus::NOTIFIED, 'delivered' => SignerStatus::OPENED, 'signed' => SignerStatus::SIGNED, 'completed' => SignerStatus::SIGNED, 'declined' => SignerStatus::DECLINED, 'autoresponded' => SignerStatus::FAILED];

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    /**
     * The envelope as DocuSign answered it: its status and, with the
     * recipients, each signer's - found back by recipientId.
     *
     * @param array<string, mixed> $answer
     */
    protected static function read(Envelope $envelope, array $answer): Envelope
    {
        $states = $envelope->states;
        $keys = [];
        foreach ($states as $key => $state) {
            $keys[(string) $state->reference] = $key;
        }
        foreach ((array) ($answer['recipients']['signers'] ?? []) as $signer) {
            $key = $keys[(string) ($signer['recipientId'] ?? '')] ?? null;
            if (null === $key) {
                continue;
            }
            $old = $states[$key];
            $states[$key] = new SignerState($key, $old->reference, self::SIGNER_STATUSES[$signer['status'] ?? ''] ?? $old->status, isset($signer['signedDateTime']) ? new \DateTimeImmutable($signer['signedDateTime']) : $old->signedAt, $old->link, $old->linkExpiresAt, $signer['declinedReason'] ?? $old->reason);
        }
        $status = (string) ($answer['status'] ?? '');

        return $envelope->with(
            reference: (string) ($answer['envelopeId'] ?? $envelope->reference),
            // A voided envelope past its expiry: DocuSign voids what expires.
            status: 'voided' === $status && str_contains(strtolower((string) ($answer['voidedReason'] ?? '')), 'expired') ? Status::EXPIRED : (self::STATUSES[$status] ?? Status::UNKNOWN),
            states: $states,
            completedAt: isset($answer['completedDateTime']) ? new \DateTimeImmutable($answer['completedDateTime']) : null,
            data: $answer,
        );
    }
}
