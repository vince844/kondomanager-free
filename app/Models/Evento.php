<?php

namespace App\Models;

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
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time'   => 'datetime',
        'meta'       => 'array',
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
