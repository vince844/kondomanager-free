<?php

namespace App\Models;

use App\Traits\RisolveIFigliDelleRotte;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Immobile extends Model
{
    use RisolveIFigliDelleRotte;

    
    use HasFactory;

    protected $table = 'immobili';

    /**
     * Come si chiama questa unità quando bisogna nominarla in una riga sola.
     *
     * Reperto «alta» della revisione della beta.58: rendendo l'interno facoltativo è emerso che in
     * sei punti — fra cui il **PDF dell'estratto conto consegnato al condòmino** — l'interno era
     * l'unico identificativo, e senza di lui restava «Int. » e nient'altro.
     *
     * La regola sta qui e non nei ventiquattro punti che costruivano l'etichetta a mano: è la stessa
     * divergenza del campo importo trovata il giorno prima, e nasce sempre dalle copie.
     *
     * L'ordine dei ripieghi non è arbitrario:
     * 1. **l'interno**, perché è come gli amministratori chiamano le unità fra loro;
     * 2. **il nome**, che è obbligatorio in creazione — quindi il ripiego esiste sempre;
     * 3. **il codice dell'unità** (`codice_immobile`: `NOT NULL`, univoco, generato qui sotto — «C16-0002»),
     *    ultima rete se un giorno anche il nome diventasse facoltativo.
     *
     * ⚠️ Il terzo ripiego era **dichiarato qui e mai scritto**: il codice restituiva `Unità #<id>`,
     * cioè la chiave primaria, che non significa niente per un amministratore. Corretto nella
     * beta.59 (coda ㊼). L'id resta come rete della rete, per il solo caso di un model costruito in
     * memoria e mai salvato.
     */
    public function getEtichettaAttribute(): string
    {
        $interno = trim((string) ($this->attributes['interno'] ?? ''));

        if ($interno !== '') {
            return 'Int. '.$interno;
        }

        $nome = trim((string) ($this->attributes['nome'] ?? ''));

        if ($nome !== '') {
            return $nome;
        }

        $codice = trim((string) ($this->attributes['codice_immobile'] ?? ''));

        if ($codice !== '') {
            return $codice;
        }

        return 'Unità #'.($this->attributes['id'] ?? '—');
    }

    /**
     * L'etichetta **estesa**: interno e nome insieme, quando ci sono entrambi.
     *
     * ⚠️ Nata da una regressione mia, trovata dal ripasso della beta.58. Applicando ovunque
     * `etichetta` avevo **tolto informazione** dove prima ce n'era di più: lo scadenziario scriveva
     * «Int. 5 (Posto auto 3)» e ha iniziato a scrivere «Int. 5». Su ogni installazione esistente —
     * dove l'interno c'è quasi sempre, perché fino a ieri era obbligatorio — la correzione del caso
     * raro peggiorava il caso comune.
     *
     * La regola: `etichetta` quando il posto è stretto o l'interno basta, `etichettaEstesa` dove
     * prima si mostravano entrambi.
     */
    public function getEtichettaEstesaAttribute(): string
    {
        $interno = trim((string) ($this->attributes['interno'] ?? ''));
        $nome = trim((string) ($this->attributes['nome'] ?? ''));

        if ($interno !== '' && $nome !== '') {
            return 'Int. '.$interno.' ('.$nome.')';
        }

        return $this->etichetta;
    }

    protected $fillable = [
        'condominio_id',
        'palazzina_id',
        'scala_id',
        'tipologia_id',
        // ⚠️ Assegnabili in massa, ma **non liberi**: che il principale stia nello stesso
        // condominio, che non sia l'unità stessa e che non sia a sua volta una pertinenza lo
        // verifica la FormRequest. Sono regole che si spiegano meglio con un messaggio che con un
        // errore SQL, ed è la ragione per cui non stanno nello schema.
        'pertinenza_di_immobile_id',
        'pertinenza_di_esterna',
        'nome',
        'descrizione',
        'interno',
        'piano',
        'superficie',
        'numero_vani',
        // ⚠️ `codice_unita` era qui e **la colonna non esiste**: sullo schema ci sono
        // `codice_immobile` e `codice_catasto`. Una chiave fillable che non corrisponde a una
        // colonna non dà errore — viene semplicemente ignorata al `create()` — quindi è il tipo di
        // riga che sopravvive per anni facendo credere che quel campo si possa valorizzare.
        //
        // `codice_immobile` resta fuori dal fillable **di proposito**: è NOT NULL, univoco a
        // livello globale, e lo genera `Immobile::booted()`. Renderlo assegnabile dall'esterno
        // significherebbe poter creare due unità con lo stesso codice da una richiesta HTTP.
        'comune_catasto',
        'sezione_catasto',
        'foglio_catasto',
        'particella_catasto',
        'subalterno_catasto',
        'codice_catasto',
        'attivo',
        'note',
    ];

    protected static function booted()
    {
        static::creating(function ($immobile) {
            // Only generate if not manually assigned
            if (! $immobile->codice_immobile) {
                $lastCode = Immobile::where('condominio_id', $immobile->condominio_id)
                    ->orderByDesc('id')
                    ->value('codice_immobile');

                $nextNumber = 1;
                if ($lastCode && preg_match('/\d+$/', $lastCode, $matches)) {
                    $nextNumber = intval($matches[0]) + 1;
                }

                // Example format: C2-0004 → "C{condominio_id}-{progressive}"
                $immobile->codice_immobile = 'C' . $immobile->condominio_id . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
            }
        });
    }

    // Relazione con il condominio
    public function condominio()
    {
        return $this->belongsTo(Condominio::class);
    }

    // Relazione con la palazzina
    public function palazzina()
    {
        return $this->belongsTo(Palazzina::class);
    }

    // Relazione con la scala
    public function scala()
    {
        return $this->belongsTo(Scala::class);
    }

    // Relazione con la tipologia dell’immobile
    public function tipologiaImmobile()
    {
        return $this->belongsTo(TipologiaImmobile::class, 'tipologia_id');
    }

    // Relazione molti-a-molti con anagrafiche (proprietari, inquilini, usufruttuari)
    public function anagrafiche()
    {
        // `id` nel pivot e modello dedicato dalla 1.11.0-beta.30 (B1 del progetto sul subentro):
        // la riga di titolarità ha un'identità propria, perché B2 registrerà più periodi per la stessa
        // persona e le rotte dovranno lavorare per periodo, non per persona. Nessun cast: vedi il
        // docblock di `TitolaritaImmobile`.
        return $this->belongsToMany(Anagrafica::class, 'anagrafica_immobile')
            ->using(TitolaritaImmobile::class)
            ->withPivot([
                'id',
                'tipologia',
                'quota',
                // `tipologie_spese` è caduta con la 1.11.0-beta.31 (decisione 8 del progetto sul subentro).
                'data_inizio',
                'data_fine',
                'attivo',
                'note',
            ])
            ->withTimestamps();
    }

    /**
     * Le righe di titolarità dell'unità come modello proprio, non come pivot di `anagrafiche()`:
     * servono a chi lavora **per periodo** — le rotte di modifica e «Dissocia» dalla 1.11.0-beta.31,
     * lo storico «Chi ha avuto questa unità», «Registra passaggio» — e a scoprire dall'`id` di una
     * riga se appartiene davvero a questa unità (`PassaggioController`, `ImmobileAnagraficaController`).
     */
    public function titolarita()
    {
        return $this->hasMany(TitolaritaImmobile::class, 'immobile_id');
    }

    /**
     * L'unità di cui questa è pertinenza — il box che punta al suo appartamento.
     *
     * ⚠️ **Sostituisce le due `belongsToMany` su `immobile_pertinenza`, tolte nella beta.53.** La
     * cardinalità molti-a-molti modellava una cosa che il diritto non consente: l'art. 817 c.c.
     * chiede che i due beni appartengano allo **stesso proprietario**, e da lì discende che una
     * pertinenza ha un solo bene principale. Il caso che il commento invocava — «il box è condiviso
     * da 2 unità» — è comproprietà del box fra due persone, e vive in `anagrafica_immobile`; se
     * invece il box è comune a un gruppo di unità non è una pertinenza, è un bene ex art. 1117 c.c.
     *
     * **Nulla nel motore la legge, ed è deliberato:** il legame non sposta millesimi, riparto,
     * saldi, rate né quorum. È presentazione.
     */
    public function pertinenzaDi()
    {
        return $this->belongsTo(Immobile::class, 'pertinenza_di_immobile_id');
    }

    /**
     * Le pertinenze di questa unità — l'appartamento che raccoglie box, cantina e soffitta.
     *
     * È il lato «uno-a-molti» del legame: un principale ne ha quante ne ha, ciascuna ne ha uno.
     */
    public function pertinenze()
    {
        return $this->hasMany(Immobile::class, 'pertinenza_di_immobile_id');
    }

    /**
     * Questa unità è dichiarata pertinenza di qualcosa? Anche di un'unità fuori dal condominio.
     *
     * Le due colonne sono alternative: `pertinenza_di_immobile_id` quando il principale è qui,
     * `pertinenza_di_esterna` per il caso Tognoli, dove l'art. 9 co. 5 L. 122/1989 impone la
     * destinazione a un'unità nello stesso **comune** — che può stare in un altro condominio.
     */
    public function haUnPrincipale(): bool
    {
        return $this->pertinenza_di_immobile_id !== null
            || filled($this->pertinenza_di_esterna);
    }

    public function documenti()
    {
        return $this->morphMany(Documento::class, 'documentable');
    }

    public function saldi()
    {
        return $this->hasMany(Saldo::class);
    }


    /**
     * Le rotte annidate sotto questo modello, e la relazione che porta a ciascun figlio.
     *
     * Vedi il blocco in testa a `App\Traits\RisolveIFigliDelleRotte` per il perché serve: Laravel
     * deriverebbe il nome con una pluralizzazione inglese, e su nomi italiani sbaglia sempre.
     *
     * @return array<string, string>
     */
    protected function relazioniDeiFigliNelleRotte(): array
    {
        return [
            // `{anagrafica}` non è più un parametro delle rotte dell'unità dalla 1.11.0-beta.31 (le
            // rotte di modifica ricevono `{titolarita}`), ma la coppia resta: costa nulla e protegge
            // chi la reintroducesse.
            'anagrafica' => 'anagrafiche',
            // La riga di titolarità cercata **dentro** l'unità: `/immobili/3/anagrafiche/1322` con una
            // riga 1322 di un'altra unità risponde 404 già qui, prima del controller.
            'titolarita' => 'titolarita',
            'documento' => 'documenti',
            // Il passaggio registrato cercato dentro l'unità (S6: `PATCH passaggi/{subentro}/copia-autentica`), annullati
            // compresi (beta.37): una seconda scheda rimasta aperta riceve un rifiuto leggibile, non una pagina 404.
            'subentro' => 'subentriConAnnullati',
        ];
    }

    /**
     * Il punto in cui si mettono in fila le scritture che cambiano chi è titolare di un'unità e che cosa deve (1.11.0-beta.37,
     * giro di verifica della Fase 1-bis): la registrazione di un passaggio, il suo annullamento, «Associa» e «Modifica»
     * bloccano le righe `immobili` delle loro unità; la generazione di un piano (`bloccaDelCondominio`) quelle di tutto il
     * condominio. Va chiamato come PRIMA istruzione della transazione: su MySQL (REPEATABLE READ) l'istantanea delle
     * letture nasce alla prima SELECT senza lock, e un lock preso dopo non fa vedere ciò che un'altra scrittura ha appena
     * committato. Sempre prima degli altri lock, così due operazioni non si incrociano (su SQLite non cambia niente).
     *
     * @param iterable<int> $ids
     */
    public static function bloccaPerScrivere(iterable $ids): void
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        if ($ids !== []) {
            \Illuminate\Support\Facades\DB::table('immobili')->whereIn('id', $ids)->lockForUpdate()->pluck('id');
        }
    }

    /** Come `bloccaPerScrivere`, per tutte le unità di un condominio: lo usa la generazione dei piani. */
    public static function bloccaDelCondominio(int $condominioId): void
    {
        \Illuminate\Support\Facades\DB::table('immobili')->where('condominio_id', $condominioId)->lockForUpdate()->pluck('id');
    }

    /** I passaggi registrati su questa unità (`subentri`, B2). */
    public function subentri(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Gestionale\Subentro::class, 'immobile_id');
    }

    /**
     * I passaggi dell'unità **con gli annullati**, solo per il binding delle rotte: `subentri()` resta quella dei conti,
     * che un passaggio annullato non deve vedere. Le rotte che ricevono un annullato lo rifiutano con un messaggio.
     */
    public function subentriConAnnullati(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Gestionale\Subentro::class, 'immobile_id')->withoutGlobalScope('non_annullati');
    }

}
