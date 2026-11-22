<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Sala;

use App\Models\Assemblee\Assemblea;

/**
 * La sala che si apre senza rete (decisione 590, T5): il programma tiene nel browser **solo** la pagina della sala e quella del verbale, con un operaio del
 * browser (un service worker) scritto qui, senza librerie: `resources/js/lib/senzaRete/operaio.js`, servito con la sua configurazione in testa.
 *
 * - **Uno per assemblea**: l'operaio vive nella cartella dell'assemblea (`/admin/gestionale/{condominio}/assemblee/{assemblea}/`) e ne tiene solo la sala e il
 *   verbale; le altre pagine passano dalla rete come sempre. Si registra dalla pagina della sala, quindi c'è solo su un computer dove la sala si è aperta
 *   con la rete.
 * - **La cache è legata alla versione compilata**: la versione è l'impronta del manifesto di Vite e del codice dell'operaio; i file da tenere sono quelli
 *   che servono a quelle due pagine (l'ingresso del programma con i suoi import, i suoi fogli di stile e i suoi caratteri, le due pagine con i loro import, le
 *   traduzioni della lingua). Dopo un aggiornamento l'operaio è un altro: il browser lo installa, tiene i file nuovi e cancella quelli della versione di prima,
 *   così una sala riaperta senza rete non è mai una pagina vecchia con file che non ci sono più.
 * - **Senza il manifesto** (il server di sviluppo di Vite) l'operaio non c'è: la pagina la serve il server di sviluppo, e non si può tenere.
 */
final class SalaSenzaRete
{
    /** L'ingresso del programma nel manifesto di Vite. */
    public const INGRESSO = 'resources/js/app.ts';

    /**
     * Le pagine che si aprono senza rete: la chiave è l'ultimo pezzo del loro indirizzo nella cartella dell'assemblea (la sala, `…/sala`; il verbale,
     * `…/il-verbale`, perché `…/verbale` è la riunione registrata a mano), il valore è come le chiama il manifesto.
     */
    public const PAGINE = [
        'sala' => 'resources/js/pages/gestionale/assemblee/Sala.vue',
        'il-verbale' => 'resources/js/pages/gestionale/assemblee/Verbale.vue',
    ];

    /** Quanto aspetta la rete, con una copia della pagina già nel browser, prima di mostrare la copia. */
    public const ATTESA_DELLA_RETE_MS = 4000;

    public function __construct(private readonly ?string $manifesto = null) {}

    /** Il percorso del manifesto della build di Vite. */
    public function manifesto(): string
    {
        return $this->manifesto ?? public_path('build/manifest.json');
    }

    /** Il codice dell'operaio, senza la configurazione. */
    public static function codice(): string
    {
        return (string) file_get_contents(resource_path('js/lib/senzaRete/operaio.js'));
    }

    /** La versione: l'impronta del manifesto e del codice dell'operaio, o null senza il manifesto. */
    public function versione(): ?string
    {
        if (! is_file($this->manifesto())) {
            return null;
        }

        return substr(hash('sha256', (string) file_get_contents($this->manifesto()) . "\n" . self::codice()), 0, 16);
    }

    /** La cartella dell'assemblea, l'ambito dell'operaio: «/admin/gestionale/5/assemblee/12/». */
    public static function ambito(Assemblea $assemblea): string
    {
        $sala = (string) parse_url(route('admin.gestionale.assemblee.sala', [$assemblea->condominio_id, $assemblea->id]), PHP_URL_PATH);

        return substr($sala, 0, (int) strrpos($sala, '/') + 1);
    }

    /**
     * I file che le due pagine usano, come indirizzi: l'ingresso con i suoi import (non quelli caricati a richiesta, cioè le altre pagine), i fogli di stile, i
     * caratteri; le pagine con i loro import; le traduzioni della lingua dell'installazione. Una pagina che il manifesto non ha (il verbale prima della sua
     * tappa) non c'è.
     *
     * @return list<string>
     */
    public function file(?string $lingua = null): array
    {
        $manifesto = json_decode((string) file_get_contents($this->manifesto()), true);
        if (! is_array($manifesto) || ! isset($manifesto[self::INGRESSO])) {
            return [];
        }
        $lingua ??= app()->getLocale();
        $file = [];
        $visti = [];
        $raccogli = function (string $chiave) use (&$raccogli, &$file, &$visti, $manifesto): void {
            if (isset($visti[$chiave]) || ! isset($manifesto[$chiave])) {
                return;
            }
            $visti[$chiave] = true;
            $voce = $manifesto[$chiave];
            $file[] = (string) $voce['file'];
            foreach (array_merge((array) ($voce['css'] ?? []), (array) ($voce['assets'] ?? [])) as $f) {
                $file[] = (string) $f;
            }
            foreach ((array) ($voce['imports'] ?? []) as $import) {
                $raccogli((string) $import);
            }
        };
        $raccogli(self::INGRESSO);
        foreach (self::PAGINE as $pagina) {
            $raccogli($pagina);
        }
        foreach (array_unique([$lingua, 'it']) as $l) {
            $raccogli('lang/' . $l . '.json');
            $raccogli('lang/php_' . $l . '.json');
        }

        return array_values(array_unique(array_map(fn (string $f) => (string) parse_url(asset('build/' . $f), PHP_URL_PATH), $file)));
    }

    /**
     * L'operaio dell'assemblea: la configurazione e il codice. Null senza il manifesto.
     */
    public function script(Assemblea $assemblea): ?string
    {
        $versione = $this->versione();
        if ($versione === null) {
            return null;
        }
        $prefisso = 'kondomanager-sala-' . $assemblea->id . '-';
        $configurazione = [
            'versione' => $versione,
            'ambito' => self::ambito($assemblea),
            'pagine' => array_keys(self::PAGINE),
            'file' => $this->file(),
            'cache' => $prefisso . $versione,
            'prefisso' => $prefisso,
            'attesa_ms' => self::ATTESA_DELLA_RETE_MS,
            'senza_copia' => self::paginaSenzaCopia(),
        ];

        return '// SPDX-License-Identifier: AGPL-3.0-or-later' . "\n"
            . 'const CONFIGURAZIONE = ' . json_encode($configurazione, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ";\n"
            . self::codice();
    }

    /** La pagina che l'operaio mostra senza rete quando la sala (o il verbale) non si è mai aperta su questo computer. */
    public static function paginaSenzaCopia(): string
    {
        return '<!DOCTYPE html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Senza rete</title><style>body{font-family:system-ui,sans-serif;margin:0;padding:24px 16px;background:#fff;color:#1f2937;line-height:1.5;font-size:16px}'
            . 'main{max-width:560px;margin:0 auto}h1{font-size:20px;margin:0 0 12px}li{margin:6px 0}'
            . '@media (prefers-color-scheme: dark){body{background:#0b0f19;color:#e5e7eb}}</style></head><body><main>'
            . '<h1>Senza rete questa pagina non si apre su questo computer</h1><ul>'
            . '<li>Senza rete si riaprono solo la sala e il verbale, e solo su un computer dove si sono già aperti con la rete.</li>'
            . '<li>Adesso si continua sul foglio della sala, e dopo si registra con «Registra dal foglio della sala».</li>'
            . '<li>Quando la rete torna, ricarica la pagina.</li></ul></main></body></html>';
    }
}
