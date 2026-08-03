<?php

namespace App\Actions\Gestionale\Movimenti;

use App\Enums\Fiscale\StatoDelegaF24;
use App\Enums\TipoMovimentoContabile;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\DelegaF24;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\DoubleEntryValidator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registra il versamento di una delega F24: **il movimento che chiude il conto 2202**.
 *
 * Fino alla beta.37 quel conto accumulava soltanto. Verificato a database prima di scrivere
 * una riga di questo modulo: `dare = 0` su tutti i condomìni. Le ritenute entravano nel
 * debito verso l'Erario e non ne uscivano mai — non per un errore di calcolo, ma perché il
 * movimento che le versa non esisteva. Questa azione è quella riga mancante.
 *
 * ```
 *   DARE  2202 Debiti v/Erario per Ritenute   ← estingue il debito fiscale
 *   AVERE Banca                               ← esce il denaro
 * ```
 *
 * Due righe, e la partita doppia quadra da sola. La difficoltà non era contabile: era
 * sapere *quali* ritenute, *quando* e con *quale* codice tributo — cioè tutto ciò che sta a
 * monte, in `PlafondRitenuteService` e `GeneraDelegheF24Action`.
 *
 * ## Il sigillo
 *
 * Da qui la delega non si rettifica più: si storna. Non è una scelta tecnica ma il
 * riconoscimento di un fatto — il denaro è uscito e l'Agenzia ha registrato il versamento.
 * Modificare a posteriori significherebbe raccontarlo diversamente da come è avvenuto.
 */
class ConfermaVersamentoF24Action
{
    /**
     * @param  int  $contoBancarioId  conto contabile da cui esce il denaro
     * @param  int|null  $cassaId  cassa collegata, per la riconciliazione bancaria (v1.16)
     */
    public function esegui(
        DelegaF24 $delega,
        string $dataVersamento,
        int $contoBancarioId,
        ?int $cassaId = null,
        ?string $note = null,
    ): DelegaF24 {
        return DB::transaction(function () use ($delega, $dataVersamento, $contoBancarioId, $cassaId, $note) {

            $delega = DelegaF24::whereKey($delega->id)->lockForUpdate()->firstOrFail();

            $this->verificaVersabile($delega);

            $contoErario = $this->contoErario($delega->condominio_id);
            $totale = (int) $delega->totale_debito;

            $scrittura = ScritturaContabile::create([
                'condominio_id' => $delega->condominio_id,
                'gestione_id' => $this->gestioneDeiPagamenti($delega),
                'esercizio_id' => $delega->esercizio_id,
                'data_registrazione' => $dataVersamento,
                'data_competenza' => $dataVersamento,
                'causale' => $this->causale($delega),
                'tipo_movimento' => TipoMovimentoContabile::PAGAMENTO_F24,
                'stato' => 'registrata',
                'created_by' => Auth::id(),
                'idempotency_key' => (string) Str::uuid(),
            ]);

            // DARE 2202: il debito verso l'Erario si estingue.
            $scrittura->righe()->create([
                'conto_contabile_id' => $contoErario->id,
                'tipo_riga' => 'dare',
                'importo' => $totale,
                'note' => 'Versamento ritenute d\'acconto — delega F24',
            ]);

            // AVERE banca: il denaro esce davvero.
            $scrittura->righe()->create([
                'conto_contabile_id' => $contoBancarioId,
                'cassa_id' => $cassaId,
                'tipo_riga' => 'avere',
                'importo' => $totale,
            ]);

            DoubleEntryValidator::validateOrFail($scrittura->id);

            $delega->update([
                'stato' => StatoDelegaF24::VERSATA,
                'data_versamento' => $dataVersamento,
                'scrittura_contabile_id' => $scrittura->id,
                'conto_corrente_id' => $contoBancarioId,
                'cassa_id' => $cassaId,
                'saldo' => 0,
                'note' => $note ?? $delega->note,
            ]);

            return $delega->fresh(['righe', 'scrittura']);
        });
    }

    /**
     * Le condizioni che rendono versabile una delega, con il motivo scritto.
     *
     * Stesso schema di `FatturaPassiva::motivoBloccoEliminazione()`: una sola funzione che
     * decide e spiega, così la guardia e il messaggio non possono divergere. È la lezione
     * della beta.34, il divieto muto.
     */
    private function verificaVersabile(DelegaF24 $delega): void
    {
        if ($delega->stato === StatoDelegaF24::VERSATA) {
            throw new \DomainException('La delega è già stata versata: per correggerla va stornata.');
        }

        if ($delega->stato === StatoDelegaF24::STORNATA) {
            throw new \DomainException('La delega è stata stornata: non può essere versata di nuovo.');
        }

        if ($delega->stato === StatoDelegaF24::ANNULLATA) {
            throw new \DomainException('La delega è stata annullata: registrane una nuova.');
        }

        if ((int) $delega->totale_debito <= 0) {
            throw new \DomainException('La delega non ha importi da versare.');
        }

        if ($delega->righe()->count() === 0) {
            throw new \DomainException('La delega non ha righe: nulla da versare.');
        }
    }

    /**
     * La gestione a cui appartiene il versamento, dedotta dai pagamenti che la delega copre.
     *
     * Non è un dettaglio estetico: senza, la scrittura compare nel Libro Giornale con la
     * colonna «Gestione» vuota mentre tutte le altre ce l'hanno, e resta fuori da ogni
     * report costruito per gestione. Ogni altro movimento la valorizza — il giroconto la
     * riceve addirittura dalla request.
     *
     * Quando i pagamenti coperti stanno su **più** gestioni la risposta onesta è `null`: il
     * versamento è unico e non appartiene a nessuna delle due più che all'altra. Spezzarlo
     * per gestione significherebbe presentare due F24 dove la legge ne vuole uno.
     */
    private function gestioneDeiPagamenti(DelegaF24 $delega): ?int
    {
        $gestioni = DB::table('riga_f24_pagamento')
            ->join('righe_f24', 'righe_f24.id', '=', 'riga_f24_pagamento.riga_f24_id')
            ->join('pagamenti_fornitori', 'pagamenti_fornitori.id', '=', 'riga_f24_pagamento.pagamento_fornitore_id')
            ->join('scritture_contabili', 'scritture_contabili.id', '=', 'pagamenti_fornitori.scrittura_contabile_id')
            ->where('righe_f24.delega_f24_id', $delega->id)
            ->whereNotNull('scritture_contabili.gestione_id')
            ->distinct()
            ->pluck('scritture_contabili.gestione_id');

        return $gestioni->count() === 1 ? (int) $gestioni->first() : null;
    }

    private function contoErario(int $condominioId): ContoContabile
    {
        $conto = ContoContabile::where('condominio_id', $condominioId)
            ->where('ruolo', 'debiti_erario_ritenute')
            ->first();

        if (! $conto) {
            throw new \DomainException(
                'Manca il conto «Debiti v/Erario per Ritenute» (2202) nel piano dei conti di questo condominio.'
            );
        }

        return $conto;
    }

    private function causale(DelegaF24 $delega): string
    {
        $codici = $delega->righe->pluck('codice_tributo')->unique()->sort()->implode(', ');

        return "Versamento F24 ritenute d'acconto — cod. {$codici}";
    }
}
