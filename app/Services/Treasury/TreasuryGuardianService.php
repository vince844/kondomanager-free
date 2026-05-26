<?php

namespace App\Services\Treasury;

use App\Support\Treasury\TreasuryStatus;
use App\Support\Treasury\AzioneSuggerita;
use App\Enums\TipoAllocazioneFattura;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

final class TreasuryGuardianService
{
    public function __construct(
        private readonly TreasuryTimelineBuilder $timeline,
    ) {}

    public function perCondominio(int $condominioId, ?int $gestioneId = null, int $giorni = 30): TreasuryStatus
    {
        $oggi = Carbon::today();
        $limite = $oggi->copy()->addDays($giorni);

        // 1. Liquidità derivata dal giornale
        $liquiditaTotaleCents = $this->calcolaLiquidita($condominioId, $gestioneId);

        // MVP §6.4: fondi vincolati = 0, disponibile = totale.
        // La separazione reale dipende da Voci Accantonamento (v1.10).
        // Non troncare con max(0, ...): se la liquidità è negativa, lo scoperto è già in atto
        // e la timeline deve partire dal saldo reale.
        $liquiditaVincolataCents = 0; // TODO(v1.10): $this->calcolaFondiVincolati($condominioId, $gestioneId);
        $liquiditaDisponibileCents = $liquiditaTotaleCents;

        // §6.4: Verifica se esiste un fondo riserva per mostrare avviso non bloccante nel frontend
        $hasFondoRiserva = $this->condominioHaFondoRiserva($condominioId);

        // 2. Inizializza variabili
        $uscitePredittiveCents = 0;
        $incassiAttesiCents = 0;
        $debitiPregressiScadutiCents = 0;
        $fattureInScadenza = [];
        $fattureSenzaScadenza = [];
        $morositaImpattanti = []; // TODO: In MVP sarà vuoto o calcolato base rate

        $this->timeline->reset()
                       ->setLiquiditaIniziale($liquiditaDisponibileCents)
                       ->setFinestra($giorni);

        // 3. Uscite Predittive (Fatture Passive)
        $fatture = $this->queryFattureAperte($condominioId, $gestioneId);
        
        foreach ($fatture as $f) {
            $residuo = $f->netto_a_pagare - $f->totale_pagato;
            if ($residuo <= 0) continue;

            // §6.2 Anomalia: fattura aperta con data_scadenza NULL
            // Non scartarla in silenzio — segregarla come anomalia.
            // NON entra nelle uscite predittive né nella timeline.
            if (empty($f->data_scadenza)) {
                $fattureSenzaScadenza[] = [
                    'fornitore'    => $f->fornitore_nome,
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

            // Entra nella timeline (le scadute non pregresse vanno a day 0 grazie al clamp del TimelineBuilder)
            $this->timeline->addUscita($f->fornitore_nome . ' - ' . $f->numero_documento, $residuo, $scadenza->toDateString());
            $uscitePredittiveCents += $residuo;

            $fattureInScadenza[] = [
                'fornitore'    => $f->fornitore_nome,
                'numero'       => $f->numero_documento,
                'dataScadenza' => $scadenza->toDateString(),
                'importoCents' => $residuo,
            ];
        }

        // 4. Incassi Attesi (Rate Emesse)
        $rate = $this->queryRateEmesse($condominioId, $gestioneId, $limite);
        
        foreach ($rate as $r) {
            $residuoRata = $r->importo_totale - $r->totale_incassato;
            if ($residuoRata <= 0) continue;

            $this->timeline->addEntrataAttesa('Rata ' . $r->numero_rata, $residuoRata, $r->data_scadenza);
            $incassiAttesiCents += $residuoRata;
        }

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
        $azioni = $this->generaAzioniSuggerite($livello, $risultato['scenarioPessimisticoCents'], $incassiAttesiCents, $debitiPregressiScadutiCents);

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
            morositaImpattanti: $morositaImpattanti,
            azioniSuggerite: $azioni,
            fattureSenzaScadenza: $fattureSenzaScadenza,
            hasFondoRiserva: $hasFondoRiserva,
        );
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
            $query->where('sc.gestione_id', $gestioneId);
        }

        $movimenti = $query->sum(DB::raw("CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END"));
        
        return (int) ($saldiIniziali + $movimenti);
    }

    private function calcolaFondiVincolati(int $condominioId, ?int $gestioneId): int
    {
        $query = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', 'rs.scrittura_id', '=', 'sc.id')
            ->join('conti_contabili as cc', 'rs.conto_contabile_id', '=', 'cc.id')
            ->where('sc.condominio_id', $condominioId)
            ->where('cc.categoria', 'fondi')
            ->whereNull('sc.deleted_at');

        if ($gestioneId) {
            $query->where('sc.gestione_id', $gestioneId);
        }

        // I fondi sono conti di tipo PASSIVO. Aumentano in AVERE, diminuiscono in DARE.
        $totale = $query->sum(DB::raw("CASE WHEN rs.tipo_riga = 'avere' THEN rs.importo ELSE -rs.importo END"));
        
        // Non ammettiamo fondi vincolati "negativi" in questo contesto, se un fondo va sottozero è un'anomalia contabile,
        // ma non restituisce vera liquidità. Al massimo è zero.
        return max(0, (int) $totale);
    }

    /**
     * §6.4 MVP: verifica se il condominio ha un fondo riserva.
     * Usato per mostrare l'avviso non bloccante "il calcolo non distingue ancora i fondi vincolati".
     * TODO(v1.10): quando Voci Accantonamento sarà disponibile, usare calcolaFondiVincolati() al suo posto.
     */
    private function condominioHaFondoRiserva(int $condominioId): bool
    {
        return DB::table('conti_contabili')
            ->where('condominio_id', $condominioId)
            ->where('categoria', 'fondi')
            ->exists();
    }

    private function queryFattureAperte(int $condominioId, ?int $gestioneId): array
    {
        $query = DB::table('fatture_passive as fp')
            ->leftJoin('fornitori as f', 'fp.fornitore_id', '=', 'f.id')
            // Join con fattura_scrittura per calcolare il totale già pagato senza N+1
            ->leftJoin('fattura_scrittura as fs', function($join) {
                $join->on('fp.id', '=', 'fs.fattura_passiva_id')
                     ->whereIn('fs.tipo', ['pagamento', 'compensazione']);
            })
            ->where('fp.condominio_id', $condominioId)
            ->whereIn('fp.stato_pagamento', ['aperta', 'parziale'])
            ->where('fp.stato_approvazione', '!=', 'contestata');
            
        // Se la fattura passiva ha gestione_id, possiamo filtrare (verificare lo schema)
        if ($gestioneId) {
            // Nota: Se fatture_passive non ha gestione_id direttamente, potremmo dover leggere la pivot.
            // Assumiamo che se richiesto per gestione, filtriamo (solitamente la prima riga o il documento).
            // Se non c'è, commentiamo o rimuoviamo.
        }

        $rows = $query->groupBy('fp.id')
            ->select(
                'fp.id',
                'fp.numero_documento',
                'fp.data_scadenza',
                'fp.netto_a_pagare',
                'fp.is_pregresso',
                'f.ragione_sociale as fornitore_nome',
                DB::raw('COALESCE(SUM(fs.importo_allocato), 0) as totale_pagato')
            )
            ->get();
            
        return $rows->toArray();
    }

    private function queryRateEmesse(int $condominioId, ?int $gestioneId, Carbon $limite): array
    {
        // Ottiene le rate emesse e non ancora chiuse
        // Join con righe_scritture per il totale pagato
        $query = DB::table('rate as r')
            ->join('piani_rate as pr', 'r.piano_rate_id', '=', 'pr.id')
            ->join('gestioni as g', 'pr.gestione_id', '=', 'g.id')
            ->leftJoin('righe_scritture as rs', 'r.id', '=', 'rs.rata_id')
            ->where('g.condominio_id', $condominioId)
            ->where('r.stato', 'emessa')
            ->where(function($q) use ($limite) {
                $q->whereNull('r.data_scadenza')
                  ->orWhere('r.data_scadenza', '<=', $limite->toDateString());
            });

        if ($gestioneId) {
            $query->where('g.id', $gestioneId);
        }

        $rows = $query->groupBy('r.id')
            ->select(
                'r.id',
                'r.numero_rata',
                'r.data_scadenza',
                'r.importo_totale',
                DB::raw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'avere' THEN rs.importo ELSE 0 END), 0) as totale_incassato")
            )
            ->get();
            
        return $rows->toArray();
    }

    private function generaAzioniSuggerite(string $livello, int $scenarioPessimisticoCents, int $incassiAttesiCents, int $debitiPregressiScadutiCents): array
    {
        $azioni = [];

        if ($livello !== 'verde') {
            if ($incassiAttesiCents > 0) {
                $azioni[] = new AzioneSuggerita(
                    tipo: 'sollecito',
                    label: 'Verifica o Sollecita Incassi',
                    descrizione: 'Registra eventuali versamenti bancari già ricevuti o invia promemoria ai ritardatari',
                    impattoCents: $incassiAttesiCents,
                    route: 'gestionale.rate.index', // Esempio
                );
            } else {
                // Se non ci sono rate emesse, l'unica soluzione per coprire le uscite è emetterle
                $azioni[] = new AzioneSuggerita(
                    tipo: 'emissione_rate',
                    label: 'Emetti Nuove Rate',
                    descrizione: 'Non ci sono incassi attesi sufficienti per coprire le uscite',
                    impattoCents: 0,
                    route: 'gestionale.piani-rate.index',
                );
            }
        }

        if ($debitiPregressiScadutiCents > 0) {
            // Un'azione informativa o per proporre piano di rientro
             $azioni[] = new AzioneSuggerita(
                tipo: 'gestione_pregressi',
                label: 'Gestisci Debiti Pregressi',
                descrizione: 'Hai esposizioni storiche che non rientrano nel budget corrente',
                impattoCents: 0, // Nessun impatto a 30gg
                route: 'gestionale.fatture.index', 
                routeParams: ['pregresso' => 1]
            );
        }

        return $azioni;
    }
}
