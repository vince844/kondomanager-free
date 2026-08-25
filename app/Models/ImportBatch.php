<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Un lotto di importazione: una sessione, dall'apertura all'esito.
 *
 * È l'unità di annullamento (§13.2 di `docs/import_migrazione_dati.md`) e l'unità di racconto:
 * il `rapporto` che porta dentro è il documento di consegna della migrazione assistita (§20.5).
 *
 * **Lo stato non contiene una scadenza, e non è una dimenticanza.** L'annullamento è possibile
 * finché nessuna operazione successiva dipende dalle entità create — è una *condizione*, non un
 * timer. Vedi `annullabile()`.
 */
class ImportBatch extends Model
{
    protected $table = 'import_batches';

    /**
     * L'identificatore esterno è lo `uuid`, non la chiave primaria — è quello che ogni rotta
     * dell'import usa già (`/importa-dati/{uuid}/...`, come stringa esplicita) e quello che
     * `ControlliPostImport::perLotto()` manda al client nel campo `lotto`.
     *
     * Senza questo, il binding implicito su `ImportBatch $batch` — usato da
     * `ControlliPostImportController::aggiorna()` — risolveva sulla chiave primaria intera. Il
     * frontend inviava lo uuid, la query cercava quello uuid dentro la colonna `id`: **nessuna
     * riga corrispondeva mai**, e ogni pulsante della pagina «Da controllare dopo
     * l'importazione» rispondeva 404. Trovato da una revisione avversariale, non da un test: i
     * test esistenti chiamavano la rotta passando `$lotto->id` a mano, aggirando esattamente il
     * valore che il browser invia davvero.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public const STATO_IN_CORSO = 'in_corso';

    public const STATO_PARZIALE = 'parziale';

    public const STATO_COMPLETATO = 'completato';

    public const STATO_ANNULLATO = 'annullato';

    protected $fillable = [
        'uuid',
        'condominio_id',
        'user_id',
        'sorgente',          // 'danea' | 'manual' | 'csv_generico' | …
        'stato',
        'livello_corrente',  // il checkpoint della ripresa, non un progresso decorativo
        'decisioni',
        'rapporto',
        'completato_at',
        'annullato_at',
    ];

    protected $casts = [
        'decisioni' => 'array',
        'rapporto' => 'array',
        'completato_at' => 'datetime',
        'annullato_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $batch): void {
            $batch->uuid ??= (string) Str::uuid();
        });
    }

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ImportFile::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ImportBatchItem::class);
    }

    /**
     * Le entità che questo lotto ha davvero creato — le sole che un annullamento eliminerebbe.
     *
     * Le righe `unito` non ci sono per costruzione: quel record esisteva già, il lotto ci ha
     * solo fatto confluire dentro dei dati. Cancellarlo non sarebbe un annullamento, sarebbe
     * una perdita.
     */
    public function itemsCreati(): HasMany
    {
        return $this->items()->where('azione', ImportBatchItem::AZIONE_CREATO);
    }

    public function isAnnullato(): bool
    {
        return $this->stato === self::STATO_ANNULLATO;
    }

    /**
     * Registra la spunta di un controllo post-import.
     *
     * Scrive **solo** dentro `rapporto['controlli']`. `rapporto['livelli']` non si tocca mai: è
     * la registrazione di cosa è successo quel giorno, ed è il documento che l'amministratore
     * conserva come ricevuta dell'operazione. La sezione `controlli` non è un intruso lì dentro — «ecco cosa ho
     * verificato e quando» è materiale di consegna quanto il resto.
     *
     * Il blocco di riga non è pedanteria: due collaboratori che spuntano due voci diverse nello
     * stesso secondo si sovrascriverebbero l'array. È il prezzo vero della scelta senza tabella,
     * e si paga qui in tre righe invece di ignorarlo.
     */
    public function segna(string $chiave, string $stato, ?int $userId, ?string $nota = null): void
    {
        DB::transaction(function () use ($chiave, $stato, $userId, $nota) {
            /** @var self $fresco */
            $fresco = self::whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            $rapporto = $fresco->rapporto ?? [];
            $rapporto['controlli'][$chiave] = [
                'stato' => $stato,
                'at' => now()->toIso8601String(),
                'user_id' => $userId,
                'nota' => $nota,
            ];

            $fresco->update(['rapporto' => $rapporto]);
            $this->setAttribute('rapporto', $rapporto);
        });
    }

    /** Torna a lasciar decidere il verificatore, o a «aperto» se non ce n'è uno. */
    public function riapri(string $chiave): void
    {
        DB::transaction(function () use ($chiave) {
            /** @var self $fresco */
            $fresco = self::whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            $rapporto = $fresco->rapporto ?? [];
            unset($rapporto['controlli'][$chiave]);

            $fresco->update(['rapporto' => $rapporto]);
            $this->setAttribute('rapporto', $rapporto);
        });
    }
}
