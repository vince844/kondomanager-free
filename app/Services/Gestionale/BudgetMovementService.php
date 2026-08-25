<?php

namespace App\Services\Gestionale;

use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\BudgetMovement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BudgetMovementService
{
    public function __construct(
        protected SpesaPerVoceService $spesaPerVoceService
    ) {}

    /**
     * Esegue lo spostamento di budget tra due voci all'interno dello stesso piano rate.
     *
     * @param  int|null  $reversesMovementId  valorizzato solo da reverseMovement(): lega il
     *         movimento appena creato a quello che sta stornando. Un chiamante esterno non lo
     *         passa mai — è la differenza fra "sposto budget" e "sto restituendo budget spostato".
     */
    public function moveBudget(PianoRate $piano, int $sourceId, int $destId, int $amount, string $reason, int $userId, ?int $reversesMovementId = null): BudgetMovement
    {
        // 1. Validazione di Base
        if ($sourceId === $destId) {
            throw ValidationException::withMessages(['destination_id' => 'Sorgente e destinazione devono essere diverse.']);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'L\'importo deve essere positivo.']);
        }

        return DB::transaction(function () use ($piano, $sourceId, $destId, $amount, $reason, $userId, $reversesMovementId) {

            // 2. Recupero Dati Pivot (con lock per evitare race conditions)
            $sourcePivot = DB::table('piano_rate_capitoli')
                ->where('piano_rate_id', $piano->id)
                ->where('conto_id', $sourceId)
                ->lockForUpdate()
                ->first();

            if (!$sourcePivot) {
                throw ValidationException::withMessages(['source_id' => 'La voce sorgente non è presente in questo piano rate.']);
            }

            // Calcolo importo attuale sorgente
            // Se è NULL, significa "Tutto il residuo". Dobbiamo convertirlo in numero per sottrarre.
            // Recuperiamo l'importo originale dal Conto se è NULL.
            $sourceCurrentAmount = $sourcePivot->importo;
            if (is_null($sourceCurrentAmount)) {
                $contoSource = Conto::find($sourceId);
                $sourceCurrentAmount = $contoSource->importo; // Assumiamo che copra tutto il preventivo
            }

            // 3. Check Capienza.
            //
            // ⚠️ Non basta confrontare col pivot: quel numero è quanto QUESTO piano ha *pianificato*
            // di finanziare, non quanto è già stato speso davvero. Fino alla beta.73 si poteva
            // spostare via budget da una voce con fatture già registrate per l'intero importo —
            // il pivot scendeva, lo speso restava lì, e la voce risultava scoperta senza nessun
            // avviso al momento in cui contava. `SpesaPerVoceService` è la stessa fonte che
            // `BudgetCoverageService` usa per il cruscotto: non una query nuova su `righe_fattura`,
            // che è la fonte incompleta corretta nella beta.30 (salta regolazioni immediate e
            // fatture pregresse).
            $spesoGiaRegistrato = $this->spesoRegistratoSu($piano, $sourceId);
            $disponibileReale = $sourceCurrentAmount - $spesoGiaRegistrato;

            if ($amount > $disponibileReale) {
                if ($spesoGiaRegistrato > 0) {
                    throw ValidationException::withMessages(['amount' =>
                        'Questa voce ha già € ' . number_format($spesoGiaRegistrato / 100, 2, ',', '.') . ' di fatture registrate. '
                        . 'Puoi spostare al massimo € ' . number_format(max(0, $disponibileReale) / 100, 2, ',', '.') . ' senza lasciarla scoperta.'
                    ]);
                }
                throw ValidationException::withMessages(['amount' => 'Fondi insufficienti. Disponibili: € ' . number_format($sourceCurrentAmount / 100, 2, ',', '.')]);
            }

            // 4. Gestione Destinazione
            $destPivot = DB::table('piano_rate_capitoli')
                ->where('piano_rate_id', $piano->id)
                ->where('conto_id', $destId)
                ->lockForUpdate()
                ->first();

            $destOldAmount = $destPivot ? ($destPivot->importo ?? Conto::find($destId)->importo) : 0;

            // 5. Esecuzione Spostamento (Aggiornamento Pivot)

            // A. Riduci Sorgente
            DB::table('piano_rate_capitoli')
                ->where('id', $sourcePivot->id)
                ->update(['importo' => $sourceCurrentAmount - $amount, 'updated_at' => now()]);

            // B. Aumenta/Crea Destinazione
            if ($destPivot) {
                // Se destinazione aveva importo NULL (tutto), dobbiamo convertirlo in numero + extra
                $newDestAmount = $destOldAmount + $amount;
                DB::table('piano_rate_capitoli')
                    ->where('id', $destPivot->id)
                    ->update(['importo' => $newDestAmount, 'updated_at' => now()]);
            } else {
                // Creiamo la riga pivot se non esiste
                DB::table('piano_rate_capitoli')->insert([
                    'piano_rate_id' => $piano->id,
                    'conto_id' => $destId,
                    'importo' => $amount,
                    'note' => 'Generato da Sposta Spesa: ' . $reason,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // 6. Audit Log (Memoria Storica)
            $logId = DB::table('budget_movements')->insertGetId([
                'piano_rate_id' => $piano->id,
                'source_conto_id' => $sourceId,
                'destination_conto_id' => $destId,
                'user_id' => $userId,
                'amount' => $amount,
                'source_old_amount' => $sourceCurrentAmount,
                'destination_old_amount' => $destOldAmount,
                'reason' => $reason,
                'type' => $reversesMovementId ? 'reversal' : 'reallocation',
                'reverses_movement_id' => $reversesMovementId,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return BudgetMovement::find($logId);
        });
    }

    /**
     * Storna un movimento: crea quello uguale e contrario, non tocca né cancella l'originale.
     *
     * Stessa filosofia della contabilità di tutto il progetto — non si cancella, si rettifica.
     * Passa dalla stessa `moveBudget()`, quindi eredita lo stesso controllo sullo speso: uno
     * storno che lascerebbe scoperta la voce che aveva ricevuto i fondi si blocca allo stesso modo.
     *
     * @throws ValidationException se il movimento è già stato stornato, o è esso stesso uno storno.
     */
    public function reverseMovement(BudgetMovement $movement, int $userId): BudgetMovement
    {
        if ($movement->reverses_movement_id !== null) {
            throw ValidationException::withMessages(['movement' => 'Non si può stornare uno storno: annullerebbe di nuovo l\'originale invece di lasciare la catena leggibile.']);
        }

        $giaStornato = BudgetMovement::where('reverses_movement_id', $movement->id)->exists();

        if ($giaStornato) {
            throw ValidationException::withMessages(['movement' => 'Questo movimento è già stato stornato.']);
        }

        $piano = $movement->pianoRate;
        $reason = 'Storno del movimento del ' . $movement->created_at->format('d/m/Y')
            . ($movement->reason ? " (\"{$movement->reason}\")" : '');

        try {
            return $this->moveBudget(
                $piano,
                $movement->destination_conto_id,
                $movement->source_conto_id,
                $movement->amount,
                $reason,
                $userId,
                reversesMovementId: $movement->id,
            );
        } catch (QueryException $e) {
            // Il SELECT sopra non basta da solo: due richieste quasi simultanee sullo stesso
            // movimento possono superarlo entrambe. L'indice unico su reverses_movement_id è il
            // vero guard finale — qui lo trasformiamo in un errore leggibile invece di un 500.
            if ($this->isDoppioStornoViolation($e)) {
                throw ValidationException::withMessages(['movement' => 'Questo movimento è già stato stornato.']);
            }

            throw $e;
        }
    }

    /**
     * Verifica se una QueryException è la violazione dell'indice unico su reverses_movement_id.
     */
    private function isDoppioStornoViolation(QueryException $e): bool
    {
        $isDuplicate = ($e->errorInfo[1] ?? null) === 1062
            || ($e->errorInfo[0] ?? null) === '23000';

        return $isDuplicate && str_contains($e->getMessage(), 'reverses_movement_id');
    }

    /**
     * Quanto è già stato speso su questa voce, letto dallo stesso posto del cruscotto.
     *
     * Nessun esercizio risolvibile (capita nei piani rate di test, o su una gestione senza
     * esercizio collegato) → nessuno speso conosciuto, non un blocco: è la stessa scelta che fa
     * il resto del progetto quando un dato non è disponibile, non un'assunzione ottimistica.
     */
    private function spesoRegistratoSu(PianoRate $piano, int $contoId): int
    {
        $esercizio = $piano->gestione->esercizi()->wherePivot('attiva', true)->first()
            ?? $piano->gestione->esercizi()->first();

        if (! $esercizio instanceof Esercizio) {
            return 0;
        }

        return max(0, $this->spesaPerVoceService->perEsercizio($esercizio, [$contoId])[$contoId] ?? 0);
    }
}
