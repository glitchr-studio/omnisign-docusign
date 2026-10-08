<?php

namespace Omnisign\Docusign\Action;

use Omnisign\Exception\InvalidConfigException;
use Omnisign\Model\Field;
use Omnisign\Model\FieldType;
use Omnisign\Model\Level;
use Omnisign\Model\SignerState;
use Omnisign\Request\Create;
use Omnisign\Request\Request;

/**
 * POST /envelopes, status "created": the documents in base64 (documentId 1,
 * 2...), the signers (recipientId 1, 2..., routingOrder their turn,
 * clientUserId their key when they sign in the page) and their tabs.
 */
final class CreateAction extends AbstractAction
{
    private const TABS = [
        'signature' => 'signHereTabs',
        'initials' => 'initialHereTabs',
        'date' => 'dateSignedTabs',
        'text' => 'textTabs',
        'mention' => 'textTabs',
    ];

    public function supports(Request $request): bool
    {
        return $request instanceof Create;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Create);
        $envelope = $request->envelope;
        if (Level::SIMPLE !== $envelope->level) {
            throw new InvalidConfigException('The "docusign" gateway signs at the simple level: advanced and qualified signatures go through providers set on the DocuSign account, not written here.');
        }
        if (!$envelope->documents || !$envelope->signers) {
            throw new InvalidConfigException('A DocuSign envelope needs a document and a signer at least.');
        }

        $documents = $ids = [];
        foreach (array_values($envelope->documents) as $i => $document) {
            $ids[$document->key] = (string) ($i + 1);
            $documents[] = ['documentBase64' => base64_encode($document->file->content), 'name' => $document->file->filename, 'fileExtension' => strtolower(pathinfo($document->file->filename, \PATHINFO_EXTENSION) ?: 'pdf'), 'documentId' => (string) ($i + 1)];
        }
        $signers = $states = [];
        foreach (array_values($envelope->signers) as $i => $signer) {
            if (null !== $signer->authentication && 'none' !== $signer->authentication) {
                throw new InvalidConfigException(\sprintf('The "docusign" gateway authenticates no signer by %s: an access code or a phone check is the account\'s setting.', $signer->authentication));
            }
            $tabs = [];
            foreach ($envelope->fieldsOf($signer->key) as $field) {
                $tabs[self::TABS[$field->type->value]][] = $this->tab($field, $ids);
            }
            $states[$signer->key] = new SignerState($signer->key, (string) ($i + 1));
            $signers[] = array_filter([
                'email' => $signer->email,
                'name' => $signer->name,
                'recipientId' => (string) ($i + 1),
                'routingOrder' => (string) ($envelope->ordered ? $signer->order : 1),
                'clientUserId' => $envelope->embedded ? $signer->key : null,
                'tabs' => $tabs ?: null,
            ], static fn ($v) => null !== $v);
        }
        $days = null !== $envelope->expiresAt ? max(1, (int) ceil(($envelope->expiresAt->getTimestamp() - time()) / 86400)) : null;

        $created = $this->api->json('POST', $this->api->envelopes(), array_filter([
            'emailSubject' => mb_substr($envelope->title, 0, 100),
            'emailBlurb' => $envelope->message,
            'documents' => $documents,
            'recipients' => ['signers' => $signers],
            'notification' => null !== $days ? ['useAccountDefaults' => 'false', 'expirations' => ['expireEnabled' => 'true', 'expireAfter' => (string) $days]] : null,
            'status' => 'created',
        ], static fn ($v) => null !== $v));

        $request->setResult(self::read($envelope->with(states: $states, documentReferences: $ids), $created));
    }

    /**
     * @param array<string, string> $ids
     *
     * @return array<string, string>
     */
    private function tab(Field $field, array $ids): array
    {
        $tab = ['documentId' => $ids[$field->document] ?? throw new InvalidConfigException(\sprintf('A field points at no document "%s".', $field->document))];
        $tab += null !== $field->anchor
            ? ['anchorString' => $field->anchor, 'anchorUnits' => 'pixels', 'anchorXOffset' => (string) $field->x, 'anchorYOffset' => (string) $field->y]
            : ['pageNumber' => (string) $field->page, 'xPosition' => (string) $field->x, 'yPosition' => (string) $field->y];
        if (FieldType::MENTION === $field->type || FieldType::TEXT === $field->type) {
            $tab += ['tabLabel' => $field->label ?? $field->type->value, 'required' => 'true'] + (FieldType::MENTION === $field->type ? ['value' => '', 'tooltip' => $field->label ?? 'Lu et approuvé'] : []);
        }

        return $tab;
    }
}
