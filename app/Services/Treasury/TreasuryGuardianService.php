<?php

namespace App\Services\Treasury;

use App\Support\Treasury\TreasuryStatus;
use App\Support\Treasury\AzioneSuggerita;
use App\Enums\TipoAllocazioneFattura;
use App\Helpers\DateHelper;
use App\Models\Anagrafica;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Immobile;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use App\Services\Gestionale\Debiti\OriginePregresso;
use App\Services\Gestionale\Debiti\Scadenza;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

final class TreasuryGuardianService
{
    public function __construct(
        private readonly TreasuryTimelineBuilder $timeline,
        private readonly LetturaDebiti $lettura,
        private readonly \App\Services\Gestionale\CreditoService $crediti,
    ) {}

    public function perCondominio(int $condominioId, ?int $gestioneId = null, int $giorni = 30): TreasuryStatus
    {
        // «Oggi» è il giorno dell'amministratore, non quello UTC del server.
        $oggi = CarbonImmutable::parse(DateHelper::oggiUtente());
        $limite = $oggi->addDays($giorni);

        // 1. Liquidità derivata dal giornale
        $liquiditaTotaleCents = $this->calcolaLiquidita($condominioId, $gestioneId);

        // Beta.19: con i giroconti i fondi sono finalmente quantificabili — sono
        // le casse tipo 'fondo', partizioni attive dell'unico c/c, lette in
        // convenzione unica (dare − avere). La liquidità DISPONIBILE è quella
        // totale meno il denaro accantonato: un semaforo verde calcolato su
        // denaro vincolato è esattamente il falso positivo che questo widget
        // nasceva per eliminare.
        // Non troncare il disponibile con max(0, ...): se è negativo, lo
        // scoperto è già in atto e la timeline deve partire dal saldo reale.
        $liquiditaVincolataCents = $this->calcolaFondiVincolati($condominioId);
        $liquiditaDisponibileCents = $liquiditaTotaleCents - $liquiditaVincolataCents;

        // §6.4: Verifica se esiste un fondo riserva per mostrare avviso non bloccante nel frontend
        $hasFondoRiserva = $this->condominioHaFondoRiserva($condominioId);

        // 2. Inizializza variabili
        $uscitePredittiveCents = 0;
        $debitiPregressiScadutiCents = 0;
        $fattureInScadenza = [];
        $fattureSenzaScadenza = [];

        $this->timeline->reset()
                       ->setLiquiditaIniziale($liquiditaDisponibileCents)
                       ->setFinestra($giorni);

        // 3. Uscite Predittive (Fatture Passive)
        $fatture = $this->queryFattureAperte($condominioId, $gestioneId);
        
        foreach ($fatture as $f) {
            $residuo = $f->netto_a_pagare - ($f->allocazioni_pagamento_sum_importo_allocato ?? 0);
            if ($residuo <= 0) continue;

            // §6.2 Anomalia: fattura aperta con data_scadenza NULL
            // Non scartarla in silenzio — segregarla come anomalia.
            // NON entra nelle uscite predittive né nella timeline.
            if (empty($f->data_scadenza)) {
                $fattureSenzaScadenza[] = [
                    'fornitore'    => $f->fornitore->ragione_sociale ?? '(sconosciuto)',
                    'numero'       => $f->numero_documento,
                    'importoCents' => $residuo,
                ];
                continue;
            }

            $scadenza = Carbon::parse($f->data_scadenza);

            // Segregazione Pregressi Scaduti (§6.7)
            if ($f->is_pregresso && $scadenza->isPast()) {
                $debitiPregressiScadutiCents += $residuo;
                continue; // Non entra nella timeline a 30gg!
            }

            // Filtro finestra (esclude le fatture future oltre i 30gg)
            if ($scadenza->gt($limite)) {
                continue;
            }

            $fornitoreNome = $f->fornitore->ragione_sociale ?? '(sconosciuto)';

            // Entra nella timeline (le scadute non pregresse vanno a day 0 grazie al clamp del TimelineBuilder)
            $this->timeline->addUscita($fornitoreNome . ' - ' . $f->numero_documento, $residuo, $scadenza->toDateString());
            $uscitePredittiveCents += $residuo;

            $fattureInScadenza[] = [
                'fornitore'    => $fornitoreNome,
                'numero'       => $f->numero_documento,
                'dataScadenza' => $scadenza->toDateString(),
                'importoCents' => $residuo,
            ];
        }

        // 4. Incassi attesi e rate scadute, dalla lettura comune dei debiti
        $attesi = $this->incassiAttesi($condominioId, $gestioneId, $oggi, $limite);
        $incassiAttesiCents = $attesi['scaduti'] + $attesi['in_scadenza'];

        // 5. Build Timeline
        $risultato = $this->timeline->build();

        // 6. Calcolo Livello Semaforo
        $livello = 'verde';
        if ($risultato['scenarioOttimisticoCents'] < 0) {
            $livello = 'rosso';
        } elseif ($risultato['scenarioPessimisticoCents'] < 0) {
            $livello = 'giallo';
        }

        // 7. Generazione Azioni Suggerite
        $azioni = $this->generaAzioniSuggerite($condominioId, $livello, $risultato['scenarioPessimisticoCents'], $incassiAttesiCents, $debitiPregressiScadutiCents);

        return new TreasuryStatus(
            condominioId: $condominioId,
            gestioneId: $gestioneId,
            liquiditaTotaleCents: $liquiditaTotaleCents,
            liquiditaVincolataCents: $liquiditaVincolataCents,
            liquiditaDisponibileCents: $liquiditaDisponibileCents,
            uscitePredittiveCents: $uscitePredittiveCents,
            incassiAttesiCents: $incassiAttesiCents,
            debitiPregressiScadutiCents: $debitiPregressiScadutiCents,
            scenarioPessimisticoCents: $risultato['scenarioPessimisticoCents'],
            scenarioOttimisticoCents: $risultato['scenarioOttimisticoCents'],
            giornoScopertoPrevisto: $risultato['giornoScopertoPrevisto'],
            scopertoMaxCents: $risultato['scopertoMaxCents'],
            livello: $livello,
            fattureInScadenza: $fattureInScadenza,
            morositaImpattanti: $attesi['morosita'],
            azioniSuggerite: $azioni,
            fattureSenzaScadenza: $fattureSenzaScadenza,
            hasFondoRiserva: $hasFondoRiserva,
            incassiScadutiCents: $attesi['scaduti'],
            incassiInScadenzaCents: $attesi['in_scadenza'],
            elencoRateScadute: ['route' => 'admin.gestionale.rate-scadute.index', 'routeParams' => ['condominio' => $condominioId]],
            morositaAltri: $attesi['altri'],
            scadutoPerFasce: $attesi['fasce'],
        );
    }

    /**
     * Gli incassi attesi dalle rate emesse, per persona, dalla lettura comune dei debiti (decisione 217 D4): le rate con un residuo che
     * scadono entro la finestra, le già scadute senza limite all'indietro (entrano nella timeline al giorno 0). Il denaro atteso da una
     * persona è il suo residuo meno il suo credito **nella stessa gestione** (il perimetro del pianificatore del credito), tolto dalla
     * rata più vecchia; mai il credito di un altro. Il credito è quello del giornale (il credito delle sue rate emesse, nella lettura) più
     * quello delle sue quote fuori dal giornale (la rata 0 in bozza a credito): il credito di una quota a giornale è già nel giornale, e
     * quando sta sulla stessa (persona, unità, rata) di una quota a debito (vendita e ricompra, decisione 25) è già nettato nel residuo.
     * Contato anche dal credito disponibile delle quote, si toglieva due volte. Fino alla 1.12.0-beta.2 il residuo era per rata, netto dentro la rata:
     * il credito di una persona toglieva denaro atteso dal debito di un'altra (tipicamente sulla rata 0), e il colore del semaforo di un
     * condominio con la rata 0 mista può cambiare.
     *
     * Le persone con rate scadute: le prime cinque per scaduto (lordo: il credito si mostra, non si netta), con l'unità, i giorni di
     * ritardo della rata più vecchia, il loro credito (lo stesso che la riga «Rate scadute» toglie, in tutto il perimetro) e il
     * collegamento al loro estratto conto; quante altre; lo scaduto di tutte per fasce.
     *
     * @return array{scaduti:int, in_scadenza:int, morosita:list<array<string,mixed>>, altri:int, fasce:array<string,int>}
     */
    private function incassiAttesi(int $condominioId, ?int $gestioneId, CarbonImmutable $oggi, CarbonImmutable $limite): array
    {
        $fino = $limite->toDateString();
        $scaduti = 0;
        $inScadenza = 0;
        $fasce = Scadenza::FASCE;
        $morosi = [];
        $fuoriGiornale = $this->crediti->disponibilePerGestione($condominioId, null, soloFuoriGiornale: true);

        foreach ($this->lettura->perCondominio($condominioId, $oggi->toDateString(), $gestioneId) as $persona => $l) {
            $perGestione = [];
            $creditoPerGestione = [];
            foreach ($l['rate'] as $r) {
                if ($r['residuo'] > 0 && $r['scadenza'] <= $fino) {
                    $perGestione[$r['gestione_id']][] = $r;
                }
                $creditoPerGestione[$r['gestione_id']] = ($creditoPerGestione[$r['gestione_id']] ?? 0) + (int) $r['credito'];
            }
            foreach ($fuoriGiornale[$persona] ?? [] as $gestione => $importo) {
                if ($gestioneId === null || $gestione === $gestioneId) {
                    $creditoPerGestione[$gestione] = ($creditoPerGestione[$gestione] ?? 0) + $importo;
                }
            }
            foreach ($perGestione as $gestione => $righe) {
                $credito = (int) ($creditoPerGestione[$gestione] ?? 0);
                foreach ($righe as $r) {
                    $coperto = min($credito, $r['residuo']);
                    $credito -= $coperto;
                    $atteso = $r['residuo'] - $coperto;
                    $this->timeline->addEntrataAttesa('Rata ' . $r['numero_rata'], $atteso, $r['scadenza']);
                    $r['scaduta'] ? $scaduti += $atteso : $inScadenza += $atteso;
                }
            }

            if ($l['totali']['scaduto'] > 0) {
                foreach ($l['totali']['fasce'] as $fascia => $importo) {
                    $fasce[$fascia] += $importo;
                }
                // Le unità delle rate scadute; una riga con l'unità cancellata non ne ha (`nullOnDelete`).
                $unita = array_values(array_unique(array_filter(array_column(array_filter($l['rate'], fn ($r) => $r['scaduta'] && $r['residuo'] > 0), 'immobile_id'), fn ($id) => $id !== null)));
                // Il ritardo più vecchio che la lettura sa contare: un pregresso senza un numero di giorni non lo sposta, e la riga lo dice.
                $morosi[] = ['anagraficaId' => (int) $persona, 'unita' => $unita, 'importoCents' => $l['totali']['scaduto'], 'giorniRitardo' => $l['totali']['ritardo_massimo'],
                    'anzianitaNonNota' => (bool) $l['totali']['anzianita_non_nota'], 'creditoCents' => (int) array_sum($creditoPerGestione)];
            }
        }

        usort($morosi, fn ($a, $b) => [$b['importoCents'], $b['giorniRitardo'], $a['anagraficaId']] <=> [$a['importoCents'], $a['giorniRitardo'], $b['anagraficaId']]);
        $primi = array_slice($morosi, 0, 5);
        $nomi = Anagrafica::whereIn('id', array_column($primi, 'anagraficaId'))->pluck('nome', 'id');
        $immobili = Immobile::whereIn('id', array_merge([], ...array_column($primi, 'unita')))->get()->keyBy('id');

        return [
            'scaduti' => $scaduti,
            'in_scadenza' => $inScadenza,
            'morosita' => array_map(fn ($m) => [
                'anagraficaId' => $m['anagraficaId'],
                'condomino' => (string) ($nomi[$m['anagraficaId']] ?? ''),
                'immobile' => implode(', ', array_map(fn ($id) => $immobili[$id]->etichetta, $m['unita'])),
                'importoCents' => $m['importoCents'],
                // Nullo quando la persona ha solo un pregresso di cui il programma non sa l'anzianità: mai «in ritardo di N giorni» per quello.
                'giorniRitardo' => $m['giorniRitardo'] > 0 || ! $m['anzianitaNonNota'] ? $m['giorniRitardo'] : null,
                'anzianitaNonNota' => $m['anzianitaNonNota'],
                'ritardoTesto' => OriginePregresso::fraseRitardo($m['giorniRitardo'], $m['anzianitaNonNota']),
                'creditoCents' => $m['creditoCents'],
                'route' => 'admin.gestionale.anagrafiche.estratto-conto',
                'routeParams' => ['condominio' => $condominioId, 'anagrafica' => $m['anagraficaId']],
            ], $primi),
            'altri' => max(0, count($morosi) - 5),
            'fasce' => $fasce,
        ];
    }

    private function calcolaLiquidita(int $condominioId, ?int $gestioneId): int
    {
        // 1. Somma dei saldi iniziali delle casse collegate a conti liquidità
        $saldiIniziali = DB::table('casse as c')
            ->join('conti_contabili as cc', 'c.conto_contabile_id', '=', 'cc.id')
            ->where('c.condominio_id', $condominioId)
            ->where('cc.categoria', 'liquidita')
            ->sum('c.saldo_iniziale');

        // 2. Somma dei movimenti contabili
        $query = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', 'rs.scrittura_id', '=', 'sc.id')
            ->join('conti_contabili as cc', 'rs.conto_contabile_id', '=', 'cc.id')
            ->where('sc.condominio_id', $condominioId)
            ->where('cc.categoria', 'liquidita')
            ->whereNull('sc.deleted_at'); // Salvo se serve per retrocompatibilità

        if ($gestioneId) {
            // Le scritture SENZA gestione (saldi di apertura delle casse, beta.25)
            // restano incluse: la liquidità in banca è del CONDOMINIO, non di una
            // gestione. Escluderle mostrerebbe meno denaro di quanto ce n'è davvero
            // e falserebbe il predittore a 30 giorni.
            $query->where(function ($q) use ($gestioneId) {
                $q->where('sc.gestione_id', $gestioneId)
                  ->orWhereNull('sc.gestione_id');
            });
        }

        $movimenti = $query->sum(DB::raw("CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END"));
        
        return (int) ($saldiIniziali + $movimenti);
    }

    /**
     * Denaro accantonato nelle casse tipo 'fondo' (beta.19).
     *
     * I fondi NON stanno in categoria 'fondi' del piano dei conti (lì vivono i
     * mastri passivi): sono casse con conto attivo/liquidità figlio del mastro
     * 1010, lette in convenzione unica saldo_iniziale + dare − avere — la stessa
     * di SaldoCassaService. Nessun filtro per gestione: l'accantonamento è
     * patrimonio del condominio, non di una gestione.
     */
    private function calcolaFondiVincolati(int $condominioId): int
    {
        $saldiIniziali = DB::table('casse')
            ->where('condominio_id', $condominioId)
            ->where('tipo', 'fondo')
            ->whereNotNull('conto_contabile_id')
            ->sum('saldo_iniziale');

        $movimenti = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', 'rs.scrittura_id', '=', 'sc.id')
            ->join('casse as c', 'rs.conto_contabile_id', '=', 'c.conto_contabile_id')
            ->where('c.condominio_id', $condominioId)
            ->where('c.tipo', 'fondo')
            ->whereNull('sc.deleted_at')
            ->sum(DB::raw("CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END"));

        // Un fondo sottozero è un'anomalia contabile, non liquidità restituita:
        // al massimo il vincolato è zero.
        return max(0, (int) ($saldiIniziali + $movimenti));
    }

    /**
     * §6.4: verifica se il condominio ha almeno una cassa fondo attiva.
     * Prima della beta.19 il controllo era su conti_contabili.categoria='fondi',
     * che è sempre vera (la radice PASSIVO 2000 ha quella categoria): il flag
     * risultava true anche senza alcun fondo reale.
     */
    private function condominioHaFondoRiserva(int $condominioId): bool
    {
        return DB::table('casse')
            ->where('condominio_id', $condominioId)
            ->where('tipo', 'fondo')
            ->where('attiva', true)
            ->exists();
    }

    /**
     * Fatture aperte/parziali con totale pagato calcolato via subquery (withSum).
     *
     * Usa Eloquent + withSum('allocazioniPagamento', 'importo_allocato') che genera
     * una subquery correlata, eliminando completamente il GROUP BY e il rischio
     * ONLY_FULL_GROUP_BY di MySQL.
     *
     * @return \Illuminate\Database\Eloquent\Collection<FatturaPassiva>
     */
    private function queryFattureAperte(int $condominioId, ?int $gestioneId): \Illuminate\Database\Eloquent\Collection
    {
        return FatturaPassiva::query()
            ->withSum('allocazioniPagamento', 'importo_allocato')
            ->with('fornitore:id,ragione_sociale')
            ->where('condominio_id', $condominioId)
            ->whereIn('stato_pagamento', ['aperta', 'parziale'])
            ->where('stato_approvazione', '!=', 'contestata')
            ->get();
    }

    /**
     * Le righe d'azione del widget aprono una pagina: `route` è il nome vero della rotta, con il prefisso
     * `admin.` (il gestionale esiste solo lì) e con i parametri che la rotta pretende, a partire dal
     * condominio. Un nome senza prefisso o un parametro che manca fa sollevare Ziggy, e la riga non si apre.
     */
    private function generaAzioniSuggerite(int $condominioId, string $livello, int $scenarioPessimisticoCents, int $incassiAttesiCents, int $debitiPregressiScadutiCents): array
    {
        $azioni = [];
        $delCondominio = ['condominio' => $condominioId];

        if ($livello !== 'verde') {
            if ($incassiAttesiCents > 0) {
                // Un consiglio sulla liquidità: la riga porta all'elenco delle rate scadute, una riga per persona, da cui si vede chi deve che
                // cosa. Il nome dice quello che la pagina fa; l'elenco si apre anche dal blocco delle persone, con «Vedi tutte», sempre.
                $azioni[] = new AzioneSuggerita(
                    tipo: 'sollecito',
                    label: 'Vedi le rate scadute',
                    descrizione: 'Chi deve ancora pagare, persona per persona: prima di scrivere, registra i versamenti già arrivati in banca',
                    impattoCents: $incassiAttesiCents,
                    route: 'admin.gestionale.rate-scadute.index',
                    routeParams: $delCondominio,
                );
            } else {
                // Se non ci sono rate emesse, l'unica soluzione per coprire le uscite è emetterle.
                // I piani rate stanno sotto un esercizio: si apre quello aperto, o l'elenco degli esercizi se non ce n'è.
                $esercizioId = DB::table('esercizi')
                    ->where('condominio_id', $condominioId)
                    ->where('stato', 'aperto')
                    ->orderBy('id')
                    ->value('id');

                $azioni[] = new AzioneSuggerita(
                    tipo: 'emissione_rate',
                    label: 'Emetti Nuove Rate',
                    descrizione: 'Non ci sono incassi attesi sufficienti per coprire le uscite',
                    impattoCents: 0,
                    route: $esercizioId ? 'admin.gestionale.esercizi.piani-rate.index' : 'admin.gestionale.esercizi.index',
                    routeParams: $esercizioId ? $delCondominio + ['esercizio' => $esercizioId] : $delCondominio,
                );
            }
        }

        if ($debitiPregressiScadutiCents > 0) {
            // I debiti del condominio verso i fornitori rimasti dalle gestioni passate: l'azione porta alle loro fatture. Riguarda i fornitori,
            // non i condòmini: l'accordo di rientro di un condòmino è un'altra cosa e sta nell'elenco delle rate scadute.
             $azioni[] = new AzioneSuggerita(
                tipo: 'gestione_pregressi',
                label: 'Gestisci Debiti Pregressi',
                descrizione: 'Hai esposizioni storiche che non rientrano nel budget corrente',
                impattoCents: 0, // Nessun impatto a 30gg
                route: 'admin.gestionale.fatture.index',
                routeParams: $delCondominio + ['pregresso' => 1]
            );
        }

        return $azioni;
    }
}
