<?php

namespace App\Services\Gestionale;

use App\Models\Gestionale\FatturaPassiva;
use Illuminate\Support\Collection;

/**
 * Quali fatture può rettificare una nota di credito del fornitore, e quale fattura dichiara il suo XML (Coda 165,
 * 1.11.0-beta.36; decisione 26, punto 6). Una regola sola per il modulo, per «Collega a una fattura» e per l'import.
 *
 * Le candidate: fatture (non note) dello stesso fornitore e condominio, di **qualunque esercizio** — su un file vero il
 * legame attraversa l'esercizio — e non stornate (una nota non si collega a una fattura già annullata: lo dice anche il
 * modello, se la richiesta arriva lo stesso). Ognuna porta i motivi della scala già calcolati dal server, con le righe
 * della nota quando si conoscono: il modulo non decide, legge.
 *
 * Dall'XML si propone solo quando numero **e** data della fattura dichiarata trovano una fattura sola: molti fornitori
 * ricominciano la numerazione a gennaio, e un numero senza data non basta. Non si tira a indovinare. Il confronto sta qui
 * e serve a due lettori: l'import (una volta, alla lettura del file) e l'elenco delle candidate, che segna la fattura
 * dichiarata **a ogni caricamento** — nel lotto la nota si legge prima che la fattura del lotto sia registrata, e l'esito
 * dell'import invecchiava (R8 della Fase 1-bis).
 */
final class FattureRettificabili
{
    /** @return Collection<int, FatturaPassiva> */
    private function base(int $condominioId, int $fornitoreId): Collection
    {
        return FatturaPassiva::query()
            ->where('condominio_id', $condominioId)
            ->where('fornitore_id', $fornitoreId)
            ->where('tipo_documento', 'fattura')
            ->where('stato_pagamento', '!=', 'stornata')
            ->with(['pianiRate', 'noteCollegate', 'righe', 'coperture', 'esercizio:id,nome'])
            ->orderByDesc('data_documento')->orderByDesc('id')
            ->get()
            ->reject(fn (FatturaPassiva $f) => (bool) ($f->dati_extra['is_stornata'] ?? false))
            ->values();
    }

    /** Numero e data del file contro una fattura: senza maiuscole né spazi ai bordi, data per data. */
    private static function corrisponde(FatturaPassiva $f, ?string $numero, ?string $data): bool
    {
        if ($numero === null || trim($numero) === '' || $data === null) {
            return false;
        }

        return mb_strtolower(trim((string) $f->numero_documento)) === mb_strtolower(trim($numero))
            && optional($f->data_documento)->format('Y-m-d') === $data;
    }

    /**
     * @param  int  $importoNotaCents  la magnitudine della nota (per il tetto del totale)
     * @param  list<array{conto_id: ?int, immobile_id: ?int, riduzione: int}>|null  $righeNota  le righe della nota, per il
     *         «di quanto» e per sapere se l'avviso serve; `null` se non si conoscono
     * @return list<array<string, mixed>>
     */
    public function candidati(
        int $condominioId,
        int $fornitoreId,
        int $importoNotaCents = 0,
        string $poi = 'registra la nota',
        ?array $righeNota = null,
        ?string $numeroDichiarato = null,
        ?string $dataDichiarata = null,
        bool $notaContestata = false,
    ): array {
        return $this->base($condominioId, $fornitoreId)
            ->map(fn (FatturaPassiva $f) => $this->comeCandidata($f, $importoNotaCents, $poi, $righeNota, $notaContestata)
                + ['corrisponde_al_file' => self::corrisponde($f, $numeroDichiarato, $dataDichiarata)])
            ->all();
    }

    /**
     * Le fatture che numero e data del file trovano: la proposta c'è solo se è una (vedi `ImportaFatturaXmlController`).
     *
     * @return Collection<int, FatturaPassiva>
     */
    public function perRiferimento(int $condominioId, int $fornitoreId, string $numero, ?string $data): Collection
    {
        if ($data === null || trim($numero) === '') {
            return collect();
        }

        return $this->base($condominioId, $fornitoreId)
            ->filter(fn (FatturaPassiva $f) => self::corrisponde($f, $numero, $data))
            ->values();
    }

    /**
     * @param  list<array{conto_id: ?int, immobile_id: ?int, riduzione: int}>|null  $righeNota
     * @return array<string, mixed>
     */
    public function comeCandidata(FatturaPassiva $f, int $importoNotaCents = 0, string $poi = 'registra la nota', ?array $righeNota = null, bool $notaContestata = false): array
    {
        $netto = $f->nettoNoteCollegate();
        $motivo = $f->motivoBloccoNotaCollegata($importoNotaCents, $poi, $righeNota, $notaContestata);

        return [
            'id' => $f->id,
            'numero_documento' => $f->numero_documento,
            'data_documento' => optional($f->data_documento)->format('Y-m-d'),
            'totale_documento' => (int) $f->importo_imponibile + (int) $f->importo_iva,
            'esercizio_nome' => $f->esercizio?->nome,
            'is_pregresso' => (bool) $f->is_pregresso,
            'gia_rettificato' => (int) ($netto['rettificato'] ?? 0),
            'motivo_blocco_nota' => $motivo,
            'avviso_nota' => $motivo === null ? $f->avvisoNotaCollegata($righeNota, $notaContestata) : null,
        ];
    }
}
