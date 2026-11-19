<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Le impostazioni di un condominio: una riga per condominio, una colonna per scelta (la scheda «Impostazioni» dell'area «Condominio»). Senza la riga valgono i
 * valori di serie, che {@see delCondominio()} rende senza scrivere niente.
 *
 * @property int $condominio_id
 * @property string $recupero_salita
 * @property bool $recupero_suggerisce_azione
 * @property ?int $aggiornate_da
 *
 * Decisioni 493 e 494: le otto scelte sugli interessi degli eredi («Recupero dei crediti», sezione «Gli interessi degli eredi»), una colonna ciascuna, con i
 * nomi di {@see \App\Services\Gestionale\Accessori\RegoleDegliEredi::SCELTE}: valgono per ogni successione, salvo quella che le cambia per sé.
 */
class ImpostazioniCondominio extends Model
{
    protected $table = 'impostazioni_condominio';

    /** Dopo una lettera, quando scadono rate nuove: la salita va avanti (di serie). */
    public const SALITA_AVANTI = 'avanti';

    /** Dopo una lettera, quando scadono rate nuove: per le rate nuove si ricomincia. */
    public const SALITA_RICOMINCIA = 'ricomincia';

    public const SALITE = [self::SALITA_AVANTI, self::SALITA_RICOMINCIA];

    /** I valori di serie, gli stessi delle colonne. */
    public const DI_SERIE = ['recupero_salita' => self::SALITA_AVANTI, 'recupero_suggerisce_azione' => true]
        + \App\Services\Gestionale\Accessori\RegoleDegliEredi::DI_SERIE;

    protected $fillable = ['condominio_id', 'recupero_salita', 'recupero_suggerisce_azione', 'aggiornate_da',
        'eredi_interessi_sugli_interessi', 'eredi_dal_decesso', 'eredi_tolleranza_rata_nuova', 'eredi_rata_per_rata', 'eredi_versamento_prima', 'eredi_anche_accordo',
        'eredi_buco_continuano', 'eredi_interessi_defunto_agli_eredi'];

    protected $casts = [
        'condominio_id' => 'integer',
        'recupero_suggerisce_azione' => 'boolean',
        'aggiornate_da' => 'integer',
        'eredi_interessi_sugli_interessi' => 'boolean',
        'eredi_dal_decesso' => 'boolean',
        'eredi_tolleranza_rata_nuova' => 'boolean',
        'eredi_rata_per_rata' => 'boolean',
        'eredi_versamento_prima' => 'boolean',
        'eredi_anche_accordo' => 'boolean',
        'eredi_buco_continuano' => 'boolean',
        'eredi_interessi_defunto_agli_eredi' => 'boolean',
    ];

    protected $attributes = self::DI_SERIE;

    /** Le impostazioni del condominio: la sua riga, o i valori di serie senza scrivere niente. */
    public static function delCondominio(int $condominioId): self
    {
        return self::where('condominio_id', $condominioId)->first() ?? new self(['condominio_id' => $condominioId] + self::DI_SERIE);
    }

    /** Le scelte, per la pagina. */
    public function perLaPagina(): array
    {
        return ['recupero_salita' => (string) $this->recupero_salita, 'recupero_suggerisce_azione' => (bool) $this->recupero_suggerisce_azione];
    }

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }
}
