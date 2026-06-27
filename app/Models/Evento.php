<?php

namespace App\Models;

use App\Enums\EventoTipo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Builder;

class Evento extends Model
{
    use MassPrunable;

    protected $table = 'eventi';

    protected $fillable = [
        'title',
        'description',
        'note',
        'start_time',
        'end_time',
        'created_by',
        'category_id',
        'recurrence_id',
        'visibility',
        'is_approved',
        'timezone',
        'meta',
        'is_completed',
        'completed_at',
        'tipo',
        'eventable_type',
        'eventable_id',
        'priorita',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time'   => 'datetime',
        'meta'       => 'array',
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
        'tipo' => EventoTipo::class,
    ];

    public function categoria()
    {
        return $this->belongsTo(CategoriaEvento::class, 'category_id');
    }

    public function ricorrenza()
    {
        return $this->belongsTo(RicorrenzaEvento::class, 'recurrence_id');
    }

    public function eccezioni()
    {
        return $this->hasMany(EccezioneEvento::class, 'evento_id');
    }

    public function anagrafiche()
    {
        return $this->belongsToMany(Anagrafica::class, 'anagrafica_evento');
    }

    public function condomini()
    {
        return $this->belongsToMany(Condominio::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Relazione polimorfica generica verso l'entità che ha scatenato l'evento.
     */
    public function eventable()
    {
        return $this->morphTo();
    }

    /**
     * Normalizza NULL a 'normale' in modo che il codice applicativo
     * riceva sempre una stringa valida per la priorità.
     */
    public function getPrioritaAttribute(?string $value): string
    {
        return $value ?? 'normale';
    }

    /**
     * SCOPE INBOX OPERATIVA:
     * Filtra solo gli eventi che richiedono attenzione ORA.
     * Logica: requires_action = true AND data inizio <= adesso.
     */
    public function scopeInbox(Builder $query): void
    {
        $query->where('meta->requires_action', true)
              ->where('start_time', '<=', now());
    }

    /**
     * LOGICA PRUNING:
     * Definisce quali record sono "spazzatura".
     * Qui: eventi finiti da più di 1 ann0.
     */
    public function prunable()
    {
        return static::where('end_time', '<=', now()->subYears(1));
    }

}
