<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Una riga di ciò che un lotto ha scritto in archivio: quale entità, con quale azione,
 * venendo da quale riga di quale file.
 *
 * Sostituisce la colonna `import_batch_id` su quattordici tabelle — vedi la motivazione estesa
 * nella migrazione `2026_08_06_090000_create_import_tables`. Le query del motore non conoscono
 * questa tabella e non devono conoscerla: la si legge solo nel rapporto e nell'annullamento.
 */
class ImportBatchItem extends Model
{
    protected $table = 'import_batch_items';

    /** L'entità è nata da questo lotto. È l'unica azione che un annullamento disfa. */
    public const AZIONE_CREATO = 'creato';

    /** La riga è confluita in un record che esisteva già, per scelta esplicita dell'utente. */
    public const AZIONE_UNITO = 'unito';

    /** La riga è stata scartata su decisione dell'utente. Nessuna entità dietro. */
    public const AZIONE_SALTATO = 'saltato';

    protected $fillable = [
        'import_batch_id',
        'import_file_id',
        'livello',            // 'condominio', 'esercizi', 'titolarita', … (§5)
        'importabile_type',
        'importabile_id',
        'azione',
        'riga_sorgente',      // 1-based, come la vede l'utente in Excel; NULL se dedotta dal banner
    ];

    protected $casts = [
        'riga_sorgente' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(ImportFile::class, 'import_file_id');
    }

    public function importabile(): MorphTo
    {
        return $this->morphTo();
    }
}
