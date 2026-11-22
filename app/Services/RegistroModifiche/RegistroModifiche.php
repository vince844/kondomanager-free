<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use App\Models\RegistroModifica;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Il registro delle modifiche (Coda 679, decisione 518): l'unico punto da cui nasce una riga.
 *
 * Due livelli. **Il campo**: il tratto {@see \App\Traits\RegistraLeModifiche} sui modelli delle schede scrive, a ogni salvataggio, i dati cambiati con le
 * parole della pagina. **Il gesto**: dove il programma scrive in blocco o in tante righe che sono un gesto solo, l'azione scrive una riga sola con il
 * riepilogo e il rimando ({@see gesto()}, {@see durante()}).
 *
 * La riga si scrive **nella transazione della modifica** (se la modifica non va, la riga non c'è; se la riga non va, la modifica non va); un gesto fuori da
 * una transazione ne apre una (P12). Il posto nella catena lo dà il sigillo, dopo il commit ({@see SigilloDelRegistro}). L'autore si legge dal contesto
 * ({@see ContestoDelRegistro::autore()}): la persona in sessione o «Il programma».
 */
final class RegistroModifiche
{
    /**
     * Scrive una riga. È la forma di base: il tratto, i gesti, «Ripristina», l'oscuramento, il ripristino di una copia e la conservazione passano da qui.
     *
     * Chiavi di `$riga`: `area`, `oggetto_tipo`, `oggetto_id`, `oggetto_nome`, `azione` (obbligatorie); `scheda_tipo`, `scheda_id`, `riepilogo`, `perche`,
     * `campi`, `rimando`, `lotto`, `condomini` (`[id => nome]`), `soggetti` (`[[tipo, id], …]`), `avvenuta_il`, `autore` ({@see Autore}).
     *
     * **Se la riga non va dentro una transazione aperta**, la transazione non si conferma più: l'errore riparte qui, e se chi chiama lo prende e va avanti
     * il commit si rifiuta ({@see RigheRimandate::rigaNonScritta()}). Così una modifica non si conferma mai senza la sua riga.
     *
     * @param array<string, mixed> $riga
     */
    public static function scrivi(array $riga): RegistroModifica
    {
        try {
            return self::scriviLaRiga($riga);
        } catch (\Throwable $e) {
            RigheRimandate::rigaNonScritta($e);

            throw $e;
        }
    }

    /** @param array<string, mixed> $riga */
    private static function scriviLaRiga(array $riga): RegistroModifica
    {
        $area = (string) ($riga['area'] ?? '');
        $azione = (string) ($riga['azione'] ?? '');
        if (! in_array($area, RegistroModifica::AREE, true)) {
            throw new InvalidArgumentException("Registro delle modifiche: area sconosciuta «{$area}».");
        }
        if (! in_array($azione, RegistroModifica::AZIONI, true)) {
            throw new InvalidArgumentException("Registro delle modifiche: azione sconosciuta «{$azione}».");
        }

        // Una riga rimandata dello stesso oggetto (una scheda nuova, una scrittura) va prima: l'ordine resta quello dei fatti.
        if (isset($riga['oggetto_id'])) {
            RigheRimandate::scriviOra((string) ($riga['oggetto_tipo'] ?? '') . ':' . (int) $riga['oggetto_id']);
        }

        $autore = $riga['autore'] ?? ContestoDelRegistro::autore();
        $adesso = now()->utc()->startOfSecond();
        $avvenuta = isset($riga['avvenuta_il']) && $riga['avvenuta_il'] instanceof \DateTimeInterface
            ? \Illuminate\Support\Carbon::instance($riga['avvenuta_il'])->utc()->startOfSecond()
            : $adesso;

        // I valori come li rileggerà il database (il JSON andata e ritorno), così l'impronta ricalcolata torna uguale.
        $valori = [];
        foreach (['campi', 'rimando', 'lotto'] as $chiave) {
            $v = $riga[$chiave] ?? null;
            $valori[$chiave] = $v === null || $v === [] ? null : json_decode((string) json_encode($v), true);
        }
        $valori['riepilogo'] = self::taglia($riga['riepilogo'] ?? null, 500);
        $valori['perche'] = isset($riga['perche']) && trim((string) $riga['perche']) !== '' ? (string) $riga['perche'] : null;

        $dati = [
            'avvenuta_il' => $avvenuta->format('Y-m-d H:i:s'),
            'registrata_il' => $adesso->format('Y-m-d H:i:s'),
            'autore_tipo' => $autore->tipo,
            'autore_id' => $autore->id,
            'autore_nome' => (string) self::taglia($autore->nome, 255),
            'autore_ruolo' => self::taglia($autore->ruolo, 255),
            'origine' => $autore->origine,
            'origine_dettaglio' => self::taglia($autore->dettaglio, 255),
            'area' => $area,
            'oggetto_tipo' => (string) self::taglia((string) ($riga['oggetto_tipo'] ?? ''), 60),
            'oggetto_id' => isset($riga['oggetto_id']) ? (int) $riga['oggetto_id'] : null,
            'oggetto_nome' => (string) self::taglia((string) ($riga['oggetto_nome'] ?? ''), 255),
            'scheda_tipo' => isset($riga['scheda_tipo']) ? (string) self::taglia((string) $riga['scheda_tipo'], 60) : null,
            'scheda_id' => isset($riga['scheda_id']) ? (int) $riga['scheda_id'] : null,
            'azione' => $azione,
            'riepilogo' => $valori['riepilogo'],
            'perche' => $valori['perche'],
            'campi' => $valori['campi'],
            'rimando' => $valori['rimando'],
            'lotto' => $valori['lotto'],
        ];
        $dati['impronta_valori'] = ImprontaDelRegistro::deiValori($valori);

        $condomini = [];
        foreach ((array) ($riga['condomini'] ?? []) as $id => $nome) {
            if ((int) $id > 0) {
                $condomini[(int) $id] = (string) self::taglia((string) $nome, 255);
            }
        }
        $soggetti = [];
        foreach ((array) ($riga['soggetti'] ?? []) as [$tipo, $id]) {
            if ($id !== null) {
                $soggetti[$tipo . ':' . (int) $id] = ['soggetto_tipo' => (string) $tipo, 'soggetto_id' => (int) $id];
            }
        }

        $scrivi = function () use ($dati, $condomini, $soggetti): RegistroModifica {
            $nuova = new RegistroModifica();
            $nuova->forceFill($dati);
            $nuova->save();

            if ($condomini !== []) {
                DB::table('registro_modifiche_condomini')->insert(array_map(
                    fn ($id, $nome) => ['riga_id' => $nuova->id, 'condominio_id' => $id, 'condominio_nome' => $nome],
                    array_keys($condomini), array_values($condomini)));
            }
            if ($soggetti !== []) {
                DB::table('registro_modifiche_soggetti')->insert(array_map(fn ($s) => $s + ['riga_id' => $nuova->id], array_values($soggetti)));
            }

            // La firma di nascita ({@see FirmaDelRegistro}): copre anche l'`id`, che c'è solo dopo l'INSERT, così una riga del programma copiata in
            // un'altra con un numero nuovo non torna. Nella stessa transazione: nessun altro vede la riga prima della firma.
            $firma = FirmaDelRegistro::firma($dati + ['id' => $nuova->id], $condomini,
                array_map(fn ($s) => [$s['soggetto_tipo'], $s['soggetto_id']], array_values($soggetti)));
            DB::table('registro_modifiche')->where('id', $nuova->id)->update(['firma' => $firma]);
            $nuova->setRawAttributes(['firma' => $firma] + $nuova->getAttributes(), true);

            DB::afterCommit(fn () => SigilloDelRegistro::sigillaSenzaFermare());

            return $nuova;
        };

        return self::nellaTransazione($scrivi);
    }

    /**
     * Il lavoro e le sue righe in una transazione: la sua, se non ce n'è una aperta; quella di chi chiama, se c'è già (il tratto delle schede, una riga
     * scritta a mano, le impostazioni). Dentro una transazione aperta non se ne apre un'altra: Laravel la farebbe con un savepoint che non rilascia mai, e
     * su SQLite ogni scrittura dopo costa in proporzione ai savepoint aperti, mentre il gestore delle transazioni scorre a ogni chiusura la lista di
     * quelle già chiuse (misurato: l'emissione di 3.000 unità diventava più che lineare). Se la riga non va, l'eccezione esce e la
     * transazione di chi chiama torna indietro tutta, con la modifica.
     *
     * @template T
     * @param Closure(): T $lavoro
     * @return T
     */
    public static function nellaTransazione(Closure $lavoro): mixed
    {
        return DB::transactionLevel() > 0 ? $lavoro() : DB::transaction($lavoro);
    }

    /**
     * Un gesto: una riga sola con il riepilogo e il rimando. Dentro un altro gesto aperto con {@see durante()} non scrive: si conta nel lotto di quello
     * esterno (P10), e restituisce `null`.
     */
    public static function gesto(Gesto $gesto): ?RegistroModifica
    {
        $aperto = ContestoDelRegistro::lottoAperto();
        if ($aperto !== null) {
            $aperto->assorbi($gesto);

            return null;
        }

        return self::scrivi($gesto->perLaRiga());
    }

    /**
     * Esegue il lavoro di un gesto: tiene spente le righe del tratto (le scritture dei modelli si contano nel lotto), e alla fine scrive la riga del gesto
     * con il lotto. Tutto in una transazione (se non ce n'è già una): se il lavoro non va, la riga non c'è. Lo stato si rimette in un `finally`.
     *
     * Un gesto dentro un altro si conta nel lotto di quello esterno. Un lavoro in più transazioni (l'importazione, una per livello) chiama `durante` una
     * volta per transazione e scrive una riga per transazione.
     *
     * @template T
     * @param Closure(Gesto): T $lavoro
     * @return T
     */
    public static function durante(Gesto $gesto, Closure $lavoro): mixed
    {
        $esterno = ContestoDelRegistro::lottoAperto();
        $lotto = new Lotto();
        $gesto->preparaIlLotto($lotto);

        $esegui = function () use ($gesto, $lavoro, $lotto, $esterno) {
            ContestoDelRegistro::apriLotto($lotto);
            try {
                $esito = $lavoro($gesto);
            } finally {
                ContestoDelRegistro::chiudiLotto();
            }

            $gesto->chiudi($lotto, $esito);
            if ($esterno !== null) {
                $esterno->assorbi($gesto, $lotto);
            } elseif ($gesto->daScrivere($lotto, $esito)) {
                self::scrivi($gesto->perLaRiga($lotto, $esito));
            }

            return $esito;
        };

        if ($esterno !== null) {
            return $esegui();
        }

        return DB::transaction($esegui);
    }

    /**
     * Un legame fatto con `sync`/`attach`/`detach` su una pivot senza modello (gesto 7): un campo della scheda madre, «Condomini: Via Roma 12 → Via Roma
     * 12, Corso Italia 3». Legge i nomi collegati prima e dopo il lavoro; se non cambiano, nessuna riga. I condomini della riga sono quelli di prima e di
     * dopo (togliere una persona da un condominio si vede anche lì); se i collegati sono persone, sono fra i soggetti (P9). Dentro un gesto si conta.
     *
     * @template T
     * @param Closure(): T $lavoro
     * @return T
     */
    public static function legame(Model $madre, string $relazione, string $etichetta, Closure $lavoro, string $colonnaDelNome = 'nome'): mixed
    {
        $lotto = ContestoDelRegistro::lottoAperto();
        if ($lotto !== null) {
            $esito = $lavoro();
            $lotto->conta(CatalogoDelRegistro::tipoDi($madre), 'collegata', $madre->getKey() === null ? null : (int) $madre->getKey());

            return $esito;
        }

        $descrizione = CatalogoDelRegistro::descrizionePer($madre);
        $rimandata = self::chiaveDellaRimandata($madre);
        self::avvisaSeFuoriDallaCreazione($madre, $rimandata);
        $condominiPrima = $rimandata === null ? ($descrizione?->condomini($madre) ?? []) : [];
        $prima = self::collegati($madre, $relazione, $colonnaDelNome);
        $esito = $lavoro();
        $dopo = self::collegati($madre, $relazione, $colonnaDelNome);
        if (array_keys($prima) === array_keys($dopo)) {
            return $esito;
        }

        // Decisione 554: la scheda è nata in questa transazione e la sua «Creata» aspetta la fine: il legame va lì, non in una riga sua. I condomini la
        // riga li legge da sé alla fine; gli altri legami (destinatari, categorie, rappresentanti) sono un dato della riga.
        if ($rimandata !== null) {
            RigheRimandate::aggiungiLegame($rimandata, [
                'campo' => $relazione === 'condomini' ? null : Campo::nuovo($relazione, $etichetta, $prima === [] ? null : implode(', ', $prima),
                    $dopo === [] ? null : implode(', ', $dopo), array_keys($prima), array_keys($dopo)),
                'soggetti' => $madre->{$relazione}()->getRelated() instanceof \App\Models\Anagrafica
                    ? array_map(fn ($id) => ['anagrafica', (int) $id], array_values(array_unique([...array_keys($prima), ...array_keys($dopo)]))) : [],
            ]);

            return $esito;
        }

        $gesto = Gesto::sulModello($madre)->azione('modificata')
            ->campo($relazione, $etichetta, $prima === [] ? null : implode(', ', $prima), $dopo === [] ? null : implode(', ', $dopo),
                array_keys($prima), array_keys($dopo))
            ->neiCondomini(array_keys($condominiPrima));
        if ($madre->{$relazione}()->getRelated() instanceof \App\Models\Anagrafica) {
            $gesto->soggettiDi('anagrafica', array_unique([...array_keys($prima), ...array_keys($dopo)]));
        }
        if ($madre instanceof \App\Models\Anagrafica && $relazione === 'condomini') {
            $gesto->neiCondomini(array_keys($dopo));
        }
        self::scrivi($gesto->perLaRiga());

        return $esito;
    }

    /**
     * Un campo della scheda madre scritto in blocco (le tabelle di una voce, con il coefficiente e le ripartizioni): una fotografia prima e dopo il lavoro
     * (`fn (): array<int|string, string>`), e se cambia un campo «com'era → com'è». Dentro un gesto si conta.
     *
     * @template T
     * @param Closure(): array<int|string, string> $fotografia
     * @param Closure(): T $lavoro
     * @return T
     */
    public static function campoDellaScheda(Model $madre, string $chiave, string $etichetta, Closure $fotografia, Closure $lavoro): mixed
    {
        $lotto = ContestoDelRegistro::lottoAperto();
        if ($lotto !== null) {
            $esito = $lavoro();
            $lotto->conta(CatalogoDelRegistro::tipoDi($madre), 'modificata', $madre->getKey() === null ? null : (int) $madre->getKey());

            return $esito;
        }
        $prima = $fotografia();
        $esito = $lavoro();
        $dopo = $fotografia();
        if ($prima === $dopo) {
            return $esito;
        }
        // Decisione 554: sulla scheda nata in questa transazione il campo va nella sua «Creata».
        $rimandata = self::chiaveDellaRimandata($madre);
        self::avvisaSeFuoriDallaCreazione($madre, $rimandata);
        if ($rimandata !== null) {
            RigheRimandate::aggiungiLegame($rimandata, ['campo' => Campo::nuovo($chiave, $etichetta, $prima === [] ? null : implode('; ', $prima),
                $dopo === [] ? null : implode('; ', $dopo), array_keys($prima), array_keys($dopo))]);

            return $esito;
        }
        self::scrivi(Gesto::sulModello($madre)->azione('modificata')->campo($chiave, $etichetta, $prima === [] ? null : implode('; ', $prima),
            $dopo === [] ? null : implode('; ', $dopo), array_keys($prima), array_keys($dopo))->perLaRiga());

        return $esito;
    }

    /**
     * Decisione 554: un legame su una scheda appena creata, fuori da una transazione del programma, arriva quando la sua «Creata» è già scritta (senza
     * condomini, se il legame è quello): la riga resta giusta, ma è la forma che la decisione ha scartato. Il log lo dice, per trovare il punto; la
     * guardia della suite cerca gli stessi punti nel testo del codice (`GuardiaDelRegistroTest`).
     */
    private static function avvisaSeFuoriDallaCreazione(Model $madre, ?string $rimandata): void
    {
        if ($rimandata === null && $madre->wasRecentlyCreated && ! RigheRimandate::inUnaTransazioneDelProgramma()) {
            \Illuminate\Support\Facades\Log::warning('Registro delle modifiche: un legame su una scheda appena creata, fuori da una transazione: la «Creata» '
                . 'è già scritta (decisione 554).', ['scheda' => CatalogoDelRegistro::tipoDi($madre) . ':' . (int) $madre->getKey()]);
        }
    }

    /** La chiave della riga rimandata della scheda madre, se è nata in questa transazione e la sua «Creata» aspetta la fine; altrimenti null. */
    private static function chiaveDellaRimandata(Model $madre): ?string
    {
        if ($madre->getKey() === null) {
            return null;
        }
        $chiave = CatalogoDelRegistro::tipoDi($madre) . ':' . (int) $madre->getKey();

        return RigheRimandate::ce($chiave) ? $chiave : null;
    }

    /** I collegati di una relazione: id => nome, nell'ordine degli id. @return array<int, string> */
    private static function collegati(Model $madre, string $relazione, string $colonnaDelNome): array
    {
        $rel = $madre->{$relazione}();
        $tabella = $rel->getRelated()->getTable();

        return $rel->reorder()->orderBy($tabella . '.id')->get([$tabella . '.id', $tabella . '.' . $colonnaDelNome])
            ->mapWithKeys(fn ($m) => [(int) $m->getKey() => (string) $m->getAttribute($colonnaDelNome)])->all();
    }

    /** @var list<array{0: Gesto, 1: Lotto, 2: ?Lotto}> i gesti aperti a mano, dal più vecchio */
    private static array $gestiAperti = [];

    /**
     * Per il codice che apre e chiude la transazione a mano (`DB::beginTransaction()` … `DB::commit()`, con i ritorni in mezzo): apre il gesto come
     * `durante`. Si chiude con {@see chiudiGesto()} prima del commit; un rollback lo lascia da solo (`DB::afterRollBack`), così un ritorno anticipato o
     * un'eccezione non lasciano il tratto spento per il resto del processo.
     */
    public static function apriGesto(Gesto $gesto): void
    {
        $esterno = ContestoDelRegistro::lottoAperto();
        $lotto = new Lotto();
        $gesto->preparaIlLotto($lotto);
        ContestoDelRegistro::apriLotto($lotto);
        self::$gestiAperti[] = [$gesto, $lotto, $esterno];
        if (DB::transactionLevel() > 0) {
            DB::afterRollBack(fn () => self::lasciaGesto($lotto));
        }
    }

    /** Chiude l'ultimo gesto aperto a mano e scrive la sua riga (o la conta in quello esterno). */
    public static function chiudiGesto(mixed $esito = null): ?RegistroModifica
    {
        $aperto = array_pop(self::$gestiAperti);
        if ($aperto === null) {
            return null;
        }
        [$gesto, $lotto, $esterno] = $aperto;
        ContestoDelRegistro::togliLotto($lotto);
        $gesto->chiudi($lotto, $esito);
        if ($esterno !== null) {
            $esterno->assorbi($gesto, $lotto);

            return null;
        }

        return $gesto->daScrivere($lotto, $esito) ? self::scrivi($gesto->perLaRiga($lotto, $esito)) : null;
    }

    /** Lascia un gesto aperto a mano senza scriverlo (il rollback). */
    public static function lasciaGesto(?Lotto $lotto = null): void
    {
        self::$gestiAperti = array_values(array_filter(self::$gestiAperti, fn ($a) => $lotto !== null && $a[1] !== $lotto));
        if ($lotto !== null) {
            ContestoDelRegistro::togliLotto($lotto);
        }
    }

    /**
     * Un gesto il cui lavoro è fatto di più transazioni sue (P10: il riallineamento, una per voce; l'importazione, una per livello) che non si
     * uniscono in una sola: le scritture dei modelli si contano nel lotto, e alla fine la riga del gesto si scrive in una transazione sua. Se il
     * lavoro si ferma a metà, la riga dice ciò che è stato fatto prima («fermato a metà»), poi l'errore riparte.
     *
     * @template T
     * @param Closure(Gesto): T $lavoro
     * @return T
     */
    public static function durantePiuTransazioni(Gesto $gesto, Closure $lavoro): mixed
    {
        $esterno = ContestoDelRegistro::lottoAperto();
        if ($esterno !== null) {
            return self::durante($gesto, $lavoro);
        }
        $lotto = new Lotto();
        $gesto->preparaIlLotto($lotto);
        ContestoDelRegistro::apriLotto($lotto);
        try {
            $esito = $lavoro($gesto);
        } catch (\Throwable $e) {
            ContestoDelRegistro::togliLotto($lotto);
            if (! $lotto->vuoto()) {
                $riga = $gesto->perLaRiga($lotto);
                $riga['riepilogo'] = trim((string) $riga['riepilogo'] . ' (fermato a metà)');
                DB::transaction(fn () => self::scrivi($riga));
            }
            throw $e;
        }
        ContestoDelRegistro::togliLotto($lotto);
        $gesto->chiudi($lotto, $esito);
        if ($gesto->daScrivere($lotto, $esito)) {
            DB::transaction(fn () => self::scrivi($gesto->perLaRiga($lotto, $esito)));
        }

        return $esito;
    }

    /**
     * Il lavoro con un autore dichiarato: l'importazione («Importazione da Danea avviata da …»), il ripristino, un comando che si firma da sé.
     *
     * @template T
     * @param Closure(): T $lavoro
     * @return T
     */
    public static function comeAutore(Autore $autore, Closure $lavoro): mixed
    {
        ContestoDelRegistro::apriAutore($autore);
        try {
            return $lavoro();
        } finally {
            ContestoDelRegistro::chiudiAutore();
        }
    }

    /**
     * «Ripristina» della «Storia» (§8): il salvataggio della scheda dentro il lavoro passa dalle stesse regole della pagina, e la riga che il
     * tratto scrive è «ripristinata», con il perché («Ripristina dalla Storia, modifica del 16/12/2026 alle 09:12»), invece di «modificata».
     *
     * @template T
     * @param Closure(): T $lavoro
     * @return T
     */
    public static function comeRipristino(string $perche, Closure $lavoro): mixed
    {
        ContestoDelRegistro::apriRipristino($perche);
        try {
            return $lavoro();
        } finally {
            ContestoDelRegistro::chiudiRipristino();
        }
    }

    public static function autore(): Autore
    {
        return ContestoDelRegistro::autore();
    }

    public static function inUnGesto(): bool
    {
        return ContestoDelRegistro::inUnGesto();
    }

    private static function taglia(?string $testo, int $quanto): ?string
    {
        if ($testo === null) {
            return null;
        }

        return mb_strlen($testo) > $quanto ? mb_substr($testo, 0, $quanto - 1) . '…' : $testo;
    }
}
