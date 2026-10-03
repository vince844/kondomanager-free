<?php

namespace App\Models\Gestionale;

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Un passaggio di titolarità registrato: l'evento datato con un autore (tabella `subentri`, B2).
 *
 * Progetto `docs/subentro_e_competenza_temporale.md` §4.4 e decisioni 13–14; interfaccia nel §6 di
 * `pertinenze_vendita_locazione.md` («Registra passaggio»). Chi esce, chi entra, con che ruolo, da che
 * data, con quale titolo; le due righe della pivot che chiude e apre; la coppia di conguaglio in `saldi`
 * che gli appartiene (D9: somma zero, mai le rate emesse). `nota_cancello` è la nota del cancello (1).
 *
 * Lo scrive `RegistraSubentroAction` (S5). Le colonne di S5 (`documento_id`, `data_fine_locazione`,
 * `regime_contratto`, `nota_conguaglio`, `subentro_padre_id`) arrivano con l'ottava migrazione di B2.
 */
class Subentro extends Model
{
    protected $table = 'subentri';

    public const TIPI_PASSAGGIO = ['vendita', 'inizio_locazione', 'fine_locazione', 'usufrutto'];

    /**
     * La vendita con riserva d'usufrutto (1.11.0-beta.38, decisione 28): una **vendita** — per la solidarietà, la copia
     * autentica e la regola delle straordinarie — in cui chi vende resta sulla stessa quota come usufruttuario. Il
     * marcatore sta nel `registro` e non in una colonna: nessun passaggio registrato prima può esserlo, perché fino alla
     * beta.38 la riserva era rifiutata.
     */
    public const RISERVA_USUFRUTTO = 'riserva_usufrutto';

    protected $fillable = [
        'condominio_id', 'immobile_id', 'subentro_padre_id', 'anagrafica_uscente_id', 'anagrafica_entrante_id',
        'riga_uscente_id', 'riga_entrante_id', 'tipologia', 'tipo_passaggio', 'decorrenza',
        'data_fine_locazione', 'regime_contratto', 'estremi_titolo', 'copia_autentica_il', 'documento_id',
        'nota', 'nota_cancello', 'nota_conguaglio', 'conguaglio_annullato_il', 'nota_annullamento_conguaglio', 'utente_id',
        'annullato_il', 'annullato_da', 'nota_annullamento', 'registro',
    ];

    protected $casts = [
        'decorrenza' => 'date:Y-m-d',
        'copia_autentica_il' => 'date:Y-m-d',
        'data_fine_locazione' => 'date:Y-m-d',
        'conguaglio_annullato_il' => 'datetime',
        'annullato_il' => 'datetime',
        'registro' => 'array',
    ];

    /**
     * Un passaggio annullato (1.11.0-beta.37, decisione 27) **resta nella tabella** — lo storico lo mostra, con la data,
     * chi l'ha annullato e perché — ma nessun conto lo legge più: questo scope lo nasconde a ogni lettura del modello,
     * relazioni comprese (`TitolaritaImmobile::subentriComeUscente()`, `Immobile::subentri()`, la nota di solidarietà,
     * il conguaglio, il prospetto degli oneri, l'emissione). Chi deve vederlo lo chiede con `Subentro::conAnnullati()`:
     * lo storico, l'annullamento stesso e il binding delle rotte (`Immobile::subentriConAnnullati`, per un rifiuto
     * leggibile). Le guardie che impediscono di cancellare una persona o un'unità non lo vedono, per scelta (verbale
     * della beta.37): le chiavi esterne verso `subentri` sono `nullOnDelete` o in cascata, e i nomi restano nel registro.
     * Le letture dirette della tabella (`DB::table('subentri')`) non passano da qui e filtrano da sé.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('non_annullati', fn ($query) => $query->whereNull($query->getModel()->getTable() . '.annullato_il'));
    }

    public function scopeConAnnullati($query)
    {
        return $query->withoutGlobalScope('non_annullati');
    }

    public function annullato(): bool
    {
        return $this->annullato_il !== null;
    }

    /** L'unico punto che dice se questo passaggio è una vendita con riserva d'usufrutto: vedi `RISERVA_USUFRUTTO`. */
    public function riservaUsufrutto(): bool
    {
        return $this->tipo_passaggio === 'vendita' && ($this->registro['sottotipo'] ?? null) === self::RISERVA_USUFRUTTO;
    }

    /**
     * L'estinzione dell'usufrutto. Dalla beta.37 il registro dice il sottotipo; prima no, e l'estinzione si riconosce dalla
     * tipologia del passaggio, «proprietario» (il nudo che torna pieno), come fa lo storico. Non da chi entra: l'estinzione
     * registra come chi entra il primo nudo proprietario (seconda revisione della Fase 1-ter, M2-3).
     */
    public function estinzioneUsufrutto(): bool
    {
        return $this->tipo_passaggio === 'usufrutto'
            && ($this->registro['sottotipo'] ?? ($this->tipologia === 'proprietario' ? 'estinzione' : 'costituzione')) === 'estinzione';
    }

    /**
     * Decisione 31.5 (1.11.0-beta.41): chi paga l'ordinaria dal giorno dell'atto, scelto dall'amministratore alla costituzione
     * o alla riserva d'usufrutto. `usufruttuario` (art. 1004 c.c.) è la proposta di legge, e lo è anche per i passaggi
     * registrati prima, che non lo scrivevano: era la regola fissa. Con `voce` l'ordinaria ha seguito le voci, una per una —
     * nella riserva sono passate a chi ha comprato la nuda proprietà le sole voci sul «Proprietario», nella costituzione sono
     * rimaste al nudo proprietario —, e i passaggi che vengono dopo lo devono sapere (`ConguaglioPassaggio::predecessori()`).
     */
    public const ORDINARIA_ALL_USUFRUTTUARIO = 'usufruttuario';
    public const ORDINARIA_COME_LA_VOCE = 'voce';

    public function ordinariaComeLaVoce(): bool
    {
        return ($this->registro['ordinaria_dopo_atto'] ?? null) === self::ORDINARIA_COME_LA_VOCE;
    }

    /**
     * Il passaggio da cui è nata una riga d'usufrutto: la costituzione (che la apre come entrante) o la vendita con riserva
     * (che la riapre per chi vende, e la scrive nel registro). Serve all'estinzione per sapere se quell'usufrutto era nato
     * «come la voce» (rilievo D4 della Fase 1-bis della beta.41). Null per una riga censita a mano o anteriore al registro.
     */
    public static function origineDellUsufrutto(int $rigaId, int $immobileId): ?self
    {
        $costituzione = self::where('immobile_id', $immobileId)->where('tipo_passaggio', 'usufrutto')->where('tipologia', 'usufruttuario')
            ->where('riga_entrante_id', $rigaId)->latest('id')->first();
        if ($costituzione !== null) {
            return $costituzione;
        }

        return self::vincolaRiservaUsufrutto(self::query()->toBase())->where('immobile_id', $immobileId)->orderByDesc('id')->pluck('id')
            ->map(fn ($id) => self::find($id))->filter()
            ->first(fn (self $s) => in_array($rigaId, $s->righeDelRegistro(), true));
    }

    /**
     * La stessa domanda di `riservaUsufrutto()` in SQL, per chi legge `subentri` senza il modello: `RisolutoreTitolari`,
     * nelle sue due forme (D7 via b, rilievo B4 della Fase 1-bis). `$tabella` è il nome con cui `subentri` compare nella
     * query.
     */
    public static function vincolaRiservaUsufrutto(QueryBuilder $query, string $tabella = 'subentri'): QueryBuilder
    {
        return $query->where("{$tabella}.tipo_passaggio", 'vendita')->where("{$tabella}.registro->sottotipo", self::RISERVA_USUFRUTTO);
    }

    /**
     * Gli id delle righe di titolarità che il passaggio ha scritto — chiuse, aperte o modificate —, letti dal registro
     * (1.11.0-beta.37). Vuoto per un passaggio registrato prima, che il registro non l'ha. Lo leggono le due forme della
     * decisione 24 (`TitolaritaImmobile::faParteDiUnPassaggio()` e l'elenco dei titolari), rilievo B7 della beta.38.
     *
     * @return list<int>
     */
    public function righeDelRegistro(): array
    {
        return collect($this->registro['righe'] ?? [])->pluck('id')->filter()->map(fn ($id) => (int) $id)->values()->all();
    }

    public function condominio(): BelongsTo { return $this->belongsTo(Condominio::class); }
    public function immobile(): BelongsTo { return $this->belongsTo(Immobile::class); }
    public function uscente(): BelongsTo { return $this->belongsTo(Anagrafica::class, 'anagrafica_uscente_id'); }
    public function entrante(): BelongsTo { return $this->belongsTo(Anagrafica::class, 'anagrafica_entrante_id'); }
    public function annullatoDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'annullato_da');
    }

    public function utente(): BelongsTo { return $this->belongsTo(User::class, 'utente_id'); }

    /** La coppia di conguaglio (D9): due righe, stesso `subentro_id`, somma zero. */
    public function saldi(): HasMany { return $this->hasMany(Saldo::class, 'subentro_id'); }

    /** Il PDF del titolo allegato al passaggio (S5); documento **dell'unità**, solo dell'amministratore. */
    public function documento(): BelongsTo { return $this->belongsTo(\App\Models\Documento::class, 'documento_id'); }

    /** Per la riga di una pertinenza: il passaggio dell'unità principale. */
    public function padre(): BelongsTo { return $this->belongsTo(self::class, 'subentro_padre_id'); }

    /** Le righe delle pertinenze che questo passaggio ha trascinato con sé. */
    public function pertinenze(): HasMany { return $this->hasMany(self::class, 'subentro_padre_id'); }

    /** La coppia di conguaglio è stata tolta dopo la registrazione (S6), con data e ragione. */
    public function conguaglioAnnullato(): bool { return $this->conguaglio_annullato_il !== null; }

    /** L'amministratore ha rinunciato alla coppia di conguaglio **alla registrazione**, con la sua ragione. */
    public function conguaglioRinunciato(): bool { return $this->nota_conguaglio !== null && trim((string) $this->nota_conguaglio) !== ''; }
}
