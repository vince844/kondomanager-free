<?php

namespace App\Models\Gestionale;

use App\Enums\TipoAllocazioneFattura;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot Model per la tabella fattura_scrittura.
 *
 * Collega FatturaPassiva ↔ ScritturaContabile con semantica tipizzata.
 *
 * Tipi pivot (TipoAllocazioneFattura):
 *   - competenza    → registrazione iniziale del debito nel ledger.
 *                     NON partecipa al calcolo saldo/residuo.
 *                     Creata da FatturaPassivaService al momento della registrazione.
 *   - pagamento     → chiusura debito tramite uscita di cassa.
 *                     PARTECIPA al calcolo saldo.
 *   - compensazione → chiusura debito tramite nota di credito o netting.
 *                     PARTECIPA al calcolo saldo.
 *
 * INVARIANTE storni: importo_allocato può essere NEGATIVO.
 * Lo storno non cancella il record originale (ledger immutabile) ma aggiunge
 * un nuovo record pivot con importo_allocato = -(valore originale) e stesso tipo.
 * Esempio: pagamento di 10000 cent → storno aggiunge pivot con importo_allocato = -10000.
 *
 * La coerenza tipo ↔ tipo_movimento della ScritturaContabile è responsabilità
 * del PagamentoFornitoreService, non di questo model.
 *
 * NOTA $timestamps: la relazione BelongsToMany in FatturaPassiva usa ->withTimestamps(),
 * quindi questa pivot deve avere $timestamps = true (default Pivot è false).
 */
class FatturaScrittura extends Pivot
{
    protected $table = 'fattura_scrittura';

    /**
     * La pivot ha una colonna `id` auto-increment — necessario per:
     * - Identificare univocamente ogni riga in insert/update/delete
     * - Permettere record multipli dello stesso tipo sulla stessa coppia (fattura, scrittura)
     *   (es. pagamento parziale + storno parziale sulla stessa fattura e scrittura)
     */
    public $incrementing = true;

    /**
     * Necessario perché la relazione usa ->withTimestamps().
     * Senza questo flag, created_at e updated_at vengono ignorati silenziosamente.
     */
    public $timestamps = true;

    protected $casts = [
        // BIGINT signed nel DB → int PHP (gestisce correttamente i negativi degli storni)
        'importo_allocato' => 'integer',
        // VARCHAR(50) nel DB → TipoAllocazioneFattura Backed Enum
        'tipo'             => TipoAllocazioneFattura::class,
    ];
}