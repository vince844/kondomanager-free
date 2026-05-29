<?php

namespace App\Services\Treasury;

use App\Support\Treasury\TreasuryStatus;
use App\Support\Treasury\AzioneSuggerita;
use App\Enums\TipoAllocazioneFattura;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\Rata;
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

        // 4. Incassi Attesi (Rate Emesse)
        $rate = $this->queryRateEmesse($condominioId, $gestioneId, $limite);
        
        foreach ($rate as $r) {
            $residuoRata = $r->importo_totale - ($r->incassi_sum_importo ?? 0);
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
     * Rate emesse con totale incassato calcolato via subquery (withSum).
     *
     * Usa Eloquent + withSum('incassi', 'importo') per sommare solo le righe
     * di tipo 'avere' collegate alla rata, senza GROUP BY.
     *
     * @return \Illuminate\Database\Eloquent\Collection<Rata>
     */
    private function queryRateEmesse(int $condominioId, ?int $gestioneId, Carbon $limite): \Illuminate\Database\Eloquent\Collection
    {
        $query = Rata::query()
            ->withSum('incassi', 'importo')
            ->where('stato', 'emessa')
            ->where(function($q) use ($limite) {
                $q->whereNull('data_scadenza')
                  ->orWhere('data_scadenza', '<=', $limite->toDateString());
            })
            ->whereHas('pianoRate.gestione', function ($q) use ($condominioId, $gestioneId) {
                $q->where('condominio_id', $condominioId);
                if ($gestioneId) {
                    $q->where('gestioni.id', $gestioneId);
                }
            });

        return $query->get();
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
