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

    public const TIPI_PASSAGGIO = ['vendita', 'inizio_locazione', 'fine_locazione', 'usufrutto', 'successione'];

    /**
     * La successione per legato (1.11.0-beta.44, decisioni 65 e 66): chi entra riceve l'unità per testamento e non eredita il
     * patrimonio, quindi l'arretrato del defunto resta a suo nome («eredi di …») e per le bozze valgono le regole della vendita.
     * Come la riserva, un marcatore nel `registro` e non una colonna.
     */
    public const LEGATO = 'legato';

    /** Decisione 65 (2): l'arretrato del defunto passa agli eredi per quota, o resta a suo nome («eredi di …»). */
    public const ARRETRATO_AGLI_EREDI = 'eredi';
    public const ARRETRATO_AL_DEFUNTO = 'defunto';

    /**
     * Decisioni 72 e 73 (1.11.0-beta.48): nella successione e nel legato, con l'arretrato a nome del defunto, il conguaglio si
     * sceglie, senza preselezione. Nella richiesta i valori sono `scrivi` e `non_scrivere`; nel registro, `scritto` e `non_scritto`.
     */
    public const SCRIVI_IL_CONGUAGLIO = 'scrivi';
    public const NON_SCRIVERE_IL_CONGUAGLIO = 'non_scrivere';
    public const CONGUAGLIO_SCRITTO = 'scritto';
    public const CONGUAGLIO_NON_SCRITTO = 'non_scritto';

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

    /**
     * Decisione 47 (1.11.0-beta.42, rilievo W5): il passaggio ha un conguaglio. L'inizio locazione no, e la fine locazione solo con
     * un nuovo inquilino (con quello che entra, o il suo nome nel registro se l'anagrafica è stata cancellata): è la stessa regola
     * di `AnteprimaPassaggio::conguaglio()`. Un passaggio senza conguaglio non prende nessun piano, anche se registrato prima.
     */
    public static function tipoHaUnConguaglio(string $tipo, bool $conChiEntra): bool
    {
        return match ($tipo) {
            'inizio_locazione' => false,
            'fine_locazione' => $conChiEntra,
            // Decisione 65 (4): il conguaglio per giorni della vendita, diviso fra gli eredi per quota.
            'successione' => true,
            default => true,
        };
    }

    public function haUnConguaglio(): bool
    {
        return self::tipoHaUnConguaglio((string) $this->tipo_passaggio, $this->anagrafica_entrante_id !== null || ! empty($this->registro['nomi']['entrante'] ?? null));
    }

    /** L'unico punto che dice se questo passaggio è una vendita con riserva d'usufrutto: vedi `RISERVA_USUFRUTTO`. */
    public function riservaUsufrutto(): bool
    {
        return $this->tipo_passaggio === 'vendita' && ($this->registro['sottotipo'] ?? null) === self::RISERVA_USUFRUTTO;
    }

    /** La successione (1.11.0-beta.44): muore un proprietario o un nudo proprietario, e gli eredi entrano nello stesso ruolo. */
    public function successione(): bool
    {
        return $this->tipo_passaggio === 'successione';
    }

    /** La successione per legato: vedi `LEGATO`. */
    public function legato(): bool
    {
        return $this->successione() && ($this->registro['sottotipo'] ?? null) === self::LEGATO;
    }

    /**
     * Gli eredi della successione, dal registro (decisione 65): persona, quota **ereditata** (non la somma della riga, se l'erede era già
     * titolare: lezione della decisione 36) e riga aperta. Vuoto per gli altri tipi.
     *
     * @return list<array{anagrafica_id: int, quota: float, riga_id: ?int}>
     */
    public function eredi(): array
    {
        return self::elencoDiChiRiceve((array) ($this->registro['eredi'] ?? []));
    }

    /**
     * L'estinzione con l'accrescimento (1.11.0-beta.44): l'usufrutto di chi muore va agli usufruttuari che restano, in proporzione, e la
     * nuda resta nuda. Dal registro, come gli eredi: persona, quota ricevuta (non la somma della riga) e riga aperta. Vuoto altrimenti.
     *
     * @return list<array{anagrafica_id: int, quota: float, riga_id: ?int}>
     */
    public function accrescimento(): array
    {
        return $this->estinzioneUsufrutto() ? self::elencoDiChiRiceve((array) ($this->registro['accrescimento'] ?? [])) : [];
    }

    public function conAccrescimento(): bool
    {
        return $this->accrescimento() !== [];
    }

    /**
     * Chi riceve la quota quando le persone che entrano sono più di una e il passaggio ne nomina una sola: gli eredi della successione,
     * gli usufruttuari dell'accrescimento. Vuoto per gli altri passaggi.
     *
     * @return list<array{anagrafica_id: int, quota: float, riga_id: ?int}>
     */
    public function destinatari(): array
    {
        return $this->successione() ? $this->eredi() : $this->accrescimento();
    }

    /** @return list<array{anagrafica_id: int, quota: float, riga_id: ?int}> */
    private static function elencoDiChiRiceve(array $elenco): array
    {
        return array_values(array_map(fn ($e) => ['anagrafica_id' => (int) $e['anagrafica_id'], 'quota' => (float) $e['quota'], 'riga_id' => isset($e['riga_id']) ? (int) $e['riga_id'] : null], $elenco));
    }

    /**
     * Le righe di `saldi` dell'arretrato del defunto (decisione 65, 2): due per erede, gestione, unità ed esercizio, con il
     * `subentro_id` del passaggio come le righe del conguaglio. Vuoto se l'arretrato è rimasto a nome del defunto.
     *
     * @return list<int>
     */
    public function saldiDellArretrato(): array
    {
        return array_values(array_map('intval', (array) ($this->registro['arretrato']['saldi'] ?? [])));
    }

    /** Decisione 67 (3): i saldi del defunto da cui l'arretrato agli eredi è stato calcolato. */
    public function fontiDellArretrato(): array
    {
        return array_values(array_map('intval', (array) ($this->registro['arretrato']['fonti'] ?? [])));
    }

    /**
     * Decisione 67 (3), rilievo X6 della Fase 1-bis: la successione non annullata che ha calcolato il suo arretrato agli eredi anche su
     * uno di questi saldi. Cambiarli sotto le righe degli eredi lascerebbe a loro un debito sbagliato e al defunto un credito finto.
     *
     * @param list<int> $saldoIds
     */
    public static function successioneCheLeggeISaldi(array $saldoIds, int $condominioId): ?self
    {
        $saldoIds = array_map('intval', $saldoIds);

        return self::where('condominio_id', $condominioId)->where('tipo_passaggio', 'successione')->orderByDesc('id')->get()
            ->first(fn (self $s) => array_intersect($s->fontiDellArretrato(), $saldoIds) !== []);
    }

    /**
     * L'arretrato è passato agli eredi con le righe di `saldi`: allora coppia e arretrato sono un conto solo (ciascun erede paga la
     * sua parte dell'anno intero), e il conguaglio da solo non si annulla né si rinuncia.
     */
    public function arretratoAgliEredi(): bool
    {
        return $this->successione() && ($this->registro['arretrato']['scelta'] ?? null) === self::ARRETRATO_AGLI_EREDI && $this->saldiDellArretrato() !== [];
    }

    /**
     * Chi il passaggio fa entrare: gli eredi della successione e gli usufruttuari dell'accrescimento (`destinatari()`), altrimenti chi
     * entra. Quei passaggi registrano come chi entra una persona sola (decisione 65), e chi cerca le persone di un passaggio con
     * `anagrafica_entrante_id` perderebbe le altre.
     *
     * @return list<int>
     */
    public function entranti(): array
    {
        return $this->destinatari() !== []
            ? array_column($this->destinatari(), 'anagrafica_id')
            : array_values(array_filter([(int) $this->anagrafica_entrante_id]));
    }

    /** La riga che il passaggio ha aperto a `$anagraficaId`, se è fra chi entra (vedi `entranti()`). */
    public function rigaEntranteDi(int $anagraficaId): ?int
    {
        if ($this->destinatari() !== []) {
            return collect($this->destinatari())->firstWhere('anagrafica_id', $anagraficaId)['riga_id'] ?? null;
        }

        return (int) $this->anagrafica_entrante_id === $anagraficaId && $this->riga_entrante_id !== null ? (int) $this->riga_entrante_id : null;
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
     *
     * Rilievo X5 della Fase 1-bis della 1.11.0-beta.44: una riga aperta da un'estinzione con l'accrescimento (l'usufrutto di chi muore
     * sommato a quello di chi resta) viene dalla riga d'usufrutto della stessa persona che quel passaggio ha chiuso, e si risale alla
     * sua origine. La parte arrivata da chi muore ha la sua origine, che può avere un'altra scelta: qui conta quella della riga propria.
     */
    public static function origineDellUsufrutto(int $rigaId, int $immobileId, int $profondita = 0): ?self
    {
        $costituzione = self::where('immobile_id', $immobileId)->where('tipo_passaggio', 'usufrutto')->where('tipologia', 'usufruttuario')
            ->where('riga_entrante_id', $rigaId)->latest('id')->first();
        if ($costituzione !== null) {
            return $costituzione;
        }

        $riserva = self::vincolaRiservaUsufrutto(self::query()->toBase())->where('immobile_id', $immobileId)->orderByDesc('id')->pluck('id')
            ->map(fn ($id) => self::find($id))->filter()
            ->first(fn (self $s) => in_array($rigaId, $s->righeDelRegistro(), true));
        if ($riserva !== null || $profondita > 20) {
            return $riserva;
        }

        $accrescimento = self::where('immobile_id', $immobileId)->where('tipo_passaggio', 'usufrutto')->orderByDesc('id')->get()
            ->first(fn (self $s) => collect($s->accrescimento())->contains(fn (array $a) => (int) ($a['riga_id'] ?? 0) === $rigaId));
        if ($accrescimento === null) {
            return null;
        }
        $persona = (int) collect($accrescimento->accrescimento())->first(fn (array $a) => (int) ($a['riga_id'] ?? 0) === $rigaId)['anagrafica_id'];
        $propria = \App\Models\TitolaritaImmobile::whereIn('id', $accrescimento->righeDelRegistro())->where('anagrafica_id', $persona)->where('tipologia', 'usufruttuario')
            ->whereKeyNot($rigaId)->orderByDesc('id')->first();

        return $propria === null ? null : self::origineDellUsufrutto((int) $propria->id, $immobileId, $profondita + 1);
    }

    /**
     * Decisione 68 (1): da dove viene la parte arrivata con l'accrescimento a questa riga d'usufrutto — il passaggio dell'accrescimento e
     * l'origine dell'usufrutto di chi è morto —, o null se la riga non è nata da un accrescimento o quell'origine non si conosce.
     *
     * @return array{passaggio: self, origine: self}|null
     */
    public static function origineDellaParteAccresciuta(int $rigaId, int $immobileId): ?array
    {
        $accrescimento = self::where('immobile_id', $immobileId)->where('tipo_passaggio', 'usufrutto')->orderByDesc('id')->get()
            ->first(fn (self $s) => collect($s->accrescimento())->contains(fn (array $a) => (int) ($a['riga_id'] ?? 0) === $rigaId));
        if ($accrescimento === null || $accrescimento->riga_uscente_id === null) {
            return null;
        }
        $origine = self::origineDellUsufrutto((int) $accrescimento->riga_uscente_id, $immobileId);

        return $origine === null ? null : ['passaggio' => $accrescimento, 'origine' => $origine];
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

    /**
     * L'amministratore ha rinunciato alla coppia di conguaglio **alla registrazione**: con la sua ragione negli altri tipi, o con
     * la scelta «Non scriverlo» nella successione e nel legato, dove la nota è facoltativa (decisioni 72 e 73, 1.11.0-beta.48).
     */
    public function conguaglioRinunciato(): bool
    {
        return ($this->nota_conguaglio !== null && trim((string) $this->nota_conguaglio) !== '') || $this->conguaglioNonScritto();
    }

    /**
     * Decisioni 72 e 73 (1.11.0-beta.48): nella successione e nel legato, con l'arretrato a nome del defunto, il conguaglio è una
     * scelta senza preselezione, e il registro la conserva (`conguaglio.scelta`). «Non scritto» non dichiara un accordo fra le
     * parti: la posizione resta com'è. I passaggi di prima non hanno la chiave, e restano letti dalla nota.
     */
    public function conguaglioNonScritto(): bool
    {
        return ($this->registro['conguaglio']['scelta'] ?? null) === self::CONGUAGLIO_NON_SCRITTO;
    }

    /**
     * Decisione 46 (1.11.0-beta.42, rilievo W2): ciò che le parti hanno regolato fra loro, con la rinuncia o con l'annullamento del
     * conguaglio — «€ 5,48 sulla gestione Ordinaria 2026» —, dal registro (`regolato_fuori`, per gestione). Null se il passaggio
     * non ha né rinuncia né conguaglio annullato, se il registro non lo dice (passaggi di prima della .42: allora resta la nota) o
     * se sulla gestione chiesta non c'era niente da regolare.
     */
    public function regolatoFuoriInParole(?int $gestioneId = null): ?string
    {
        if (! $this->conguaglioRinunciato() && ! $this->conguaglioAnnullato()) {
            return null;
        }
        $voci = collect($this->registro['regolato_fuori'] ?? [])
            ->filter(fn (array $v) => ($gestioneId === null || (int) ($v['gestione_id'] ?? 0) === $gestioneId)
                && ((int) ($v['importo'] ?? 0) !== 0 || collect($v['persone'] ?? [])->contains(fn ($p) => (int) ($p['importo'] ?? 0) !== 0)));

        // Rilievo R5 (denaro) della Fase 1-bis della .48: la successione con più eredi registrata prima della .48 non ha le cifre di
        // ciascuno nel registro, e la loro somma non è la cifra di nessuno. Le coppie non sono state scritte: non si ricostruiscono, si dice.
        $piuEredi = $this->successione() && count($this->eredi()) > 1;

        return $voci->isEmpty() ? null : $voci->map(fn (array $v) => $piuEredi && ! array_key_exists('persone', $v)
            // Rilievo denaro-g1 del giro sulle correzioni: chi riceve l'unità per legato non è un erede.
            ? sprintf($this->legato() ? 'il conguaglio sulla gestione %s, ma le cifre di ciascuno non sono nel registro' : 'il conguaglio sulla gestione %s, ma le cifre per erede non sono nel registro', $v['gestione'] ?? '?')
            : self::cifreInParole($v))->join('; ');
    }

    /**
     * 1.11.0-beta.48 (P3): con più persone dalla parte di chi entra (gli eredi, i nudi che tornano pieni) la somma delle coppie ha
     * segni diversi e non è la cifra di nessuno — «€ 5,48» era € 531,45 a credito di Anna e € 268,47 e € 268,46 a debito di Bruno
     * e Carla —: si dice la cifra di ciascuno. Con una persona sola, la somma come prima.
     *
     * @param array{gestione?:string, importo?:int, persone?:list<array{nome?:string, importo?:int}>} $voce
     */
    private static function cifreInParole(array $voce): string
    {
        $persone = collect($voce['persone'] ?? [])->filter(fn ($p) => (int) ($p['importo'] ?? 0) !== 0)->values();
        if ($persone->count() < 2) {
            return sprintf('%s sulla gestione %s', \App\Helpers\MoneyHelper::format(abs((int) ($voce['importo'] ?? 0))), $voce['gestione'] ?? '?');
        }
        $pezzi = $persone->map(fn ($p) => sprintf('%s a %s di %s', \App\Helpers\MoneyHelper::format(abs((int) $p['importo'])), (int) $p['importo'] < 0 ? 'credito' : 'debito', $p['nome'] ?? '?'))->all();
        $ultimo = array_pop($pezzi);

        return sprintf('%s e %s sulla gestione %s', implode(', ', $pezzi), $ultimo, $voce['gestione'] ?? '?');
    }

    /**
     * Decisione 46: le coppie del conguaglio, per gestione, nella forma del registro (`regolato_fuori`): l'importo come quello della
     * coppia (positivo se chi entra doveva a chi esce).
     *
     * @param iterable<array{gestione_id:int, gestione:string, importo:int}> $coppie
     * @return list<array{gestione_id:int, gestione:string, importo:int}>
     */
    public static function regolatoFuoriDalleCoppie(iterable $coppie): array
    {
        return collect($coppie)->groupBy(fn ($c) => (int) $c['gestione_id'])
            ->map(fn ($g, $id) => ['gestione_id' => (int) $id, 'gestione' => (string) ($g->first()['gestione'] ?? '?'), 'importo' => (int) $g->sum('importo'),
                // P3 della 1.11.0-beta.48: la cifra di ciascuna persona che entra, per i messaggi con più eredi o più nudi.
                'persone' => $g->groupBy(fn ($c) => (int) ($c['anagrafica_entrante_id'] ?? 0))
                    ->map(fn ($p, $aid) => ['anagrafica_id' => (int) $aid, 'nome' => (string) ($p->first()['entrante_nome'] ?? '?'), 'importo' => (int) $p->sum('importo')])
                    ->values()->all()])
            ->values()->all();
    }
}
