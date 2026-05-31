<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Gestionale\ScritturaContabile;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller per la visualizzazione di dettaglio di una Scrittura Contabile.
 *
 * v1.9.1-beta.7: vista read-only del Libro Giornale.
 * Lo storno si gestisce sempre dal documento genitore (pagamento/fattura).
 *
 * Responsabilità:
 *  - Caricare una scrittura con tutte le relazioni (righe, conti, documenti collegati)
 *  - Calcolare i totali DARE/AVERE e la quadratura
 *  - Preparare i dati per la pagina Inertia/Vue
 */
class ScritturaContabileController extends Controller
{
    use HandleFlashMessages, HasEsercizio, HasCondomini;

    /**
     * Mostra il dettaglio di una singola scrittura contabile.
     *
     * Carica le righe in partita doppia, i documenti collegati (pagamento fornitore,
     * fatture passive), e le scritture correlate (padre/figlie per storni).
     */
    public function show(Condominio $condominio, ScritturaContabile $scrittura): Response
    {
        // ── Guard: appartenenza al condominio ─────────────────────────────
        if ($scrittura->condominio_id !== $condominio->id) {
            abort(403, 'La scrittura non appartiene a questo condominio.');
        }

        // ── Eager loading di tutte le relazioni necessarie ────────────────
        $scrittura->load([
            'righe.contoContabile',
            'righe.cassa',
            'righe.voceSpesa',
            'padre',
            'figlie',
            'esercizio',
            'gestione',
            'pagamentoFornitore.fornitore',
            'pagamentoFornitore.contoCorrente',
            'fatture',
        ]);

        // ── Calcolo quadratura partita doppia ─────────────────────────────
        $totaleDare  = $scrittura->righe->where('tipo_riga', 'dare')->sum('importo');
        $totaleAvere = $scrittura->righe->where('tipo_riga', 'avere')->sum('importo');

        $listaCondomini = CondominioResource::collection($this->getCondomini())->resolve();
        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/movimenti/scritture/Show', [
            'condominio' => $condominio,
            'condomini'  => $listaCondomini,
            'esercizio'  => $esercizio,
            'scrittura'  => [
                'id'                   => $scrittura->id,
                'data_registrazione'   => $scrittura->data_registrazione?->format('d/m/Y'),
                'data_competenza'      => $scrittura->data_competenza?->format('d/m/Y'),
                'numero_protocollo'    => $scrittura->numero_protocollo,
                'causale'              => $scrittura->causale,
                'descrizione'          => $scrittura->descrizione,
                'tipo_movimento'       => $scrittura->tipo_movimento?->value,
                'tipo_movimento_label' => $scrittura->tipo_movimento?->label(),
                'stato'                => $scrittura->stato,
                'note'                 => $scrittura->note,
                'created_at'           => $scrittura->created_at?->format('d/m/Y H:i'),

                // Contesto
                'esercizio' => $scrittura->esercizio ? [
                    'id'   => $scrittura->esercizio->id,
                    'nome' => $scrittura->esercizio->nome,
                ] : null,
                'gestione' => $scrittura->gestione ? [
                    'id'   => $scrittura->gestione->id,
                    'nome' => $scrittura->gestione->nome,
                ] : null,

                // Righe in partita doppia
                'righe' => $scrittura->righe->map(fn ($r) => [
                    'id'        => $r->id,
                    'tipo_riga' => $r->tipo_riga,
                    'importo'   => $r->importo,
                    'note'      => $r->note,
                    'conto'     => $r->contoContabile ? [
                        'id'     => $r->contoContabile->id,
                        'codice' => $r->contoContabile->codice,
                        'nome'   => $r->contoContabile->nome,
                    ] : null,
                    'cassa' => $r->cassa ? [
                        'id'   => $r->cassa->id,
                        'nome' => $r->cassa->nome,
                    ] : null,
                    'voce_spesa' => $r->voceSpesa ? [
                        'id'   => $r->voceSpesa->id,
                        'nome' => $r->voceSpesa->nome,
                    ] : null,
                ]),

                // Totali e quadratura
                'totale_dare'  => $totaleDare,
                'totale_avere' => $totaleAvere,
                'is_quadrata'  => $totaleDare === $totaleAvere,

                // Scritture collegate (storni)
                'padre' => $scrittura->padre ? [
                    'id'      => $scrittura->padre->id,
                    'causale' => $scrittura->padre->causale,
                    'tipo_movimento_label' => $scrittura->padre->tipo_movimento?->label(),
                ] : null,
                'figlie' => $scrittura->figlie->map(fn ($f) => [
                    'id'      => $f->id,
                    'causale' => $f->causale,
                    'tipo_movimento_label' => $f->tipo_movimento?->label(),
                    'created_at' => $f->created_at?->format('d/m/Y H:i'),
                ]),

                // Pagamento fornitore collegato (1:1)
                'pagamento_fornitore' => $scrittura->pagamentoFornitore ? [
                    'id'                => $scrittura->pagamentoFornitore->id,
                    'importo_lordo'     => $scrittura->pagamentoFornitore->importo_lordo,
                    'importo_netto'     => $scrittura->pagamentoFornitore->importo_netto,
                    'importo_ritenuta'  => $scrittura->pagamentoFornitore->importo_ritenuta,
                    'importo_commissione' => $scrittura->pagamentoFornitore->importo_commissione,
                    'metodo_pagamento'  => $scrittura->pagamentoFornitore->metodo_pagamento?->value,
                    'data_pagamento'    => $scrittura->pagamentoFornitore->data_pagamento?->format('d/m/Y'),
                    'iban_beneficiario' => $scrittura->pagamentoFornitore->iban_beneficiario,
                    'causale_bonifico'  => $scrittura->pagamentoFornitore->causale_bonifico,
                    'stato'             => $scrittura->pagamentoFornitore->stato?->value,
                    'stato_label'       => $scrittura->pagamentoFornitore->stato?->label(),
                    'bonifico_parlante' => $scrittura->pagamentoFornitore->bonifico_parlante,
                    'fornitore'         => $scrittura->pagamentoFornitore->fornitore ? [
                        'id'              => $scrittura->pagamentoFornitore->fornitore->id,
                        'ragione_sociale' => $scrittura->pagamentoFornitore->fornitore->ragione_sociale,
                    ] : null,
                    'conto_corrente' => $scrittura->pagamentoFornitore->contoCorrente ? [
                        'id'   => $scrittura->pagamentoFornitore->contoCorrente->id,
                        'nome' => $scrittura->pagamentoFornitore->contoCorrente->nome,
                    ] : null,
                ] : null,

                // Fatture passive collegate (via pivot fattura_scrittura)
                'fatture' => $scrittura->fatture->map(fn ($f) => [
                    'id'                 => $f->id,
                    'numero_documento'   => $f->numero_documento,
                    'data_documento'     => $f->data_documento?->format('d/m/Y'),
                    'tipo_documento'     => $f->tipo_documento,
                    'importo_allocato'   => $f->pivot->importo_allocato,
                    'tipo_allocazione'   => $f->pivot->tipo?->value ?? $f->pivot->tipo,
                    'dati_extra'         => $f->dati_extra,
                    'stato_approvazione' => $f->stato_approvazione,
                ]),
            ],
        ]);
    }
}
