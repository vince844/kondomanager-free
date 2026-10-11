<?php

namespace App\Actions\Gestionale\Movimenti;

use App\Enums\TipoMovimentoContabile;
use App\Models\Condominio;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\DoubleEntryValidator;
use App\Services\Gestionale\RiallineaCreditiService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lo storno di un incasso rate o di un rimborso di credito: per ogni scrittura della famiglia una `rettifica` che ne è lo specchio
 * (stessi conti, versi invertiti), la pivot delle quote rovesciata, l'originale sigillata «annullata». Il giornale si corregge
 * con scritture in aggiunta, mai riscrivendo.
 *
 * La famiglia (1.11.0-beta.49): l'incasso, le sue figlie (la compensazione `storno_credito`) e le rettifiche del riallineamento
 * dei crediti figlie di quelle figlie (`RiallineaCreditiService`, contrasto 3 del progetto): la rettifica sposta il credito della
 * compensazione da «Crediti verso condomini» a «Passate gestioni», e senza il suo specchio lo storno lascerebbe il credito uscito
 * da lì due volte.
 *
 * Il credito riportato restituito (decisione 76.3, contrasto 2): una compensazione o un rimborso presi da una quota a credito
 * mentre la sua rata era in bozza sono usciti da «Passate gestioni»; se nel frattempo la rata è andata a giornale, l'emissione
 * ha portato fra i crediti verso condomini solo il credito che restava allora. Lo specchio rimette la parte restituita fra le
 * «Passate gestioni», dove la quota non la cercherà più: una scrittura figlia di quell'emissione la porta fra i crediti, come
 * avrebbe fatto l'emissione senza l'uso. Se l'emissione si annulla, la figlia se ne va con lei (`EmissioneRateController`).
 * Non «la rettifica sul conto dove il credito sta adesso»: misurata, sbagliava dopo un annullamento dell'emissione e sui dati
 * della 1.10. Vale anche quando la quota è rimasta senza scrittura in una rata andata a giornale per gli altri, perché il suo
 * credito era stato usato tutto prima dell'emissione (rilievi K4 e LDA-1 della Fase 1-bis) o perché una versione di prima l'ha
 * lasciata fuori: se ha il dettaglio del calcolo, la figlia la scrive con le regole dell'emissione e la lega, così il suo
 * credito sta fra i crediti verso condomini come per ogni quota a giornale. Lo specchio da solo lasciava il credito fra le
 * «Passate gestioni» in una rata a giornale (la forma DC2-b).
 * Non una quota a credito, o a zero, senza il dettaglio del calcolo (piani della 1.7.x, `RataQuote::fermaSenzaDettaglio`): la
 * tengono ferma l'emissione e il comando (decisione 76.3, ritoccata l'11/10/2026), perché il programma non sa quanta parte è
 * spesa e quanta credito riportato, e la tiene ferma anche lo storno. Basta lo specchio: il credito torna fra le «Passate
 * gestioni», dove la quota lo cerca, e la card «Crediti da riallineare» continua a elencarla (giro stretto sulle correzioni
 * della Fase 1-bis, G1: la figlia la scriveva con spesa 0 e tutto l'importo come riporto).
 * Il credito delle entrate dell'anno (la spesa negativa di una voce di tipo «entrata», rilievo K2) non passa da «Passate
 * gestioni»: lo specchio lo rimette fra i crediti verso condomini, e la figlia non serve.
 */
class StornoIncassoRateAction
{
    public function execute(ScritturaContabile $scrittura, Condominio $condominio): void
    {
        // 1. GUARD CLAUSE: Preveniamo lo "Storno dello Storno" (Inception contabile)
        // tipo_movimento è castato all'enum: il confronto con la stringa era sempre
        // falso, quindi questa protezione non scattava mai.
        if ($scrittura->tipo_movimento === TipoMovimentoContabile::RETTIFICA) {
            throw new \RuntimeException('Non è possibile stornare una scrittura di rettifica.');
        }

        DB::transaction(function () use ($scrittura, $condominio) {
            // 2. Il blocco contro il doppio storno concorrente (1.11.0-beta.49, P4): la scrittura bloccata e il suo stato riletto
            // dal database, non preso dal modello che arriva. Una seconda richiesta partita con la scrittura ancora valida aspetta
            // la prima, poi la trova annullata e non scrive niente: prima scriveva un'altra rettifica (e un'altra figlia
            // dell'emissione), con la cassa e le quote rovesciate due volte.
            if (ScritturaContabile::whereKey($scrittura->id)->lockForUpdate()->value('stato') === 'annullata') {
                return;
            }

            $stornate = collect();

            foreach ($this->famiglia($scrittura) as $scr) {

                // Protezione contro i doppi click, sullo stato riletto
                if (ScritturaContabile::whereKey($scr->id)->value('stato') === 'annullata') continue;

                // 3. Creiamo la Scrittura speculare di Rettifica
                $rettifica = ScritturaContabile::create([
                    'condominio_id'      => $condominio->id,
                    'esercizio_id'       => $scr->esercizio_id,
                    'gestione_id'        => $scr->gestione_id,
                    'scrittura_padre_id' => $scr->id, // 🟢 LINK DIRETTO ALLA SCRITTURA ORIGINALE
                    'data_registrazione' => now(),
                    // ⚠️ **La rettifica ha la data in cui viene fatta, non quella dell'incasso.**
                    // Era l'unico storno del gestionale a retrodatare (giroconto, regolazione
                    // immediata, pagamento fornitore e F24 usano tutti `now()`), e nel registro
                    // di contabilità — ordinato per data effettiva — la rettifica si infilava
                    // subito dopo l'incasso: ogni saldo progressivo fra le due date era quello
                    // di un conto su cui quel denaro non era mai entrato, mentre c'era. Chi
                    // legge le rettifiche non dipende da questa data: l'estratto conto per
                    // anagrafica ordina per `created_at` e `scrittura_id`, il sigillo si ancora
                    // a `created_at`. Punto aperto di Coda 93, chiuso dalla revisione della
                    // beta.24.
                    'data_competenza'    => now(),
                    'causale'            => 'Storno: ' . $scr->causale,
                    'tipo_movimento'     => 'rettifica',
                    'stato'              => 'registrata',
                    'note'               => 'Annullamento prot. ' . $scr->numero_protocollo,
                ]);

                // 4. Invertiamo le righe (Partita Doppia): lo specchio, sugli stessi conti
                foreach ($scr->righe as $riga) {
                    $rettifica->righe()->create([
                        'conto_contabile_id' => $riga->conto_contabile_id,
                        'cassa_id'           => $riga->cassa_id,
                        'anagrafica_id'      => $riga->anagrafica_id,
                        'rata_id'            => $riga->rata_id,
                        'immobile_id'        => $riga->immobile_id,
                        'tipo_riga'          => $riga->tipo_riga === 'dare' ? 'avere' : 'dare',
                        'importo'            => $riga->importo,
                    ]);
                }

                // 5. Ripristiniamo il debito/credito sulle quote (Metodo Audit-Safe)
                foreach ($scr->quotePagate as $quota) {

                    // Sfruttiamo il caricamento Eager per leggere il dato storico dalla pivot
                    $importoStorico = $quota->pivot->importo_pagato;

                    // Attach() con importo negativo per stornare
                    $quota->pagamenti()->attach($rettifica->id, [
                        'importo_pagato' => -$importoStorico,
                        'data_pagamento' => $scr->data_competenza,
                    ]);

                    // Ricalcolo dello stato leggendo la nuova pivot aggiornata
                    $quota->ricalcolaStato();
                }

                // La rettifica è il mirror esatto dell'originale: se l'originale
                // quadrava deve quadrare anche lei. Verificarlo qui intercetta
                // un'inversione incompleta prima che entri nel giornale.
                DoubleEntryValidator::validateOrFail($rettifica->id);

                // 6. Sigilliamo la scrittura originale
                $scr->update(['stato' => 'annullata']);
                $stornate->push($scr);
            }

            // 7. Il credito riportato restituito, se la sua rata è ormai a giornale (vedi il docblock della classe)
            $this->riportaFraICrediti($stornate, $condominio);
        });
    }

    /**
     * La scrittura da stornare, le sue figlie e le rettifiche del riallineamento dei crediti figlie delle figlie: rettifiche la cui
     * causale comincia con il marcatore (`RiallineaCreditiService::MARCATORE`) e il cui padre è una compensazione, come le
     * riconosce l'estratto conto.
     *
     * @return Collection<int, ScritturaContabile>
     */
    private function famiglia(ScritturaContabile $scrittura): Collection
    {
        $figlie = $scrittura->figlie;
        $compensazioni = $figlie->filter(fn (ScritturaContabile $f) => ($f->tipo_movimento instanceof \BackedEnum ? $f->tipo_movimento->value : $f->tipo_movimento) === 'storno_credito');
        $riallineamenti = $compensazioni->isEmpty() ? collect() : ScritturaContabile::whereIn('scrittura_padre_id', $compensazioni->pluck('id'))
            ->where('tipo_movimento', TipoMovimentoContabile::RETTIFICA->value)
            ->where('causale', 'like', RiallineaCreditiService::MARCATORE . '%')
            ->with(['righe', 'quotePagate'])
            ->orderBy('id')
            ->get();

        return collect([$scrittura])->merge($figlie)->merge($riallineamenti);
    }

    /**
     * Per ogni quota a credito (persona, rata, unità) a cui le scritture stornate hanno restituito del credito su «Passate
     * gestioni» — il netto delle loro righe su quel conto, in DARE — e la cui rata è ormai a giornale: una scrittura figlia
     * dell'emissione porta quel credito fra i crediti verso condomini, AVERE «Crediti verso condomini» a nome della persona e DARE
     * «Passate gestioni».
     * - La quota ha la sua scrittura: la figlia porta il credito restituito.
     * - La quota è senza scrittura in una rata a giornale (letto dal giornale, `Rata::aGiornale`, non dallo stato): il suo credito
     *   era uscito tutto prima dell'emissione, o una versione di prima l'ha lasciata fuori. Se ha il dettaglio del calcolo, la
     *   figlia la scrive come l'emissione (`RataQuote::scritturaDiEmissione`, dopo lo specchio) e la lega, figlia dell'emissione
     *   della rata; senza il legame un nuovo uso del credito lo preleverebbe di nuovo da «Passate gestioni» (rilievi K4 e LDA-1).
     * Lo specchio basta con la rata ancora in bozza (il credito è tornato dove la quota lo cerca, e l'emissione lo porterà) e con
     * una quota ferma della 1.7.x (`RataQuote::fermaSenzaDettaglio`, decisione 76.3 ritoccata): resta fuori anche dopo lo storno,
     * con il credito fra le «Passate gestioni», come la tengono l'emissione e il comando.
     * La figlia ha la data dello storno, non quella dell'emissione: i periodi già passati non cambiano.
     *
     * @param Collection<int, ScritturaContabile> $stornate
     */
    private function riportaFraICrediti(Collection $stornate, Condominio $condominio): void
    {
        $passate = (int) ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'passate_gestioni')->whereNull('deleted_at')->value('id');
        if ($passate === 0 || $stornate->isEmpty()) {
            return;
        }

        $netto = [];
        foreach ($stornate as $scr) {
            foreach ($scr->righe as $riga) {
                if ((int) $riga->conto_contabile_id !== $passate || ! $riga->anagrafica_id || ! $riga->rata_id) {
                    continue;
                }
                $chiave = $riga->rata_id . '|' . $riga->anagrafica_id . '|' . ($riga->immobile_id ?? '');
                $netto[$chiave] = ($netto[$chiave] ?? 0) + ($riga->tipo_riga === 'dare' ? (int) $riga->importo : -(int) $riga->importo);
            }
        }

        $crediti = null;
        foreach ($netto as $chiave => $restituito) {
            if ($restituito <= 0) {
                continue;
            }
            [$rataId, $anagraficaId, $immobileId] = explode('|', $chiave);
            $quote = RataQuote::where('rata_id', (int) $rataId)->where('anagrafica_id', (int) $anagraficaId)
                ->when($immobileId === '', fn ($q) => $q->whereNull('immobile_id'), fn ($q) => $q->where('immobile_id', (int) $immobileId))
                ->where('importo', '<', 0)
                ->orderBy('id')
                ->get();
            $quota = $quote->first(fn (RataQuote $q) => $q->scrittura_contabile_id !== null);
            if ($quota === null) {
                $this->scriviLaQuotaRimastaFuori($quote->first(), (int) $rataId, $passate, $condominio);

                continue;
            }

            $crediti ??= ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'crediti_condomini')->firstOrFail();
            $emissione = ScritturaContabile::findOrFail($quota->scrittura_contabile_id);
            $figlia = ScritturaContabile::create([
                'condominio_id'      => $condominio->id,
                'esercizio_id'       => $emissione->esercizio_id,
                'gestione_id'        => $emissione->gestione_id,
                'scrittura_padre_id' => $emissione->id,
                'data_registrazione' => now(),
                'data_competenza'    => now(),
                'causale'            => 'Credito riportato restituito da uno storno — ' . $emissione->causale,
                'tipo_movimento'     => 'emissione_rata',
                'stato'              => 'registrata',
                'note'               => 'Il credito riportato usato prima dell\'emissione della rata è tornato con lo storno: si porta fra i crediti verso condomini, come avrebbe fatto l\'emissione.',
            ]);
            $figlia->righe()->create([
                'conto_contabile_id' => $crediti->id,
                'anagrafica_id'      => (int) $anagraficaId,
                'rata_id'            => (int) $rataId,
                'immobile_id'        => $immobileId === '' ? null : (int) $immobileId,
                'tipo_riga'          => 'avere',
                'importo'            => $restituito,
                'note'               => 'Credito riportato restituito dallo storno',
            ]);
            $figlia->righe()->create([
                'conto_contabile_id' => $passate,
                'tipo_riga'          => 'dare',
                'importo'            => $restituito,
                'note'               => 'Credito riportato restituito dallo storno',
            ]);
            DoubleEntryValidator::validateOrFail($figlia->id);
        }
    }

    /**
     * La quota a credito senza scrittura in una rata già a giornale, a cui lo storno ha restituito credito su «Passate gestioni»
     * (rilievi K4 e LDA-1): la figlia dell'emissione della rata la scrive con le righe dell'emissione, calcolate dopo lo specchio
     * (il credito restituito non conta più come uscito), e la lega. Con la rata ancora in bozza non fa niente: la porterà
     * l'emissione. Non fa niente neanche per una quota senza il dettaglio del calcolo (piani della 1.7.x): l'emissione la ferma
     * (decisione 76.3 ritoccata), e scriverla qui vorrebbe dire indovinare spesa 0 e tutto l'importo come riporto (giro stretto
     * sulle correzioni della Fase 1-bis, G1, LT-1 e GS-1).
     */
    private function scriviLaQuotaRimastaFuori(?RataQuote $quota, int $rataId, int $passate, Condominio $condominio): void
    {
        if ($quota === null || $quota->scrittura_contabile_id !== null || $quota->fermaSenzaDettaglio()) {
            return;
        }
        // L'emissione della rata: la scrittura di un'altra sua quota, risalendo al padre se quella è a sua volta una figlia.
        $legate = RataQuote::where('rata_id', $rataId)->whereNotNull('scrittura_contabile_id')->orderBy('id')->pluck('scrittura_contabile_id');
        $emissione = $legate->isEmpty() ? null : ScritturaContabile::whereIn('id', $legate)->where('tipo_movimento', 'emissione_rata')
            ->orderByRaw('scrittura_padre_id IS NOT NULL')->orderBy('id')->first();
        if ($emissione !== null && $emissione->scrittura_padre_id !== null) {
            $emissione = ScritturaContabile::where('id', $emissione->scrittura_padre_id)->where('tipo_movimento', 'emissione_rata')->first() ?? $emissione;
        }
        if ($emissione === null) {
            return; // la rata è ancora in bozza
        }

        $calcolo = RataQuote::scritturaDiEmissione([$quota], $passate)[(int) $quota->id];
        if ($calcolo['righe'] === []) {
            return;
        }

        $crediti = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'crediti_condomini')->firstOrFail();
        $gestioneRate = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'gestione_rate')->first();
        $descrizione = (string) $quota->rata?->descrizione;
        $figlia = ScritturaContabile::create([
            'condominio_id'      => $condominio->id,
            'esercizio_id'       => $emissione->esercizio_id,
            'gestione_id'        => $emissione->gestione_id,
            'scrittura_padre_id' => $emissione->id,
            'data_registrazione' => now(),
            'data_competenza'    => now(),
            'causale'            => 'Credito riportato restituito da uno storno — ' . $emissione->causale,
            'tipo_movimento'     => 'emissione_rata',
            'stato'              => 'registrata',
            'note'               => 'La quota era rimasta fuori dall\'emissione della rata: il credito tornato con lo storno va a giornale con lei, come l\'avrebbe scritta l\'emissione.',
        ]);
        foreach ($calcolo['righe'] as $i => [$verso, $importoRiga]) {
            $figlia->righe()->create([
                'conto_contabile_id' => $crediti->id,
                'anagrafica_id'      => $quota->anagrafica_id,
                'rata_id'            => $rataId,
                'immobile_id'        => $quota->immobile_id,
                'tipo_riga'          => $verso,
                'importo'            => $importoRiga,
                'note'               => $quota->notaDiEmissione($i, $verso, $descrizione),
            ]);
        }
        foreach ([[$gestioneRate?->id, $calcolo['gestione'], 'Totale emissione '], [$passate, $calcolo['pregresso'], 'Riporto esercizi precedenti — ']] as [$conto, $totale, $nota]) {
            if ($totale === 0) {
                continue;
            }
            if ($conto === null) {
                throw new \RuntimeException('Manca il conto «Gestione rate» del condominio.');
            }
            $figlia->righe()->create([
                'conto_contabile_id' => $conto,
                'tipo_riga'          => $totale > 0 ? 'avere' : 'dare',
                'importo'            => abs($totale),
                'note'               => $nota . $descrizione,
            ]);
        }
        DoubleEntryValidator::validateOrFail($figlia->id);
        $quota->update(['scrittura_contabile_id' => $figlia->id]);
    }
}
