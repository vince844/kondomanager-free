<?php

namespace App\Services\Riparto;

use App\Models\Anagrafica;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RataQuote;
use App\Models\Immobile;
use App\Models\Tabella;
use Illuminate\Support\Facades\Log;

/**
 * La matrice delle due stampe del riparto, costruita **leggendo** il dettaglio (1.11.0-beta.29).
 *
 * Fino alla beta.28 i due servizi di stampa ricalcolavano: `RipartoTabelleService` istanziava il
 * motore e spaccava dal vivo i pesi per tabella con un secondo Hare, `RipartoCapitoliService` si
 * rifaceva gli importi da `righe_fattura` e i pesi dall'albero, con un Hare per radice; i centesimi
 * che le due ricostruzioni non sapevano spiegare finivano in «Fuori riparto» o sommati agli
 * addebiti ad personam (Code 77 e 78). Qui non c'è nessuna aritmetica di riparto: le celle sono
 * somme di righe, e ogni riga è quella che il motore ha deciso alla generazione.
 *
 * Le due stampe differiscono solo per la colonna: la **tabella** (`per_tabella`) o la **radice**
 * del conto (`per_capitolo`). Il resto — righe da `rate_quote`, pseudo-colonne, totali, ordine di
 * lettura — è uno. Le chiavi della matrice sono quelle che i due template leggono da sempre.
 *
 * ## Le pseudo-colonne
 *
 * - «Addebito diretto» (`diretto`): le righe `ad_personam` — e **solo** quelle (Coda 77).
 * - «Saldi precedenti» (`pregresso`): `regole_calcolo.importi.saldo_usato` delle quote, che non
 *   passa dal motore e non è nel dettaglio.
 * - «Già versato» (`gia_versato`): le righe `netting`, negative, per soggetto (Coda 78).
 * - «Fuori riparto» (`fuori_riparto`): **una guardia, non una colonna attesa**. Nel registrato la
 *   somma delle righe di un soggetto più il suo pregresso è la sua quota per costruzione: un
 *   residuo ≠ 0 è un errore, viene loggato come tale e la colonna compare per dirlo. Nel
 *   ricostruito può capitare davvero (dati cambiati dopo la generazione) e la colonna lo mostra.
 */
final class MatriceRipartoBuilder
{
    public const COLONNA_DIRETTO = 'diretto';
    public const COLONNA_PREGRESSO = 'pregresso';
    public const COLONNA_GIA_VERSATO = 'gia_versato';
    public const COLONNA_FUORI_RIPARTO = 'fuori_riparto';

    public function perTabelle(PianoRate $pianoRate): array
    {
        return $this->costruisci($pianoRate, 'tabelle');
    }

    public function perCapitoli(PianoRate $pianoRate): array
    {
        return $this->costruisci($pianoRate, 'capitoli');
    }

    private function costruisci(PianoRate $pianoRate, string $modo): array
    {
        $perTabelle = $modo === 'tabelle';
        $chiaveColonne = $perTabelle ? 'tabelle' : 'capitoli';
        $chiaveCelle = $perTabelle ? 'per_tabella' : 'per_capitolo';
        $chiaveTotali = $perTabelle ? 'tot_per_tabella' : 'tot_per_capitolo';

        ['righe' => $dettaglio, 'fonte' => $fonte] = DettaglioRiparto::perPiano($pianoRate);

        // ─── 1. Le colonne, dalle righe ──────────────────────────────────────────────────────
        $colonne = [];      // chiave colonna → info
        $celle = [];        // chiave colonna → «aid|iid» → centesimi
        $quoteCella = [];   // chiave colonna → immobile_id → valore (millesimo) o null
        $sommaValori = [];  // tabella_id → denominatore congelato
        $tabellaDiRadice = []; // radice → tabella_id | false (mista)
        $contestoSoggetto = []; // «aid|iid» → [ruolo_risolto, quota_possesso]

        $decimaliTabelle = Tabella::whereIn('id', array_values(array_unique(array_filter(array_column($dettaglio, 'tabella_id')))))
            ->pluck('numero_decimali', 'id')->all();

        foreach ($dettaglio as $r) {
            $chiaveSoggetto = $r['anagrafica_id'] !== null ? $r['anagrafica_id'].'|'.$r['immobile_id'] : null;
            // La chiave della colonna sopravvive al conto o alla tabella cancellati dopo la
            // generazione: id vivo se c'è, altrimenti il nome congelato sulla riga (`x:<nome>`).
            // Senza, un documento «registrato» perdeva la colonna e mandava l'importo in «Fuori
            // riparto», con la legenda che dichiarava il contrario.
            $chiaveTabella = $this->chiaveTabella($r);

            if ($r['tipo'] === 'riparto') {
                $colonna = $perTabelle ? $chiaveTabella : $this->chiaveCapitolo($r);
                if ($colonna === null) continue;

                if (!isset($colonne[$colonna])) {
                    $colonne[$colonna] = $perTabelle
                        ? $this->infoTabella($r, $decimaliTabelle)
                        : ['nome' => $r['conto_radice_nome'] ?? $r['conto_nome'] ?? '—', 'quota_label' => $this->etichettaQuota($r['tabella_quota']), 'quota_tipo' => $r['tabella_quota'], 'decimali' => (int) ($decimaliTabelle[$r['tabella_id']] ?? 2), 'tot_importo' => 0, 'quota_mista' => false];
                    $tabellaDiRadice[$colonna] = $chiaveTabella;
                }
                if (!$perTabelle && $tabellaDiRadice[$colonna] !== false && $tabellaDiRadice[$colonna] !== $chiaveTabella) {
                    // Più tabelle sotto la stessa radice: nessun millesimo è «quello» del capitolo.
                    $tabellaDiRadice[$colonna] = false;
                    $colonne[$colonna]['quota_mista'] = true;
                    $colonne[$colonna]['quota_label'] = '—';
                    unset($quoteCella[$colonna]);
                }
                $celle[$colonna][$chiaveSoggetto] = ($celle[$colonna][$chiaveSoggetto] ?? 0) + (int) $r['importo'];
                if (!$perTabelle) $colonne[$colonna]['tot_importo'] += (int) $r['importo'];

                if ($chiaveTabella !== null) {
                    $sommaValori[$chiaveTabella] = $r['somma_valori'];
                    if ($perTabelle || $tabellaDiRadice[$colonna] === $chiaveTabella) {
                        $quoteCella[$colonna][$r['immobile_id']] = $r['valore_millesimo'];
                    }
                }
                $contestoSoggetto[$chiaveSoggetto] ??= ['ruolo' => $r['ruolo_risolto'], 'quota' => $r['quota_possesso']];
                continue;
            }

            if ($r['tipo'] === 'quota_zero') {
                // Lo zero congelato si legge nella seconda passata: le righe `quota_zero` di un
                // conto precedono le sue righe `riparto`, e l'ordine delle colonne è delle seconde.
                continue;
            }

            if ($r['tipo'] === 'ad_personam') {
                $this->dichiaraPseudoColonna($colonne, self::COLONNA_DIRETTO, 'Addebito diretto');
                $celle[self::COLONNA_DIRETTO][$chiaveSoggetto] = ($celle[self::COLONNA_DIRETTO][$chiaveSoggetto] ?? 0) + (int) $r['importo'];
                $contestoSoggetto[$chiaveSoggetto] ??= ['ruolo' => $r['ruolo_risolto'], 'quota' => $r['quota_possesso']];
                continue;
            }

            if ($r['tipo'] === 'netting') {
                $this->dichiaraPseudoColonna($colonne, self::COLONNA_GIA_VERSATO, 'Già versato');
                $celle[self::COLONNA_GIA_VERSATO][$chiaveSoggetto] = ($celle[self::COLONNA_GIA_VERSATO][$chiaveSoggetto] ?? 0) + (int) $r['importo'];
            }
        }

        // Seconda passata, le `quota_zero`: la cella esiste con il millesimo di allora (0 o NULL).
        // Una tabella vista solo qui (nessuna riga `riparto`: fetta interamente scoperta) resta
        // una colonna, in coda alle altre.
        foreach ($dettaglio as $r) {
            if ($r['tipo'] !== 'quota_zero') continue;
            $chiaveTabella = $this->chiaveTabella($r);
            if ($chiaveTabella === null) continue;
            if ($perTabelle) {
                if (!isset($colonne[$chiaveTabella])) $colonne[$chiaveTabella] = $this->infoTabella($r, $decimaliTabelle);
                $quoteCella[$chiaveTabella][$r['immobile_id']] ??= $r['valore_millesimo'];
                continue;
            }
            foreach ($tabellaDiRadice as $radice => $tabId) {
                if ($tabId === $chiaveTabella) $quoteCella[$radice][$r['immobile_id']] ??= $r['valore_millesimo'];
            }
        }

        // ─── 2. Le righe: da `rate_quote` quando esistono (la garanzia legale), altrimenti dal dettaglio ──
        $totaliReali = [];
        $pregresso = [];
        $quote = RataQuote::whereIn('rata_id', $pianoRate->rate()->pluck('id'))->get();
        foreach ($quote as $rq) {
            if (!$rq->anagrafica_id || !$rq->immobile_id) continue;
            $k = $rq->anagrafica_id.'|'.$rq->immobile_id;
            $totaliReali[$k] = ($totaliReali[$k] ?? 0) + (int) round($rq->importo);
            $regole = is_string($rq->regole_calcolo) ? json_decode($rq->regole_calcolo, true) : $rq->regole_calcolo;
            if (is_array($regole) && isset($regole['importi']['saldo_usato'])) {
                $pregresso[$k] = ($pregresso[$k] ?? 0) + (int) round($regole['importi']['saldo_usato']);
            }
        }
        $anteprima = $fonte['tipo'] === DettaglioRiparto::ANTEPRIMA;
        if ($anteprima) {
            foreach ($dettaglio as $r) {
                if ($r['anagrafica_id'] === null) continue;
                $k = $r['anagrafica_id'].'|'.$r['immobile_id'];
                $totaliReali[$k] = ($totaliReali[$k] ?? 0) + (int) $r['importo'];
            }
        }
        foreach ($pregresso as $k => $imp) {
            if ($imp === 0) continue;
            $this->dichiaraPseudoColonna($colonne, self::COLONNA_PREGRESSO, 'Saldi precedenti');
            $celle[self::COLONNA_PREGRESSO][$k] = $imp;
        }

        if ($totaliReali === []) {
            return $this->vuota($chiaveColonne, $chiaveTotali, $fonte);
        }

        $immobiliIds = []; $anagraficheIds = [];
        foreach (array_keys($totaliReali) as $k) { [$aid, $iid] = explode('|', $k); $anagraficheIds[] = (int) $aid; $immobiliIds[] = (int) $iid; }
        $immobili = Immobile::with('anagrafiche')->whereIn('id', array_unique($immobiliIds))->get()->keyBy('id');
        $anagrafiche = Anagrafica::whereIn('id', array_unique($anagraficheIds))->get()->keyBy('id');

        $sigleRuolo = ['proprietario' => 'P', 'nuda_proprietario' => 'NP', 'inquilino' => 'I', 'usufruttuario' => 'U'];
        $righe = [];
        // Le pseudo-colonne in coda, sempre: nascono nell'ordine in cui il motore scrive le righe
        // (l'ad personam prima del millesimale, il già versato subito dopo il suo conto) e senza
        // questo riordino finivano in testa o in mezzo alle tabelle, come non erano mai state.
        $colonne = $this->pseudoColonneInCoda($colonne);
        $totPerColonna = array_fill_keys(array_keys($colonne), 0);
        $granTotale = 0;

        $chiaviConRighe = [];
        foreach ($dettaglio as $r) {
            if ($r['anagrafica_id'] !== null) $chiaviConRighe[$r['anagrafica_id'].'|'.$r['immobile_id']] = true;
        }

        foreach ($totaliReali as $k => $importoTotale) {
            // Un soggetto a zero resta in stampa se il dettaglio lo spiega (riparto + già versato
            // = 0: la riga documentaria «coperta da versamento»); sparisce solo se non ha né
            // righe né importo — una quota a zero senza storia.
            if ($importoTotale === 0 && !$anteprima && !isset($chiaviConRighe[$k])) continue;
            [$aid, $iid] = array_map('intval', explode('|', $k));
            $immobile = $immobili[$iid] ?? null;
            $anagrafica = $anagrafiche[$aid] ?? null;
            if (!$immobile || !$anagrafica) continue;

            // Il ruolo e la quota di possesso **di allora**, dalle righe; la pivot viva solo in mancanza.
            $ctx = $contestoSoggetto[$k] ?? null;
            $pivot = $immobile->anagrafiche->where('id', $aid)->first()?->pivot;
            $ruoloRaw = $ctx['ruolo'] ?? ($pivot?->tipologia ?? 'proprietario');
            $quotaSogg = $ctx['quota'] ?? ($pivot?->quota ?? 100);

            $importiPerColonna = [];
            $somma = 0;
            foreach ($colonne as $chiave => $_) {
                $cent = (int) ($celle[$chiave][$k] ?? 0);
                $importiPerColonna[$chiave] = $cent;
                $somma += $cent;
            }
            $residuo = $importoTotale - $somma;
            if ($residuo !== 0) {
                $this->dichiaraPseudoColonna($colonne, self::COLONNA_FUORI_RIPARTO, 'Fuori riparto');
                $importiPerColonna[self::COLONNA_FUORI_RIPARTO] = $residuo;
                $totPerColonna[self::COLONNA_FUORI_RIPARTO] ??= 0;
                $messaggio = "MatriceRipartoBuilder: residuo di {$residuo} cent per il soggetto {$k} del piano {$pianoRate->id} ({$fonte['tipo']}).";
                $fonte['tipo'] === DettaglioRiparto::REGISTRATO ? Log::error($messaggio) : Log::info($messaggio);
            }

            $cellePerColonna = [];
            foreach ($colonne as $chiave => $info) {
                $cellePerColonna[$chiave] = [
                    'quota'   => ($info['senza_quote'] ?? false) ? null : ($quoteCella[$chiave][$iid] ?? null),
                    'importo' => $importiPerColonna[$chiave] ?? 0,
                ];
            }

            $righe[$iid] ??= [
                'codice_immobile' => $immobile->codice_immobile ?? '',
                'interno'         => $immobile->interno ?? '',
                'piano'           => $immobile->piano ?? '',
                'nome_immobile'   => $immobile->nome ?: ($immobile->codice_immobile ?? ''),
                'soggetti'        => [],
                'totale_immobile' => 0,
            ];
            $righe[$iid]['soggetti'][$aid] = [
                'nome'       => $anagrafica->nome ?? '—',
                'ruolo'      => $sigleRuolo[$ruoloRaw] ?? strtoupper(substr((string) $ruoloRaw, 0, 1)),
                'ruolo_raw'  => $ruoloRaw,
                'quota_sogg' => $quotaSogg,
                $chiaveCelle => $cellePerColonna,
                'totale'     => $importoTotale,
            ];
            $righe[$iid]['totale_immobile'] += $importoTotale;
            $granTotale += $importoTotale;
            foreach ($importiPerColonna as $chiave => $imp) {
                $totPerColonna[$chiave] = ($totPerColonna[$chiave] ?? 0) + $imp;
            }
        }

        // Le celle dei soggetti aggiunti dopo (Fuori riparto dichiarata a metà giro) vanno completate.
        foreach ($righe as &$riga) {
            foreach ($riga['soggetti'] as &$sogg) {
                foreach ($colonne as $chiave => $_) {
                    $sogg[$chiaveCelle][$chiave] ??= ['quota' => null, 'importo' => 0];
                }
            }
            unset($sogg);
        }
        unset($riga);

        // ─── 3. Ordine di lettura (beta.27) e ordine dei ruoli nell'unità ─────────────────────
        $confronto = fn (array $a, array $b) =>
            ((($a['interno'] ?? '') === '') <=> (($b['interno'] ?? '') === ''))
            ?: strnatcasecmp($a['interno'] ?? '', $b['interno'] ?? '')
            ?: strnatcasecmp($a['nome_immobile'] ?? '', $b['nome_immobile'] ?? '')
            ?: strnatcasecmp($a['codice_immobile'] ?? '', $b['codice_immobile'] ?? '');
        uasort($righe, $confronto);
        $ordineRuoli = ['proprietario' => 0, 'nuda_proprietario' => 1, 'usufruttuario' => 2, 'inquilino' => 3];
        foreach ($righe as &$riga) {
            uasort($riga['soggetti'], fn ($a, $b) => ($ordineRuoli[$a['ruolo_raw']] ?? 9) <=> ($ordineRuoli[$b['ruolo_raw']] ?? 9));
        }
        unset($riga);

        $matrice = [
            $chiaveColonne => $colonne,
            'righe'        => $righe,
            'gran_totale'  => $granTotale,
            $chiaveTotali  => $totPerColonna,
            'fonte'        => $fonte,
        ];
        if ($perTabelle) {
            $totQuota = [];
            foreach ($colonne as $chiave => $info) {
                if ($info['senza_quote'] ?? false) continue;
                $totQuota[$chiave] = (float) ($sommaValori[$chiave] ?? array_sum(array_map('floatval', $quoteCella[$chiave] ?? [])));
            }
            $matrice['tot_quota_per_tabella'] = $totQuota;
        }

        return $matrice;
    }

    /** Id della tabella se vive ancora, altrimenti il nome congelato: la colonna non muore col dato. */
    private function chiaveTabella(array $r): int|string|null
    {
        if ($r['tabella_id'] !== null) return (int) $r['tabella_id'];

        return ($r['tabella_nome'] ?? '') !== '' ? 'x:'.$r['tabella_nome'] : null;
    }

    /** Id della radice (o del conto) se vive ancora, altrimenti il nome congelato. */
    private function chiaveCapitolo(array $r): int|string|null
    {
        $id = $r['conto_radice_id'] ?? $r['conto_id'];
        if ($id !== null) return (int) $id;
        $nome = $r['conto_radice_nome'] ?? $r['conto_nome'];

        return ($nome ?? '') !== '' ? 'x:'.$nome : null;
    }

    /**
     * Le pseudo-colonne dopo le colonne vere. Unione `+` e non `array_merge`: le chiavi delle
     * tabelle e dei conti sono id interi e `array_merge` li rinumererebbe da zero.
     */
    private function pseudoColonneInCoda(array $colonne): array
    {
        $coda = [];
        foreach ([self::COLONNA_DIRETTO, self::COLONNA_PREGRESSO, self::COLONNA_GIA_VERSATO, self::COLONNA_FUORI_RIPARTO] as $chiave) {
            if (isset($colonne[$chiave])) $coda[$chiave] = $colonne[$chiave];
        }

        return array_diff_key($colonne, $coda) + $coda;
    }

    private function infoTabella(array $r, array $decimali): array
    {
        return [
            'nome'        => $r['tabella_nome'] ?? '—',
            'quota_label' => $this->etichettaQuota($r['tabella_quota']),
            'quota_tipo'  => $r['tabella_quota'] ?? 'millesimi',
            'decimali'    => (int) ($decimali[$r['tabella_id']] ?? 2),
        ];
    }

    private function etichettaQuota(?string $quota): string
    {
        if ($quota === null || $quota === '') return 'mill.';

        return Tabella::etichettaQuota($quota);
    }

    private function dichiaraPseudoColonna(array &$colonne, string $chiave, string $nome): void
    {
        if (isset($colonne[$chiave])) return;
        $colonne[$chiave] = [
            'nome'        => $nome,
            'quota_label' => '—',
            'quota_tipo'  => null,
            'decimali'    => 0,
            'tot_importo' => 0,
            'senza_quote' => true,
        ];
    }

    private function vuota(string $chiaveColonne, string $chiaveTotali, array $fonte): array
    {
        return [$chiaveColonne => [], 'righe' => [], 'gran_totale' => 0, $chiaveTotali => [], 'tot_quota_per_tabella' => [], 'fonte' => $fonte];
    }
}
