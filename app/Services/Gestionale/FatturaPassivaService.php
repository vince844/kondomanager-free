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

class FatturaPassivaService
{
    public function registraFattura(array $data, int $condominioId, ?UploadedFile $file = null): FatturaPassiva
    {
        return DB::transaction(function () use ($data, $condominioId, $file) {

            $fornitore      = Fornitore::findOrFail($data['fornitore_id']);
            $isNotaCredito  = ($data['tipo_documento'] === 'nota_credito');
            $moltiplicatore = $isNotaCredito ? -1 : 1;

            // 1. Elaborazione Righe (calcoli in centesimi)
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

            // 2. Calcolo Ritenuta
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
            $netto = ($totaleDoc - $ritenuta) * $moltiplicatore;

            // 3. Determinazione stato_approvazione (Override Budget)
            $statoApprovazione = $data['stato_approvazione'];
            if (!empty($data['dati_extra']['override_budget'])) {
                $statoApprovazione = 'sforo_motivato';
            }

            // 4. Creazione Fattura
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

            // 5. Upload Documento
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

            // 6. Contabilità (Partita Doppia)
            $contoDebiti = ContoContabile::where('condominio_id', $condominioId)
                ->where('ruolo', 'debiti_fornitori')
                ->firstOrFail();

            $scrittura = ScritturaContabile::create([
                'condominio_id'      => $condominioId,
                'esercizio_id'       => $data['esercizio_id'],
                'gestione_id'        => $data['gestione_id'],
                'data_registrazione' => now(),
                'data_competenza'    => $fattura->data_documento,
                // Manteniamo la coerenza: forziamo la scrittura ad avere lo stesso protocollo.
                // Siccome glielo passiamo esplicitamente, il Trait NON lo sovrascriverà!
                'numero_protocollo'  => $fattura->numero_protocollo,
                'causale'            => "Ft. {$data['numero_documento']} - {$fornitore->ragione_sociale}",
                'tipo_movimento'     => 'fattura_acquisto',
                'stato'              => 'registrata',
            ]);

            // DARE — Costi
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

            // AVERE — Debito
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

            // 7. Fire Evento
            event(new FatturaRegistrata($fattura, auth()->id() ?? 1));

            return $fattura;
        });
    }
}