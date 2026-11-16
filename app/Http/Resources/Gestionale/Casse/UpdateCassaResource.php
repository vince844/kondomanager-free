<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Resources\Gestionale\Casse;

use App\Helpers\MoneyHelper;
use App\Services\Gestionale\SaldoCassaService;
use App\Services\Gestionale\TipoDelFondo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UpdateCassaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cc = $this->contoCorrente;

        // Il "tipo" resta modificabile finché non ci sono movimenti OPERATIVI
        // (l'apertura da sola non conta). Il "saldo di apertura" invece si blocca
        // già alla sola apertura: da quel momento il dato vive nella scrittura, non
        // più nella colonna, e i due campi hanno guardie diverse per questo.
        $bloccatoDaApertura = $this->resource->hasAperturaRegistrata();

        return [
            'id'             => $this->id,
            'nome'           => $this->nome,
            'tipo'           => $this->tipo,
            'descrizione'    => $this->descrizione,
            'note'           => $this->note,
            'attiva'         => (bool) $this->attiva,
            'has_movements'  => $this->resource->hasMovimentiOperativi(),
            'saldo_iniziale_bloccato' => $bloccatoDaApertura,

            // --- CAMPI GOVERNANCE FONDI ---
            // Un fondo senza tipo (un fondo di prima che il tipo esistesse) resta senza tipo anche qui: il resto del programma lo conta come vincolato
            // (`TipoDelFondo`, lo Stato patrimoniale, la tesoreria), e mostrarlo «generico» lo faceva sembrare libero; salvato così, lo diventava davvero.
            // La pagina lo dice con le parole di sempre (`tipo_non_indicato`) e chiede di scegliere il tipo per salvare. Una cassa che non è un fondo resta com'era.
            'sottotipo_fondo'         => $this->tipo === 'fondo' ? (filled($this->sottotipo_fondo) ? $this->sottotipo_fondo : null) : ($this->sottotipo_fondo ?? 'generico'),
            'tipo_non_indicato'       => $this->tipo === 'fondo' && blank($this->sottotipo_fondo) ? TipoDelFondo::etichetta(null, null, false, null)['testo'] : null,
            'vincolo_descrizione'     => $this->vincolo_descrizione,
            'is_override_assemblea'   => (bool) $this->is_override_assemblea,
            'motivazione_override'    => $this->motivazione_override,

            // Una volta registrata l'apertura il campo non è più "il valore da
            // modificare" ma un'informazione: mostriamo il saldo reale corrente
            // (fonte unica: SaldoCassaService) invece di una colonna a zero che
            // sembrerebbe un campo vuoto da compilare.
            'saldo_iniziale' => $bloccatoDaApertura
                                    ? MoneyHelper::format(app(SaldoCassaService::class)->saldoDisponibile($this->resource), false)
                                    : ($this->saldo_iniziale ? MoneyHelper::format($this->saldo_iniziale, false) : ''),

            'conto_corrente' => $cc ? [
                'id'           => $cc->id,
                'istituto'     => $cc->istituto,
                'iban'         => $cc->iban,
                'swift'        => $cc->swift, 
                'intestatario' => $cc->intestatario,
                'tipo'         => $cc->tipo,
                'predefinito'  => (bool) $cc->predefinito,
                'indirizzo'    => $cc->indirizzo,
                'comune'       => $cc->comune,
                'cap'          => $cc->cap,
                'provincia'    => $cc->provincia,
                'nazione'      => $cc->nazione,
            ] : null,
        ];
    }
}