<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models;

use App\Exceptions\RegistroModifiche\RigaDelRegistroIntoccabile;
use Illuminate\Database\Eloquent\Model;

/**
 * Una riga del registro delle modifiche (Coda 679, decisione 518): chi ha cambiato che cosa e quando.
 *
 * Nessuno la modifica o la cancella, neanche l'amministratore: il modello rifiuta `update` e `delete` con un'eccezione. Le sole scritture dopo la nascita
 * sono di tre servizi dichiarati, con `DB::table` e non con il modello: il sigillo (che dà sequenza e impronte), l'oscuramento (che sostituisce i dati di
 * una persona) e la conservazione (che toglie le righe più vecchie della durata). Sono le sole eccezioni della guardia su questa tabella.
 *
 * Si scrive solo da {@see \App\Services\RegistroModifiche\RegistroModifiche::scrivi()}, che copia l'autore dal contesto, calcola l'impronta dei valori e
 * scrive la firma di nascita, subito dopo l'INSERT e nella stessa transazione.
 *
 * @property int $id
 * @property int|null $sequenza
 * @property \Illuminate\Support\Carbon $avvenuta_il
 * @property \Illuminate\Support\Carbon $registrata_il
 * @property string $autore_tipo
 * @property int|null $autore_id
 * @property string $autore_nome
 * @property string|null $autore_ruolo
 * @property string $origine
 * @property string|null $origine_dettaglio
 * @property string $area
 * @property string $oggetto_tipo
 * @property int|null $oggetto_id
 * @property string $oggetto_nome
 * @property string|null $scheda_tipo
 * @property int|null $scheda_id
 * @property string $azione
 * @property string|null $riepilogo
 * @property string|null $perche
 * @property array|null $campi
 * @property array|null $rimando
 * @property array|null $lotto
 * @property string $impronta_valori
 * @property string|null $firma la firma di nascita ({@see \App\Services\RegistroModifiche\FirmaDelRegistro})
 * @property string|null $impronta_precedente
 * @property string|null $impronta
 * @property \Illuminate\Support\Carbon|null $oscurata_il
 * @property int|null $oscurata_da
 */
class RegistroModifica extends Model
{
    protected $table = 'registro_modifiche';

    public $timestamps = false;

    protected $guarded = ['id', 'sequenza', 'impronta_precedente', 'impronta', 'firma', 'oscurata_il', 'oscurata_da'];

    protected $casts = [
        'avvenuta_il' => 'datetime',
        'registrata_il' => 'datetime',
        'oscurata_il' => 'datetime',
        'campi' => 'array',
        'rimando' => 'array',
        'lotto' => 'array',
        'sequenza' => 'integer',
        'autore_id' => 'integer',
        'oggetto_id' => 'integer',
        'scheda_id' => 'integer',
        'oscurata_da' => 'integer',
    ];

    /** Le azioni di una riga: le parole della pagina le mette chi la legge. */
    public const AZIONI = ['creata', 'modificata', 'eliminata', 'collegata', 'scollegata', 'ripristinata', 'gesto', 'oscuramento', 'ripristino_copia',
        'conservazione', 'atto_utenza'];

    /** Le aree, nell'ordine dei filtri della pagina. */
    public const AREE = ['persone', 'condominio', 'millesimi', 'piani_rate', 'contabilita', 'comunicazioni', 'segnalazioni', 'documenti', 'impostazioni',
        'utenti', 'agenda', 'assemblee'];

    /** Le origini di una riga. */
    public const ORIGINI = ['pagina', 'comando', 'pianificato', 'importazione', 'ripristino'];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw RigaDelRegistroIntoccabile::modifica($this->id);
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw RigaDelRegistroIntoccabile::cancellazione($this->id);
    }

    public function forceDelete(): ?bool
    {
        throw RigaDelRegistroIntoccabile::cancellazione($this->id);
    }

    /** I condomini della riga: `[id => nome]`, vuoto per ciò che è dello studio. @return array<int, string> */
    public function condomini(): array
    {
        return \Illuminate\Support\Facades\DB::table('registro_modifiche_condomini')->where('riga_id', $this->id)
            ->orderBy('condominio_id')->pluck('condominio_nome', 'condominio_id')->map(fn ($n) => (string) $n)->all();
    }

    /** Le persone di cui la riga contiene dati: `[[tipo, id], …]`. @return array<int, array{0: string, 1: int}> */
    public function soggetti(): array
    {
        return \Illuminate\Support\Facades\DB::table('registro_modifiche_soggetti')->where('riga_id', $this->id)
            ->orderBy('soggetto_tipo')->orderBy('soggetto_id')->get()
            ->map(fn ($s) => [(string) $s->soggetto_tipo, (int) $s->soggetto_id])->all();
    }

    public function eSigillata(): bool
    {
        return $this->sequenza !== null;
    }
}
