<?php

namespace App\Services\Gestionale;

use App\Enums\TipoMovimentoContabile;
use App\Helpers\MoneyHelper;
use App\Models\Condominio;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestionale\RigaScrittura;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class IncassoRateService
{
    /**
     * Recupera la query per gli incassi con filtri ed Eager Loading
     */
    public function getIncassiQuery(Condominio $condominio, ?string $search = null, ?string $stato = null, ?string $dataDa = null, ?string $dataA = null): Builder
    {
        $query = ScritturaContabile::query()
            ->where('condominio_id', $condominio->id)
            ->where('tipo_movimento', 'incasso_rata')
            ->with([
                'gestione', 
                'righe.anagrafica', 
                'righe.cassa',
                // EAGER LOADING: Carichiamo le quote e le rate padre in un colpo solo
                'quotePagate.rata',
                'quotePagate.immobile',  // ← aggiungi
                'figlie.quotePagate.rata',     // ← aggiungi per credito
                'figlie.quotePagate.immobile', // ← aggiungi per credito
                'figlie.righe.anagrafica',     // ← pagante su compensazioni a cassa zero
            ]);

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('numero_protocollo', 'like', "%{$search}%")
                  ->orWhere('causale', 'like', "%{$search}%")
                  ->orWhereHas('righe', function($qr) use ($search) {
                      $qr->whereHas('anagrafica', function($qa) use ($search) {
                             $qa->where('nome', 'like', "%{$search}%");
                         });
                  });
            });
        }

        if ($stato) {
            $query->where('stato', $stato);
        }

        $query
            ->when($dataDa, fn ($q, $v) => $q->whereDate('data_registrazione', '>=', $v))
            ->when($dataA, fn ($q, $v) => $q->whereDate('data_registrazione', '<=', $v));

        return $query->orderByDesc('data_registrazione')
            ->orderByDesc('numero_protocollo');
    }

    /**
     * Trasforma un movimento in array per il frontend
     */
    public function formatMovimentoForFrontend(ScritturaContabile $movimento): array
    {
        $rigaCassa = $movimento->righe->firstWhere('tipo_riga', 'dare');

        // Righe su cui cercare il pagante: quelle della scrittura padre, più quelle
        // delle scritture figlie (storno_credito). Una compensazione a cassa zero
        // (importo versato € 0, saldata interamente col credito) non crea alcuna
        // riga sul padre: senza questo fallback il pagante risultava "Sconosciuto"
        // pur essendo perfettamente noto sulla scrittura figlia.
        $righeAvereConAnagrafica = $movimento->righe
            ->where('tipo_riga', 'avere')
            ->whereNotNull('anagrafica_id');

        if ($righeAvereConAnagrafica->isEmpty()) {
            $righeAvereConAnagrafica = $movimento->figlie
                ->flatMap(fn($figlia) => $figlia->righe)
                ->where('tipo_riga', 'avere')
                ->whereNotNull('anagrafica_id');
        }

        $rigaPagantePrinc = $righeAvereConAnagrafica->first();

        $nomiPaganti = $righeAvereConAnagrafica
            ->map(fn($r) => $r->anagrafica->nome ?? null)
            ->filter()
            ->unique()
            ->values();

        return [
            'id'                       => $movimento->id,
            'numero_protocollo'        => $movimento->numero_protocollo,
            'data_competenza'          => $movimento->data_competenza?->format('Y-m-d'),
            'data_registrazione'       => $movimento->data_registrazione?->format('Y-m-d'),
            'causale'                  => $movimento->causale,
            
            // Passiamo l'oggetto intero, non l'ID, per usare i dati in memoria
            'dettagli_rate'            => $this->getDettagliRate($movimento),
            
            'importo_totale_raw'       => $rigaCassa ? $rigaCassa->importo / 100 : 0,
            'importo_totale_formatted' => MoneyHelper::format($rigaCassa?->importo ?? 0),
            'stato'                    => $movimento->stato,
            'pagante' => [
                'principale'     => $nomiPaganti->first() ?? 'Sconosciuto',
                'altri_count'    => max(0, $nomiPaganti->count() - 1),
                'lista_completa' => $nomiPaganti->join(', '),
                'ruolo'          => $this->getRuoloPagante($rigaPagantePrinc)
            ],
            'cassa_nome'               => $rigaCassa?->cassa?->nome ?? 'N/D',
            'cassa_tipo_label'         => $this->getTipoRisorsaLabel($rigaCassa),
            'gestione_nome'            => $movimento->gestione?->nome ?? 'Generica',
            'anagrafica_id_principale' => $rigaPagantePrinc?->anagrafica_id,
        ];
    }

    /**
     * Recupera i dettagli delle rate (Zero Query, usa le relazioni caricate)
     */
    private function getDettagliRate(ScritturaContabile $movimento): array
    {
        $dettagli = collect();

        // Quote pagate con contanti (scrittura padre)
        foreach ($movimento->quotePagate as $quota) {
            if ($quota->importo <= 0) continue; // salta saldo_iniziale

            $dettagli->push([
                'numero'            => $quota->rata->numero_rata ?? '-',
                'scadenza'          => $quota->rata->data_scadenza?->format('d/m/Y') ?? '-',
                // `etichetta` e non `interno ?? null`: il `??` non scatta su stringa vuota, quindi
                    // con l'interno facoltativo passava `''` e la schermata scriveva «Int. N/D»
                    // su un'unità che esiste e ha un nome (ripasso della .58).
                    'immobile'          => $quota->immobile?->etichetta,
                'importo_formatted' => MoneyHelper::format($quota->pivot->importo_pagato),
                'tipo'              => 'contanti', // icona banconota
            ]);
        }

        // Quote pagate con credito (scritture figlie storno_credito)
        foreach ($movimento->figlie as $figlia) {
            // tipo_movimento è castato all'enum TipoMovimentoContabile: il confronto
            // con la stringa era sempre falso (tipi diversi), quindi questo ramo non
            // scattava mai e le compensazioni a credito restavano invisibili sia in
            // lista che nel dettaglio incasso, per qualunque provenienza del credito.
            if ($figlia->tipo_movimento !== TipoMovimentoContabile::STORNO_CREDITO) continue;

            foreach ($figlia->quotePagate as $quota) {
                if ($quota->importo <= 0) continue;
                if ($quota->pivot->importo_pagato <= 0) continue;

                $dettagli->push([
                    'numero'            => $quota->rata->numero_rata ?? '-',
                    'scadenza'          => $quota->rata->data_scadenza?->format('d/m/Y') ?? '-',
                    // `etichetta` e non `interno ?? null`: il `??` non scatta su stringa vuota, quindi
                    // con l'interno facoltativo passava `''` e la schermata scriveva «Int. N/D»
                    // su un'unità che esiste e ha un nome (ripasso della .58).
                    'immobile'          => $quota->immobile?->etichetta,
                    'importo_formatted' => MoneyHelper::format($quota->pivot->importo_pagato),
                    'tipo'              => 'credito', // icona monete
                ]);
            }
        }

        return $dettagli
            ->sortBy(fn($d) => $d['numero'])
            ->values()
            ->toArray();
    }

    /**
     * Determina il ruolo del pagante
     *
     * @param RigaScrittura|null $rigaPagantePrinc La riga contabile associata al pagante principale
     * @return string
     */
    private function getRuoloPagante(?RigaScrittura $rigaPagantePrinc): string
    {
        $ruoloPagante = 'Condòmino';

        // Nota: Questa query DB rimane perché dipende da una relazione complessa anagrafica-immobile
        // Ottimizzarla richiederebbe caricare tutte le relazioni immobiliari all'inizio.
        // Dato che è una query per riga (veloce, su indici), per ora è accettabile.
        if ($rigaPagantePrinc && $rigaPagantePrinc->anagrafica_id && $rigaPagantePrinc->immobile_id) {
            $ruoloDb = DB::table('anagrafica_immobile')
                ->where('anagrafica_id', $rigaPagantePrinc->anagrafica_id)
                ->where('immobile_id', $rigaPagantePrinc->immobile_id)
                ->value('tipologia');

            if ($ruoloDb) {
                $ruoloPagante = ucfirst($ruoloDb);
            }
        }

        return $ruoloPagante;
    }

    /**
     * Ottiene la label del tipo di risorsa
     *
     * @param RigaScrittura|null $rigaCassa La riga contabile della cassa utilizzata
     * @return string
     */
    private function getTipoRisorsaLabel(?RigaScrittura $rigaCassa): string
    {
        if (!$rigaCassa || !$rigaCassa->cassa) {
            return 'N/D';
        }

        $labels = [
            'banca' => 'Conto Corrente',
            'contanti' => 'Cassa Contanti',
            'postale' => 'Conto Postale'
        ];

        return $labels[$rigaCassa->cassa->tipo] ?? ucfirst($rigaCassa->cassa->tipo);
    }
}