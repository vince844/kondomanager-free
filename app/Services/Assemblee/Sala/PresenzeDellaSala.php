<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Sala;

use App\Enums\Assemblee\ComePresente;
use App\Enums\Assemblee\ModalitaAssemblea;
use App\Enums\Assemblee\RuoloDiVoto;
use App\Enums\Assemblee\StatoDelega;
use App\Models\Anagrafica;
use App\Enums\Assemblee\Maggioranza;
use App\Models\Assemblee\DelegaDellAssemblea;
use App\Models\Assemblee\GestoDellaSala;
use App\Models\Assemblee\PresenzaInSala;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Validation\ValidationException;

/**
 * Le presenze della sala (R6): entrata e uscita con l'ora del gesto, chi esce e rientra ha due righe; «In collegamento» senza conferma se l'assemblea è in
 * videoconferenza o mista, altrimenti con il motivo obbligatorio (art. 66 c. 6 disp. att.; «Nel motivo niente dati sulla salute»); il collegamento caduto è
 * un'uscita, il ritorno una riga nuova. Chi può entrare: una persona della fotografia della seduta (condòmini, usufruttuari, nudi proprietari, conduttori), o
 * un delegato con una delega valida (dell'anagrafica, o fuori con la chiave `delega:<id>`).
 *
 * **La versione** di una presenza è quante volte è cambiata (le entrate più le uscite): un gesto partito da una versione cambiata in un altro modo è in
 * conflitto; uno che chiede ciò che c'è già non cambia niente.
 *
 * **Chi è presente in un momento** ({@see presenti()}): chi ha una riga aperta in quel momento, più chi ha delegato con una delega valida in quel momento un
 * delegato presente; il delegante presente conta da sé (la delega si sospende finché c'è). Una delega «per alcuni punti» vale sul voto solo per i suoi
 * punti, e nella costituzione porta presente chi ha delegato (il conduttore ne resta fuori comunque: non è fra le sue teste). La delega oltre il quinto
 * ancora «da decidere» non conta finché il presidente non la decide.
 *
 * **L'uscita arrivata tardi** che toglierebbe dagli intervenuti di un voto non confermato una testa che ha già votato non si applica in silenzio: è «Due
 * versioni» sulla votazione, e sceglie l'amministratore. Scelta l'uscita, la riga del registro e il messaggio dicono quale voto non conta più.
 */
final class PresenzeDellaSala
{
    public const FRASE_CHI = 'Questa persona non è fra i condòmini di questa assemblea, né un delegato con una delega valida.';

    public const FRASE_MOTIVO = 'Scrivi perché è in collegamento: l\'avviso non prevede la videoconferenza. Nel motivo niente dati sulla salute.';

    public function entra(GestoInArrivo $g): array
    {
        $seduta = StatiDellaSala::sedutaApertaDelGesto($g);
        [$chiave, $anagraficaId, $nome, $delega] = $this->chi($g, $seduta);
        $come = ComePresente::tryFrom((string) $g->dato('come', ComePresente::IN_SALA->value))
            ?? throw ValidationException::withMessages(['gesto' => 'Come partecipa: «in_sala» o «in_collegamento».']);
        $motivo = $g->testo('motivo');
        $conVideo = in_array($g->assemblea->modalita, [ModalitaAssemblea::VIDEOCONFERENZA, ModalitaAssemblea::MISTA], true);
        if ($come === ComePresente::IN_COLLEGAMENTO && ! $conVideo && $motivo === '') {
            throw ValidationException::withMessages(['gesto' => self::FRASE_MOTIVO]);
        }
        $versione = self::versione($seduta->id, $chiave);
        $aperta = self::aperta($seduta->id, $chiave);
        if ($aperta !== null) {
            return ['versione' => $versione, 'messaggio' => 'Era già così: niente da cambiare.'];
        }
        $dentroCon = $anagraficaId === null ? null : self::entratoInsieme($seduta->id, $anagraficaId);
        if ($dentroCon !== null) {
            throw ValidationException::withMessages(['gesto' => $nome . ' è già in sala, con ' . $dentroCon->nome . ': per entrare da sé, prima esce ' . $dentroCon->nome . '.']);
        }
        $base = $g->baseDaControllare();
        if ($base !== null && $base !== $versione) {
            throw new DueVersioni($nome . ': ' . ($come === ComePresente::IN_SALA ? 'entra in sala' : 'in collegamento') . ' alle ' . $g->ora(),
                $nome . ': ' . self::statoDi($seduta->id, $chiave), 'presenza:' . $seduta->id . ':' . $chiave, self::gestoDiAdesso($seduta->id, $chiave));
        }
        $rappresenta = $this->rappresenta($g, $seduta, $anagraficaId);
        // Gli altri contitolari della testa che entrano con chi la rappresenta (rilievo C53): presenti senza voto; chi entra rappresenta le unità in comune.
        [$insieme, $inComune] = $this->insieme($g, $seduta, $anagraficaId);
        if ($insieme !== [] && $rappresenta === []) {
            $rappresenta = $inComune;
        }
        $frase = $nome . ($come === ComePresente::IN_SALA ? ' entra in sala alle ' : ' in collegamento alle ') . $g->ora()
            . ($delega !== null ? ', con la delega di ' . $delega->delegante_nome : '') . self::conGliAltri($insieme, true);

        $tolti = RegistroModifiche::durante(GestiDelRegistro::dellaSala($g->assemblea, fn ($lotto, $tolti) => $frase . self::codaDeiTolti((array) $tolti),
            $g->segnatoIl, $g->dalFoglio($seduta), $come === ComePresente::IN_COLLEGAMENTO && $motivo !== '' ? $motivo : null,
            array_filter([$anagraficaId, $delega?->delegante_anagrafica_id])),
            function () use ($seduta, $chiave, $anagraficaId, $nome, $come, $g, $rappresenta, $motivo, $insieme) {
                PresenzaInSala::create(['seduta_id' => $seduta->id, 'chiave' => $chiave, 'anagrafica_id' => $anagraficaId, 'nome' => $nome,
                    'come' => $come->value, 'entrata_il' => $g->segnatoIl, 'rappresenta' => $rappresenta === [] ? null : $rappresenta,
                    'insieme' => $insieme === [] ? null : $insieme,
                    'motivo_collegamento' => $come === ComePresente::IN_COLLEGAMENTO && $motivo !== '' ? $motivo : null, 'gesto_entrata' => $g->numero]);

                return app(VotazioniDellaSala::class)->dopoUnaPresenza($seduta, $g->segnatoIl);
            });

        return ['versione' => $versione + 1, 'messaggio' => self::messaggio((array) $tolti, $seduta, $g, 'con l\'entrata alle ' . $g->ora() . ' la testa la porta '
            . 'un\'altra persona')];
    }

    public function esce(GestoInArrivo $g): array
    {
        $seduta = StatiDellaSala::sedutaApertaDelGesto($g);
        [$chiave, $anagraficaId, $nome] = $this->chi($g, $seduta, true);
        $versione = self::versione($seduta->id, $chiave);
        $aperta = self::aperta($seduta->id, $chiave);
        $base = $g->baseDaControllare();
        if ($aperta === null) {
            if ($base !== null && $base !== $versione && $versione === 0) {
                throw new DueVersioni($nome . ': esce alle ' . $g->ora(), $nome . ': non è mai entrato in questa seduta', 'presenza:' . $seduta->id . ':' . $chiave);
            }

            return ['versione' => $versione, 'messaggio' => 'Era già così: niente da cambiare.'];
        }
        if ($base !== null && $base !== $versione) {
            throw new DueVersioni($nome . ': esce alle ' . $g->ora(), $nome . ': ' . self::statoDi($seduta->id, $chiave), 'presenza:' . $seduta->id . ':' . $chiave,
                self::gestoDiAdesso($seduta->id, $chiave));
        }
        if ($g->segnatoIl->lt($aperta->entrata_il)) {
            throw ValidationException::withMessages(['gesto' => 'L\'uscita alle ' . $g->ora() . ' è prima dell\'entrata alle '
                . $aperta->entrata_il->copy()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('H:i') . '.']);
        }
        $caduto = (bool) $g->dato('caduto', false);
        // Chi era entrato insieme esce con lui (rilievo C53): la riga è una.
        $frase = ($caduto ? $nome . ', il collegamento è caduto alle ' . $g->ora() : $nome . ' esce alle ' . $g->ora()) . self::conGliAltri((array) $aperta->insieme, false);
        if (! $g->forzato) {
            $this->nessunVotoCade($g, $seduta, $chiave, $nome . ': ' . ($caduto ? 'il collegamento è caduto alle ' : 'esce alle ') . $g->ora());
        }

        $tolti = RegistroModifiche::durante(GestiDelRegistro::dellaSala($g->assemblea, fn ($lotto, $tolti) => $frase . self::codaDeiTolti((array) $tolti),
            $g->segnatoIl, $g->dalFoglio($seduta), null, array_filter([$anagraficaId])),
            function () use ($aperta, $g, $caduto, $seduta) {
                $aperta->update(['uscita_il' => $g->segnatoIl, 'caduto' => $caduto, 'gesto_uscita' => $g->numero]);

                return app(VotazioniDellaSala::class)->dopoUnaPresenza($seduta, $g->segnatoIl);
            });

        return ['versione' => $versione + 1, 'messaggio' => self::messaggio((array) $tolti, $seduta, $g, 'l\'uscita alle ' . $g->ora() . ' è prima del voto')];
    }

    /**
     * L'uscita arrivata tardi (segnata prima di un voto non ancora confermato) non toglie in silenzio un voto già segnato: se con l'uscita una testa che ha
     * votato non sarebbe più fra gli intervenuti, il gesto è «Due versioni» sulla votazione, che non si conferma finché non si sceglie.
     */
    private function nessunVotoCade(GestoInArrivo $g, SedutaDellAssemblea $seduta, string $chiave, string $frase): void
    {
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        foreach (VotazioneDelPunto::query()->where('seduta_id', $seduta->id)->whereNull('confermata_il')->where('votata_il', '>=', $g->segnatoIl)
            ->where('maggioranza', '!=', Maggioranza::SENZA_VOTO->value)->with('punto')->orderBy('votata_il')->get() as $v) {
            $usufruttuari = [];
            foreach ((array) ($v->scelte['usufruttuari'] ?? []) as $u) {
                $usufruttuari[(int) $u['immobile_id']] = (string) $u['perche'];
            }
            $dopo = array_column(VotazioniDellaSala::intervenuti($seduta, $v->punto, $usufruttuari, $v->votata_il, [$chiave => $g->segnatoIl])['teste'], 'chiave');
            $cade = $v->voti()->whereNotIn('testa', $dopo)->orderBy('id')->first();
            if ($cade === null) {
                continue;
            }
            $quando = $cade->gesto === null ? null : GestoDellaSala::query()->where('assemblea_id', $g->assemblea->id)->where('gesto', $cade->gesto)->value('segnato_il');
            throw new DueVersioni($frase, $cade->nome . ': ha votato ' . mb_strtolower((string) $cade->voto?->parola()) . ' al punto ' . $v->punto?->numero
                . ($quando !== null ? ' alle ' . \Carbon\CarbonImmutable::parse($quando)->setTimezone($fuso)->format('H:i') : ''), 'votazione:' . $v->id, $cade->gesto);
        }
    }

    /** «; il voto di Colombo Rita al punto 1 non conta più», per la riga del registro. @param list<array{nome: string, punto: string}> $tolti */
    private static function codaDeiTolti(array $tolti): string
    {
        return implode('', array_map(fn ($t) => '; il voto di ' . $t['nome'] . ' al punto ' . $t['punto'] . ' non conta più', $tolti));
    }

    /**
     * Il messaggio della risposta: i voti che non contano più (una presenza tarda scelta fra due versioni), e l'avviso sui voti già confermati.
     *
     * @param list<array{nome: string, punto: string}> $tolti
     */
    private static function messaggio(array $tolti, SedutaDellAssemblea $seduta, GestoInArrivo $g, string $perche): ?string
    {
        $frasi = array_map(fn ($t) => 'Il voto di ' . $t['nome'] . ' al punto ' . $t['punto'] . ' non conta più: ' . $perche . '.', $tolti);
        $avviso = app(VotazioniDellaSala::class)->avvisoDelleConfermate($seduta, $g->segnatoIl);

        return implode(' ', array_filter([...$frasi, $avviso])) ?: null;
    }

    /** Chi rappresenta le unità in comproprietà («rappresenta Anna»): la persona presente, per le unità della sua testa. */
    public function rappresentaLeUnita(GestoInArrivo $g): array
    {
        $seduta = StatiDellaSala::sedutaApertaDelGesto($g);
        [$chiave, $anagraficaId, $nome] = $this->chi($g, $seduta);
        $aperta = self::aperta($seduta->id, $chiave) ?? throw ValidationException::withMessages(['gesto' => $nome . ' non è presente: prima segnalo entrato.']);
        $unita = $this->rappresenta($g, $seduta, $anagraficaId);
        $nomi = array_map(fn ($u) => (string) ($seduta->fotografia['unita'][$u]['nome'] ?? $u), $unita);

        RegistroModifiche::durante(GestiDelRegistro::dellaSala($g->assemblea, $nome . ' rappresenta ' . ($nomi === [] ? 'nessuna unità in comproprietà'
            : 'l\'' . TesteDellaSala::elenco($nomi)), $g->segnatoIl, $g->dalFoglio($seduta), null, array_filter([$anagraficaId])),
            fn () => $aperta->update(['rappresenta' => $unita === [] ? null : $unita]));

        return ['versione' => self::versione($seduta->id, $chiave), 'messaggio' => null];
    }

    /** La chiave di chi è presente: `anagrafica:<id>`, o `delega:<id>` per un delegato fuori dall'anagrafica. */
    public static function chiaveDelDelegato(DelegaDellAssemblea $d): string
    {
        return $d->delegato_anagrafica_id !== null ? 'anagrafica:' . $d->delegato_anagrafica_id : 'delega:' . $d->id;
    }

    /** La versione della presenza di una persona in una seduta: le entrate più le uscite. */
    public static function versione(int $sedutaId, string $chiave): int
    {
        $righe = PresenzaInSala::query()->where('seduta_id', $sedutaId)->where('chiave', $chiave)->get(['uscita_il']);

        return $righe->count() + $righe->whereNotNull('uscita_il')->count();
    }

    /** Il gesto che ha fatto la versione di adesso di una presenza: l'ultima uscita, o l'ultima entrata. */
    public static function gestoDiAdesso(int $sedutaId, string $chiave): ?string
    {
        $ultima = PresenzaInSala::query()->where('seduta_id', $sedutaId)->where('chiave', $chiave)->orderByDesc('entrata_il')->orderByDesc('id')->first();

        return $ultima === null ? null : ($ultima->gesto_uscita ?? $ultima->gesto_entrata);
    }

    /** Le versioni di tutte le presenze della seduta: chiave => versione. @return array<string, int> */
    public static function versioni(int $sedutaId): array
    {
        $versioni = [];
        foreach (PresenzaInSala::query()->where('seduta_id', $sedutaId)->get(['chiave', 'uscita_il']) as $p) {
            $versioni[$p->chiave] = ($versioni[$p->chiave] ?? 0) + 1 + ($p->uscita_il !== null ? 1 : 0);
        }

        return $versioni;
    }

    /**
     * Chi è presente in un momento (adesso con `$al` vuoto): anagrafica => `{come: di_persona|per_delega, nome, chi: {anagrafica_id, nome, delega_id?}}`.
     * Per un punto (`$puntoId`) la delega per alcuni punti vale solo se il punto è fra i suoi (anche quella del conduttore); per la costituzione vale (il
     * conduttore non conta comunque: non è fra le teste della costituzione). Più la mappa delle unità in comproprietà con chi le rappresenta.
     * `$usciteDaProvare` (chiave => ora) conta come uscite le righe aperte di quelle persone, senza scriverle: per vedere che cosa farebbe un'uscita tarda.
     *
     * @return array{persone: array<int, array<string, mixed>>, rappresenta: array<int, int>}
     */
    /**
     * `$senzaLaChiusura`: le uscite dalla chiusura in poi non contano (la chiusura fa uscire tutti a quell'ora). Serve alla costituzione letta dopo, per il
     * verbale: dal foglio apertura, presenze e chiusura possono stare nello stesso minuto, e quelle uscite toglievano tutti i presenti (21/11/2026).
     */
    public static function presenti(SedutaDellAssemblea $seduta, ?\DateTimeInterface $al = null, ?int $puntoId = null, array $usciteDaProvare = [],
        bool $senzaLaChiusura = false): array
    {
        $chiusa = $senzaLaChiusura ? $seduta->chiusa_il : null;
        $righe = PresenzaInSala::query()->where('seduta_id', $seduta->id)->orderBy('entrata_il')->orderBy('id')->get()
            ->each(function (PresenzaInSala $p) use ($chiusa) {
                if ($chiusa !== null && $p->uscita_il !== null && $p->uscita_il->gte($chiusa)) {
                    $p->uscita_il = null;
                }
            })
            ->each(function (PresenzaInSala $p) use ($usciteDaProvare) {
                // L'uscita da provare (un gesto che arriva tardi): la riga aperta come se fosse chiusa a quell'ora, senza scriverla.
                if ($p->uscita_il === null && isset($usciteDaProvare[$p->chiave])) {
                    $p->uscita_il = \Illuminate\Support\Carbon::instance($usciteDaProvare[$p->chiave]);
                }
            })
            ->filter(fn (PresenzaInSala $p) => $al === null ? $p->uscita_il === null
                : $p->entrata_il->lte($al) && ($p->uscita_il === null || $p->uscita_il->gt($al)));
        $dentro = [];
        $persone = [];
        $rappresenta = [];
        foreach ($righe as $p) {
            $dentro[$p->chiave] = $p;
            if ($p->anagrafica_id !== null) {
                $persone[(int) $p->anagrafica_id] = ['come' => 'di_persona', 'nome' => (string) $p->nome,
                    'chi' => ['anagrafica_id' => (int) $p->anagrafica_id, 'nome' => (string) $p->nome]];
                foreach ((array) $p->rappresenta as $u) {
                    $rappresenta[(int) $u] = (int) $p->anagrafica_id;
                }
            }
        }
        foreach (self::delegheValide($seduta->assemblea_id, $al) as $d) {
            if ($d->delegante_anagrafica_id === null || ! self::delegaConta($d->punti, (bool) ($d->dati['da_decidere'] ?? false), $puntoId)) {
                continue;
            }
            $chiave = self::chiaveDelDelegato($d);
            if (! isset($dentro[$chiave]) || isset($persone[(int) $d->delegante_anagrafica_id])) {
                continue;
            }
            $persone[(int) $d->delegante_anagrafica_id] = ['come' => 'per_delega', 'nome' => (string) $d->delegato_nome . ', per delega',
                'chi' => ['anagrafica_id' => $d->delegato_anagrafica_id === null ? null : (int) $d->delegato_anagrafica_id, 'nome' => (string) $d->delegato_nome,
                    'delega_id' => (int) $d->id]];
        }

        return ['persone' => $persone, 'rappresenta' => $rappresenta];
    }

    /**
     * Chi porta una testa in un momento, dalle persone presenti: di persona (con più persone, quella designata a rappresentare l'unità, o la prima), o il
     * delegato. Il ruolo: `rappresentante` per una testa di più persone, il ruolo dell'unità per una persona sola, `delegato` per delega. Null se assente.
     *
     * @param array<string, mixed> $testa
     * @param array{persone: array<int, array<string, mixed>>, rappresenta: array<int, int>} $presenti
     * @param array<int, string> $ruoli unità => ruolo di chi vota
     * @return ?array{anagrafica_id: ?int, nome: string, ruolo: string, delega_id?: int}
     */
    public static function chiPorta(array $testa, array $presenti, array $ruoli = []): ?array
    {
        $diPersona = array_values(array_filter(array_map('intval', $testa['persone']), fn ($p) => ($presenti['persone'][$p]['come'] ?? null) === 'di_persona'));
        if ($diPersona !== []) {
            $scelto = $diPersona[0];
            foreach ($testa['unita'] as $u) {
                if (in_array($presenti['rappresenta'][(int) $u] ?? null, $diPersona, true)) {
                    $scelto = $presenti['rappresenta'][(int) $u];
                    break;
                }
            }
            $ruolo = count($testa['persone']) > 1 ? RuoloDiVoto::RAPPRESENTANTE->value : ($ruoli[(int) ($testa['unita'][0] ?? 0)] ?? RuoloDiVoto::PROPRIETARIO->value);

            return ['anagrafica_id' => $scelto, 'nome' => (string) $presenti['persone'][$scelto]['chi']['nome'], 'ruolo' => $ruolo];
        }
        foreach ($testa['persone'] as $p) {
            $presente = $presenti['persone'][(int) $p] ?? null;
            if (($presente['come'] ?? null) === 'per_delega') {
                return ['anagrafica_id' => $presente['chi']['anagrafica_id'], 'nome' => (string) $presente['chi']['nome'], 'ruolo' => RuoloDiVoto::DELEGATO->value,
                    'delega_id' => (int) $presente['chi']['delega_id']];
            }
        }

        return null;
    }

    /** Le teste presenti e il loro valore. @param list<array<string, mixed>> $teste @return array{teste: int, valore: int} */
    public static function conteggio(array $teste, array $presenti): array
    {
        $quante = 0;
        $valore = 0;
        foreach ($teste as $t) {
            if (self::chiPorta($t, $presenti) !== null) {
                $quante++;
                $valore += (int) $t['millesimi'];
            }
        }

        return ['teste' => $quante, 'valore' => $valore];
    }

    /**
     * Quando una delega valida conta (le stesse regole in `conti.ts`, sugli stessi casi di `tests/Fixtures/sala/conti.json`): la delega oltre il quinto
     * ancora «da decidere» mai; quella per alcuni punti nella costituzione sì (chi ha delegato è rappresentato in assemblea; il conduttore resta fuori comunque,
     * perché non è fra le teste della costituzione), sul voto solo per i suoi punti.
     *
     * @param ?list<int> $punti
     */
    public static function delegaConta(?array $punti, bool $daDecidere, ?int $puntoId): bool
    {
        if ($daDecidere) {
            return false;
        }
        $punti = array_map('intval', (array) $punti);

        return $punti === [] || $puntoId === null || in_array($puntoId, $punti, true);
    }

    /**
     * Le deleghe valide in un momento (adesso con `$al` vuoto): segnate entro quel momento, e non ancora revocate allora. La delega oltre il quinto ancora «da
     * decidere» non vale; accettata dal presidente vale da quando l'ha decisa.
     */
    public static function delegheValide(int $assembleaId, ?\DateTimeInterface $al = null)
    {
        return DelegaDellAssemblea::query()->where('assemblea_id', $assembleaId)->orderBy('id')->get()
            ->filter(function (DelegaDellAssemblea $d) use ($al) {
                if ((bool) ($d->dati['da_decidere'] ?? false)) {
                    return false;
                }
                if ($al === null) {
                    return $d->stato === StatoDelega::VALIDA;
                }
                if ($d->segnata_il !== null && $d->segnata_il->gt($al)) {
                    return false;
                }
                $decisa = $d->dati['decisa_il'] ?? null;
                if ($decisa !== null && \Carbon\CarbonImmutable::parse((string) $decisa)->gt($al)) {
                    return false;
                }

                return $d->stato === StatoDelega::VALIDA || ($d->stato === StatoDelega::REVOCATA && $d->revocata_il !== null && $d->revocata_il->gt($al));
            })->values();
    }

    /** La riga aperta di una persona nella seduta. */
    private static function aperta(int $sedutaId, string $chiave): ?PresenzaInSala
    {
        return PresenzaInSala::query()->where('seduta_id', $sedutaId)->where('chiave', $chiave)->whereNull('uscita_il')->latest('id')->first();
    }

    /** Lo stato di adesso di una persona, per le due versioni: «in sala dalle 18:45», «uscito alle 19:20». */
    private static function statoDi(int $sedutaId, string $chiave): string
    {
        $ultima = PresenzaInSala::query()->where('seduta_id', $sedutaId)->where('chiave', $chiave)->latest('id')->first();
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        if ($ultima === null) {
            return 'non è entrato';
        }
        if ($ultima->uscita_il === null) {
            return ($ultima->come === ComePresente::IN_SALA ? 'in sala' : 'in collegamento') . ' dalle ' . $ultima->entrata_il->copy()->setTimezone($fuso)->format('H:i');
        }

        return 'uscito alle ' . $ultima->uscita_il->copy()->setTimezone($fuso)->format('H:i');
    }

    /**
     * Chi è la persona del gesto: `{anagrafica_id}` (una persona della fotografia della seduta, o il delegato dell'anagrafica di una delega valida) o
     * `{delega_id}` (il delegato della delega; per uscire anche di una delega non più valida, se è ancora dentro). Rende la chiave, l'anagrafica, il nome e
     * la delega.
     *
     * @return array{0: string, 1: ?int, 2: string, 3: ?DelegaDellAssemblea}
     */
    private function chi(GestoInArrivo $g, SedutaDellAssemblea $seduta, bool $perUscire = false): array
    {
        $persona = (array) $g->dato('persona', []);
        if (isset($persona['delega_id'])) {
            $delega = DelegaDellAssemblea::query()->where('assemblea_id', $g->assemblea->id)->whereKey((int) $persona['delega_id'])->first();
            // Per uscire basta la riga aperta: il delegato fuori dall'anagrafica esce anche dopo che la sua delega è revocata o sostituita.
            $vale = $delega !== null && ($delega->stato === StatoDelega::VALIDA
                || ($perUscire && self::aperta($seduta->id, self::chiaveDelDelegato($delega)) !== null));
            if (! $vale) {
                throw ValidationException::withMessages(['gesto' => self::FRASE_CHI]);
            }

            return [self::chiaveDelDelegato($delega), $delega->delegato_anagrafica_id, (string) $delega->delegato_nome, $delega];
        }
        $id = (int) ($persona['anagrafica_id'] ?? 0);
        $nellaFotografia = isset(((array) ($seduta->fotografia['persone'] ?? []))[$id]) || isset(((array) ($seduta->fotografia['persone'] ?? []))[(string) $id]);
        $delega = $nellaFotografia ? null : DelegaDellAssemblea::query()->where('assemblea_id', $g->assemblea->id)->where('delegato_anagrafica_id', $id)
            ->where('stato', StatoDelega::VALIDA->value)->first();
        if ($id <= 0 || (! $nellaFotografia && $delega === null)) {
            throw ValidationException::withMessages(['gesto' => self::FRASE_CHI]);
        }
        $nome = (string) (Anagrafica::query()->whereKey($id)->value('nome') ?? $delega?->delegato_nome ?? '');

        return ['anagrafica:' . $id, $id, $nome, $delega];
    }

    /**
     * «, con Russo Anna, contitolare, presente senza voto» all'entrata, «, con Russo Anna» all'uscita; niente senza nessuno.
     *
     * @param list<array{anagrafica_id: int, nome: string}> $insieme
     */
    public static function conGliAltri(array $insieme, bool $entrata): string
    {
        if ($insieme === []) {
            return '';
        }
        $nomi = TesteDellaSala::elenco(array_map(fn ($x) => (string) $x['nome'], $insieme));

        return ', con ' . $nomi . ($entrata ? (count($insieme) === 1 ? ', contitolare, presente senza voto' : ', contitolari, presenti senza voto') : '');
    }

    /**
     * Le altre persone della testa che entrano con chi la rappresenta (`con`, rilievo C53; art. 67 c. 2 disp. att.: l'unità in comproprietà ha un solo
     * rappresentante): solo persone della stessa testa della fotografia, non già in sala; più le unità in comune che chi entra rappresenta.
     *
     * @return array{0: list<array{anagrafica_id: int, nome: string}>, 1: list<int>}
     */
    private function insieme(GestoInArrivo $g, SedutaDellAssemblea $seduta, ?int $anagraficaId): array
    {
        $chiesti = array_values(array_unique(array_map('intval', (array) $g->dato('con', []))));
        if ($chiesti === []) {
            return [[], []];
        }
        $fotografia = (array) $seduta->fotografia;
        $teste = collect((array) ($fotografia['costituzione']['teste'] ?? []));
        $diChiEntra = $teste->filter(fn ($t) => $anagraficaId !== null && in_array($anagraficaId, array_map('intval', (array) $t['persone']), true));
        // La testa in comune: quella che contiene chi entra e tutte le persone che entrano con lui. Non la prima per nome: chi ha anche una testa sua
        // («Russo Anna» viene prima di «Russo Anna e Russo Marco») la troverebbe al posto di quella in comune (revisione del 22/11).
        $testa = $diChiEntra->first(fn ($t) => array_diff($chiesti, array_map('intval', (array) $t['persone'])) === []) ?? $diChiEntra->first();
        $persone = $testa === null ? [] : array_map('intval', (array) $testa['persone']);
        $nomi = (array) ($fotografia['persone'] ?? []);
        $insieme = [];
        foreach ($chiesti as $id) {
            $nome = (string) ($nomi[$id]['nome'] ?? $nomi[(string) $id]['nome'] ?? Anagrafica::query()->whereKey($id)->value('nome') ?? '');
            if ($id === $anagraficaId || ! in_array($id, $persone, true)) {
                throw ValidationException::withMessages(['gesto' => 'Con chi entra vanno solo gli altri comproprietari della stessa unità: '
                    . ($nome !== '' ? $nome : 'questa persona') . ' non lo è.']);
            }
            // Chi ha anche una testa sua (un'unità tutta sua) non entra «senza voto»: entra da sé e vota per la sua testa; entrato insieme, la sua testa
            // risultava assente e non poteva più entrare da sé (revisione del 22/11, alta).
            $altraTesta = $teste->first(fn ($t) => $t !== $testa && in_array($id, array_map('intval', (array) $t['persone']), true));
            if ($altraTesta !== null) {
                throw ValidationException::withMessages(['gesto' => ($nome !== '' ? $nome : 'Questa persona') . ' ha anche un\'unità sua: entra da sé, con «Entra», e vota per la sua testa.']);
            }
            if (self::aperta($seduta->id, 'anagrafica:' . $id) !== null || self::entratoInsieme($seduta->id, $id) !== null) {
                throw ValidationException::withMessages(['gesto' => $nome . ' è già in sala.']);
            }
            $insieme[] = ['anagrafica_id' => $id, 'nome' => $nome];
        }
        $unita = (array) ($fotografia['unita'] ?? []);
        $inComune = array_values(array_filter(array_map('intval', (array) ($testa['unita'] ?? [])), function (int $u) use ($unita, $anagraficaId) {
            $titolari = array_filter((array) ($unita[$u]['titolari'] ?? $unita[(string) $u]['titolari'] ?? []), fn ($t) => ($t['ruolo'] ?? '') !== 'inquilino');

            return count($titolari) > 1 && in_array($anagraficaId, array_map(fn ($t) => (int) $t['anagrafica_id'], $titolari), true);
        }));
        sort($inComune);

        return [$insieme, $inComune];
    }

    /** La riga aperta con cui una persona è entrata insieme a chi rappresenta la sua testa, o null. */
    public static function entratoInsieme(int $sedutaId, int $anagraficaId): ?PresenzaInSala
    {
        return PresenzaInSala::query()->where('seduta_id', $sedutaId)->whereNull('uscita_il')->whereNotNull('insieme')->get()
            ->first(fn (PresenzaInSala $p) => in_array($anagraficaId, array_map(fn ($x) => (int) $x['anagrafica_id'], (array) $p->insieme), true));
    }

    /** Le unità in comproprietà che la persona rappresenta: solo unità della fotografia di cui è titolare con altri. @return list<int> */
    private function rappresenta(GestoInArrivo $g, SedutaDellAssemblea $seduta, ?int $anagraficaId): array
    {
        $chieste = array_values(array_unique(array_map('intval', (array) $g->dato('rappresenta', $g->dato('unita', [])))));
        if ($chieste === []) {
            return [];
        }
        $unita = (array) ($seduta->fotografia['unita'] ?? []);
        foreach ($chieste as $u) {
            $titolari = array_map(fn ($t) => (int) $t['anagrafica_id'], (array) ($unita[$u]['titolari'] ?? $unita[(string) $u]['titolari'] ?? []));
            if ($anagraficaId === null || ! in_array($anagraficaId, $titolari, true) || count($titolari) < 2) {
                throw ValidationException::withMessages(['gesto' => 'Si rappresenta solo un\'unità in comproprietà di cui si è titolari.']);
            }
        }
        sort($chieste);

        return $chieste;
    }
}
