<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models;

use App\Enums\EventoTipo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;

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
     * I compiti aperti delle due Inbox (la casella delle attività dello studio e l'Inbox operativa del condominio), dei loro conteggi e del numero della barra
     * laterale: chiedono un'azione, non sono chiusi, non sono privati (le scadenze dei condòmini), e non aspettano un «Ricordamelo».
     *
     * Un compito aspetta un «Ricordamelo» quando la sua risposta (`risposte_promemoria`, riga `compito`) è in quello stato e il promemoria nell'agenda c'è
     * ancora con il giorno non arrivato, nel calendario dell'amministratore: dalla mezzanotte di quel giorno il compito torna. Il giorno si legge dall'evento
     * (il compito spostato, o il promemoria a parte), così lo spostamento nell'agenda vale; un promemoria cancellato fa tornare il compito subito (la chiave
     * esterna lascia vuoto `promemoria_evento_id`). Torna prima:
     * - un commento o un allegato, quando ne arriva uno nuovo dopo il «Ricordamelo» (`meta.ultimo_arrivo` dopo `dati.aggiornato_il`; un allegato approvato
     *   non è un arrivo);
     * - un compito con la scadenza (il promemoria a parte), quando il programma la anticipa: torna il giorno della scadenza nuova, se viene prima del giorno
     *   scelto (`start_time` diverso da `dati.scadenza`, scritta al «Ricordamelo», e non più da domani in poi).
     */
    public function scopeCompitiAperti(Builder $query): Builder
    {
        // La mezzanotte di domani nel giorno dell'amministratore, come l'agenda scrive le ore (così come si leggono).
        $domani = \App\Services\Gestionale\RispostePromemoria::inizioDiDomani();

        return $query
            ->compitiNonChiusi()
            // Una lettura sola delle risposte in attesa, non una per compito: sono poche, e la colonna dell'evento non ha un indice suo su ogni database.
            // `evento_id` non nullo: con un nullo nella lista, `NOT IN` non terrebbe nessun compito.
            ->whereNotIn('eventi.id', fn (Query $q) => $q->select('rp.evento_id')
                ->from('risposte_promemoria as rp')
                ->join('eventi as promemoria', 'promemoria.id', '=', 'rp.promemoria_evento_id')
                ->join('eventi as compito', 'compito.id', '=', 'rp.evento_id')
                ->where('rp.argomento', 'compito')
                ->where('rp.stato', 'ricordamelo')
                ->whereNotNull('rp.evento_id')
                ->where('promemoria.start_time', '>=', $domani)
                ->whereRaw(self::sqlNienteDiNuovo(), [$domani->format('Y-m-d H:i:s')]));
    }

    /**
     * I compiti non chiusi, compresi quelli nascosti da un «Ricordamelo» in attesa: `meta.requires_action` vero, `is_completed` falso, non privati. Sono i
     * compiti aperti di {@see scopeCompitiAperti()} prima della regola che nasconde; la tesoreria li conta per i bonifici segnalati (un bonifico rimandato
     * resta da verificare prima di sollecitare).
     */
    public function scopeCompitiNonChiusi(Builder $query): Builder
    {
        return $query
            ->whereJsonContains('eventi.meta->requires_action', true)
            ->where('eventi.is_completed', false)
            ->where(fn (Builder $q) => $q->where('eventi.visibility', '!=', 'private')->orWhereNull('eventi.visibility'));
    }

    /**
     * Le due condizioni per cui un compito in attesa resta nascosto: niente di nuovo dopo il «Ricordamelo» (per i commenti e gli allegati) e la scadenza non
     * anticipata (per i compiti con la scadenza). Per gli altri compiti `aggiornato_il` e `scadenza` non ci sono, e valgono sempre.
     */
    private static function sqlNienteDiNuovo(): string
    {
        if (\Illuminate\Support\Facades\DB::connection()->getDriverName() === 'sqlite') {
            $dato = fn (string $colonna, string $chiave) => "json_extract({$colonna}, '\$.{$chiave}')";
            $inizio = 'compito.start_time';
        } else {
            // Un `null` del JSON letto con JSON_UNQUOTE è la parola «null», non un NULL: NULLIF lo riporta a NULL, come su SQLite. Il «Ricordamelo» scrive le
            // due chiavi anche quando non servono, con `null`.
            $dato = fn (string $colonna, string $chiave) => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$colonna}, '\$.{$chiave}')), 'null')";
            $inizio = "DATE_FORMAT(compito.start_time, '%Y-%m-%d %H:%i:%s')";
        }
        $il = $dato('rp.dati', 'aggiornato_il');
        $arrivo = $dato('compito.meta', 'ultimo_arrivo');
        $scadenza = $dato('rp.dati', 'scadenza');

        return "({$il} IS NULL OR {$arrivo} IS NULL OR {$arrivo} <= {$il}) AND ({$scadenza} IS NULL OR {$inizio} = {$scadenza} OR compito.start_time >= ?)";
    }

    /**
     * La pulizia notturna dell'agenda (`model:prune`): gli eventi finiti da più di un anno, tranne un compito aperto con la regola delle Inbox
     * ({@see scopeCompitiAperti()}: `meta.requires_action` vero e `is_completed` falso). Il giorno del compito di una segnalazione è quello della segnalazione
     * e non si riscrive: una segnalazione aperta da più di un anno perderebbe il compito ogni notte.
     *
     * Senza la chiave `requires_action` (o senza `meta`) l'evento non è un compito e si pota: la prova del nullo è a parte perché su MySQL `JSON_CONTAINS` su
     * una chiave che manca dà NULL, e la negazione di NULL non terrebbe la riga.
     */
    public function prunable()
    {
        return static::where('end_time', '<=', now()->subYears(1))
            ->where(fn (Builder $q) => $q->where('is_completed', true)
                ->orWhereNull('meta')
                ->orWhereNull('meta->requires_action')
                ->orWhereJsonDoesntContain('meta->requires_action', true));
    }

}
