<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Anagrafica;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RataQuote;
use App\Models\Saldo;
use App\Helpers\MoneyHelper;
use App\Models\Gestionale\Cassa;
use App\Services\Gestionale\CreditoService;
use App\Services\Gestionale\EstrattoContoDellaPersona;
use App\Services\Gestionale\SaldoCassaService;
use App\Traits\HasEsercizio;
use App\Services\PDF\PdfService;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Illuminate\Http\Request;

class EstrattoContoAnagraficaController extends Controller
{
    use HasEsercizio;

    /**
     * Genera l'Estratto Conto finanziario di una singola Anagrafica (vista Inertia).
     * Costruisce un "Ledger" (Libro Mastro) cronologico in Dare/Avere con saldo progressivo
     * "Waterfall" al centesimo, basato sul pattern Double-Entry.
     */
    public function show(Request $request, Condominio $condominio, Anagrafica $anagrafica)
    {
        $esercizio = $this->getEsercizioCorrente($condominio);

        $anagrafica->load(['immobili' => function($q) use ($condominio) {
            $q->where('condominio_id', $condominio->id);
        }]);

        [$timeline, $stats] = $this->buildLedger($condominio, $esercizio, $anagrafica);

        return Inertia::render('gestionale/pianiRate/EstrattoContoAnagrafica', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'anagrafica' => $anagrafica,
            'timeline'   => $timeline,
            'stats'      => $stats,
            'rimborso'   => $this->datiRimborso($condominio, $anagrafica),
            // B2, S7: la solidarietà dell'art. 63 co. 4 verso chi è entrato — una nota, non una quota.
            'solidarieta' => app(\App\Services\Subentro\NotaSolidarieta::class)->per($condominio, $anagrafica),
            // Coda 167, beta.40: i versamenti fatti per il debito di un altro — un riquadro, non righe del libro.
            'versamenti_per_altri' => app(\App\Services\Gestionale\VersamentiPerContoDiAltri::class)->per($condominio, $anagrafica),
            // 1.12.0-beta.4: le lettere e le comunicazioni a questa persona in questo condominio, con il residuo di oggi delle stesse rate.
            'lettere' => app(\App\Services\Gestionale\Lettere\RegistroLettere::class)->perPersona($condominio, $anagrafica),
            // Coda 533: nella scheda «Corrispondenza», con le lettere, le ricevute, gli avvisi di compensazione e «non serve comunicarla»; e in testa
            // l'ultima lettera, letta come la colonna di «Rate scadute».
            'documenti' => app(\App\Services\Gestionale\Lettere\CorrispondenzaDellaPersona::class)->documenti($condominio, $anagrafica),
            'ultima_lettera' => app(\App\Services\Gestionale\Lettere\CorrispondenzaDellaPersona::class)->ultimaLettera($condominio, $anagrafica),
            // Il giorno dell'amministratore, per le date della spedizione (non dopo oggi).
            'oggi' => \App\Helpers\DateHelper::oggiUtente(),
            // Gli accordi di rientro della persona in questo condominio, anche chiusi o riusciti, con il segno e i versati oltre il capitale.
            'accordi_rientro' => $this->accordiRientro($condominio, $anagrafica),
            // Gli addebiti a questa persona sola in questo condominio, con quello che si può ancora annullare; «Nuovo addebito» parte da qui.
            'addebiti' => app(\App\Services\Gestionale\Addebiti\AddebitiIndividuali::class)->dellaPersona((int) $condominio->id, (int) $anagrafica->id),
            // I fascicoli per il legale preparati per questa persona, con l'indice e il prospetto di quel giorno.
            'fascicoli' => \App\Services\Gestionale\Debiti\FascicoloLegale::preparati($condominio, $anagrafica),
        ]);
    }

    /**
     * Gli accordi di rientro della persona, dal più recente, con le cifre di oggi: il segno, «fino al» passato, la parte versata oltre il
     * capitale riconosciuto (che è un credito della persona). Ci sono anche quando la persona non ha più rate scadute e non è nell'elenco.
     *
     * @return list<array<string, mixed>>
     */
    private function accordiRientro(Condominio $condominio, Anagrafica $anagrafica): array
    {
        $accordi = \App\Models\Gestionale\AccordoRientro::with(['rate', 'scadenze'])->where('condominio_id', $condominio->id)->where('anagrafica_id', $anagrafica->id)
            ->orderByDesc('data_accordo')->orderByDesc('id')->get();
        if ($accordi->isEmpty()) {
            return [];
        }
        $oggi = \App\Helpers\DateHelper::oggiUtente();
        $lettura = app(\App\Services\Gestionale\Debiti\LetturaDebiti::class)->perPersona((int) $condominio->id, (int) $anagrafica->id)['rate'];
        $inCorso = $accordi->keyBy('id');

        return array_map(fn (array $a) => $a + ['in_corso' => $inCorso[$a['id']]->inCorsoIl($oggi)],
            app(\App\Services\Gestionale\Debiti\AccordiRientro::class)->segni($accordi, $lettura, $oggi)['accordi']);
    }

    /**
     * Ciò che il modulo «Rimborsa il credito» deve sapere (B2, S6): le quote a credito della persona in
     * questo condominio, ognuna con la sua origine (da saldi o da eccedenza: la contropartita cambia), e le
     * casse da cui il denaro può uscire, col saldo. Finché nessun piano ha assorbito la coppia del
     * passaggio, il credito di chi esce vive in `saldi` e qui non c'è una quota: il box lo dice.
     *
     * @return array{quote: list<array<string,mixed>>, casse: list<array<string,mixed>>, credito_in_saldi: int, rate_da_pagare: int}
     */
    private function datiRimborso(Condominio $condominio, Anagrafica $anagrafica): array
    {
        $contoPassate = \App\Models\Gestionale\ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'passate_gestioni')->whereNull('deleted_at')->value('id');
        $quote = RataQuote::whereHas('rata.pianoRate', fn ($p) => $p->where('condominio_id', $condominio->id))
            ->where('anagrafica_id', $anagrafica->id)
            ->where(fn ($q) => $q->whereRaw('importo_pagato > importo')->orWhere('importo', '<', 0))
            ->with(['rata.pianoRate.gestione:id,nome', 'immobile:id,nome,interno'])
            ->get()
            ->filter(fn (RataQuote $q) => $q->credito_disponibile > 0)
            ->map(fn (RataQuote $q) => [
                'id'                   => $q->id,
                'rata'                 => $q->rata?->numero_rata,
                'piano'                => $q->rata?->pianoRate?->nome,
                'gestione'             => $q->rata?->pianoRate?->gestione?->nome,
                'immobile'             => $q->immobile ? $q->immobile->nome . ($q->immobile->interno ? " (Int. {$q->immobile->interno})" : '') : null,
                // Da dove viene il credito, letto dalle componenti e non dal segno (rilievo K2 della Fase 1-bis): un riporto da un
                // esercizio precedente («saldi»), la spesa negativa delle entrate dell'anno («entrate», una voce di tipo «entrata»),
                // tutte e due («misto»), o un versamento in eccesso («eccedenza»).
                'origine'              => self::origineDelCredito($q),
                // Da dove esce in contabilità (decisione 71): un credito riportato con la quota non ancora a giornale (la rata in
                // bozza, o una quota rimasta fuori dall'emissione) sta fra le «Passate gestioni»; quello già a giornale con la sua
                // quota, quello delle entrate e l'eccedenza fra i crediti verso condomini (`RataQuote::creditoSuPassate`). Con una
                // quota mista, «passate_gestioni» finché ne resta una parte lì.
                'conto'                => $q->creditoSuPassate($contoPassate === null ? null : (int) $contoPassate) > 0 ? 'passate_gestioni' : 'crediti_condomini',
                'a_giornale'           => $q->scrittura_contabile_id !== null,
                'credito_cents'        => (int) $q->credito_disponibile,
                'credito_formattato'   => MoneyHelper::format((int) $q->credito_disponibile),
                // Come stringa italiana: è il valore che il campo importo propone, e la conversione in
                // centesimi avviene una volta sola, nella Request.
                'credito_euro'         => number_format($q->credito_disponibile / 100, 2, ',', '.'),
            ])->values()->all();

        $saldoCassa = app(SaldoCassaService::class);
        $casse = Cassa::where('condominio_id', $condominio->id)
            ->whereIn('tipo', ['banca', 'contanti'])
            ->where('attiva', true)
            ->orderBy('nome')
            ->get()
            ->map(fn (Cassa $c) => [
                'id'               => $c->id,
                'nome'             => $c->nome,
                'tipo'             => $c->tipo,
                'saldo_cents'      => $saldoCassa->saldoDisponibile($c),
                'saldo_formattato' => MoneyHelper::format($saldoCassa->saldoDisponibile($c)),
            ])->values()->all();

        // La coppia del passaggio non ancora assorbita: credito vero, ma ancora in `saldi`. Dalla 1.11.0-beta.44 al netto per gestione e
        // unità, come la assorbirà il piano: con più coppie sulla stessa unità (gli eredi, i nudi) una persona può averne una a credito e
        // una a debito, e contare solo la prima gonfiava il credito. E senza le righe del defunto nelle successioni con l'arretrato agli
        // eredi (rilievo X10 della Fase 1-bis), né le righe dei passaggi precedenti che quell'arretrato ha letto (le sue fonti, G4 del giro
        // sulle correzioni): lì la posizione del defunto si chiude a zero per costruzione, e quel «credito» è già passato agli eredi. Con
        // l'arretrato a suo nome la coppia resta un credito vero; una successione annullata torna a contare tutto.
        $successioniDelDefunto = \App\Models\Gestionale\Subentro::where('condominio_id', $condominio->id)->where('tipo_passaggio', 'successione')
            ->where('anagrafica_uscente_id', $anagrafica->id)->get();
        $successioniChiuse = $successioniDelDefunto->filter(fn ($s) => $s->arretratoAgliEredi());
        // 1.11.0-beta.53 (Coda 244): anche i saldi del Wallet legati a un passaggio, lo stesso credito della coppia. Non quelli del defunto
        // legati alla sua successione con l'arretrato agli eredi (la scelta, anche quando l'arretrato non ha scritto righe): pareggiano la
        // quota che il piano calcola ancora per lui, e non diventano una quota da rimborsare.
        $legateAlDefunto = $successioniDelDefunto->filter(fn ($s) => ($s->capo()->registro['arretrato']['scelta'] ?? null) === \App\Models\Gestionale\Subentro::ARRETRATO_AGLI_EREDI)
            ->map(fn ($s) => (int) $s->capo()->id)->unique()->values()->all();
        $creditoInSaldi = (int) $anagrafica->saldi()
            ->where('condominio_id', $condominio->id)
            ->where('is_applicato', false)
            ->where(fn ($q) => $q->where(fn ($c) => $c->whereNotNull('subentro_id')->whereNotIn('subentro_id', $successioniChiuse->pluck('id')->all()))
                ->orWhere(fn ($l) => $l->whereNotNull('passaggio_id')->whereNotIn('passaggio_id', $legateAlDefunto)))
            ->whereNotIn('id', $successioniChiuse->flatMap(fn ($s) => $s->fontiDellArretrato())->all())
            ->get(['gestione_id', 'immobile_id', 'saldo_iniziale'])
            ->groupBy(fn ($r) => $r->gestione_id . '|' . $r->immobile_id)
            ->map(fn ($g) => (int) $g->sum('saldo_iniziale'))
            ->filter(fn (int $netto) => $netto < 0)
            ->sum();

        // Coda 227: le rate che la persona ha ancora da pagare in questo condominio (i residui aperti, la lettura che usa anche l'annullamento di un
        // passaggio): il credito nei saldi può andare prima su quelle, con «Compensa», e la frase del riquadro lo dice (lo decide l'amministratore: ci sono
        // anche le rate emesse non ancora scadute).
        $rateDaPagare = (int) collect(app(\App\Services\Gestionale\Debiti\LetturaDebiti::class)->perPersona((int) $condominio->id, (int) $anagrafica->id)['rate'])
            ->sum(fn ($r) => max(0, (int) $r['residuo']));

        return ['quote' => $quote, 'casse' => $casse, 'credito_in_saldi' => abs($creditoInSaldi), 'rate_da_pagare' => $rateDaPagare];
    }

    /** @return 'saldi'|'entrate'|'misto'|'eccedenza' */
    private static function origineDelCredito(RataQuote $q): string
    {
        if ((int) $q->importo >= 0) {
            return 'eccedenza';
        }
        ['spesa' => $spesa, 'riporto' => $riporto] = $q->componenti();

        return match (true) {
            $riporto < 0 && $spesa < 0 => 'misto',
            $riporto < 0               => 'saldi',
            default                    => 'entrate',
        };
    }

    /**
     * Genera il PDF dell'Estratto Conto di una singola Anagrafica, per l'esercizio corrente: la stampa del servizio ({@see EstrattoContoDellaPersona::pdf()}),
     * la stessa che il fascicolo per il legale mette nell'archivio per gli esercizi dei piani delle sue rate.
     * Aperto in una nuova scheda dal frontend tramite window.open().
     */
    public function print(Request $request, Condominio $condominio, Anagrafica $anagrafica, EstrattoContoDellaPersona $estratto)
    {
        $esercizio = $this->getEsercizioCorrente($condominio);

        // ⚠️ `Output(..., 'I')` scrive il PDF sull'output buffer e torna vuota: mPDF mandava da sé,
        // con `header()` ed `echo`, intestazioni e corpo, e nella risposta Laravel non passava nulla —
        // nessun test la guardava (beta.28, Coda 151; stessa cura del Libro Giornale e delle stampe
        // del piano rate): il servizio rende il contenuto. Il nome era «EC_Nome_Cognome_Esercizio anno 2026.pdf», spazi compresi;
        // ora ha la forma della casa: libro, condominio, anno, e l'anagrafica in coda.
        $nomeFile = PdfService::nomeFile('estratto-conto', $condominio->nome, $esercizio->data_inizio?->format('Y'), Str::slug($anagrafica->nome));

        return response($estratto->pdf($condominio, $esercizio, $anagrafica))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$nomeFile.'"');
    }

    /**
     * Il calcolo dell'estratto conto per la pagina, dal servizio ({@see EstrattoContoDellaPersona::ledger()}): resta qui con il suo nome perché `show()`
     * lo chiama così e alcune prove lo leggono così.
     */
    private function buildLedger(Condominio $condominio, Esercizio $esercizio, Anagrafica $anagrafica): array
    {
        return app(EstrattoContoDellaPersona::class)->ledger($condominio, $esercizio, $anagrafica);
    }
}
