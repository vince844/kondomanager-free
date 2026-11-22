<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\RegistraLeModifiche;

class CategoriaDocumento extends Model
{
    use RegistraLeModifiche;

    protected $table = 'categorie_documento';

    /**
     * ⚠️ **`slug` non è assegnabile in massa, ed è deliberato.**
     *
     * È la chiave con cui il **codice** ritrova le categorie che gli servono, e non deve poter
     * arrivare da un modulo: se un amministratore potesse scriverla, potrebbe spostare la chiave
     * «fatture» su una categoria qualunque — o toglierla — e il difetto che questa colonna chiude
     * tornerebbe da un'altra porta. L'etichetta (`name`) resta invece completamente sua.
     */
    protected $fillable = [
        'name',
        'description'
    ];

    /** Le chiavi stabili in uso. Aggiungerne una qui significa che il codice la cerca. */
    public const SLUG_FATTURE = 'fatture';

    /** I PDF caricati da un punto dell'ordine del giorno, nel passo «Documenti» dell'assemblea. */
    public const SLUG_ASSEMBLEE = 'assemblee';

    /**
     * I documenti di questa categoria — dalla 1.11.0-beta.10 il legame è **molti a molti**.
     *
     * Chi la usa per decidere se una categoria si può cancellare
     * (`CategoriaDocumentoController::destroy()`) continua a funzionare senza modifiche: la domanda
     * «questa categoria ha documenti?» ha la stessa risposta con l'una e con l'altra relazione.
     */
    public function documenti()
    {
        return $this->belongsToMany(
            Documento::class,
            'documento_categoria',
            'categoria_documento_id',
            'documento_id'
        )->withTimestamps();
    }

    /**
     * La categoria degli allegati delle fatture, ritrovata **senza dipendere dall'etichetta**.
     *
     * ## ⚠️ La ricerca è un superset di quella di prima, e questo è il punto
     *
     * Prima il codice faceva `where('name', 'Fatture')`, e bastava una rinomina per non trovare più
     * niente — con l'allegato che finiva in archivio senza categoria, in silenzio (Coda 106).
     *
     * Adesso cerca **prima la chiave stabile**, e se non la trova **ricade sull'etichetta**. Questo
     * garantisce la proprietà che serviva: si trova la categoria in **strettamente più casi di
     * prima, mai in meno**. Nessuna installazione che oggi funziona può smettere di funzionare,
     * perché il ramo vecchio è ancora lì.
     *
     * Il ripiego serve a un caso preciso e non è provvisorio: un'installazione in cui la rinomina è
     * **già** avvenuta prima di questa versione non ha nessuna riga con lo slug — il backfill della
     * migrazione cerca per nome e lì non trova niente — e continua a funzionare come oggi.
     *
     * ⚠️ Restituisce `null` se non c'è nessuna delle due, e chi chiama **deve reggerlo**: è ciò che
     * il servizio già faceva, e toglierlo trasformerebbe una degradazione silenziosa in un errore
     * in faccia a chi registra una fattura.
     */
    public static function perFatture(): ?self
    {
        return static::where('slug', self::SLUG_FATTURE)->first()
            ?? static::where('name', 'Fatture')->first();
    }

    /**
     * La categoria dei PDF caricati dai punti dell'assemblea: per chiave stabile, poi per etichetta (quella scritta dall'amministratore si usa e prende la
     * chiave), e se non c'è nasce «Assemblee». Come {@see perFatture()}, l'etichetta resta libera: rinominarla non ne fa nascere un'altra.
     */
    public static function perLeAssemblee(): self
    {
        $categoria = static::where('slug', self::SLUG_ASSEMBLEE)->first() ?? static::where('name', 'Assemblee')->whereNull('slug')->orderBy('id')->first();
        if ($categoria === null) {
            $categoria = static::create(['name' => 'Assemblee', 'description' => 'I documenti dei punti delle assemblee, caricati dal passo «Documenti».']);
        }
        if ($categoria->getAttribute('slug') === null) {
            $categoria->forceFill(['slug' => self::SLUG_ASSEMBLEE])->saveQuietly();
        }

        return $categoria;
    }
}
