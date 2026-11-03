<?php

namespace App\Models;

use App\Models\Gestionale\Subentro;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * La riga di `anagrafica_immobile`: chi è titolare di un'unità, con quale ruolo, da quando a quando.
 *
 * Nasce con la 1.11.0-beta.30 (B1 del progetto `docs/subentro_e_competenza_temporale.md`) come
 * aggancio di B2. L'`id` della riga lo espone già `withPivot('id')`; il modello serve perché B2
 * lavora **per periodo** — la stessa persona che vende e ricompra, l'inquilino che diventa
 * proprietario — e le rotte indirizzano la riga per `id` (decisione 13), e perché `save()`
 * in `attach` rilegga l'`id` appena scritto (`$incrementing = true`). Fin qui le relazioni
 * `belongsToMany` lavoravano **per persona** — `updateExistingPivot($anagraficaId, …)`,
 * `detach($anagraficaId)` — e dall'interfaccia la riga per coppia è una sola (guardia 1 di
 * `ValidatesImmobileAnagraficaPivot`, agganciata alle sole FormRequest; l'importatore può scriverne
 * due con ruoli diversi, `LivelloTitolarita::commit()`).
 *
 * ⚠️ `using()` porta anche `attach`/`detach`/`updateExistingPivot` sul modello
 * (`InteractsWithPivotTable::attachUsingCustomClass` / `detachUsingCustomClass` /
 * `updateExistingPivotUsingCustomClass`). Conseguenze in B1: `updateExistingPivot($anagraficaId, …)`
 * aggiorna UNA riga della coppia — la prima che la query restituisce, senza ORDER BY — e non più
 * tutte; con valori identici a quelli in banca dati (`isDirty()` falso) non esegue nessun UPDATE,
 * `updated_at` non si muove e ritorna 0; partono gli eventi Eloquent del modello (oggi nessun
 * listener). Con una riga per coppia è indifferente. Dalla 1.11.0-beta.31 (S3) le rotte lavorano per
 * riga: `wherePivot('id', $rigaId)->updateExistingPivot(...)` tocca solo quella riga, e
 * `wherePivot('id', $rigaId)->detach($anagraficaId)` cancella solo quella.
 *
 * **I cast arrivano con la 1.11.0-beta.31 (S3), insieme all'interfaccia «Registra passaggio».** Il
 * formato `date:Y-m-d` mantiene identico il JSON di chi serializza il modello (`toArray()`:
 * `SaldoInizialeController:38-40, :66`); chi legge l'attributo a mano e lo mette in una Resource
 * (`ImmobileAnagraficaResource`, `AnagraficaController:202-204`) riceve ora un Carbon e chiama
 * `toDateString()`, cambiato nello stesso passo. `attivo` passa da `1` a `true`: il tipo TS
 * `AnagraficaPivot` lo dichiarava già booleano, e nessun lettore lo confronta con `1`.
 */
class TitolaritaImmobile extends Pivot
{
    protected $table = 'anagrafica_immobile';

    public $incrementing = true;

    protected $casts = [
        'data_inizio' => 'date:Y-m-d',
        'data_fine' => 'date:Y-m-d',
        'attivo' => 'boolean',
    ];

    public function anagrafica(): BelongsTo
    {
        return $this->belongsTo(Anagrafica::class);
    }

    public function immobile(): BelongsTo
    {
        return $this->belongsTo(Immobile::class);
    }

    /**
     * Il passaggio registrato (non annullato) che ha chiuso questa riga: come riga di chi esce, oppure sommandola o chiudendola nel suo
     * registro (`operazione = chiusa`: la riga di chi riceve in una somma, la nuda di un'estinzione). Null per una riga chiusa a mano.
     * Giro sulle correzioni della Fase 1-bis della 1.11.0-beta.44 (G3, G25): prima si guardava solo `riga_uscente_id`.
     */
    public function passaggioCheLaChiude(): ?Subentro
    {
        // Ultima revisione (UD4): i passaggi della sola unità della riga; le pertinenze hanno il loro passaggio, con il loro registro.
        return Subentro::where('immobile_id', $this->immobile_id)->orderByDesc('id')->get()
            ->first(fn (Subentro $s) => (int) $s->riga_uscente_id === (int) $this->id
                || collect($s->registro['righe'] ?? [])->contains(fn ($op) => ($op['operazione'] ?? null) === 'chiusa' && (int) ($op['id'] ?? 0) === (int) $this->id));
    }

    /** I passaggi in cui questa riga è quella che si chiude. */
    public function subentriComeUscente(): HasMany
    {
        return $this->hasMany(Subentro::class, 'riga_uscente_id');
    }

    /** I passaggi in cui questa riga è quella che si apre. */
    public function subentriComeEntrante(): HasMany
    {
        return $this->hasMany(Subentro::class, 'riga_entrante_id');
    }

    /**
     * La riga fa parte di un passaggio registrato: come uscente, come entrante, oppure è entrata con la stessa
     * decorrenza e tipologia di un passaggio su questa unità — `riga_entrante_id` è una colonna sola, e
     * nell'estinzione dell'usufrutto con più nudi il record ne nomina uno (decisione 24; verifica S8-bis, L2-2).
     * ➕ Rilievo B7 della beta.38: oppure il passaggio l'ha scritta, e il suo registro la nomina per id — chi vende e
     * resta usufruttuario nella riserva, il proprietario che resta nudo nella costituzione, la riga di prima del
     * comproprietario che compra l'altra metà (chiusa dal passaggio: l'annullamento la riaprirebbe col ruolo cambiato).
     * Uscente, entrante e tripla restano per i passaggi di prima della beta.37, che non hanno il registro.
     * Il registro qui conta e in `RisolutoreTitolari` (D7 stretto, via b) no: là deciderebbe il riparto.
     */
    public function faParteDiUnPassaggio(): bool
    {
        if ($this->subentriComeUscente()->exists() || $this->subentriComeEntrante()->exists()) {
            return true;
        }

        // Dallo scope del modello, senza i passaggi annullati: su MySQL 5.7 l'id di una riga che l'annullamento ha
        // cancellato può tornare, dopo un riavvio, su una riga nuova.
        if (Subentro::where('immobile_id', $this->immobile_id)->whereNotNull('registro')->get(['registro'])
            ->contains(fn (Subentro $s) => in_array((int) $this->id, $s->righeDelRegistro(), true))) {
            return true;
        }

        // La tripla vale solo per l'usufrutto (l'estinzione con più nudi: un record, più righe), come nel risolutore.
        return $this->data_inizio !== null && Subentro::where('immobile_id', $this->immobile_id)
            ->where('tipo_passaggio', 'usufrutto')
            ->where('tipologia', $this->tipologia)
            ->whereDate('decorrenza', $this->data_inizio->toDateString())
            ->exists();
    }

    /**
     * Il periodo copre questo giorno? La lettura dell'interfaccia — «chi è titolare oggi» — con la
     * regola D7 del progetto: `data_fine` filtra sempre, `data_inizio` conta quando c'è. `attivo`
     * falso spegne la riga in ogni caso, come per il motore.
     *
     * ⚠️ Non è la regola del motore. `RisolutoreTitolari::attiviAlla()` in B1 ignora il periodo
     * (`attivo === true` e basta) e lo riceve in S4 (inv. 7 e 8): qui si decide cosa mostrare
     * nell'elenco dei titolari attuali, non a chi intestare una quota.
     */
    public function inCorsoIl(CarbonInterface $giorno): bool
    {
        if (! $this->attivo) {
            return false;
        }

        $g = $giorno->toDateString();

        if ($this->data_inizio !== null && $this->data_inizio->toDateString() > $g) {
            return false;
        }

        return $this->data_fine === null || $this->data_fine->toDateString() >= $g;
    }

    /**
     * Una riga con storia si chiude, non si cancella (decisione 13): ha una `data_fine`, oppure è
     * l'uscente o l'entrante di un passaggio registrato in `subentri`. Cancellarla farebbe sparire
     * dall'unità un periodo che il riparto, l'estratto conto e lo storico hanno già raccontato.
     * «Dissocia» resta per la riga senza storia: quella scritta per sbaglio, o l'associazione di
     * prova che nessun documento ha ancora visto.
     *
     * La decisione 13 nomina una terza condizione, «un successore sulla stessa coppia (immobile,
     * tipologia)». **Qui non si controlla a parte, di proposito**: un successore scritto da «Registra
     * passaggio» chiude sempre il predecessore (`data_fine`, prima condizione) e lo aggancia in
     * `subentri` (seconda); una riga **aperta** con un'altra riga aperta iniziata dopo sulla stessa
     * coppia non è una successione, è una **comproprietà** che si è aggiunta (o un errore di battitura),
     * e quella si deve poter dissociare. Chiamare «successore» ogni riga iniziata dopo avrebbe reso
     * incancellabile il primo dei due coniugi. Scritto anche nel verbale S3 del piano B2.
     *
     * ➕ S5: una quarta forma, la **riga di continuazione** — la stessa persona, sulla stessa unità, con
     * una riga chiusa il giorno prima di questa (`data_fine` = `data_inizio` − 1). La scrive «Registra
     * passaggio» quando il proprietario resta come nudo proprietario (costituzione dell'usufrutto), quando
     * il nudo torna pieno (estinzione) e quando il comproprietario compra l'altra metà (decisione A): una
     * riga che prosegue una riga con storia non è una prova da dissociare. Non è il «successore» della
     * decisione 13 (persona diversa): qui la persona è la stessa.
     *
     * ➕ 1.11.0-beta.44: la riga di un erede. La successione nomina come chi entra un erede solo (decisione 65); le righe degli altri
     * stanno nel registro del passaggio, e senza questa condizione si sarebbero potute dissociare.
     */
    public function haStoria(): bool
    {
        return $this->data_fine !== null
            || $this->subentriComeUscente()->exists()
            || $this->subentriComeEntrante()->exists()
            || $this->eContinuazione()
            || $this->eRigaDiUnErede();
    }

    /** La riga è stata aperta (o sommata) da una successione per uno degli eredi. */
    public function eRigaDiUnErede(): bool
    {
        return Subentro::where('immobile_id', $this->immobile_id)->where('tipo_passaggio', 'successione')->get(['id', 'tipo_passaggio', 'registro'])
            ->contains(fn (Subentro $s) => in_array((int) $this->id, array_column($s->eredi(), 'riga_id'), true));
    }

    /** Esiste una riga della stessa persona su questa unità chiusa il giorno prima di questa? */
    public function eContinuazione(): bool
    {
        if ($this->data_inizio === null) {
            return false;
        }

        return static::query()
            ->where('immobile_id', $this->immobile_id)
            ->where('anagrafica_id', $this->anagrafica_id)
            ->where('id', '!=', $this->id)
            ->whereDate('data_fine', $this->data_inizio->subDay()->toDateString())
            ->exists();
    }
}
