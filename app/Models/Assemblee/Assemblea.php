<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Assemblee;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Enums\Assemblee\ModalitaAssemblea;
use App\Enums\Assemblee\OrigineAssemblea;
use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\Assemblee\TipoAssemblea;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Evento;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Palazzina;
use App\Models\Scala;
use App\Models\Tabella;
use App\Traits\RegistraLeModifiche;
use App\Traits\RisolveIFigliDelleRotte;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Un'assemblea del programma o una riunione registrata a mano (Coda 679, §1.1 del progetto della tappa 2; decisione 492 (1)).
 *
 * `data` è **il giorno della riunione**, una regola sola (§13, rilievo 6): per la riunione a mano il suo giorno; per un'assemblea del programma la seconda
 * convocazione se c'è, altrimenti la prima, finché non si tiene. La scrive solo il servizio ({@see \App\Services\Assemblee\PreparazioneDellAssemblea}), mai
 * un modulo. I termini della convocazione leggono la prima convocazione delle sedute.
 *
 * Il nome («Ordinaria del 15/12/2026», come la bozza approvata) e l'archivio per anno leggono `data`.
 */
class Assemblea extends Model
{
    use RegistraLeModifiche;
    use RisolveIFigliDelleRotte;

    protected $table = 'assemblee';

    protected $fillable = [
        'condominio_id', 'ambito', 'palazzina_id', 'scala_id', 'tipo', 'origine', 'stato', 'data', 'luogo', 'modalita', 'piattaforma',
        'consenso_videoconferenza_il', 'consenso_videoconferenza_modo', 'tabella_id', 'numero_verbale', 'verbale_documento_id', 'comunicazione_id',
        'affissione_dal', 'affissione_al', 'raccomandate_entro_il', 'riconvocata_da_id', 'annullata_il', 'annullata_motivo', 'annullata_da',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'condominio_id' => 'integer',
        'palazzina_id' => 'integer',
        'scala_id' => 'integer',
        'tabella_id' => 'integer',
        'ambito' => AmbitoAssemblea::class,
        'tipo' => TipoAssemblea::class,
        'origine' => OrigineAssemblea::class,
        'stato' => StatoAssemblea::class,
        'modalita' => ModalitaAssemblea::class,
        'data' => 'date:Y-m-d',
        'consenso_videoconferenza_il' => 'date:Y-m-d',
        'affissione_dal' => 'date:Y-m-d',
        'affissione_al' => 'date:Y-m-d',
        'raccomandate_entro_il' => 'date:Y-m-d',
        'annullata_il' => 'datetime',
        'foglio_stampato_il' => 'datetime',
        'documenti_visti_il' => 'datetime',
    ];

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function palazzina(): BelongsTo
    {
        return $this->belongsTo(Palazzina::class);
    }

    public function scala(): BelongsTo
    {
        return $this->belongsTo(Scala::class);
    }

    /** La tabella del valore, proposta da quella di proprietà del perimetro. */
    public function tabella(): BelongsTo
    {
        return $this->belongsTo(Tabella::class);
    }

    /** Le date dell'avviso, nell'ordine: prima, seconda, poi le prosecuzioni. */
    public function sedute(): HasMany
    {
        return $this->hasMany(SedutaDellAssemblea::class, 'assemblea_id')->orderByRaw("CASE convocazione WHEN 'prima' THEN 0 ELSE 1 END")->orderBy('numero');
    }

    /** L'ordine del giorno. */
    public function punti(): HasMany
    {
        return $this->hasMany(PuntoDellOrdineDelGiorno::class, 'assemblea_id')->orderBy('numero');
    }

    public function delibere(): HasMany
    {
        return $this->hasMany(Delibera::class, 'assemblea_id');
    }

    /** Gli invii della convocazione: le righe del registro degli invii con l'assemblea come documento. */
    public function invii(): MorphMany
    {
        return $this->morphMany(RegistroInvio::class, 'documentabile');
    }

    /** I convocati, fissati all'invio di ognuno (§4.1). */
    public function convocati(): HasMany
    {
        return $this->hasMany(ConvocatoDellAssemblea::class, 'assemblea_id');
    }

    /** I memo che aspettano questa assemblea («Per quale assemblea»). */
    public function memo(): HasMany
    {
        return $this->hasMany(MemoDellAssemblea::class, 'assemblea_id');
    }

    /** Le deleghe dell'assemblea: valgono per tutte le sue date. */
    public function deleghe(): HasMany
    {
        return $this->hasMany(DelegaDellAssemblea::class, 'assemblea_id')->orderBy('id');
    }

    /** Il libro dei gesti della sala. */
    public function gesti(): HasMany
    {
        return $this->hasMany(GestoDellaSala::class, 'assemblea_id');
    }

    /** I verbali delle riunioni dell'assemblea: uno per riunione (la seduta che prosegue dopo una sospensione ha il suo), e le registrazioni annullate. */
    public function verbali(): HasMany
    {
        return $this->hasMany(VerbaleDellAssemblea::class, 'assemblea_id')->orderBy('id');
    }

    /** Il verbale sottoscritto della riunione registrata a mano (decisione 492 (4)): un documento con proprietario, fuori dall'archivio e dal portale. */
    public function verbale(): BelongsTo
    {
        return $this->belongsTo(Documento::class, 'verbale_documento_id');
    }

    /** I documenti dell'assemblea (il verbale sottoscritto e le sue versioni). */
    public function documenti(): MorphMany
    {
        return $this->morphMany(Documento::class, 'documentable');
    }

    /** L'avviso in bacheca (decisione 492 (3)). */
    public function comunicazione(): BelongsTo
    {
        return $this->belongsTo(Comunicazione::class);
    }

    public function riconvocataDa(): BelongsTo
    {
        return $this->belongsTo(self::class, 'riconvocata_da_id');
    }

    /** Le voci dell'agenda delle sedute (§4.6): nascoste ai condòmini. */
    public function vociInAgenda(): MorphMany
    {
        return $this->morphMany(Evento::class, 'eventable');
    }

    public function eInPreparazione(): bool
    {
        return $this->stato === StatoAssemblea::IN_PREPARAZIONE;
    }

    public function eAMano(): bool
    {
        return $this->origine === OrigineAssemblea::A_MANO;
    }

    /** «Ordinaria del 15/12/2026»; la riunione registrata a mano «Riunione del 12/03/2024». */
    public function nome(): string
    {
        $giorno = $this->data?->format('d/m/Y');
        if ($this->eAMano()) {
            return 'Riunione' . ($giorno !== null ? ' del ' . $giorno : '');
        }

        return ($this->tipo?->parola() ?? 'Assemblea') . ($giorno !== null ? ' del ' . $giorno : '');
    }

    /** @return array<string, string> */
    protected function relazioniDeiFigliNelleRotte(): array
    {
        return [
            'seduta' => 'sedute',
            'punto' => 'punti',
            'delibera' => 'delibere',
            'invio' => 'invii',
            'convocato' => 'convocati',
            'memo' => 'memo',
            'delegaRicevuta' => 'deleghe',
            'verbaleDellAssemblea' => 'verbali',
        ];
    }
}
