<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoriaDocumento extends Model
{
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
}
