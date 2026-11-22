<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\FontePunto;
use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\OrigineAssemblea;
use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\Assemblee\TipoAssemblea;
use App\Helpers\DateHelper;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\OggettoDelPunto;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\User;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Documenti\VersioniDeiDocumenti;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\Gesto;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La delibera registrata a mano (R8, R2, R17 del progetto della tappa 2; decisioni 492 (1), 562, 563): il giorno, per chi, che cosa ha deciso, l'esito, il
 * numero del verbale di carta e, se c'è, il verbale sottoscritto. Crea in una transazione la riunione a mano (`origine = a_mano`, `stato =
 * registrata_a_mano`), il suo punto e la delibera; una riga sola nel registro.
 *
 * - **Entra in una riunione a mano che c'è già solo con lo stesso giorno, lo stesso perimetro e lo stesso numero del verbale** (spazi e maiuscole non
 *   contano), **o senza numero da tutte e due le parti** (§13, rilievo 19); la finestra lo dice prima del clic ({@see fraseDiDestinazione()}).
 * - **Senza il verbale si registra lo stesso** (decisione 563), con il segno «Verbale da caricare» (`assemblee.verbale_documento_id` vuoto). Il verbale lo
 *   carica chi ha «Registra il verbale sottoscritto» (decisione 562); sostituito, resta la versione di prima (decisione 559).
 * - Aperta da una pagina, porta con sé l'origine e ciò di cui parla: la proposta di «Scegli la delibera» la trova al livello 1. **Non collega niente** (R3).
 * - **R2**: in una riunione a mano il giorno è della riunione: «Correggi la data» corregge la riunione con le sue delibere; una delibera nella riunione
 *   sbagliata **si sposta**. Le schede non cambiano: la riunione dice chi le usa.
 * - **R17**: una delibera a mano senza collegamenti si elimina; con i collegamenti il programma elenca le schede da cambiare prima. Non si usa «annullata» per
 *   un errore (l'annullamento è l'atto di un giudice, principio 4).
 */
final class DeliberaAMano
{
    /** Le estensioni del verbale sottoscritto: una scansione o un PDF firmato digitalmente (decisione 492 (4)), al più 10 MB come le prove. */
    public const ESTENSIONI_DEL_VERBALE = ['pdf', 'p7m', 'jpg', 'jpeg', 'png'];

    public const KB_MASSIMI_DEL_VERBALE = 10240;

    /**
     * `$dati`: `giorno` (Y-m-d, non dopo oggi), `testo`, `esito`; facoltativi `ambito`, `palazzina_id`, `scala_id`, `numero_verbale`, `tipo`, `materia`,
     * `importo` (centesimi), `riunione_id` (la riunione a mano scelta con «Cambia»). `$contesto`: `oggetto_tipo` e `oggetto_id` della pagina da cui si apre,
     * `parla_di` (`[[tipo, id], …]`), risolti nel condominio (R18).
     *
     * @param array<string, mixed> $dati
     * @param array<string, mixed>|null $contesto
     */
    public static function registra(Condominio $condominio, array $dati, ?UploadedFile $verbale, User $utente, ?array $contesto = null): Delibera
    {
        $campi = self::campi($condominio, $dati);
        if ($verbale !== null && ! AccessoAlleAssemblee::registraIlVerbale($utente)) {
            throw new AuthorizationException('Per caricare il verbale sottoscritto serve il permesso «Registra il verbale sottoscritto».');
        }
        $oggetti = self::oggetti($condominio, $contesto);
        // Senza una materia scelta, quella della pagina da cui la delibera nasce: «Scegli la delibera» la proporrà per la stessa materia.
        if (empty($dati['materia']) && $oggetti['materia'] !== null) {
            $campi['materia'] = $oggetti['materia'];
        }
        $riunione = ! empty($dati['riunione_id']) ? self::riunioneScelta($condominio, (int) $dati['riunione_id'], $campi['giorno'])
            : self::riunioneDiDestinazione($condominio, $dati);
        // Una riunione di carta nuova con un numero intero: il numero non può essere già usato nel registro dei verbali (un verbale registrato, un'altra
        // riunione). Una riunione di prima del registro può avere un numero più basso: si controlla solo che non sia già usato.
        if ($riunione === null && $campi['numero_verbale'] !== null && preg_match('/^\d+$/', (string) $campi['numero_verbale']) === 1) {
            $registro = $campi['ambito'] === \App\Enums\Assemblee\AmbitoAssemblea::SCALA->value && $campi['scala_id'] !== null ? 'scala:' . $campi['scala_id']
                : ($campi['ambito'] === \App\Enums\Assemblee\AmbitoAssemblea::PALAZZINA->value && $campi['palazzina_id'] !== null ? 'palazzina:' . $campi['palazzina_id'] : 'condominio');
            $errore = app(\App\Services\Assemblee\Verbale\RegistroDeiVerbali::class)->numeroUsato($condominio, $registro, (int) $campi['numero_verbale'], false);
            if ($errore !== null) {
                throw ValidationException::withMessages(['numero_verbale' => $errore]);
            }
        }

        return RegistroModifiche::durante(GestiDelRegistro::deliberaRegistrataAMano($condominio),
            function () use ($condominio, $campi, $riunione, $verbale, $utente, $oggetti) {
                $riunione ??= Assemblea::create([
                    'condominio_id' => $condominio->id, 'ambito' => $campi['ambito'], 'palazzina_id' => $campi['palazzina_id'], 'scala_id' => $campi['scala_id'],
                    'tipo' => $campi['tipo'], 'origine' => OrigineAssemblea::A_MANO->value, 'stato' => StatoAssemblea::REGISTRATA_A_MANO->value,
                    'data' => $campi['giorno'], 'numero_verbale' => $campi['numero_verbale'], 'created_by' => $utente->id, 'updated_by' => $utente->id,
                ]);
                $punto = PuntoDellOrdineDelGiorno::create([
                    'assemblea_id' => $riunione->id, 'numero' => (int) $riunione->punti()->max('numero') + 1, 'testo' => $campi['testo'],
                    'materia' => $campi['materia'], 'maggioranza' => MaggioranzePerMateria::proposta($campi['materia']),
                    'amministrazione' => MaggioranzePerMateria::amministrazione($campi['materia'], MaggioranzePerMateria::proposta($campi['materia'])),
                    'importo' => $campi['importo'], 'fonte' => FontePunto::A_MANO->value, 'chiave_fonte' => $oggetti['chiave'], 'created_by' => $utente->id,
                ]);
                foreach ($oggetti['oggetti'] as [$tipo, $id, $ruolo]) {
                    OggettoDelPunto::create(['punto_id' => $punto->id, 'oggetto_tipo' => $tipo, 'oggetto_id' => $id, 'ruolo' => $ruolo]);
                }
                $delibera = Delibera::create([
                    'condominio_id' => $condominio->id, 'assemblea_id' => $riunione->id, 'punto_id' => $punto->id, 'testo' => $campi['testo'],
                    'esito' => $campi['esito'], 'deliberata_il' => $riunione->data?->format('Y-m-d') ?? $campi['giorno'], 'importo' => $campi['importo'],
                    'created_by' => $utente->id,
                ]);
                if ($verbale !== null) {
                    self::scriviIlVerbale($riunione, $verbale, $utente);
                }

                return $delibera->refresh();
            });
    }

    /**
     * Una delibera su un punto di un'assemblea tenuta fuori dal programma (R16): il giorno della seduta tenuta, nel punto che c'è già. Una riga sola.
     *
     * @param array<string, mixed> $dati `esito`, `testo`, `importo`
     */
    public static function registraSulPunto(PuntoDellOrdineDelGiorno $punto, array $dati, User $utente): Delibera
    {
        $assemblea = $punto->assemblea;
        if ($assemblea->stato !== StatoAssemblea::REGISTRATA_A_MANO || $assemblea->eAMano()) {
            throw ValidationException::withMessages(['punto' => 'Sul punto si registra la delibera quando l\'assemblea è tenuta su carta.']);
        }
        if (EsitoDelibera::tryFrom((string) ($dati['esito'] ?? '')) === null) {
            throw ValidationException::withMessages(['esito' => 'Scegli l\'esito della delibera.']);
        }
        if ($punto->delibere()->where('votazione', (int) ($dati['votazione'] ?? 1))->exists()) {
            throw ValidationException::withMessages(['punto' => 'Il punto ha già la sua delibera.']);
        }

        return RegistroModifiche::durante(GestiDelRegistro::deliberaRegistrataAMano($assemblea->condominio),
            fn () => StatiDellAssemblea::deliberaSulPunto($punto, $dati, $assemblea->data->format('Y-m-d'), $utente)->refresh());
    }

    /** La riunione a mano in cui entra la delibera: stesso giorno, stesso perimetro, stesso numero del verbale o senza numero tutte e due. @param array<string, mixed> $dati */
    public static function riunioneDiDestinazione(Condominio $condominio, array $dati): ?Assemblea
    {
        $giorno = (string) ($dati['giorno'] ?? '');
        if ($giorno === '') {
            return null;
        }
        [$ambito, $palazzina, $scala] = MemoriaDelCondominio::perimetro($condominio, $dati);
        $numero = self::numero($dati['numero_verbale'] ?? null);
        $candidate = Assemblea::query()->where('condominio_id', $condominio->id)->where('origine', OrigineAssemblea::A_MANO->value)
            ->whereDate('data', $giorno)->where('ambito', $ambito)
            ->when($palazzina === null, fn ($q) => $q->whereNull('palazzina_id'), fn ($q) => $q->where('palazzina_id', $palazzina))
            ->when($scala === null, fn ($q) => $q->whereNull('scala_id'), fn ($q) => $q->where('scala_id', $scala))
            ->orderBy('id')->get();

        return $candidate->first(fn (Assemblea $a) => self::numero($a->numero_verbale) === $numero);
    }

    /** La riga sotto il numero (P5): «Entra nella riunione del 22/10/2026, verbale n. 41», o «Nasce una riunione nuova del 22/10/2026». */
    public static function fraseDiDestinazione(?Assemblea $riunione, ?string $giorno): string
    {
        if ($riunione === null) {
            return 'Nasce una riunione nuova' . ($giorno !== null && $giorno !== '' ? ' del ' . \Illuminate\Support\Carbon::parse($giorno)->format('d/m/Y') : '');
        }
        $verbale = trim((string) $riunione->numero_verbale);

        return 'Entra nella riunione del ' . $riunione->data?->format('d/m/Y') . ($verbale !== '' ? ', verbale n. ' . $verbale : '');
    }

    /**
     * «Correggi la data» (R2): la riunione registrata a mano con tutte le sue delibere va al giorno giusto. Le schede che le usano non cambiano: l'esito le
     * elenca con la loro data di oggi.
     *
     * @return array{assemblea: Assemblea, posti: list<array<string, mixed>>, frase: ?string}
     */
    public static function correggiData(Assemblea $riunione, string $giorno, User $utente): array
    {
        if ($riunione->stato !== StatoAssemblea::REGISTRATA_A_MANO) {
            throw ValidationException::withMessages(['giorno' => 'Si corregge il giorno di una riunione registrata a mano.']);
        }
        if ($giorno > DateHelper::oggiUtente()) {
            throw ValidationException::withMessages(['giorno' => 'Il giorno della riunione non può essere dopo oggi.']);
        }
        $prima = (string) $riunione->data?->format('Y-m-d');
        $posti = [];
        foreach ($riunione->delibere()->get() as $d) {
            array_push($posti, ...CollegamentiDelleDelibere::posti($d));
        }
        $frase = CollegamentiDelleDelibere::frasiDeiPosti($posti);
        if ($prima === $giorno) {
            return ['assemblea' => $riunione, 'posti' => $posti, 'frase' => $frase];
        }
        $delibere = $riunione->delibere()->count();
        $perche = $frase === null ? null : 'Le sue ' . ($delibere === 1 ? 'una delibera è usata' : $delibere . ' delibere sono usate') . ' da '
            . (count($posti) === 1 ? '1 scheda' : count($posti) . ' schede') . ': ' . implode(', ', array_map(fn ($p) => $p['nome'], $posti));
        RegistroModifiche::durante(GestiDelRegistro::dataDellaRiunioneCorretta($riunione, $prima, $giorno, $perche),
            function () use ($riunione, $giorno, $utente) {
                $riunione->forceFill(['data' => $giorno, 'updated_by' => $utente->id])->save();
                foreach ($riunione->delibere()->get() as $d) {
                    $d->deliberata_il = $giorno;
                    $d->save();
                }
            });

        return ['assemblea' => $riunione->refresh(), 'posti' => $posti, 'frase' => $frase];
    }

    /**
     * «Sposta in un'altra riunione» (R2): la delibera e il suo punto passano in un'altra riunione a mano del condominio (`riunione_id`), o in quella del
     * giorno, del perimetro e del numero dati, che nasce se non c'è. La delibera prende il giorno della riunione di arrivo.
     *
     * @param array<string, mixed> $destinazione
     */
    public static function sposta(Delibera $delibera, array $destinazione, User $utente): Delibera
    {
        $da = $delibera->assemblea;
        if ($da !== null && ! $da->eAMano() && $da->stato === StatoAssemblea::REGISTRATA_A_MANO) {
            // Il punto è dell'ordine del giorno convocato: spostarlo lo toglierebbe dall'assemblea tenuta.
            throw ValidationException::withMessages(['delibera' => 'La delibera è sul punto di un\'assemblea tenuta su carta: non si sposta. '
                . 'Se è sbagliata, eliminala e registrala di nuovo sul punto.']);
        }
        self::soloAMano($da);
        $condominio = $da->condominio;
        $verso = ! empty($destinazione['riunione_id']) ? self::riunioneScelta($condominio, (int) $destinazione['riunione_id'], null)
            : self::riunioneDiDestinazione($condominio, $destinazione);
        if ($verso !== null && (int) $verso->id === (int) $da->id) {
            return $delibera;
        }
        $campi = $verso === null ? self::campi($condominio, $destinazione + ['testo' => $delibera->testo, 'esito' => $delibera->esito?->value]) : null;

        RegistroModifiche::durante(GestiDelRegistro::deliberaSpostata($delibera, $da), function () use ($delibera, $da, $verso, $campi, $utente, $condominio) {
            $verso ??= Assemblea::create([
                'condominio_id' => $condominio->id, 'ambito' => $campi['ambito'], 'palazzina_id' => $campi['palazzina_id'], 'scala_id' => $campi['scala_id'],
                'tipo' => $da->tipo?->value ?? TipoAssemblea::ORDINARIA->value, 'origine' => OrigineAssemblea::A_MANO->value,
                'stato' => StatoAssemblea::REGISTRATA_A_MANO->value, 'data' => $campi['giorno'], 'numero_verbale' => $campi['numero_verbale'],
                'created_by' => $utente->id, 'updated_by' => $utente->id,
            ]);
            $punto = $delibera->punto;
            $numeroDiPrima = (int) $punto->numero;
            $punto->fill(['assemblea_id' => $verso->id, 'numero' => (int) $verso->punti()->max('numero') + 1])->save();
            $delibera->fill(['assemblea_id' => $verso->id, 'deliberata_il' => $verso->data?->format('Y-m-d')])->save();
            self::rinumera($da, $numeroDiPrima);

            return $verso;
        });

        return $delibera->refresh();
    }

    /**
     * «Elimina» (R17): una delibera a mano **senza collegamenti** se ne va, con il suo punto se il punto non ha altre delibere; i memo del punto tornano nella
     * memoria. Con i collegamenti, l'errore elenca le schede da cambiare prima. Una riga sola.
     *
     * Vale anche per la delibera sul punto di un'assemblea **tenuta fuori dal programma** (R16: è registrata a mano anche lei): lì il punto resta, perché è
     * dell'ordine del giorno convocato, e i suoi memo tornano in attesa ricordandolo; l'esito giusto si registra di nuovo sul punto. Con `$conLaRiunione`,
     * l'ultima delibera di una riunione a mano senza il verbale porta con sé la riunione, che resterebbe vuota (una riga in più).
     *
     * **Le cose da fare del seguito**: con una cosa ancora da fare la delibera non si elimina, e la frase dice quale e come chiuderla («Non serve più»);
     * chiuse tutte, se ne vanno con la delibera, ognuna con la sua riga. Niente sparisce senza che l'amministratore l'abbia vista. **Con le cose da fare il
     * motivo è sempre obbligatorio** («esito sbagliato, la registro di nuovo»), e la riga della delibera eliminata tiene la fotografia intera: la delibera con
     * l'esito, ogni cosa con lo stato, chi e quando, il documento; il motivo è il perché. Il diario dell'assemblea la mostra, e il PDF resta fra i documenti.
     */
    public static function elimina(Delibera $delibera, User $utente, bool $conLaRiunione = false, ?string $motivo = null): void
    {
        $riunione = $delibera->assemblea;
        $tenutaFuori = $riunione !== null && ! $riunione->eAMano() && $riunione->stato === StatoAssemblea::REGISTRATA_A_MANO;
        if (! $tenutaFuori) {
            self::soloAMano($riunione);
        }
        // Le schede che non ci sono più non trattengono la delibera: i loro collegamenti se ne vanno con lei.
        $posti = array_values(array_filter(CollegamentiDelleDelibere::posti($delibera), fn ($p) => $p['esiste']));
        if ($posti !== []) {
            throw ValidationException::withMessages(['delibera' => 'La delibera è usata: cambia prima ' . implode(', ', array_map(fn ($p) => $p['nome'], $posti))
                . ', poi eliminala.']);
        }
        $cose = \Illuminate\Support\Facades\Schema::hasTable('delibere_cose_da_fare')
            ? \App\Models\Assemblee\CosaDaFareDellaDelibera::query()->where('delibera_id', $delibera->id)->orderBy('id')->get() : collect();
        $daFare = $cose->filter(fn ($c) => $c->eDaFare())->values();
        if ($daFare->isNotEmpty()) {
            $nomi = $daFare->map(fn ($c) => '«' . ($c->tipo === \App\Enums\Assemblee\TipoCosaDaFare::FUORI_DAL_PROGRAMMA ? $c->testo : $c->tipo?->parola()) . '»')->implode(', ');
            throw ValidationException::withMessages(['delibera' => 'La delibera ha ' . ($daFare->count() === 1 ? '1 cosa da fare' : $daFare->count() . ' cose da fare')
                . ' (' . $nomi . '): segnala «Non serve più», poi eliminala.']);
        }
        $motivo = trim((string) $motivo);
        if ($cose->isNotEmpty() && $motivo === '') {
            throw ValidationException::withMessages(['motivo' => 'Scrivi perché la elimini: resta nel diario dell\'assemblea e nel registro delle modifiche.']);
        }
        $gesto = GestiDelRegistro::deliberaAManoEliminata($delibera);
        if ($cose->isNotEmpty()) {
            $gesto->perche($motivo)->campi(self::fotografia($delibera, $cose));
        }
        $vuota = $conLaRiunione && self::restaVuota($delibera);
        DB::transaction(function () use ($delibera, $riunione, $tenutaFuori, $vuota, $utente, $cose, $gesto) {
            RegistroModifiche::durante($gesto, function () use ($delibera, $riunione, $tenutaFuori, $utente, $cose) {
                CollegamentiDelleDelibere::togliDiSchedeCheNonCiSonoPiu($delibera, $utente);
                $punto = $delibera->punto;
                // Le cose da fare già chiuse (fatte o «Non serve più») se ne vanno con la delibera.
                foreach ($cose as $cosa) {
                    $cosa->delete();
                }
                $delibera->delete();
                if ($punto !== null && ! $punto->delibere()->exists()) {
                    foreach (MemoriaDelCondominio::delPunto($punto) as $memo) {
                        MemoriaDelCondominio::tornaInAttesa($memo, $tenutaFuori);
                    }
                    if (! $tenutaFuori) {
                        $numero = (int) $punto->numero;
                        $punto->delete();
                        self::rinumera($riunione, $numero);
                    }
                }
            });
            if ($vuota) {
                self::eliminaRiunione($riunione->refresh(), $utente);
            }
        });
    }

    /**
     * La fotografia della delibera che se ne va con le sue cose da fare, nei campi della riga del registro: «Approvata · Delibera del 15/12/2026, punto 4
     * (verbale n. 42)», e per ogni cosa «Fatta il 16/12/2026 da Andrea Costa · contratto firmato.pdf» (o «Non serve più dal …», o «Da fare, entro il …»).
     *
     * @param \Illuminate\Support\Collection<int, \App\Models\Assemblee\CosaDaFareDellaDelibera> $cose
     * @return list<array<string, mixed>>
     */
    private static function fotografia(Delibera $delibera, \Illuminate\Support\Collection $cose): array
    {
        $campi = [\App\Services\RegistroModifiche\Campo::nuovo('delibera', 'La delibera', (string) $delibera->esito?->parola() . ' · '
            . \App\Services\Assemblee\DeliberaInParole::breve($delibera), null)];
        foreach ($cose as $c) {
            $c->loadMissing(['fattaDa', 'documento']);
            $quando = $c->fatta_il?->format('d/m/Y');
            $stato = match ($c->stato) {
                \App\Enums\Assemblee\StatoCosaDaFare::FATTA => 'Fatta il ' . $quando . ($c->fattaDa !== null ? ' da ' . $c->fattaDa->name : ''),
                \App\Enums\Assemblee\StatoCosaDaFare::NON_SERVE => 'Non serve più dal ' . $quando . ($c->fattaDa !== null ? ', segnata da ' . $c->fattaDa->name : ''),
                default => 'Da fare' . ($c->entro_il !== null ? ', entro il ' . $c->entro_il->format('d/m/Y') : ''),
            };
            $campi[] = \App\Services\RegistroModifiche\Campo::nuovo('cosa:' . $c->id, (string) $c->testo, $stato . ($c->documento !== null ? ' · ' . $c->documento->name : ''),
                null);
        }

        return $campi;
    }

    /** L'ultima delibera di una riunione registrata a mano senza il verbale sottoscritto: eliminata, la riunione resterebbe vuota (R17). */
    public static function restaVuota(Delibera $delibera): bool
    {
        $riunione = $delibera->assemblea;

        return $riunione !== null && $riunione->eAMano() && $riunione->verbale_documento_id === null
            && ! $riunione->delibere()->whereKeyNot($delibera->id)->exists();
    }

    /** Una riunione a mano senza delibere si elimina (R17); con il verbale sottoscritto no: il verbale resta con la sua riunione. */
    public static function eliminaRiunione(Assemblea $riunione, User $utente): void
    {
        self::soloAMano($riunione);
        if ($riunione->delibere()->exists()) {
            throw ValidationException::withMessages(['riunione' => 'La riunione ha delle delibere: elimina prima quelle, o spostale in un\'altra riunione.']);
        }
        if ($riunione->verbale_documento_id !== null) {
            throw ValidationException::withMessages(['riunione' => 'La riunione ha il verbale sottoscritto: non si elimina.']);
        }
        RegistroModifiche::durante(GestiDelRegistro::riunioneAManoEliminata($riunione), function () use ($riunione) {
            foreach ($riunione->punti()->get() as $punto) {
                $punto->delete();
            }
            $riunione->delete();
        });
    }

    /**
     * Carica il verbale sottoscritto di una riunione registrata a mano (decisioni 492 (4) e 562): un documento della riunione, fuori dall'archivio e dal portale.
     * Se c'era già, il file nuovo lo sostituisce e la versione di prima resta (decisione 559).
     */
    public static function caricaVerbale(Assemblea $riunione, UploadedFile $file, User $utente): Documento
    {
        if (! AccessoAlleAssemblee::registraIlVerbale($utente)) {
            throw new AuthorizationException('Per caricare il verbale sottoscritto serve il permesso «Registra il verbale sottoscritto».');
        }
        if ($riunione->stato !== StatoAssemblea::REGISTRATA_A_MANO) {
            throw ValidationException::withMessages(['verbale' => 'Il verbale sottoscritto si carica su una riunione registrata a mano.']);
        }

        return DB::transaction(function () use ($riunione, $file, $utente) {
            $documento = self::scriviIlVerbale($riunione, $file, $utente);
            // La riunione su carta (decisione 622 (4)): il rendiconto approvato dal seguito prende il documento del verbale adesso, senza un secondo file.
            \App\Services\Assemblee\Seguito\Effetti\SulRendiconto::agganciaIlVerbale($riunione->refresh());

            return $documento;
        });
    }

    /**
     * Toglie il verbale sottoscritto di una riunione registrata a mano, con le sue versioni: è il primo passo per eliminare una riunione registrata per
     * errore (una riunione con il verbale non si elimina). Lo fa chi ha «Registra il verbale sottoscritto» (decisione 562). Nel registro la riga della
     * riunione («Il verbale sottoscritto: … → vuoto») e quella del documento che se ne va, con il nome, la grandezza e l'impronta del file.
     */
    public static function togliVerbale(Assemblea $riunione, User $utente): void
    {
        if (! AccessoAlleAssemblee::registraIlVerbale($utente)) {
            throw new AuthorizationException('Per togliere il verbale sottoscritto serve il permesso «Registra il verbale sottoscritto».');
        }
        if ($riunione->stato !== StatoAssemblea::REGISTRATA_A_MANO || $riunione->verbale_documento_id === null) {
            throw ValidationException::withMessages(['verbale' => 'Questa riunione non ha un verbale sottoscritto da togliere.']);
        }
        DB::transaction(function () use ($riunione, $utente) {
            $documento = Documento::query()->find($riunione->verbale_documento_id);
            $riunione->update(['verbale_documento_id' => null, 'updated_by' => $utente->id]);
            if ($documento !== null) {
                app(VersioniDeiDocumenti::class)->elimina($documento);
            }
        });
    }

    private static function scriviIlVerbale(Assemblea $riunione, UploadedFile $file, User $utente): Documento
    {
        $estensione = mb_strtolower($file->getClientOriginalExtension());
        if (! in_array($estensione, self::ESTENSIONI_DEL_VERBALE, true) || $file->getSize() > self::KB_MASSIMI_DEL_VERBALE * 1024) {
            throw ValidationException::withMessages(['verbale' => 'Il verbale sottoscritto è una scansione o un PDF firmato (pdf, p7m, jpg, png), al più 10 MB.']);
        }
        $esistente = $riunione->verbale_documento_id === null ? null : Documento::query()->find($riunione->verbale_documento_id);
        if ($esistente !== null) {
            app(VersioniDeiDocumenti::class)->sostituisci($esistente, $file);
            $esistente->update(['updated_by' => $utente->id]);
            RegistroModifiche::gesto(Gesto::sulModello($riunione)->riepilogo('Verbale sottoscritto sostituito: ' . $file->getClientOriginalName()
                . '; resta la versione di prima'));

            return $esistente->refresh();
        }
        $percorso = app(ArchivioDocumenti::class)->salva($file);
        DB::afterRollBack(fn () => app(ArchivioDocumenti::class)->elimina($percorso));
        $verbale = trim((string) $riunione->numero_verbale);
        $documento = $riunione->documenti()->create([
            'name' => 'Verbale' . ($verbale !== '' ? ' n. ' . $verbale : '') . ' del ' . $riunione->data?->format('d/m/Y'),
            'description' => 'Il verbale sottoscritto della riunione', 'path' => $percorso, 'mime_type' => (string) $file->getMimeType(),
            'file_size' => (int) $file->getSize(), 'created_by' => $utente->id, 'is_published' => false, 'is_approved' => true,
        ]);
        $riunione->update(['verbale_documento_id' => $documento->id, 'updated_by' => $utente->id]);

        return $documento;
    }

    /** I campi controllati: il giorno non dopo oggi, il testo, l'esito, il perimetro del condominio. @param array<string, mixed> $dati @return array<string, mixed> */
    private static function campi(Condominio $condominio, array $dati): array
    {
        $giorno = (string) ($dati['giorno'] ?? '');
        if ($giorno === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $giorno)) {
            throw ValidationException::withMessages(['giorno' => 'Scrivi il giorno della riunione.']);
        }
        if ($giorno > DateHelper::oggiUtente()) {
            throw ValidationException::withMessages(['giorno' => 'Il giorno della riunione non può essere dopo oggi.']);
        }
        $testo = trim((string) ($dati['testo'] ?? ''));
        if ($testo === '') {
            throw ValidationException::withMessages(['testo' => 'Scrivi che cosa ha deciso l\'assemblea.']);
        }
        $esito = EsitoDelibera::tryFrom((string) ($dati['esito'] ?? ''));
        if ($esito === null) {
            throw ValidationException::withMessages(['esito' => 'Scegli l\'esito della delibera.']);
        }
        [$ambito, $palazzina, $scala] = MemoriaDelCondominio::perimetro($condominio, $dati);
        $materia = MateriaPunto::tryFrom((string) ($dati['materia'] ?? ''))?->value ?? MateriaPunto::ALTRO->value;
        $numero = trim((string) ($dati['numero_verbale'] ?? ''));

        return ['giorno' => $giorno, 'testo' => $testo, 'esito' => $esito->value, 'ambito' => $ambito, 'palazzina_id' => $palazzina, 'scala_id' => $scala,
            'materia' => $materia, 'numero_verbale' => $numero === '' ? null : mb_substr($numero, 0, 50),
            'tipo' => TipoAssemblea::tryFrom((string) ($dati['tipo'] ?? ''))?->value ?? TipoAssemblea::ORDINARIA->value,
            'importo' => isset($dati['importo']) && $dati['importo'] !== '' ? (int) $dati['importo'] : null];
    }

    /**
     * L'origine, ciò di cui parla e la materia della sua proposta, risolti nel condominio (R18).
     *
     * @param array<string, mixed>|null $contesto
     * @return array{oggetti: list<array{0: string, 1: int, 2: string}>, chiave: ?string, materia: ?string}
     */
    private static function oggetti(Condominio $condominio, ?array $contesto): array
    {
        $oggetti = [];
        $chiave = null;
        $materia = null;
        if (! empty($contesto['oggetto_tipo']) && ! empty($contesto['oggetto_id'])) {
            $origine = SchedaDelCondominio::risolvi($condominio, (string) $contesto['oggetto_tipo'], (int) $contesto['oggetto_id']);
            $proposta = PropostaDelPunto::perLaScheda($origine);
            $oggetti = $proposta['oggetti'];
            $chiave = $proposta['chiave_fonte'];
            $materia = $proposta['materia'];
        }
        foreach ((array) ($contesto['parla_di'] ?? []) as $o) {
            [$tipo, $id] = array_is_list($o) ? [$o[0], $o[1]] : [$o['tipo'], $o['id']];
            $scheda = SchedaDelCondominio::risolvi($condominio, (string) $tipo, (int) $id);
            $oggetti[] = [SchedaDelCondominio::tipoDi($scheda), (int) $scheda->getKey(), 'parla_di'];
        }

        return ['oggetti' => array_values(array_unique($oggetti, SORT_REGULAR)), 'chiave' => $chiave, 'materia' => $materia];
    }

    /** La riunione scelta con «Cambia»: una riunione a mano del condominio, dello stesso giorno se il giorno c'è. */
    private static function riunioneScelta(Condominio $condominio, int $id, ?string $giorno): Assemblea
    {
        $riunione = SchedaDelCondominio::risolvi($condominio, 'assemblea', $id);
        if (! $riunione->eAMano() || ($giorno !== null && $riunione->data?->format('Y-m-d') !== $giorno)) {
            throw ValidationException::withMessages(['riunione_id' => 'Scegli una riunione registrata a mano dello stesso giorno.']);
        }

        return $riunione;
    }

    private static function soloAMano(?Assemblea $riunione): void
    {
        if ($riunione === null || ! $riunione->eAMano()) {
            throw ValidationException::withMessages(['delibera' => 'Si cambia così solo una delibera registrata a mano.']);
        }
    }

    /** I punti dopo quello tolto scendono di uno. */
    private static function rinumera(Assemblea $riunione, int $dal): void
    {
        foreach ($riunione->punti()->where('numero', '>', $dal)->get() as $p) {
            $p->numero = (int) $p->numero - 1;
            $p->save();
        }
    }

    /** Il numero del verbale per il confronto: senza spazi, in minuscolo; vuoto è `null`. */
    public static function numero(?string $numero): ?string
    {
        $n = mb_strtolower((string) preg_replace('/\s+/u', '', (string) $numero));

        return $n === '' ? null : $n;
    }
}
