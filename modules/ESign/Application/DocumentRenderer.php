<?php

declare(strict_types=1);

namespace Modules\ESign\Application;

use Gereq\Setting\Settings;
use Modules\ESign\Domain\Signature;
use Modules\ESign\Domain\SignableDocument;

final class DocumentRenderer
{
    public function __construct(
        private readonly SignatureStorage $storage,
        private readonly Settings $settings,
    ) {
    }

    public function documentBody(SignableDocument $document): string
    {
        if ($document->executedHtml() !== null && $document->executedHtml() !== '') {
            return $document->executedHtml();
        }
        if ($document->lockedHtml() !== null && $document->lockedHtml() !== '') {
            return $document->lockedHtml();
        }

        return $this->renderTokens($document->bodyHtml(), $document);
    }

    public function renderTokens(string $html, SignableDocument $document, ?Signature $counterparty = null): string
    {
        $provider = $this->partySignatureMarkup(
            $document->providerSignaturePath(),
            $document->signedBy() ?: (string) $this->settings->get('esign.provider_name', 'GEREQ'),
            $document->signatureHash(),
            'provider',
        );
        $counter = $counterparty !== null
            ? $this->partySignatureMarkup(
                $counterparty->signaturePath(),
                $counterparty->signerName(),
                $counterparty->documentHash(),
                'counterparty',
            )
            : $this->counterpartyPlaceholder();

        $replacements = [
            'provider_signature' => $provider,
            'counterparty_signature' => $counter,
            'number' => htmlspecialchars($document->number(), ENT_QUOTES, 'UTF-8'),
            'title' => htmlspecialchars($document->title(), ENT_QUOTES, 'UTF-8'),
            'counterparty_name' => htmlspecialchars($document->counterpartyName(), ENT_QUOTES, 'UTF-8'),
            'counterparty_company' => htmlspecialchars((string) $document->counterpartyCompany(), ENT_QUOTES, 'UTF-8'),
        ];

        $out = preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            static function (array $m) use ($replacements): string {
                $key = strtolower($m[1]);

                return $replacements[$key] ?? $m[0];
            },
            $html,
        );

        return is_string($out) ? $out : $html;
    }

    public function affixCounterpartySignature(SignableDocument $document, Signature $signature): string
    {
        $base = $document->lockedHtml() ?? $this->renderTokens($document->bodyHtml(), $document);
        $markup = $this->partySignatureMarkup(
            $signature->signaturePath(),
            $signature->signerName(),
            $signature->documentHash(),
            'counterparty',
        );

        if (str_contains($base, '<!--esign:counterparty-signature-->')) {
            $replaced = preg_replace(
                '/<!--esign:counterparty-signature-->.*?<!--\/esign:counterparty-signature-->/s',
                '<!--esign:counterparty-signature-->' . $markup . '<!--/esign:counterparty-signature-->',
                $base,
            );
            $base = is_string($replaced) ? $replaced : $base . $markup;
        } elseif (str_contains($base, '{{ counterparty_signature }}') || str_contains($base, '{{counterparty_signature}}')) {
            $base = $this->renderTokens($base, $document, $signature);
        } else {
            $base .= '<div class="esign-sig-block">' . $markup . '</div>';
        }

        // U plného CarService PDF (už má patičku s podpisy) nepřidávat druhý audit blok.
        if (!str_contains($base, 'pdf-footer__lbl')) {
            $base .= $this->protocolMarkup($document, $signature);
        }

        return $base;
    }

    public function counterpartyPlaceholder(): string
    {
        return '<!--esign:counterparty-signature--><p style="color:#888">Čeká na podpis protistrany</p><!--/esign:counterparty-signature-->';
    }

    public function partySignatureMarkup(?string $path, string $name, ?string $hash, string $party): string
    {
        $dataUri = $this->storage->dataUri($path);

        if ($dataUri !== null) {
            $img = '<img src="' . htmlspecialchars($dataUri, ENT_QUOTES, 'UTF-8') . '" alt="Podpis" style="max-width:220px;max-height:70px;display:block;margin:6px 0">';
        } else {
            $initials = mb_strtoupper(mb_substr(trim($name), 0, 1));
            $img = '<svg xmlns="http://www.w3.org/2000/svg" width="220" height="70" viewBox="0 0 220 70">'
                . '<rect width="220" height="70" rx="6" fill="#f4f4f5"/>'
                . '<text x="110" y="42" text-anchor="middle" font-family="Georgia,serif" font-size="28" fill="#111">'
                . htmlspecialchars($initials, ENT_QUOTES, 'UTF-8')
                . '</text></svg>';
        }

        // U plného CarService PDF stačí obrázek (hint „digitální ePodpis“ je v šabloně).
        // U obecných eSign dokumentů necháme jméno pod podpisem.
        return '<div class="esign-signature" data-party="' . htmlspecialchars($party, ENT_QUOTES, 'UTF-8') . '">'
            . $img
            . '</div>';
    }

    public function protocolMarkup(SignableDocument $document, Signature $counterparty): string
    {
        $hash = htmlspecialchars((string) $document->lockedHash(), ENT_QUOTES, 'UTF-8');

        return '<section class="esign-protocol" style="margin-top:32px;padding-top:16px;border-top:1px solid #ddd;font-size:12px;color:#333">'
            . '<h3 style="margin:0 0 8px;font-size:14px">Protokol elektronického podpisu</h3>'
            . '<p>Dokument byl uzavřen prostým elektronickým podpisem (eIDAS SES). '
            . 'Otisk uzamčeného dokumentu (SHA-256): <code style="word-break:break-all">' . $hash . '</code></p>'
            . '<p>Protistrana: ' . htmlspecialchars($counterparty->signerName(), ENT_QUOTES, 'UTF-8')
            . ($counterparty->signerEmail() ? ' &lt;' . htmlspecialchars($counterparty->signerEmail(), ENT_QUOTES, 'UTF-8') . '&gt;' : '')
            . ', metoda: ' . htmlspecialchars($counterparty->method(), ENT_QUOTES, 'UTF-8')
            . ', IP: ' . htmlspecialchars((string) $counterparty->ipAddress(), ENT_QUOTES, 'UTF-8')
            . '.</p></section>';
    }

    public function wrapDocument(string $body, string $title, string $number): string
    {
        $trimmed = ltrim($body);
        if (str_starts_with($trimmed, '<!DOCTYPE') || str_starts_with($trimmed, '<html')) {
            return $body;
        }

        return '<!DOCTYPE html><html lang="cs"><head><meta charset="utf-8">'
            . '<title>' . htmlspecialchars($title . ' · ' . $number, ENT_QUOTES, 'UTF-8') . '</title>'
            . '<style>body{font-family:Georgia,serif;line-height:1.55;color:#111;padding:24px;max-width:800px;margin:0 auto}'
            . 'h1,h2,h3{font-family:system-ui,sans-serif}img{max-width:100%}</style></head><body>'
            . $body
            . '</body></html>';
    }
}
