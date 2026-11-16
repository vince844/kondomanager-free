<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Gestionale;

use App\Models\Condominio;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una regola del recupero dei crediti di un condominio, dal giorno in cui comincia: il tasso degli interessi di mora (legale, o scritto con la fonte e
 * il riferimento), i giorni dopo la scadenza da cui decorrono, i giorni di tolleranza prima di sollecitare, il termine di serie delle lettere. Vale fino
 * al giorno prima della regola dopo. Non si riscrive: una regola nuova ha la sua data, e quella vecchia resta nella storia.
 */
class RegolaRecupero extends Model
{
    public const TASSO_LEGALE = 'legale';

    public const TASSO_SCRITTO = 'scritto';

    /** Da dove viene un tasso scritto. */
    public const FONTI = ['regolamento' => 'regolamento', 'delibera' => 'delibera dell\'assemblea', 'altro' => 'altro'];

    protected $table = 'regole_recupero';

    protected $fillable = [
        'condominio_id', 'dal', 'tasso_tipo', 'tasso_annuo', 'fonte', 'riferimento', 'giorni_decorrenza', 'giorni_tolleranza', 'termine_lettere_giorni', 'creata_da',
    ];

    protected $casts = [
        'dal' => 'date',
        // Il testo con quattro decimali, uguale su MySQL e su SQLite: il tasso si legge dal testo, senza numeri con la virgola.
        'tasso_annuo' => 'decimal:4',
        'giorni_decorrenza' => 'integer',
        'giorni_tolleranza' => 'integer',
        'termine_lettere_giorni' => 'integer',
    ];

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function creataDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creata_da');
    }

    /** Il tasso scritto in decimillesimi di punto (3,00 % è 30.000), letto dal testo della colonna senza passare dai numeri con la virgola. */
    public function tassoE4(): ?int
    {
        if ($this->tasso_tipo !== self::TASSO_SCRITTO || $this->tasso_annuo === null) {
            return null;
        }
        [$intero, $decimali] = array_pad(explode('.', (string) $this->tasso_annuo, 2), 2, '');

        return (int) $intero * 10000 + (int) str_pad(substr($decimali, 0, 4), 4, '0');
    }
}
