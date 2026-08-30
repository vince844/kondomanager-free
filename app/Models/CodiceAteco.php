<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Un codice della classificazione ATECO, con il suo titolo ufficiale.
 *
 * L'elenco arriva da ISTAT — «Struttura ATECO 2025 italiano inglese» — e questa tabella è la sua
 * forma interrogabile: si popola con `kondomanager:aggiorna-ateco`.
 *
 * ⚠️ **Non è un'anagrafica: è un aiuto.** Il campo «Codice ATECO» della scheda fornitore resta
 * libero e nessuna colonna del prodotto ha una foreign key verso qui. È la stessa scelta fatta per
 * i Comuni, e la ragione è la stessa: una classificazione invecchia per revisioni, e un vincolo
 * impedirebbe di scrivere il codice giusto proprio quando l'elenco è indietro di una.
 *
 * ⚠️ **E non decide niente di fiscale.** L'ATECO dice *che attività dichiara di fare* un'impresa;
 * il reverse charge in edilizia dipende dalla **prestazione fatta**, non dal codice dell'impresa.
 * Questa tabella potrà un giorno *suggerire*, mai concludere.
 */
class CodiceAteco extends Model
{
    protected $table = 'codici_ateco';

    protected $fillable = [
        'codice',
        'titolo',
        'titolo_en',
        'livello',
        'codice_padre',
        'ordine',
        'testo_ricerca',
        'versione_fonte',
        'fonte_al',
    ];

    protected $casts = [
        'livello'  => 'integer',
        'ordine'   => 'integer',
        'fonte_al' => 'date',
    ];

    /** I sei livelli, con il nome che ISTAT dà a ciascuno. */
    public const LIVELLI = [
        1 => 'sezione',
        2 => 'divisione',
        3 => 'gruppo',
        4 => 'classe',
        5 => 'categoria',
        6 => 'sottocategoria',
    ];

    /**
     * Nessuna riga può nascere non cercabile.
     *
     * ⚠️ `kondomanager:aggiorna-ateco` scrive con `upsert`, che **non fa scattare gli eventi del
     * model**: là `testo_ricerca` è calcolata a mano, e questo aggancio copre tutte le altre strade
     * — un `create()` in un test, una riga aggiunta da uno script. Senza, quella riga entrerebbe in
     * tabella e non si troverebbe mai. È la stessa rete che ha `Comune`.
     */
    protected static function booted(): void
    {
        static::saving(function (self $c) {
            if (blank($c->testo_ricerca) && filled($c->codice)) {
                $c->testo_ricerca = self::testoRicerca($c->codice, $c->titolo);
            }
        });
    }

    /**
     * Minuscola, senza accenti, senza apostrofi, spazi collassati.
     *
     * ⚠️ **È la funzione che normalizza sia la colonna sia il termine cercato, e sono due usi
     * diversi che devono restare separati.** La prima stesura ne aveva una sola — quella che
     * costruisce la colonna — usata anche per il termine: «idraulic» diventava «idraulic idraulic»
     * e non combaciava con niente. Il difetto è stato trovato provando la ricerca sui dati veri
     * prima di consegnarla, non dai test.
     *
     * ⚠️ Non ci si appoggia alla collation del database: i test girano su SQLite e la produzione su
     * MySQL, e le due non trattano accenti e maiuscole allo stesso modo.
     */
    public static function normalizza(string $testo): string
    {
        $t = Str::lower(Str::ascii($testo));
        $t = str_replace(["'", '’', '`'], '', $t);

        return trim(preg_replace('/\s+/u', ' ', $t));
    }

    /**
     * La forma su cui si cerca, **per una riga in tabella**.
     *
     * Contiene il codice in **due grafie** — «43.22.01» e «432201» — perché chi lo batte senza punti
     * deve trovarlo lo stesso, e il titolo, perché chi non ricorda il codice cerca a parole.
     */
    public static function testoRicerca(string $codice, ?string $titolo = null): string
    {
        $codicePiatto = preg_replace('/[^a-z0-9]/', '', Str::lower($codice));

        return self::normalizza(trim($codice . ' ' . $codicePiatto . ' ' . ($titolo ?? '')));
    }

    /**
     * Cerca per codice o per titolo, **parola per parola**.
     *
     * Il termine passa da `normalizza()` — **non** da `testoRicerca()`, che è il costruttore della
     * colonna e aggiungerebbe al termine una seconda copia di sé stesso.
     *
     * ⚠️ **Le parole si cercano separatamente, e la prima stesura non lo faceva.** Con un `LIKE`
     * sulla frase intera, «installazione ascensori» dava **zero** risultati — misurato — perché
     * pretendeva le due parole attaccate e in quell'ordine, mentre il titolo ufficiale è
     * «Installazione di ascensori e scale mobili». È esattamente la forma che `Comune::scopeCerca()`
     * aveva già: qui era stata copiata la struttura e non il contenuto.
     */
    public function scopeCerca(Builder $q, string $termine): Builder
    {
        $normalizzato = self::normalizza($termine);
        $parole = array_filter(explode(' ', $normalizzato));

        return $q->where(function (Builder $w) use ($parole) {
            foreach ($parole as $parola) {
                $w->where('testo_ricerca', 'like', '%' . self::scappaLike($parola) . '%');
            }
        });
    }

    /**
     * L'ordine in cui si legge: prima ciò che combacia, poi l'albero.
     *
     * ⚠️ **Senza, la ricerca è inservibile proprio sui casi comuni.** Misurato: cercando «elettric»
     * l'«Installazione di impianti elettrici» finiva alla **posizione 36 su 45**, cioè oltre il
     * taglio a venti — l'utente non lo vedeva affatto. L'ordinamento per `ordine` è quello
     * dell'albero della classificazione, ed è giusto **a parità di pertinenza**, non al posto di essa.
     * `Comune::scopeOrdinaPerRilevanza()` faceva già così.
     */
    public function scopeOrdinaPerRilevanza(Builder $q, string $termine): Builder
    {
        $t = self::normalizza($termine);
        $prefisso = self::scappaLike($t) . '%';

        return $q
            ->orderByRaw(
                'CASE WHEN codice = ? THEN 0 WHEN codice LIKE ? THEN 1 WHEN titolo LIKE ? THEN 2 ELSE 3 END',
                [$termine, $prefisso, $prefisso]
            )
            ->orderBy('ordine');
    }

    /**
     * I caratteri jolly di `LIKE` scritti dall'utente valgono come caratteri, non come jolly.
     *
     * ⚠️ **È il difetto già corretto sui Comuni, rimesso qui identico.** Misurato prima della
     * correzione: cercando `%` uscivano **tutti e 2.210** i codici classificabili, e `4_.22` ne dava
     * quindici a caso. Nessun titolo ATECO contiene `%` o `_`, quindi chi li scrive non sta cercando.
     */
    private static function scappaLike(string $testo): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $testo);
    }

    /**
     * I soli livelli che un fornitore dichiara davvero.
     *
     * Chi classifica un'impresa usa la **sottocategoria** (sei cifre) o al massimo la categoria: le
     * sezioni e le divisioni sono contenitori dell'albero, e offrirle in una tendina significa far
     * scegliere «AGRICOLTURA, SILVICOLTURA E PESCA» a chi cercava un idraulico. Restano in tabella
     * perché servono a mostrare la gerarchia di un codice trovato.
     */
    public function scopeClassificabili(Builder $q): Builder
    {
        return $q
            ->whereIn('livello', [5, 6])
            // ⚠️ **E senza i doppioni, che sono un terzo dell'elenco.** Misurato sulla fonte: delle
            // 920 categorie, **725 hanno un unico figlio e il titolo identico al suo** — «42.91.0
            // Costruzione di opere idrauliche» e «42.91.00 Costruzione di opere idrauliche» sono la
            // stessa cosa scritta due volte, e comparivano entrambe occupando due posti dei venti.
            // Si tiene la **sottocategoria**, che è il codice a sei cifre che sta sulla visura.
            ->whereNotExists(function ($sub) {
                $sub->selectRaw(1)
                    ->from('codici_ateco as figlio')
                    ->whereColumn('figlio.codice_padre', 'codici_ateco.codice')
                    ->whereColumn('figlio.titolo', 'codici_ateco.titolo')
                    // Una riga non è doppione di sé stessa. Sulla fonte ISTAT l'albero è aciclico e
                    // non può succedere, ma senza questa riga un codice che si dichiarasse padre di
                    // sé sparirebbe dai risultati senza che niente lo dica.
                    ->whereColumn('figlio.codice', '!=', 'codici_ateco.codice');
            });
    }

    /**
     * La revisione da dichiarare, e su cui cercare.
     *
     * ⚠️ **Deterministica, e non «la prima riga capitata».** Prima si usava `value('versione_fonte')`,
     * che senza ordinamento prende una riga qualunque: oggi è innocuo perché il comando ne carica
     * una sola, ma il giorno del cambio di revisione l'`upsert` lascia in tabella i codici che ISTAT
     * ha ritirato — e il dialogo avrebbe potuto dichiarare la revisione **vecchia** mentre offriva
     * quella nuova. La riga in fondo al dialogo è la condizione a cui questo aiuto è stato accettato:
     * non può essere presa a caso.
     */
    public static function revisioneCorrente(): ?string
    {
        return self::query()->max('versione_fonte');
    }

    /**
     * Solo i codici della revisione corrente.
     *
     * I superstiti di una revisione precedente restano in tabella — `upsert` corregge quelli che
     * ritrova e non tocca gli altri, e il comando di caricamento lo dice — ma **non si offrono**:
     * sono codici che ISTAT ha ritirato, e proporli senza contrassegno è peggio che non averli.
     */
    public function scopeDellaRevisioneCorrente(Builder $q): Builder
    {
        $corrente = self::revisioneCorrente();

        return $corrente === null ? $q : $q->where('versione_fonte', $corrente);
    }

    /** Il nome del livello, per dirlo a schermo invece di stampare un numero. */
    public function nomeLivello(): string
    {
        return self::LIVELLI[$this->livello] ?? 'codice';
    }

    /** Il padre nell'albero, se c'è. */
    public function padre(): ?self
    {
        return $this->codice_padre
            ? self::where('codice', $this->codice_padre)->first()
            : null;
    }
}
