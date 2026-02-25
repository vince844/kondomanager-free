<?php

namespace App\Services\Gestionale;

use App\Events\Gestionale\FatturaRegistrata;
use App\Models\Fornitore;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class FatturaPassivaService
{
    public function registraFattura(array $data, int $condominioId, ?UploadedFile $file = null): FatturaPassiva
    {
        return DB::transaction(function () use ($data, $condominioId, $file) {

            $fornitore      = Fornitore::findOrFail($data['fornitore_id']);
            $isNotaCredito  = ($data['tipo_documento'] === 'nota_credito');
            $moltiplicatore = $isNotaCredito ? -1 : 1;

            // ------------------------------------------------------------------
            // 1. Elaborazione Righe (calcoli in centesimi)
            // ------------------------------------------------------------------
            $imponibileTotale = 0;
            $ivaTotale        = 0;
            $righeProcessate  = [];

            foreach ($data['righe'] as $rigaInput) {
                $impRiga = (int) round($rigaInput['importo_imponibile'] * 100);
                $aliq    = (float) $rigaInput['aliquota_iva'];
                $ivaRiga = (int) round(($impRiga * $aliq) / 100);

                $imponibileTotale += $impRiga;
                $ivaTotale        += $ivaRiga;

                $righeProcessate[] = [
                    'descrizione'        => $rigaInput['descrizione'],
                    'importo_imponibile' => $impRiga * $moltiplicatore,
                    'aliquota_iva'       => $aliq,
                    'importo_iva'        => $ivaRiga * $moltiplicatore,
                    'conto_id'           => $rigaInput['conto_id'],
                    'immobile_id'        => $rigaInput['immobile_id'] ?? null,
                ];
            }

            // ------------------------------------------------------------------
            // 2. Calcolo Ritenuta (solo su fatture, mai su note credito)
            // ------------------------------------------------------------------
            $ritenuta     = 0;
            $datiRitenuta = null;
            if ($fornitore->soggetto_ritenuta && !$isNotaCredito) {
                $base     = $imponibileTotale * ($fornitore->perc_imponibile_ritenuta / 100);
                $ritenuta = (int) round($base * ($fornitore->perc_ritenuta / 100));
                $datiRitenuta = [
                    'imponibile_calcolo' => $base,
                    'aliquota'           => $fornitore->perc_ritenuta,
                    'codice_tributo'     => $fornitore->codice_tributo,
                ];
            }

            $totaleDoc = $imponibileTotale + $ivaTotale;

            // FIX: moltiplicatore applicato al netto finale in modo coerente.
            // $ritenuta è sempre 0 sulle note credito (bloccato sopra),
            // quindi non serve moltiplicarla separatamente.
            $netto = ($totaleDoc - $ritenuta) * $moltiplicatore;

            // ------------------------------------------------------------------
            // 3. Determinazione stato_approvazione
            // ------------------------------------------------------------------
            // Se l'amministratore ha compilato l'override_budget nel frontend,
            // la fattura nasce già con stato 'sforo_motivato' invece di quello
            // scelto manualmente. Il listener creerà automaticamente il reminder
            // di ratifica assembleare.
            $statoApprovazione = $data['stato_approvazione'];
            if (!empty($data['dati_extra']['override_budget'])) {
                $statoApprovazione = 'sforo_motivato';
            }

            // ------------------------------------------------------------------
            // 4. Creazione Fattura
            // ------------------------------------------------------------------
            $fattura = FatturaPassiva::create([
                'condominio_id'      => $condominioId,
                'fornitore_id'       => $fornitore->id,
                'esercizio_id'       => $data['esercizio_id'],
                'conto_corrente_id'  => $data['conto_corrente_id'] ?? null,
                'tipo_documento'     => $data['tipo_documento'],
                'numero_documento'   => $data['numero_documento'],
                'data_documento'     => $data['data_documento'],
                'data_scadenza'      => $data['data_scadenza'],
                'importo_imponibile' => $imponibileTotale * $moltiplicatore,
                'importo_iva'        => $ivaTotale * $moltiplicatore,
                'importo_ritenuta'   => $ritenuta,
                'totale_documento'   => $totaleDoc * $moltiplicatore,
                'netto_a_pagare'     => $netto,
                'stato_pagamento'    => 'aperta',
                'stato_approvazione' => $statoApprovazione,
                'modalita_pagamento' => $data['modalita_pagamento'],
                'iban_fornitore'     => $data['iban_fornitore'] ?? null,
                'dati_extra'         => [
                    'fiscal'          => array_merge(
                        $data['dati_extra']['fiscal'] ?? [],
                        ['ritenuta_details' => $datiRitenuta]
                    ),
                    'competenza'      => $data['dati_extra']['competenza'] ?? null,
                    'override_budget' => $data['dati_extra']['override_budget'] ?? null,
                ],
            ]);

            $fattura->righe()->createMany($righeProcessate);

            // ------------------------------------------------------------------
            // 5. Upload Documento
            // ------------------------------------------------------------------
            if ($file) {
                $path = $file->store('fatture/' . $condominioId, 'public');
                $fattura->documenti()->create([
                    'name'         => $file->getClientOriginalName(),
                    'description'  => 'Fattura Passiva n.' . $data['numero_documento'],
                    'path'         => $path,
                    'mime_type'    => $file->getMimeType(),
                    'file_size'    => $file->getSize(),
                    'created_by'   => auth()->id() ?? 1,
                    'is_published' => true,
                    'is_approved'  => true,
                ]);
            }

            // ------------------------------------------------------------------
            // 6. Contabilità (Partita Doppia)
            // ------------------------------------------------------------------
            $contoDebiti = ContoContabile::where('condominio_id', $condominioId)
                ->where('ruolo', 'debiti_fornitori')
                ->firstOrFail();

            // FIX: numero_protocollo obbligatorio per il vincolo unique su scritture_contabili.
            // Generato dopo la creazione della fattura per avere l'ID disponibile.
            $numeroProtocollo = 'FT-' . date('Y') . '-' . str_pad($fattura->id, 5, '0', STR_PAD_LEFT);

            $scrittura = ScritturaContabile::create([
                'condominio_id'      => $condominioId,
                'esercizio_id'       => $data['esercizio_id'],
                'gestione_id'        => $data['gestione_id'],
                'data_registrazione' => now(),
                'data_competenza'    => $fattura->data_documento,
                'numero_protocollo'  => $numeroProtocollo,
                'causale'            => "Ft. {$data['numero_documento']} - {$fornitore->ragione_sociale}",
                'tipo_movimento'     => 'fattura_acquisto',
                'stato'              => 'registrata',
            ]);

            // DARE — una riga per ogni voce di costo
            foreach ($righeProcessate as $riga) {
                if ($riga['conto_id']) {
                    $contoBudget = Conto::find($riga['conto_id']);
                    if ($contoBudget && $contoBudget->conto_contabile_id) {
                        $scrittura->righe()->create([
                            'conto_contabile_id' => $contoBudget->conto_contabile_id,
                            'tipo_riga'          => $isNotaCredito ? 'avere' : 'dare',
                            'importo'            => abs($riga['importo_imponibile'] + $riga['importo_iva']),
                            'voce_spesa_id'      => $riga['conto_id'],
                            'immobile_id'        => $riga['immobile_id'] ?? null,
                        ]);
                    }
                }
            }

            // AVERE — debito verso il fornitore
            $scrittura->righe()->create([
                'conto_contabile_id' => $contoDebiti->id,
                'tipo_riga'          => $isNotaCredito ? 'dare' : 'avere',
                'importo'            => abs($totaleDoc * $moltiplicatore),
                'anagrafica_id'      => $fornitore->anagrafiche->first()->id ?? null,
            ]);

            // Collegamento Pivot
            $fattura->scritture()->attach($scrittura->id, [
                'importo_allocato' => abs($totaleDoc * $moltiplicatore),
                'tipo'             => 'competenza',
            ]);

            // ------------------------------------------------------------------
            // 7. Fire Evento
            // ------------------------------------------------------------------
            // auth()->id() viene catturato QUI, in contesto sincro con la request,
            // e passato all'evento. Il listener in coda non avrà auth() disponibile.
            event(new FatturaRegistrata($fattura, auth()->id() ?? 1));

            return $fattura;
        });
    }
}