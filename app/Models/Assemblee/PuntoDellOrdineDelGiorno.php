<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Assemblee;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Enums\Assemblee\AmministrazionePunto;
use App\Enums\Assemblee\ConduttorePunto;
use App\Enums\Assemblee\FontePunto;
use App\Enums\Assemblee\Maggioranza;
use App\Enums\Assemblee\MateriaPunto;
use App\Models\Esercizio;
use App\Models\Palazzina;
use App\Models\Scala;
use App\Models\Tabella;
use App\Traits\RegistraLeModifiche;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un punto dell'ordine del giorno (§1.1): la frase specifica dell'avviso per persona (`testo`) e quella senza dati personali per la bacheca
 * (`testo_pubblico`, decisione 492 (5)); la materia, la maggioranza (vuota dove il programma non propone: tabelle e criteri di riparto, Cass. SU 9839/2021),
 * l'amministrazione (di serie straordinaria per lavori, innovazioni, tabelle, riparto, destinazione d'uso e ogni maggioranza diversa da «Ordinaria», §13
 * rilievo 1), il conduttore, il perimetro facoltativo del punto (decisione 568), l'esercizio del rendiconto e del preventivo, l'importo in **centesimi**.
 *
 * `fonte` e `chiave_fonte` dicono da quale sospeso nasce (§5): un sospeso con la sua chiave in un'assemblea in preparazione o convocata non si propone più.
 * Si scrive solo da {@see \App\Services\Assemblee\OrdineDelGiorno}; compare nella «Storia» dell'assemblea.
 */
class PuntoDellOrdineDelGiorno extends Model
{
    use RegistraLeModifiche;

    protected $table = 'assemblee_punti';

    protected $fillable = [
        'assemblea_id', 'numero', 'testo', 'testo_pubblico', 'materia', 'maggioranza', 'maggioranza_libera', 'amministrazione', 'conduttore', 'ambito',
        'palazzina_id', 'scala_id', 'tabella_id', 'esercizio_id', 'importo', 'dati', 'fonte', 'chiave_fonte', 'created_by',
    ];

    protected $casts = [
        'assemblea_id' => 'integer',
        'numero' => 'integer',
        'palazzina_id' => 'integer',
        'scala_id' => 'integer',
        'tabella_id' => 'integer',
        'esercizio_id' => 'integer',
        'importo' => 'integer',
        'materia' => MateriaPunto::class,
        'maggioranza' => Maggioranza::class,
        'amministrazione' => AmministrazionePunto::class,
        'conduttore' => ConduttorePunto::class,
        'ambito' => AmbitoAssemblea::class,
        'fonte' => FontePunto::class,
        'maggioranza_libera' => 'array',
        'dati' => 'array',
    ];

    public function assemblea(): BelongsTo
    {
        return $this->belongsTo(Assemblea::class, 'assemblea_id');
    }

    /** Di che cosa parla e da dove nasce (§2, R6). */
    public function oggetti(): HasMany
    {
        return $this->hasMany(OggettoDelPunto::class, 'punto_id');
    }

    public function delibere(): HasMany
    {
        return $this->hasMany(Delibera::class, 'punto_id');
    }

    /** I memo entrati in questo punto. */
    public function memo(): HasMany
    {
        return $this->hasMany(MemoDellAssemblea::class, 'punto_id');
    }

    public function esercizio(): BelongsTo
    {
        return $this->belongsTo(Esercizio::class);
    }

    public function palazzina(): BelongsTo
    {
        return $this->belongsTo(Palazzina::class);
    }

    public function scala(): BelongsTo
    {
        return $this->belongsTo(Scala::class);
    }

    public function tabella(): BelongsTo
    {
        return $this->belongsTo(Tabella::class);
    }
}
