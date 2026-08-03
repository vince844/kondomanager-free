<?php

namespace App\Actions\Gestionale\Movimenti;

use App\Enums\Fiscale\StatoDelegaF24;
use App\Enums\TipoMovimentoContabile;
use App\Models\Gestionale\DelegaF24;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\DoubleEntryValidator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Storna il versamento di una delega F24 già versata.
 *
 * **Rettifica vietata, storno sempre ammesso**: è la regola non negoziabile del design, e
 * qui è l'unica via d'uscita. Il giornale è append-only — non si modifica una scrittura
 * esistente, se ne scrive una uguale e contraria:
 *
 * ```
 *   DARE  Banca                                ← il denaro rientra
 *   AVERE 2202 Debiti v/Erario per Ritenute    ← il debito fiscale si riapre
 * ```
 *
 * Dopo lo storno le ritenute tornano **da versare**: la delega passa a `stornata`, e i
 * pagamenti che copriva rientrano nel calcolo del plafond, perché
 * `GeneraDelegheF24Action` esclude solo quelli agganciati a deleghe non stornate.
 *
 * ## Perché non si riclassifica il versato su un conto crediti
 *
 * Il design prevede, per lo storno *dopo* il versamento, la riclassifica su un conto
 * `1403 crediti_erario_ritenute` — perché se l'Erario ha davvero incassato, quel denaro non
 * torna indietro da solo: diventa un credito da compensare. È corretto, ed è il caso duro
 * descritto al §3 S7. Qui non viene fatto, deliberatamente: quel conto **non esiste** nel
 * piano dei conti, e crearlo su tutti i condomìni è la migrazione M6, che appartiene alla
 * Fase 2 insieme al resto del sigillo.
 *
 * Finché non c'è, questo storno copre il caso reale e frequente — *«ho confermato il
 * versamento per sbaglio, o con la data o il conto sbagliati, e devo rifarlo»* — dove il
 * denaro non è mai uscito o rientra subito. Lo storno di un versamento **realmente
 * incassato dall'Erario** va gestito a mano finché non arriva il conto crediti, e il
 * messaggio della UI lo dirà.
 */
class StornaVersamentoF24Action
{
    public function esegui(DelegaF24 $delega, string $motivo, ?string $dataStorno = null): DelegaF24
    {
        if (trim($motivo) === '') {
            throw new \DomainException('Lo storno di un versamento richiede una motivazione.');
        }

        return DB::transaction(function () use ($delega, $motivo, $dataStorno) {

            $delega = DelegaF24::whereKey($delega->id)->lockForUpdate()->firstOrFail();

            if ($delega->stato !== StatoDelegaF24::VERSATA) {
                throw new \DomainException(
                    'Si può stornare solo una delega versata. Una delega in bozza o confermata si annulla.'
                );
            }

            $originale = $delega->scrittura;

            if (! $originale) {
                throw new \DomainException('La delega risulta versata ma non ha una scrittura collegata.');
            }

            $data = $dataStorno ?? now()->toDateString();
            $totale = (int) $delega->totale_debito;

            $storno = ScritturaContabile::create([
                'condominio_id' => $delega->condominio_id,
                'esercizio_id' => $delega->esercizio_id,
                'data_registrazione' => $data,
                'data_competenza' => $data,
                'causale' => "Storno versamento F24 — {$motivo}",
                'tipo_movimento' => TipoMovimentoContabile::STORNO_PAGAMENTO_F24,
                'stato' => 'registrata',
                'created_by' => Auth::id(),
                'idempotency_key' => (string) Str::uuid(),
            ]);

            // Uguale e contraria: il denaro rientra, il debito verso l'Erario si riapre.
            $storno->righe()->create([
                'conto_contabile_id' => $delega->conto_corrente_id,
                'cassa_id' => $delega->cassa_id,
                'tipo_riga' => 'dare',
                'importo' => $totale,
            ]);

            $storno->righe()->create([
                'conto_contabile_id' => $originale->righe()->where('tipo_riga', 'dare')->value('conto_contabile_id'),
                'tipo_riga' => 'avere',
                'importo' => $totale,
                'note' => 'Storno versamento ritenute — il debito torna aperto',
            ]);

            DoubleEntryValidator::validateOrFail($storno->id);

            $delega->update([
                'stato' => StatoDelegaF24::STORNATA,
                'motivo_annullamento' => $motivo,
                'saldo' => $totale,
            ]);

            return $delega->fresh(['righe', 'scrittura']);
        });
    }
}
