<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models;

use App\Traits\ContaNelGesto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una versione di prima di un documento (§9 del registro delle modifiche, decisione 518 (4)): il file che c'era prima di una sostituzione, spostato nella
 * cartella delle versioni e tenuto in sola lettura, con la sua impronta. Nasce dentro il gesto della sostituzione
 * ({@see \App\Services\Documenti\VersioniDeiDocumenti::sostituisci()}); se ne va con il documento.
 *
 * @property int $id
 * @property int $documento_id
 * @property string $path
 * @property string $nome_originale
 * @property string $mime_type
 * @property int $file_size
 * @property string $impronta
 * @property \Illuminate\Support\Carbon $sostituita_il
 * @property int|null $sostituita_da
 */
class DocumentoVersione extends Model
{
    use ContaNelGesto;

    protected $table = 'documenti_versioni';

    public $timestamps = false;

    protected $fillable = ['documento_id', 'path', 'nome_originale', 'mime_type', 'file_size', 'impronta', 'sostituita_il', 'sostituita_da'];

    protected $hidden = ['path'];

    protected $casts = [
        'sostituita_il' => 'datetime',
        'file_size' => 'integer',
        'sostituita_da' => 'integer',
    ];

    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class, 'documento_id');
    }

    public function sostituitaDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sostituita_da');
    }

    /** Il nome con cui si scarica: quello di allora, con il giorno della sostituzione prima dell'estensione («Verbale (versione del 16-12-2026).pdf»). */
    public function nomeDiScaricamento(): string
    {
        $nome = $this->nome_originale !== '' ? $this->nome_originale : 'documento';
        $estensione = pathinfo($nome, PATHINFO_EXTENSION);
        $base = $estensione === '' ? $nome : mb_substr($nome, 0, -(mb_strlen($estensione) + 1));
        $giorno = $this->sostituita_il->copy()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('d-m-Y');

        return $base . ' (versione del ' . $giorno . ')' . ($estensione === '' ? '' : '.' . $estensione);
    }
}
