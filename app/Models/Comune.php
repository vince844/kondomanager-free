<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Un comune italiano, con il suo codice catastale.
 *
 * L'elenco arriva da ISTAT e viaggia convertito dentro il repository
 * (`resources/data/comuni/comuni-italiani.json`); questa tabella è la sua forma interrogabile e si
 * popola con `kondomanager:aggiorna-comuni`.
 *
 * ⚠️ **Non è una tabella di anagrafica: è un aiuto.** Il campo «comune» delle schermate resta libero,
 * e nessuna colonna del prodotto ha una foreign key verso qui. È deliberato: i comuni si fondono e
 * cambiano nome, e un vincolo impedirebbe di scrivere il comune giusto proprio quando l'elenco è
 * invecchiato.
 */
class Comune extends Model
{
    protected $table = 'comuni';

    protected $fillable = [
        'codice_catasto',
        'nome',
        'nome_altra_lingua',
        'nome_ricerca',
        'sigla',
        'provincia',
        'regione',
        'fonte_al',
    ];

    protected $casts = [
        'fonte_al' => 'date',
    ];

    /**
     * Nessuna riga può nascere non cercabile.
     *
     * ⚠️ `kondomanager:aggiorna-comuni` scrive con `upsert`, che **non fa scattare gli eventi del
     * model**: là `nome_ricerca` è calcolata a mano, e questo aggancio copre tutte le altre strade —
     * un `create()` in un test, una riga aggiunta a mano, un comune caricato da uno script. Senza,
     * una riga scritta fuori dal comando entrerebbe in tabella e non si troverebbe mai.
     */
    protected static function booted(): void
    {
        static::saving(function (self $comune) {
            if (blank($comune->nome_ricerca) && filled($comune->nome)) {
                $comune->nome_ricerca = self::testoRicerca($comune->nome, $comune->nome_altra_lingua);
            }
        });
    }

    /**
     * La forma su cui si cerca: minuscola, senza accenti, senza apostrofi.
     *
     * **La stessa funzione normalizza ciò che sta in tabella e ciò che l'utente scrive**, ed è per
     * questo che funziona: chi batte «Forli», «Sant Agata» o «Sant’Agata» con l'apostrofo
     * tipografico del telefono arriva alla stessa stringa di chi li scrive per esteso.
     *
     * ⚠️ Non ci si appoggia alla collation del database. I test girano su SQLite e la produzione su
     * MySQL, e le due non trattano accenti e maiuscole allo stesso modo: una ricerca che funziona
     * «per grazia della collation» è una ricerca che si rompe cambiando ambiente.
     */
    public static function normalizza(string $testo): string
    {
        // `Str::ascii()` porta «Forlì» a «forli» e l'apostrofo tipografico ’ a quello dritto;
        // togliere anche quello copre chi non lo scrive affatto («SantAgata», «Sant Agata»).
        $t = Str::lower(Str::ascii($testo));
        $t = str_replace(["'", '’', '`'], '', $t);

        return trim(preg_replace('/\s+/u', ' ', $t));
    }

    /**
     * Il testo su cui si cerca una riga: le due denominazioni insieme, normalizzate.
     *
     * Stanno in un campo solo perché la ricerca deve trovare «Aldein» tanto quanto «Aldino» senza
     * dover interrogare due colonne con un `OR` — e perché così l'indice è uno.
     */
    public static function testoRicerca(string $nome, ?string $altra): string
    {
        return trim(self::normalizza($nome).' '.self::normalizza((string) $altra));
    }

    /**
     * La ricerca del pulsante accanto al campo.
     *
     * **Tutte le parole scritte devono comparire, in qualunque ordine e in qualunque punto.** È la
     * forma che regge i nomi italiani veri: 2.687 comuni su 7.894 sono multi-parola, e la ricerca
     * per solo prefisso non trovava «La Spezia» a chi scrive «Spezia», né «Reggio nell'Emilia» a chi
     * scrive «Reggio Emilia» — cioè come li chiamano tutti.
     *
     * Il costo è misurato e accettato: su 7.894 righe una `LIKE '%x%'` costa **4,2 ms** contro gli
     * 0,35 del prefisso. Su una tabella che sta interamente in memoria è un prezzo giusto per una
     * ricerca che trova quello che le si chiede.
     *
     * Si cerca **anche per codice**: chi ha il codice sotto mano fa il giro inverso.
     */
    public function scopeCerca(Builder $query, string $testo): Builder
    {
        $normalizzato = self::normalizza($testo);
        $parole = array_filter(explode(' ', $normalizzato));

        return $query->where(function (Builder $q) use ($parole, $testo) {
            $q->where(function (Builder $w) use ($parole) {
                foreach ($parole as $parola) {
                    $w->where('nome_ricerca', 'like', '%'.self::scappaLike($parola).'%');
                }
            })->orWhere('codice_catasto', Str::upper(trim($testo)));
        });
    }

    /**
     * L'ordine dei risultati: prima quello che l'utente ha scritto, poi il resto.
     *
     * ⚠️ Serve proprio perché la ricerca trova le parole **in mezzo** al nome. Ordinando per sola
     * denominazione, chi scrive «Roma» riceve venti comuni che contengono «roma» — Barbarano
     * Romano, Bassano Romano, Roccaromana… — e **Roma non è fra questi**, perché la R viene dopo la
     * B. Cioè la ricerca migliorata perdeva il caso più facile di tutti.
     *
     * Tre gradini: corrispondenza esatta, poi chi comincia con quel testo, poi tutti gli altri; a
     * parità, l'ordine alfabetico. La forma `CASE WHEN` regge sia su MySQL sia su SQLite, che è
     * dove girano i test.
     */
    public function scopeOrdinaPerRilevanza(Builder $query, string $testo): Builder
    {
        $normalizzato = self::normalizza($testo);

        return $query
            ->orderByRaw(
                'CASE WHEN nome_ricerca = ? THEN 0 WHEN nome_ricerca LIKE ? THEN 1 ELSE 2 END',
                [$normalizzato, self::scappaLike($normalizzato).'%']
            )
            ->orderBy('nome');
    }

    /**
     * I caratteri jolly di `LIKE` scritti dall'utente valgono come caratteri, non come jolly.
     *
     * Nessun nome di comune contiene `%` o `_`, quindi chi li scrive non sta cercando: senza questa
     * riga `%%` restituiva l'elenco intero e `_a` duemilacinquecento comuni a caso.
     */
    private static function scappaLike(string $testo): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $testo);
    }
}
