<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito;

use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use Illuminate\Support\Carbon;
use Mpdf\Output\Destination;

/**
 * La stampa «Lo stato di esecuzione delle delibere» (§8 del progetto della tappa 5; R10): il documento del punto «Stato di esecuzione delle delibere
 * precedenti», che va nel fascicolo dell'assemblea. Per ogni delibera l'assemblea, il punto, la frase pubblica, eseguita il o da eseguire, con le cose da
 * fare e il loro stato; prima le aperte di sempre, poi le eseguite dall'ultima ordinaria.
 *
 * **Le stesse righe dell'avviso e del verbale**: l'elenco è quello fermato nei dati del punto il giorno in cui si è preparato; senza il punto, quello di oggi
 * ({@see StatoDiEsecuzione::elenco()}). Niente nomi e niente testi scritti a mano: le frasi pubbliche dei punti e le parole dei tipi delle cose
 * (Cass. 29323/2022). È un documento del condominio: la vista estende `pdf.base`, con il gruppo «Rendiconto e assemblea» e la testata comune.
 */
final class StampaDelloStatoDiEsecuzione
{
    public const TITOLO = 'Lo stato di esecuzione delle delibere';

    public function __construct(private readonly PdfService $pdf) {}

    /** Il punto «Stato di esecuzione delle delibere precedenti» dell'assemblea, se c'è. */
    public static function puntoDellAssemblea(Assemblea $assemblea): ?PuntoDellOrdineDelGiorno
    {
        return $assemblea->punti()->where('chiave_fonte', StatoDiEsecuzione::CHIAVE)->orderBy('numero')->first();
    }

    /**
     * L'elenco della stampa: quello fermato nel punto, o quello di oggi; con le date in parole.
     *
     * @return array{al: string, ultima_ordinaria: ?string, aperte: list<array<string, mixed>>, eseguite: list<array<string, mixed>>, dal_punto: ?int}
     */
    public static function elenco(Assemblea $assemblea): array
    {
        $punto = self::puntoDellAssemblea($assemblea);
        $fermato = $punto === null ? null : (((array) ($punto->dati ?? []))[StatoDiEsecuzione::CHIAVE] ?? null);
        $elenco = is_array($fermato) && isset($fermato['al'])
            ? $fermato + ['ultima_ordinaria' => null, 'aperte' => [], 'eseguite' => []]
            : StatoDiEsecuzione::elenco($assemblea->condominio()->firstOrFail(), $assemblea);

        return ['al' => (string) $elenco['al'], 'ultima_ordinaria' => $elenco['ultima_ordinaria'] ?? null, 'aperte' => array_values((array) $elenco['aperte']),
            'eseguite' => array_values((array) $elenco['eseguite']), 'dal_punto' => $punto === null ? null : (int) $punto->numero];
    }

    /** Le righe come le scrive la vista: la delibera, le cose con il loro stato. @return array<string, mixed> */
    public static function perLaVista(Assemblea $assemblea): array
    {
        $elenco = self::elenco($assemblea);
        $giorno = fn (?string $g) => $g === null || $g === '' ? null : Carbon::parse($g)->format('d/m/Y');
        $riga = fn (array $r) => [
            'delibera' => trim(($r['assemblea'] ?? 'Assemblea') . (isset($r['punto']) && $r['punto'] !== null ? ', punto ' . $r['punto'] : '')),
            'testo' => (string) ($r['testo'] ?? ''),
            'eseguita_il' => $giorno($r['eseguita_il'] ?? null),
            'cose' => array_map(fn (array $c) => ['testo' => (string) ($c['testo'] ?? ''), 'stato' => match ($c['stato'] ?? null) {
                'fatta' => 'fatta' . (($c['fatta_il'] ?? null) !== null ? ' il ' . $giorno($c['fatta_il']) : ''),
                'non_serve' => 'non serve più',
                default => 'da fare' . (($c['entro_il'] ?? null) !== null ? ' entro il ' . $giorno($c['entro_il']) : ''),
            }], (array) ($r['cose'] ?? [])),
        ];

        return [
            'titolo' => self::TITOLO,
            'assemblea' => $assemblea->nome(),
            'al' => $giorno($elenco['al']),
            'ultima_ordinaria' => $giorno($elenco['ultima_ordinaria']),
            'dal_punto' => $elenco['dal_punto'],
            'aperte' => array_map($riga, $elenco['aperte']),
            'eseguite' => array_map($riga, $elenco['eseguite']),
        ];
    }

    /** Il PDF. */
    public function pdf(Assemblea $assemblea): string
    {
        $dati = self::perLaVista($assemblea);

        return $this->pdf->generate('pdf.gestionale.assemblea_stato_esecuzione', $dati + [
            'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA,
            'condominio' => $assemblea->condominio,
            'titolo_stampa' => self::TITOLO . ' · ' . $dati['assemblea'],
        ])->Output('', Destination::STRING_RETURN);
    }

    /** Il nome del file. */
    public static function nomeDelFile(Assemblea $assemblea): string
    {
        return PdfService::nomeFile('stato-di-esecuzione-delle-delibere', (string) $assemblea->condominio?->nome, $assemblea->data?->format('Y-m-d'));
    }
}
