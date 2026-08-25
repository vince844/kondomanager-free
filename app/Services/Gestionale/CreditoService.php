<?php

namespace App\Services\Gestionale;

use App\Helpers\MoneyHelper;
use Carbon\Carbon;
use App\Models\Gestionale\RataQuote;
use Illuminate\Support\Collection;

class CreditoService
{
    /**
     * Memoria della richiesta per `creditoRataZeroSpendibile()`, indicizzata «piano:anagrafica».
     *
     * È una proprietà e non una chiave del contenitore perché il servizio è registrato
     * `scoped` (vedi `AppServiceProvider`): `app()->instance()` sopravvive al **processo**, non
     * alla richiesta, e sotto un runtime persistente il valore si congelerebbe — cioè tornerebbe
     * esattamente lo snapshot che questo metodo esiste per evitare.
     *
     * @var array<string,int>
     */
    private array $memoRataZero = [];

    /**
     * Credito disponibile di una singola anagrafica in un condominio,
     * raggruppato per gestione. Copre entrambe le forme di credito: saldo
     * iniziale a importo negativo e quote strapagate.
     *
     * @return array{totale_cents:int, totale_formatted:string, per_gestione:array<int,array{gestione_id:int|null,gestione_nome:string,importo_cents:int,importo_formatted:string}>}
     */
    public function perAnagrafica(int $condominioId, int $anagraficaId): array
    {
        $quote = $this->queryCreditoBase($condominioId)
            ->where('anagrafica_id', $anagraficaId)
            ->get();

        $perGestione = $quote
            ->groupBy(fn($q) => $q->rata->pianoRate->gestione_id ?? 0)
            ->map(function ($gruppo) {
                $primo = $gruppo->first();
                $importoCents = $gruppo->sum(fn($q) => $q->credito_disponibile);

                return [
                    'gestione_id'       => $primo->rata->pianoRate->gestione_id ?? null,
                    'gestione_nome'     => $primo->rata->pianoRate->gestione->nome ?? 'Generica',
                    'importo_cents'     => $importoCents,
                    'importo_formatted' => MoneyHelper::format($importoCents),
                ];
            })
            ->filter(fn($g) => $g['importo_cents'] > 0)
            ->values()
            ->toArray();

        $totaleCents = array_sum(array_column($perGestione, 'importo_cents'));

        return [
            'totale_cents'     => $totaleCents,
            'totale_formatted' => MoneyHelper::format($totaleCents),
            'per_gestione'     => $perGestione,
            'compensabile'     => $this->compensabile(
                $quote,
                $this->debitiAperti($condominioId, [$anagraficaId])->get($anagraficaId, collect()),
                $totaleCents,
            ),
        ];
    }



    /**
     * L'aritmetica del credito spendibile di un insieme di quote, in centesimi.
     *
     * Sta qui, statica e minuscola, perché ha **due** chiamanti che partono da collezioni
     * diverse: questa classe, che le carica per una singola anagrafica, e
     * `SyncScadenziarioWithPianoRate`, che le ha già in mano per tutte insieme e non deve
     * rifare una query a testa. La formula però dev'essere una sola: due copie della stessa
     * regola divergono alla prima modifica.
     *
     * @param  \Illuminate\Support\Collection<int,RataQuote>  $quote
     */
    public static function nettoSpendibile(Collection $quote): int
    {
        return max(0,
            (int) $quote->sum(fn (RataQuote $q) => $q->credito_disponibile)
            - (int) $quote->sum(fn (RataQuote $q) => $q->importo_residuo)
        );
    }

    /**
     * Il credito della Rata 0 di un piano ancora spendibile da un'anagrafica, in centesimi.
     *
     * È il numero che il condòmino legge nel «Salvadanaio» del portale. Vive qui e non nel
     * listener che scrive l'evento perché **quello scrive uno snapshot**: il listener gira solo
     * all'approvazione del piano e una guardia gli impedisce di riscrivere un evento già
     * creato, quindi il credito consumato dopo non lo aggiorna nessuno. Chi mostra il numero
     * deve calcolarlo al momento — vedi `EventoResource`.
     *
     * Al netto del debito che sta sulla stessa Rata 0: `credito_disponibile` è clampato a zero
     * sulle quote a debito, quindi sommarlo da solo farebbe crescere il numero su una Rata 0
     * mista. Il Salvadanaio è una dichiarazione di posizione, non l'elenco di ciò che il motore
     * riesce a prelevare.
     *
     * Memoizzato nel contenitore, che vive quanto la richiesta: la Resource gira per ogni
     * evento e un cruscotto ne mostra parecchi.
     */
    public function creditoRataZeroSpendibile(int $pianoRateId, int $anagraficaId): int
    {
        $chiave = "{$pianoRateId}:{$anagraficaId}";

        if (array_key_exists($chiave, $this->memoRataZero)) {
            return $this->memoRataZero[$chiave];
        }

        $quote = RataQuote::whereHas('rata', fn ($r) => $r
                ->where('piano_rate_id', $pianoRateId)
                ->where('numero_rata', 0))
            ->where('anagrafica_id', $anagraficaId)
            ->get();

        $valore = self::nettoSpendibile($quote);

        return $this->memoRataZero[$chiave] = $valore;
    }

    /**
     * Quale debito quel credito copre davvero — non quanto credito c'è.
     *
     * Serve perché il credito, da solo, non è azionabile. Il motore lo preleva dalle quote di
     * UNA rata alla volta e verifica la capienza su quella rata
     * (StoreIncassoRateAction:265-271, tetto a :308-312): un totale per persona non basta a
     * costruire un salvataggio che vada a buon fine. Qui il totale viene quindi spezzato in
     * `origini` — da dove si preleva — e `rate_coperte` — cosa si paga.
     *
     * ⚠️ **`origini[]['rata_id']` è una chiave di `rate`, non di `rate_quote`.** Il campo
     * omonimo dentro `dettaglio_pagamenti` del salvataggio è invece un id di **quota**
     * (`StoreIncassoRateAction:263` fa `RataQuote::findOrFail`). Due chiavi con lo stesso
     * nome su due tabelle diverse: passare l'una dove serve l'altra o solleva
     * `ModelNotFoundException`, o — peggio — trova per caso una quota esistente che
     * appartiene a un'altra rata e magari a un'altra persona, e preleva credito da lì.
     * `origini` serve a **dire** da dove viene il credito, non a costruire il payload: il
     * payload lo compone la pagina di incasso dalle righe che ha in mano.
     *
     * Due regole di perimetro, decise e non dedotte dal codice:
     *
     * - **Solo rate emesse come BERSAGLIO.** Suggerire di compensare una rata ancora in bozza
     *   proporrebbe all'amministratore un debito che per il condòmino non esiste ancora. Le
     *   bozze però entrano lo stesso nel calcolo del **netto** della loro rata: scartarle del
     *   tutto faceva uscire una rata in bozza come «solo credito», perché il suo debito
     *   spariva per strada — e la Rata 0, dove il credito da saldi iniziali vive quasi sempre,
     *   nasce proprio in bozza. Il vincolo riguarda cosa si propone di pagare, non come si
     *   misura ciò che una rata offre.
     * - **Nessun attraversamento fra gestioni.** Il motore lo consente e lo traccia, ma
     *   pretende una spunta esplicita dell'amministratore (IncassoRateNew.vue:746-760): un
     *   suggerimento automatico non può darla per acquisita. Il credito sull'altra gestione
     *   resta nel totale e semplicemente non entra nel consiglio.
     *
     * I debiti arrivano già caricati e non vengono cercati qui: il widget di dashboard chiama
     * questo calcolo per ogni condòmino a credito, e una query per persona sarebbe un N+1 su
     * una pagina che si apre a ogni accesso.
     *
     * @param  \Illuminate\Support\Collection<int,RataQuote>  $quoteCredito
     * @param  \Illuminate\Support\Collection<int,array<string,mixed>>  $debitoPerRata
     * @return array{importo_cents:int,importo_formatted:string,residuo_credito_cents:int,residuo_credito_formatted:string,rate_coperte:array<int,array<string,mixed>>,origini:array<int,array<string,mixed>>}
     */
    private function compensabile(
        Collection $quoteCredito,
        Collection $debitoPerRata,
        int $totaleCreditoCents,
    ): array {
        // Credito e debito vanno guardati INSIEME, rata per rata, perché è così che la
        // pagina di incasso li mostra: il server aggrega le quote per rata ed espone il
        // residuo NETTO. Da quel netto discende cosa lo schermo offre davvero, e il consiglio
        // non può promettere niente di più:
        //
        //   netto < 0  → riga a credito: si può usare |netto|
        //   netto = 0  → riga «saldo misto»: il pulsante offre tutto il credito interno
        //   netto > 0  → riga a debito: il credito interno non è offerto da nessuna parte
        //
        // E il credito di una rata non può mai coprire il debito di QUELLA STESSA rata: per
        // farlo servirebbe una riga di payload positiva su di lei, e la casella dell'importo
        // compare solo dove il netto è positivo — cioè mai, nel caso che conta. Un consiglio
        // che la nominasse come bersaglio sarebbe ineseguibile.
        $perRata = [];

        foreach ($quoteCredito->groupBy('rata_id') as $rataId => $gruppo) {
            $perRata[(int) $rataId] = [
                'rata_id'       => (int) $rataId,
                'gestione_id'   => $gruppo->first()->rata->pianoRate->gestione_id ?? null,
                'data_scadenza' => optional($gruppo->first()->rata)->data_scadenza,
                'credito_cents' => (int) $gruppo->sum(fn (RataQuote $q) => $q->credito_disponibile),
                'debito_cents'  => 0,
                'debito'        => null,
            ];
        }

        foreach ($debitoPerRata as $debito) {
            $id = $debito['rata_id'];
            $perRata[$id] ??= [
                'rata_id'       => $id,
                'gestione_id'   => $debito['gestione_id'],
                'data_scadenza' => $debito['data_scadenza'],
                'credito_cents' => 0,
                'debito_cents'  => 0,
                'debito'        => null,
            ];
            $perRata[$id]['debito_cents'] = $debito['residuo_cents'];
            $perRata[$id]['debito'] = $debito;
        }

        $fonti = [];
        $bersagli = [];

        foreach ($perRata as $r) {
            $netto = $r['debito_cents'] - $r['credito_cents'];

            $offerto = $netto < 0 ? -$netto : ($netto === 0 ? $r['credito_cents'] : 0);
            if ($offerto > 0) {
                $fonti[] = [
                    'rata_id'       => $r['rata_id'],
                    'gestione_id'   => $r['gestione_id'],
                    'data_scadenza' => $r['data_scadenza'],
                    'credito_cents' => $offerto,
                ];
            }

            // Bersaglio solo se la rata è stata emessa: proporre di compensare una rata che
            // il condòmino non ha mai ricevuto sarebbe un consiglio su un debito che per lui
            // non esiste ancora.
            if ($netto > 0 && $r['debito'] !== null && ($r['debito']['emessa'] ?? true)) {
                $bersagli[] = ['residuo_netto' => $netto] + $r['debito'];
            }
        }

        $creditoPerRata = collect($fonti)->sortBy('data_scadenza')->values();
        $debitoPerRata  = collect($bersagli)->sortBy('data_scadenza')->values();

        $rateCoperte = [];
        $origini     = [];
        $totaleCompensabile = 0;

        foreach ($creditoPerRata->groupBy('gestione_id') as $gestioneId => $origineGestione) {
            $debiti = $debitoPerRata->where('gestione_id', $gestioneId)->values();
            if ($debiti->isEmpty()) {
                continue;
            }

            // Le rate più vicine a scadere si coprono per prime: è l'ordine che
            // l'amministratore si aspetta e l'unico difendibile senza chiederglielo.
            $residuiCredito = $origineGestione->map(fn ($o) => $o)->values()->all();
            $indiceOrigine  = 0;

            foreach ($debiti as $debito) {
                $daCoprire = $debito['residuo_netto'];

                $saltate = [];

                while ($daCoprire > 0 && $indiceOrigine < count($residuiCredito)) {
                    $disponibile = $residuiCredito[$indiceOrigine]['credito_cents'];

                    if ($disponibile <= 0) {
                        $indiceOrigine++;
                        continue;
                    }

                    // Mai la propria rata: vedi la nota sopra.
                    if ($residuiCredito[$indiceOrigine]['rata_id'] === $debito['rata_id']) {
                        $saltate[] = $indiceOrigine;
                        $indiceOrigine++;
                        continue;
                    }

                    $preso = min($disponibile, $daCoprire);
                    $residuiCredito[$indiceOrigine]['credito_cents'] -= $preso;
                    $daCoprire -= $preso;
                    $totaleCompensabile += $preso;

                    $rataOrigine = $residuiCredito[$indiceOrigine]['rata_id'];
                    $origini[$rataOrigine] = [
                        'rata_id'       => $rataOrigine,
                        'credito_cents' => ($origini[$rataOrigine]['credito_cents'] ?? 0) + $preso,
                    ];
                }

                // Le origini saltate perché coincidevano con questo bersaglio tornano
                // disponibili per i bersagli successivi.
                foreach ($saltate as $i) {
                    $indiceOrigine = min($indiceOrigine, $i);
                }

                $coperto = $debito['residuo_netto'] - $daCoprire;
                if ($coperto > 0) {
                    $rateCoperte[] = [
                        'rata_id'           => $debito['rata_id'],
                        'numero_rata'       => $debito['numero_rata'],
                        'descrizione'       => $debito['descrizione'],
                        'data_scadenza'     => $debito['data_scadenza']?->format('Y-m-d'),
                        'gestione_id'       => $debito['gestione_id'],
                        'gestione_nome'     => $debito['gestione_nome'],
                        'residuo_cents'     => $debito['residuo_netto'],
                        'coperto_cents'     => $coperto,
                        'coperto_formatted' => MoneyHelper::format($coperto),
                        'copre_tutto'       => $coperto === $debito['residuo_netto'],
                    ];
                }
            }
        }

        usort($rateCoperte, fn ($a, $b) => ($a['data_scadenza'] ?? '') <=> ($b['data_scadenza'] ?? ''));

        foreach ($origini as &$origine) {
            $origine['credito_formatted'] = MoneyHelper::format($origine['credito_cents']);
        }
        unset($origine);

        // La base è il credito che le schermate mettono davvero a disposizione, non il totale
        // lordo: su una rata con credito 100 e debito 40 il lordo annuncerebbe 40 € di avanzo
        // che sono già assorbiti dal debito della stessa rata, e che nessuna riga offre.
        $creditoOfferto = (int) $creditoPerRata->sum('credito_cents');
        $residuoCredito = $creditoOfferto - $totaleCompensabile;

        $esito = [
            'importo_cents'             => $totaleCompensabile,
            'importo_formatted'         => MoneyHelper::format($totaleCompensabile),
            'residuo_credito_cents'     => $residuoCredito,
            'residuo_credito_formatted' => MoneyHelper::format($residuoCredito),
            'rate_coperte'              => $rateCoperte,
            'origini'                   => array_values($origini),
        ];

        // Serve a `frase()` per distinguere «non c'è niente da coprire» da «c'è, ma su
        // un'altra gestione»: due situazioni opposte che collassavano nella stessa riga, e la
        // seconda faceva chiudere la pagina a chi invece poteva compensare.
        // Il debito che una gestione DIVERSA da quella del credito potrebbe assorbire. Va
        // confrontato con le gestioni che offrono credito, ma **solo se ce ne sono**: quando
        // nessuna rata offre credito quella lista è vuota, `in_array` è sempre falso e ogni
        // debito finiva contato come «altrove» — su un condominio con una gestione sola la
        // frase parlava di un attraversamento che non esiste.
        $gestioniConCredito = $creditoPerRata->pluck('gestione_id')->unique()->all();

        $esito['debito_altrove_cents'] = $gestioniConCredito === []
            ? 0
            : $debitoPerRata
                ->reject(fn ($d) => in_array($d['gestione_id'], $gestioniConCredito, true))
                ->sum('residuo_netto');

        $esito['frase'] = $this->frase($esito);

        return $esito;
    }

    /**
     * La frase che trasforma un importo in un'indicazione: non «ha 200 € di credito» ma
     * «copre la Rata 1 in scadenza il 31/03/2025». Dice anche quando **non** c'è niente da
     * coprire, perché un credito fermo è un'informazione, non un silenzio.
     *
     * Vive qui e non nelle singole superfici perché le superfici sono tre — widget di
     * dashboard, Estratto Conto, emissione — e la stessa frase scritta in tre posti diverge
     * alla prima modifica. È la lezione della beta.35 applicata a un testo invece che a un
     * importo.
     *
     * @param  array<string,mixed>  $comp
     */
    private function frase(array $comp): string
    {
        if ($comp['rate_coperte'] === []) {
            // La rata aperta può esserci eccome, solo su un'altra gestione: il motore la
            // coprirebbe, chiede però la spunta esplicita che il consiglio non può dare per
            // acquisita. Dirgli «niente da coprire» lo manderebbe a chiudere la pagina.
            if (($comp['debito_altrove_cents'] ?? 0) > 0) {
                return 'Le rate aperte sono su un\'altra gestione: la compensazione è possibile, '
                    .'ma va confermata da te perché attraversa ordinaria e straordinaria.';
            }

            return 'Nessuna rata aperta da coprire con questo credito.';
        }

        $prima = $comp['rate_coperte'][0];
        $nome = $prima['descrizione'] ?: 'Rata '.$prima['numero_rata'];
        $scadenza = $prima['data_scadenza']
            ? ' in scadenza il '.Carbon::parse($prima['data_scadenza'])->format('d/m/Y')
            : '';

        $testo = ($prima['copre_tutto'] ? 'Copre ' : 'Copre in parte ').$nome.$scadenza;

        $altre = count($comp['rate_coperte']) - 1;
        if ($altre > 0) {
            $testo .= $altre === 1 ? ' e un\'altra rata' : ' e altre '.$altre.' rate';
        }

        if ($comp['residuo_credito_cents'] > 0) {
            $testo .= '. Avanzano '.$comp['residuo_credito_formatted'];
        }

        return $testo.'.';
    }

    /**
     * I debiti ancora aperti, aggregati per rata e raggruppati per anagrafica.
     *
     * Una query sola per l'intero insieme di anagrafiche richiesto: è la ragione per cui la
     * firma prende una lista e non un id. `importo > importo_pagato` è la forma SQL di
     * `importo_residuo`, che è un accessor PHP e quindi non filtrabile in query; la coppia con
     * `importo > 0` esclude le quote a credito — che per costruzione non soddisfano il
     * confronto, ma è meglio dire esplicitamente da che parte si sta guardando.
     *
     * @param  array<int>  $anagraficheIds
     * @return Collection<int,Collection<int,array<string,mixed>>>  indicizzata per anagrafica_id
     */
    private function debitiAperti(int $condominioId, array $anagraficheIds): Collection
    {
        if ($anagraficheIds === []) {
            return collect();
        }

        return RataQuote::whereHas('rata.pianoRate', fn ($p) => $p->where('condominio_id', $condominioId))
            ->whereIn('anagrafica_id', $anagraficheIds)
            ->where('importo', '>', 0)
            ->whereRaw('importo > importo_pagato')
            ->with(['rata.pianoRate.gestione:id,nome'])
            ->get()
            ->groupBy('anagrafica_id')
            ->map(fn ($quoteAnagrafica) => $quoteAnagrafica
                ->groupBy('rata_id')
                ->map(function ($gruppo) {
                    $rata = $gruppo->first()->rata;

                    return [
                        'rata_id'       => (int) $gruppo->first()->rata_id,
                        // Le bozze restano nell'elenco ma non possono essere BERSAGLIO: servono
                        // a calcolare il netto della loro rata, che altrimenti uscirebbe come
                        // «solo credito» — e la Rata 0, dove il credito da saldi iniziali vive
                        // quasi sempre, nasce proprio in bozza.
                        'emessa'        => ($rata->stato ?? null) !== 'bozza',
                        'numero_rata'   => $rata->numero_rata ?? null,
                        'descrizione'   => $rata->descrizione ?? null,
                        'data_scadenza' => $rata->data_scadenza ?? null,
                        'gestione_id'   => $rata->pianoRate->gestione_id ?? null,
                        'gestione_nome' => $rata->pianoRate->gestione->nome ?? 'Generica',
                        'residuo_cents' => (int) $gruppo->sum(fn (RataQuote $q) => $q->importo_residuo),
                    ];
                })
                ->filter(fn ($d) => $d['residuo_cents'] > 0)
                ->sortBy('data_scadenza')
                ->values()
            );
    }

    /**
     * Elenco delle anagrafiche con credito disponibile in un condominio,
     * opzionalmente ristretto a un sottoinsieme di anagrafiche. Usato dal
     * widget dashboard (nessun filtro) e dal suggerimento di compensazione
     * all'emissione (filtrato alle anagrafiche delle rate appena emesse).
     *
     * @param  array<int>|null  $anagraficheIds
     * @return Collection<int,array{anagrafica_id:int,nome:string,totale_cents:int,totale_formatted:string}>
     */
    public function perCondominio(int $condominioId, ?array $anagraficheIds = null): Collection
    {
        $query = $this->queryCreditoBase($condominioId)
            ->whereNotNull('anagrafica_id')
            ->with('anagrafica:id,nome');

        if ($anagraficheIds !== null) {
            $query->whereIn('anagrafica_id', $anagraficheIds);
        }

        $perAnagrafica = $query->get()->groupBy('anagrafica_id');

        // I debiti di TUTTE le anagrafiche a credito in una query sola: questo elenco alimenta
        // il widget di dashboard, che si apre a ogni accesso al gestionale.
        $debiti = $this->debitiAperti($condominioId, $perAnagrafica->keys()->all());

        return $perAnagrafica
            ->map(function ($quote, $anagraficaId) use ($debiti) {
                $totaleCents = $quote->sum(fn($q) => $q->credito_disponibile);

                return [
                    'anagrafica_id'    => $quote->first()->anagrafica_id,
                    'nome'             => $quote->first()->anagrafica->nome ?? 'Condomino',
                    'totale_cents'     => $totaleCents,
                    'totale_formatted' => MoneyHelper::format($totaleCents),
                    'compensabile'     => $this->compensabile(
                        $quote,
                        $debiti->get($anagraficaId, collect()),
                        $totaleCents,
                    ),
                ];
            })
            ->filter(fn($c) => $c['totale_cents'] > 0)
            ->values();
    }

    /**
     * Query di base: tutte le quote che portano credito (strapagamento o
     * saldo iniziale/anticipo negativo) in un condominio, con le relazioni
     * verso gestione già caricate per evitare N+1 nei consumer.
     */
    private function queryCreditoBase(int $condominioId)
    {
        return RataQuote::whereHas('rata.pianoRate', fn($p) => $p->where('condominio_id', $condominioId))
            ->where(function ($q) {
                $q->whereRaw('importo_pagato > importo')
                  ->orWhere('importo', '<', 0);
            })
            ->with(['rata.pianoRate.gestione:id,nome']);
    }
}
