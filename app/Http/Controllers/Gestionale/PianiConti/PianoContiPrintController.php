<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Gestionale\PianiConti;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Conto;
use App\Services\PDF\PdfService;
use Illuminate\Support\Str;
use App\Support\OrdinamentoConti;
use Illuminate\Http\Request;
use App\Services\PDF\GruppoCarta;

class PianoContiPrintController extends Controller
{
    /**
     * Stampa la Distinta delle Spese – una riga per capitolo con il relativo importo preventivato.
     * Orientamento: Portrait.
     */
    public function distinta(
        Request $request,
        Condominio $condominio,
        Esercizio $esercizio,
        PianoConto $pianoConto,
        PdfService $pdfService
    ) {
        // Il criterio arriva dalla pagina, così la Distinta stampata rispetta l'ordine
        // che l'amministratore vede a schermo: stamparla per nome mentre la pagina è
        // ordinata per codice renderebbe la scelta a schermo praticamente inutile.
        $ordina = OrdinamentoConti::criterioValido($request->query('ordina'));

        // Carica i conti radice (senza parent) con i sottoconti, esclusi i tecnici (sopravvenienze)
        $conti = Conto::with('sottoconti')
            ->where('piano_conto_id', $pianoConto->id)
            ->whereNull('parent_id')
            ->get();

        // Ordinamento in PHP e non in SQL: il criterio "codice" deve confrontare in
        // modo naturale (A.2 prima di A.10) e spingere in fondo le voci senza codice,
        // regole che un ORDER BY non esprime in modo portabile fra MySQL e SQLite.
        $conti = OrdinamentoConti::applica($conti, $ordina);

        $conti->each(function ($conto) use ($ordina) {
            $conto->setRelation('sottoconti', OrdinamentoConti::applica($conto->sottoconti, $ordina));
        });

        // «Totale preventivo» è la somma delle sole voci di spesa; le voci di entrata si sommano a parte e il netto da ripartire è la
        // differenza. Ogni voce conta come la conta il calcolo delle quote, per il valore assoluto dal lato del suo tipo
        // (`Conto::partiDelTipo`): una spesa scritta con il meno da una versione vecchia si addebita in positivo, la sua riga porta il
        // valore del calcolo e una nota in fondo la nomina (`speseNegative`), così righe e totali tornano fra loro.
        $totalePreventivo     = 0;
        $totaleEntrate        = 0;
        $totaleSopravvenienze = 0;
        $speseNegative        = [];

        $contaVoce = function (Conto $voce) use (&$totalePreventivo, &$totaleEntrate, &$speseNegative) {
            $parti = Conto::partiDelTipo((string) $voce->tipo, (int) ($voce->importo ?? 0));
            $totalePreventivo += $parti['spese'];
            $totaleEntrate += $parti['entrate'];
            if (Conto::eSpesaNegativa((string) $voce->tipo, (int) ($voce->importo ?? 0))) {
                $speseNegative[] = ['nome' => (string) $voce->nome, 'importo' => (int) $voce->importo];
            }
        };

        foreach ($conti as $conto) {
            if ($conto->is_tecnico) {
                $totaleSopravvenienze += $conto->importo ?? 0;
                foreach ($conto->sottoconti as $s) {
                    $totaleSopravvenienze += $s->importo ?? 0;
                }
            } else {
                // Per i conti con sottoconti il padre è solo un raggruppamento (importo = 0)
                foreach ($conto->sottoconti as $s) {
                    if ($s->is_tecnico) {
                        $totaleSopravvenienze += $s->importo ?? 0;
                    } else {
                        $contaVoce($s);
                    }
                }
                // Se il conto è foglia (nessun sottoconto), conta lui stesso
                if ($conto->sottoconti->isEmpty()) {
                    $contaVoce($conto);
                }
            }
        }

        $data = [
            'condominio'           => $condominio,
            'esercizio'            => $esercizio,
            'pianoConto'           => $pianoConto,
            'conti'                => $conti,
            'totalePreventivo'     => $totalePreventivo,
            'totaleEntrate'        => $totaleEntrate,
            'totaleDaRipartire'    => $totalePreventivo - $totaleEntrate,
            'totaleSopravvenienze' => $totaleSopravvenienze,
            'speseNegative'        => $speseNegative,
        ];

        $mpdf = $pdfService->generate('pdf.gestionale.distinta_spese', $data + ['gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA], [
            'orientation' => 'P',
            'margin_top'  => 32,
        ]);

        // ⚠️ `Output(..., 'I')` scrive il PDF sull'output buffer e torna vuota: il browser lo riceveva
        // dall'echo di mPDF, la risposta Laravel era vuota e un test sullo status era verde su zero
        // byte (beta.28, Coda 151). E «distinta_spese.pdf» era uguale per ogni condominio; il piano
        // dei conti è uno per gestione, e un esercizio con ordinaria e straordinaria ne ha due.
        $nomeFile = PdfService::nomeFile('distinta-spese', $condominio->nome, $esercizio->data_inizio?->format('Y'), Str::slug($pianoConto->nome));

        return response($mpdf->Output($nomeFile, \Mpdf\Output\Destination::STRING_RETURN))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$nomeFile.'"');
    }

    /**
     * Stampa la Ripartizione delle Spese – matrice condòmino × rata.
     * Per ogni unità immobiliare mostra quanto deve pagare su ogni rata del piano.
     * Orientamento: Landscape.
     */
    public function riparto(
        Request $request,
        Condominio $condominio,
        Esercizio $esercizio,
        PianoConto $pianoConto,
        PdfService $pdfService
    ) {
        // Recupera i piani rate collegati alla stessa gestione del piano dei conti
        $pianiRate = PianoRate::where('gestione_id', $pianoConto->gestione_id)
            ->orderBy('id')
            ->with([
                'rate' => fn($q) => $q->orderBy('numero_rata'),
                'rate.rateQuote.anagrafica',
                'rate.rateQuote.immobile.anagrafiche',
            ])
            ->get();

        // Solo i piani dell'esercizio della stampa: una gestione usata in più esercizi ha anche i piani degli altri, e il foglio dell'anno non porta le
        // cifre di un altro anno. Un piano senza l'esercizio salvato (i piani vecchi) è quello che il programma gli attribuisce.
        $competenza = app(\App\Services\Riparto\CompetenzaDelPiano::class);
        $pianiRate = $pianiRate->filter(fn (PianoRate $p) => $competenza->esercizioDelPiano($p)?->id === $esercizio->id)->values();

        // Una riga per ogni soggetto dentro la sua unità, una colonna per ogni rata di ogni piano.
        $matrice = self::matricePerSoggetto($pianiRate, 'codice');

        $data = [
            'condominio'  => $condominio,
            'esercizio'   => $esercizio,
            'pianoConto'  => $pianoConto,
            'colonneRate' => $matrice['colonne'],
            'matrice'     => $matrice['righe'],
            'conPiani'    => $matrice['piani'] > 1,
        ];

        $mpdf = $pdfService->generate('pdf.gestionale.ripartizione_spese', $data + ['gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA], [
            'orientation' => 'L',
            'margin_top'  => 32,
        ]);

        // Stessa trappola e stessa cura della distinta, qui sopra.
        $nomeFile = PdfService::nomeFile('ripartizione-spese', $condominio->nome, $esercizio->data_inizio?->format('Y'), Str::slug($pianoConto->nome));

        return response($mpdf->Output($nomeFile, \Mpdf\Output\Destination::STRING_RETURN))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$nomeFile.'"');
    }

    /**
     * Le quote di uno o più piani rate, per unità e per soggetto: la forma delle stampe date ai condòmini (la ripartizione spese e lo
     * scadenziario per unità, Coda 756). Una riga per unità, dentro la riga una per ogni soggetto con il suo ruolo (P, NP, U, I) e la
     * sua parte rata per rata; il totale dell'unità è la somma dei suoi soggetti. Le quote annullate non entrano, come in tutte le altre
     * letture delle quote. Le colonne sono una per rata di ogni piano (chiave «<piano>:<numero>»): con più piani nella gestione la
     * rata 1 del secondo non finisce sotto la data della rata 1 del primo.
     *
     * Il ruolo è quello **di allora**, dalle righe del riparto del piano (`righe_riparto.ruolo_risolto`), e la titolarità del giorno solo
     * in mancanza: la stessa regola della stampa del riparto per tabella (`MatriceRipartoBuilder`).
     *
     * @param  \Illuminate\Support\Collection<int, PianoRate>  $pianiRate  con `rate.rateQuote` caricate
     * @param  'codice'|'etichetta'  $ordine
     * @param  bool  $conQuoteSenzaUnita  le quote di una persona senza unità fanno una riga a parte (la ripartizione) o non entrano (lo scadenziario per unità)
     * @return array{colonne: array<string, array{nome: string, scadenza: string, piano: string}>, piani: int, righe: list<array<string, mixed>>}
     */
    public static function matricePerSoggetto(\Illuminate\Support\Collection $pianiRate, string $ordine = 'etichetta', bool $conQuoteSenzaUnita = true): array
    {
        // Fase 1-bis della beta.14: un `foreach` e non una freccia in un ciclo della raccolta (la trappola della Coda 212: una freccia che restituisce `false` ferma il ciclo).
        foreach ($pianiRate as $p) {
            $p->loadMissing(['rate.rateQuote.anagrafica', 'rate.rateQuote.immobile.anagrafiche']);
        }

        $ruoliDiAllora = [];
        $righeRiparto = \App\Models\Gestionale\RigaRiparto::whereIn('piano_rate_id', $pianiRate->pluck('id'))
            ->whereIn('tipo', [\App\Models\Gestionale\RigaRiparto::TIPO_RIPARTO, \App\Models\Gestionale\RigaRiparto::TIPO_AD_PERSONAM])
            ->whereNotNull('ruolo_risolto')->whereNotNull('anagrafica_id')
            ->orderBy('id')->get(['anagrafica_id', 'immobile_id', 'ruolo_risolto']);
        foreach ($righeRiparto as $r) {
            $ruoliDiAllora[$r->anagrafica_id.'|'.$r->immobile_id] ??= (string) $r->ruolo_risolto;
        }

        $titolari = new \App\Services\Riparto\RisolutoreTitolari();
        $sigle = ['proprietario' => 'P', 'nuda_proprietario' => 'NP', 'usufruttuario' => 'U', 'inquilino' => 'I'];
        $ordineRuoli = ['proprietario' => 0, 'nuda_proprietario' => 1, 'usufruttuario' => 2, 'inquilino' => 3];

        $colonne = [];
        $unita = [];
        $piani = 0;

        foreach ($pianiRate as $pianoRate) {
            $haRate = false;
            foreach ($pianoRate->rate as $rata) {
                $chiaveColonna = $pianoRate->id.':'.$rata->numero_rata;
                $colonne[$chiaveColonna] = [
                    'nome'     => $rata->numero_rata.'ª Rata',
                    'scadenza' => $rata->data_scadenza ? $rata->data_scadenza->format('d/m/Y') : '',
                    'piano'    => (string) $pianoRate->nome,
                ];
                $haRate = true;

                foreach ($rata->rateQuote as $quota) {
                    if ($quota->stato === 'annullata') {
                        continue;
                    }
                    if (! $quota->immobile_id && ! $quota->anagrafica_id) {
                        continue;
                    }
                    if (! $quota->immobile_id && ! $conQuoteSenzaUnita) {
                        continue;
                    }

                    $chiaveUnita = $quota->immobile_id ? 'immobile_'.$quota->immobile_id : 'anagrafica_'.$quota->anagrafica_id;
                    $immobile = $quota->immobile;

                    if (! isset($unita[$chiaveUnita])) {
                        $codice = $immobile ? ($immobile->codice_immobile ?? '-') : '-';
                        $dettagli = $immobile ? array_filter([
                            $immobile->interno ? 'int. '.$immobile->interno : null,
                            \App\Support\EtichettaDelPiano::per($immobile->piano),
                            $codice !== '-' ? $codice : null,
                        ]) : [];
                        $unita[$chiaveUnita] = [
                            'codice'    => (string) $codice,
                            'etichetta' => $immobile ? ($immobile->nome ?: $codice) : 'Senza unità immobiliare',
                            'dettaglio' => implode(' · ', $dettagli),
                            'soggetti'  => [],
                            'totale'    => 0,
                        ];
                    }

                    $chiaveSoggetto = (int) $quota->anagrafica_id;
                    if (! isset($unita[$chiaveUnita]['soggetti'][$chiaveSoggetto])) {
                        $ruoloRaw = $ruoliDiAllora[$quota->anagrafica_id.'|'.$quota->immobile_id] ?? null;
                        if ($ruoloRaw === null && $immobile) {
                            $pivot = $titolari->attiviAlla($immobile->anagrafiche)->where('id', $quota->anagrafica_id)->first()?->pivot
                                ?? $immobile->anagrafiche->where('id', $quota->anagrafica_id)->first()?->pivot;
                            $ruoloRaw = $pivot?->tipologia;
                        }
                        $ruoloRaw = (string) ($ruoloRaw ?: ($immobile ? 'proprietario' : ''));
                        $unita[$chiaveUnita]['soggetti'][$chiaveSoggetto] = [
                            'nome'             => (string) ($quota->anagrafica->nome ?? '—'),
                            'ruolo'            => $sigle[$ruoloRaw] ?? ($ruoloRaw === '' ? '' : strtoupper(substr($ruoloRaw, 0, 1))),
                            'ruolo_raw'        => $ruoloRaw,
                            'importi_per_rata' => [],
                            'totale'           => 0,
                        ];
                    }

                    $unita[$chiaveUnita]['soggetti'][$chiaveSoggetto]['importi_per_rata'][$chiaveColonna] =
                        ($unita[$chiaveUnita]['soggetti'][$chiaveSoggetto]['importi_per_rata'][$chiaveColonna] ?? 0) + (int) $quota->importo;
                    $unita[$chiaveUnita]['soggetti'][$chiaveSoggetto]['totale'] += (int) $quota->importo;
                    $unita[$chiaveUnita]['totale'] += (int) $quota->importo;
                }
            }
            if ($haRate) {
                $piani++;
            }
        }

        foreach ($unita as &$riga) {
            uasort($riga['soggetti'], fn ($a, $b) => ($ordineRuoli[$a['ruolo_raw']] ?? 9) <=> ($ordineRuoli[$b['ruolo_raw']] ?? 9));
            $riga['soggetti'] = array_values($riga['soggetti']);
        }
        unset($riga);

        $righe = array_values($unita);
        usort($righe, function ($a, $b) use ($ordine) {
            $perCodice = strnatcasecmp($a['codice'], $b['codice']);
            $perNome = strnatcasecmp($a['etichetta'], $b['etichetta']);

            return $ordine === 'codice' ? ($perCodice ?: $perNome) : ($perNome ?: $perCodice);
        });

        return ['colonne' => $colonne, 'piani' => $piani, 'righe' => $righe];
    }
}
