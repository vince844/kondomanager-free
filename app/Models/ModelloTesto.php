<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un modello di testo dell'amministratore: un nome, il testo con i campi da riempire, per tutti i condomini (`condominio_id` nullo) o per uno.
 * `ambito` dice a che cosa serve (oggi solo le lettere ai condòmini, `lettera`), `tipo` il tipo dentro l'ambito (per le lettere: promemoria,
 * sollecito, diffida, messa in mora). Chi usa un modello ne copia il testo: cambiarlo o cancellarlo dopo non tocca quello che è già stato scritto.
 *
 * @property int $id
 * @property string $ambito
 * @property string $tipo
 * @property string $nome
 * @property string $testo
 * @property int|null $condominio_id
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class ModelloTesto extends Model
{
    protected $table = 'modelli_testo';

    public const AMBITO_LETTERA = 'lettera';

    public const LUNGHEZZA_NOME = 120;

    protected $fillable = ['ambito', 'tipo', 'nome', 'testo', 'condominio_id', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['condominio_id' => 'integer', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function autore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cambiatoDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** I modelli delle lettere ai condòmini. */
    public function scopePerLettere(Builder $query): Builder
    {
        return $query->where('ambito', self::AMBITO_LETTERA);
    }

    /** I modelli che valgono in un condominio: i suoi e quelli per tutti. */
    public function scopeValidiNel(Builder $query, int $condominioId): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('condominio_id')->orWhere('condominio_id', $condominioId));
    }
}
