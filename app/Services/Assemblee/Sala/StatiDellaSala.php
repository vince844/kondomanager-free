<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Sala;

use App\Enums\Assemblee\ConvocazioneSeduta;
use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\EsitoSeduta;
use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Anagrafica;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Services\Assemblee\Convocazione\CompitiDellaConvocazione;
use App\Services\Assemblee\Convocazione\ConvocatiDellAssemblea;
use App\Services\Assemblee\MemoriaDelCondominio;
use App\Services\Assemblee\StatiDellAssemblea;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\Lotto;
use App\Services\RegistroModifiche\RegistroModifiche;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Gli stati della sala (R5), ognuno un gesto con la sua riga:
 *
 * - **«Segna deserta»** una convocazione dalla sua ora; deserte la prima e la seconda, l'assemblea è «deserta» e i memo dei punti tornano in attesa. La
 *   prima e la seconda si segnano deserte anche dopo averle aperte e aver contato i presenti, finché non si vota: le presenze si chiudono, e l'appello resta
 *   per il verbale (la seconda deserta: l'assemblea è deserta, art. 1136 c. 3 c.c.).
 * - **«Apri l'assemblea alle …»** dal giorno della seduta (la seconda dopo la prima deserta, la prosecuzione dopo la seduta sospesa): la fotografia con la
 *   sua impronta, il controllo dell'apertura con la scelta del presidente quando serve (art. 1136 c. 6), le deleghe ricontate (quelle rifiutate nel perché
 *   della riga), presidente e segretario; l'assemblea «in corso» nel giorno della seduta. Il gesto dice che cosa la pagina ha visto del controllo
 *   (`controllo_visto`): se adesso il controllo chiede la scelta e la pagina non l'ha vista (senza rete, o il controllo cambiato intanto), l'apertura non si
 *   perde: si applica con la scelta «da decidere», e il presidente decide dopo ({@see decidiLApertura()}); fino ad allora l'assemblea non si chiude né si
 *   sospende.
 * - **«Sospendi, prosegue il …»** solo verso una data di prosecuzione dell'avviso (art. 66 c. 5 disp. att.); le presenze si chiudono all'ora del gesto.
 * - **«Rinvia l'assemblea»**, anche da in corso, con la riconvocazione ({@see StatiDellAssemblea::annullaERiconvoca()}): l'assemblea si annulla «Rinviata
 *   dalla sala», e ne nasce una nuova; non dopo una delibera confermata.
 * - **«Chiudi l'assemblea»** solo con un esito per ogni punto: i non trattati «Rinviata» (i loro memo tornano in attesa), le presenze chiuse all'ora del
 *   gesto, poi «chiusa».
 */
final class StatiDellaSala
{
    /** La scelta dell'apertura che il presidente deve ancora fare: la seduta si è aperta senza, perché la pagina non sapeva del controllo. */
    public const DA_DECIDERE = 'da_decidere';

    public function __construct(
        private readonly FotografiaDellaSeduta $fotografie,
        private readonly ControlloDellApertura $controllo,
        private readonly DelegheDellAssemblea $deleghe,
    ) {}

    public function deserta(GestoInArrivo $g): array
    {
        $a = $g->assemblea;
        $seduta = self::laSeduta($a, $g->dato('seduta_id'));
        $aperta = self::sedutaAperta($a);
        if ($aperta !== null && (int) $aperta->id === (int) $seduta->id) {
            return $this->desertaDopoLAppello($g, $seduta);
        }
        if ($a->stato !== StatoAssemblea::CONVOCATA) {
            throw ValidationException::withMessages(['gesto' => self::perche($a)]);
        }
        if ($seduta->aperta_il !== null || $seduta->esito !== null) {
            throw ValidationException::withMessages(['gesto' => 'Questa seduta è già ' . ($seduta->esito?->parola() !== null ? mb_strtolower($seduta->esito->parola())
                : 'aperta') . '.']);
        }
        $fissata = self::istante($seduta);
        if ($g->segnatoIl->lt($fissata)) {
            throw ValidationException::withMessages(['gesto' => 'La ' . self::convocazione($seduta) . ' è fissata alle ' . $seduta->fissata_il->format('H:i') . ' del '
                . $seduta->fissata_il->format('d/m/Y') . ': si segna deserta da quell\'ora.']);
        }
        $prima = self::primaDella($a, $seduta);
        if ($prima !== null && $prima->esito !== EsitoSeduta::DESERTA) {
            throw ValidationException::withMessages(['gesto' => 'Prima segna deserta la prima convocazione del ' . $prima->fissata_il->format('d/m/Y') . '.']);
        }
        $tutta = $seduta->convocazione === ConvocazioneSeduta::SECONDA;
        $dichiarata = self::oraDellaDeserta($g, $seduta, $fissata);
        $frase = 'la ' . self::convocazione($seduta) . ' del ' . $seduta->fissata_il->format('d/m/Y') . ' è deserta' . ($tutta ? ': l\'assemblea è deserta' : '')
            . ($dichiarata->format('Y-m-d H:i') !== $g->segnatoIl->format('Y-m-d H:i') ? ' (segnata alle ' . $dichiarata->setTimezone((string) config('app.user_timezone',
                'Europe/Rome'))->format('H:i') . ' del ' . $dichiarata->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y') . ')' : '');

        RegistroModifiche::durante(GestiDelRegistro::dellaSala($a, $frase, $g->segnatoIl, $g->dalFoglio()), function () use ($a, $seduta, $tutta, $dichiarata) {
            $seduta->update(['esito' => EsitoSeduta::DESERTA->value, 'chiusa_il' => $dichiarata]);
            if ($tutta) {
                self::assembleaDeserta($a);
            }
        });

        return ['versione' => null, 'messaggio' => null];
    }

    /**
     * L'ora in cui la seduta mai aperta è dichiarata deserta (decisione 604 (5)): quella scritta nella finestra (`dichiarata_il`, l'ora di Roma «2026-12-14
     * 08:40», fra l'ora della convocazione e il gesto); senza, l'ora del gesto se è dello stesso giorno, altrimenti il giorno e l'ora della convocazione, che
     * la finestra propone e si correggono. L'annotazione del registro dei verbali la dice.
     */
    private static function oraDellaDeserta(GestoInArrivo $g, SedutaDellAssemblea $seduta, CarbonImmutable $fissata): CarbonImmutable
    {
        $scritta = trim((string) $g->dato('dichiarata_il', ''));
        if ($scritta !== '') {
            try {
                $ora = CarbonImmutable::parse($scritta, (string) config('app.user_timezone', 'Europe/Rome'))->utc();
            } catch (\Throwable) {
                throw ValidationException::withMessages(['gesto' => 'L\'ora in cui la seduta è deserta non è nella forma giusta.']);
            }
            if ($ora->lt($fissata) || $ora->gt($g->segnatoIl)) {
                throw ValidationException::withMessages(['gesto' => 'L\'ora in cui la seduta è deserta è fra l\'ora della convocazione e adesso.']);
            }

            return $ora;
        }

        return $g->giorno() === $seduta->fissata_il->format('Y-m-d') ? $g->segnatoIl : $fissata;
    }

    /** Deserte la prima e la seconda: l'assemblea è deserta, i memo dei suoi punti tornano in attesa, e i punti fra quelli per la prossima assemblea. */
    private static function assembleaDeserta(Assemblea $a): void
    {
        $a->update(['stato' => StatoAssemblea::DESERTA->value]);
        foreach ($a->punti()->get() as $punto) {
            foreach (MemoriaDelCondominio::delPunto($punto) as $memo) {
                MemoriaDelCondominio::tornaInAttesa($memo, true);
            }
        }
        StatiDellAssemblea::memoPerLaProssima($a);
        app(CompitiDellaConvocazione::class)->sincronizza($a);
    }

    /**
     * «Segna deserta» dopo aver aperto la prima o la seconda convocazione e contato i presenti, finché non si vota (né si tratta) un punto: le presenze si
     * chiudono all'ora del gesto, la seduta è deserta con la sua apertura e il suo appello (restano per il verbale). La prima deserta: l'assemblea torna
     * «convocata», in attesa della seconda. La seconda deserta: l'assemblea è deserta e i punti tornano fra quelli per la prossima (art. 1136 c. 3 c.c.).
     * La prosecuzione di una seduta sospesa no: l'assemblea era già costituita.
     */
    private function desertaDopoLAppello(GestoInArrivo $g, SedutaDellAssemblea $seduta): array
    {
        $a = $g->assemblea;
        if ((int) $seduta->numero !== 1) {
            throw ValidationException::withMessages(['gesto' => 'La prosecuzione è aperta: si segna deserta solo la prima o la seconda convocazione aperta, prima di '
                . 'votare.']);
        }
        $quale = self::convocazione($seduta);
        $votata = VotazioneDelPunto::query()->where('seduta_id', $seduta->id)->with('punto')->orderBy('id')->first();
        if ($votata !== null) {
            throw ValidationException::withMessages(['gesto' => ($votata->maggioranza === \App\Enums\Assemblee\Maggioranza::SENZA_VOTO ? 'Il punto ' . $votata->punto?->numero . ' è già trattato'
                : 'Sul punto ' . $votata->punto?->numero . ' si è già votato') . ': la ' . $quale . ' non si segna più deserta. Chiudi l\'assemblea, o rinviala.']);
        }
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $apertaAlle = $seduta->aperta_il->copy()->setTimezone($fuso);
        if ($g->segnatoIl->lt($seduta->aperta_il)) {
            throw ValidationException::withMessages(['gesto' => 'La ' . $quale . ' è aperta alle ' . $apertaAlle->format('H:i') . ': si segna deserta da quell\'ora.']);
        }
        $tutta = $seduta->convocazione === ConvocazioneSeduta::SECONDA;
        $fotografia = (array) $seduta->fotografia;
        $conto = PresenzeDellaSala::conteggio((array) $fotografia['costituzione']['teste'], PresenzeDellaSala::presenti($seduta, $g->segnatoIl));
        $barra = ContiDellaSala::costituzione((string) $fotografia['convocazione'], (int) $fotografia['costituzione']['totale_teste'],
            (int) $fotografia['costituzione']['totale_valore'], $conto['teste'], $conto['valore'])['barra'];
        $frase = 'la ' . $quale . ' del ' . $seduta->fissata_il->format('d/m/Y') . ' è deserta: aperta alle ' . $apertaAlle->format('H:i') . ', all\'appello '
            . $barra . ($tutta ? ': l\'assemblea è deserta' : '');

        RegistroModifiche::durante(GestiDelRegistro::dellaSala($a, $frase, $g->segnatoIl, $g->dalFoglio($seduta)),
            function () use ($a, $seduta, $g, $tutta) {
                self::chiudiLePresenze($seduta, $g);
                $seduta->update(['esito' => EsitoSeduta::DESERTA->value, 'chiusa_il' => $g->segnatoIl]);
                if ($tutta) {
                    self::assembleaDeserta($a);

                    return;
                }
                $a->update(['stato' => StatoAssemblea::CONVOCATA->value, 'data' => \App\Services\Assemblee\PreparazioneDellAssemblea::giornoDellaRiunione($a)]);
                app(CompitiDellaConvocazione::class)->sincronizza($a);
            });

        return ['versione' => null, 'messaggio' => null];
    }

    /** Chi è ancora in sala esce all'ora del gesto (la seduta deserta, sospesa o chiusa): il prospetto non ha più «Uscita —» per chi c'era. */
    private static function chiudiLePresenze(SedutaDellAssemblea $seduta, GestoInArrivo $g): void
    {
        foreach (\App\Models\Assemblee\PresenzaInSala::query()->where('seduta_id', $seduta->id)->whereNull('uscita_il')->get() as $presenza) {
            $presenza->update(['uscita_il' => $g->segnatoIl, 'gesto_uscita' => $g->numero]);
        }
    }

    public function apri(GestoInArrivo $g): array
    {
        $a = $g->assemblea;
        $seduta = self::laSeduta($a, $g->dato('seduta_id'));
        if (! in_array($a->stato, [StatoAssemblea::CONVOCATA, StatoAssemblea::IN_CORSO], true)) {
            throw ValidationException::withMessages(['gesto' => self::perche($a)]);
        }
        if ($seduta->aperta_il !== null || $seduta->esito !== null) {
            throw ValidationException::withMessages(['gesto' => 'Questa seduta è già ' . ($seduta->esito !== null ? mb_strtolower($seduta->esito->parola()) : 'aperta') . '.']);
        }
        if ($g->giorno() < $seduta->fissata_il->format('Y-m-d')) {
            throw ValidationException::withMessages(['gesto' => 'La sala si apre il giorno della seduta, il ' . $seduta->fissata_il->format('d/m/Y') . '.']);
        }
        $aperta = self::sedutaAperta($a);
        if ($aperta !== null) {
            throw ValidationException::withMessages(['gesto' => 'La seduta del ' . $aperta->fissata_il->format('d/m/Y') . ' è aperta: chiudila o sospendila prima.']);
        }
        $prima = self::primaDella($a, $seduta);
        if ($prima !== null && (int) $seduta->numero === 1 && $prima->esito !== EsitoSeduta::DESERTA) {
            throw ValidationException::withMessages(['gesto' => 'Prima segna deserta la prima convocazione del ' . $prima->fissata_il->format('d/m/Y') . '.']);
        }
        if ((int) $seduta->numero > 1 && $prima?->esito !== EsitoSeduta::SOSPESA) {
            throw ValidationException::withMessages(['gesto' => 'Si riapre solo la data di prosecuzione di una seduta sospesa.']);
        }
        $presidente = $this->persona((array) $g->dato('presidente', []), 'il presidente');
        $segretario = $this->persona((array) $g->dato('segretario', []), 'il segretario');
        $dalFoglio = $g->dalFoglio();

        $fotografia = $this->fotografie->leggi($a, $seduta);
        $controllo = $this->controllo->controlla($a, $seduta, $fotografia);
        $scelta = (string) $g->dato('scelta', '');
        $motivo = $g->testo('motivo');
        $problemi = implode(' ', array_column($controllo['problemi'], 'frase'));
        $daDecidere = false;
        if ($controllo['serve_la_scelta'] && ($scelta !== 'apri' || $motivo === '')) {
            if ($scelta !== '' || ! self::nonLHaVisto($g, $controllo)) {
                throw ValidationException::withMessages(['gesto' => (string) $controllo['frase']]);
            }
            // La pagina non sapeva di questi problemi (senza rete, o il controllo cambiato intanto): l'apertura non si perde, decide il presidente dopo.
            $daDecidere = true;
            $scelta = self::DA_DECIDERE;
            $motivo = '';
        }
        $rifiutate = $this->deleghe->allApertura($a, $fotografia, $g->segnatoIl, true, true);
        $perche = implode(' ', array_filter([
            $controllo['serve_la_scelta'] && ! $daDecidere ? 'Apri, e il verbale lo scrive: ' . $problemi . ' Motivo: ' . $motivo : null,
            $daDecidere ? 'Il controllo dell\'apertura è cambiato mentre la sala era senza rete: ' . $problemi . ' Decide il presidente.' : null,
            ...$rifiutate,
        ])) ?: null;
        $prosecuzione = (int) $seduta->numero > 1;
        $frase = ($prosecuzione ? 'riaperta il ' . $seduta->fissata_il->format('d/m/Y') . ' alle ' . $g->ora() . ', prosecuzione della ' . self::convocazione($seduta)
            : 'aperta in ' . self::convocazione($seduta) . ' alle ' . $g->ora()) . ', presidente ' . $presidente['nome'] . ', segretario ' . $segretario['nome'];

        RegistroModifiche::durante(GestiDelRegistro::dellaSala($a, $frase, $g->segnatoIl, $dalFoglio, $perche, array_filter([$presidente['anagrafica_id'],
            $segretario['anagrafica_id']])), function () use ($a, $seduta, $g, $presidente, $segretario, $fotografia, $controllo, $scelta, $motivo, $dalFoglio,
                $rifiutate) {
                $deleghe = $this->deleghe->allApertura($a, $fotografia, $g->segnatoIl);
                $seduta->update([
                    'aperta_il' => $g->segnatoIl, 'presidente' => $presidente, 'segretario' => $segretario, 'dal_foglio' => $dalFoglio,
                    'apertura' => ['problemi' => $controllo['problemi'], 'da_guardare' => $controllo['da_guardare'], 'deleghe' => $deleghe,
                        'deleghe_rifiutate' => $rifiutate, 'scelta' => $controllo['serve_la_scelta'] ? $scelta : null,
                        'motivo' => $controllo['serve_la_scelta'] && $motivo !== '' ? $motivo : null, 'da' => $g->utente->name,
                        'il' => $g->segnatoIl->toIso8601ZuluString()],
                    'fotografia' => $fotografia, 'impronta_fotografia' => FotografiaDellaSeduta::impronta($fotografia),
                ]);
                if ($a->stato === StatoAssemblea::CONVOCATA) {
                    $a->update(['stato' => StatoAssemblea::IN_CORSO->value, 'data' => $seduta->fissata_il->format('Y-m-d')]);
                }
                app(CompitiDellaConvocazione::class)->sincronizza($a);
            });

        return ['versione' => null, 'messaggio' => $daDecidere ? 'Il controllo dell\'apertura è cambiato mentre la sala era senza rete: ' . $problemi
            . ' Decide il presidente: «Apri, e il verbale lo scrive» con il motivo, o «Rinvia l\'assemblea».' : null];
    }

    /**
     * La pagina non ha visto i problemi del controllo di adesso: lo dice il gesto con `controllo_visto` (le persone con un problema che la pagina mostrava,
     * vuoto se il controllo era pulito, null se non l'aveva). Un gesto che non lo dice è di una pagina di prima: si rifiuta come allora.
     *
     * @param array<string, mixed> $controllo
     */
    private static function nonLHaVisto(GestoInArrivo $g, array $controllo): bool
    {
        if (! array_key_exists('controllo_visto', $g->dati)) {
            return false;
        }
        $visto = $g->dato('controllo_visto');
        if (! is_array($visto)) {
            return true;
        }
        $adesso = array_map(fn ($p) => (int) $p['anagrafica_id'], (array) $controllo['problemi']);

        return array_diff($adesso, array_map('intval', $visto)) !== [];
    }

    /**
     * Il presidente decide sull'apertura rimasta «da decidere» (la seduta aperta senza rete con il controllo cambiato): «Apri, e il verbale lo scrive» con il
     * motivo. L'altra scelta è «Rinvia l'assemblea», con il suo gesto.
     */
    public function decidiLApertura(GestoInArrivo $g): array
    {
        $a = $g->assemblea;
        $seduta = self::sedutaApertaDelGesto($g);
        $apertura = (array) $seduta->apertura;
        if (($apertura['scelta'] ?? null) !== self::DA_DECIDERE) {
            throw ValidationException::withMessages(['gesto' => 'Sull\'apertura di questa seduta non c\'è niente da decidere.']);
        }
        $motivo = $g->testo('motivo');
        if ($motivo === '') {
            throw ValidationException::withMessages(['gesto' => 'Scrivi il motivo del presidente: va nel verbale.']);
        }
        $problemi = implode(' ', array_column((array) ($apertura['problemi'] ?? []), 'frase'));
        $frase = 'il presidente decide sull\'apertura alle ' . $g->ora() . ': aperta, e il verbale lo scrive';
        RegistroModifiche::durante(GestiDelRegistro::dellaSala($a, $frase, $g->segnatoIl, $g->dalFoglio($seduta), 'Apri, e il verbale lo scrive: ' . $problemi
            . ' Motivo: ' . $motivo, array_filter([$seduta->presidente['anagrafica_id'] ?? null])), fn () => $seduta->update(['apertura' => array_merge($apertura,
                ['scelta' => 'apri', 'motivo' => $motivo, 'decisa_il' => $g->segnatoIl->toIso8601ZuluString(), 'decisa_da' => $g->utente->name])]));

        return ['versione' => null, 'messaggio' => null];
    }

    /** Prima di chiudere o sospendere: la scelta dell'apertura non resta «da decidere». */
    private static function aperturaDecisa(SedutaDellAssemblea $seduta, string $per): void
    {
        $apertura = (array) $seduta->apertura;
        if (($apertura['scelta'] ?? null) === self::DA_DECIDERE) {
            throw ValidationException::withMessages(['gesto' => 'Prima di ' . $per . ' decide il presidente sull\'apertura: '
                . implode(' ', array_column((array) ($apertura['problemi'] ?? []), 'frase'))]);
        }
    }

    public function sospendi(GestoInArrivo $g): array
    {
        $a = $g->assemblea;
        $seduta = self::sedutaApertaDelGesto($g);
        $prosegue = SedutaDellAssemblea::query()->where('assemblea_id', $a->id)->whereKey((int) $g->dato('prosegue_seduta_id'))->first();
        if ($prosegue === null || $prosegue->convocazione !== $seduta->convocazione || (int) $prosegue->numero <= (int) $seduta->numero
            || $prosegue->aperta_il !== null || $prosegue->esito !== null) {
            throw ValidationException::withMessages(['gesto' => 'Si sospende solo verso una data di prosecuzione scritta nell\'avviso (art. 66 c. 5 disp. att.).']);
        }
        self::senzaVotiDaConfermare($a, 'sospendere');
        self::aperturaDecisa($seduta, 'sospendere');
        $frase = 'sospesa alle ' . $g->ora() . ', prosegue il ' . $prosegue->fissata_il->format('d/m/Y') . ' alle ' . $prosegue->fissata_il->format('H:i');
        RegistroModifiche::durante(GestiDelRegistro::dellaSala($a, $frase, $g->segnatoIl, $g->dalFoglio($seduta)), function () use ($seduta, $g) {
            self::chiudiLePresenze($seduta, $g);
            $seduta->update(['esito' => EsitoSeduta::SOSPESA->value, 'chiusa_il' => $g->segnatoIl]);
        });
        app(\App\Services\Assemblee\Verbale\VersioniDelVerbale::class)->allaChiusuraDellaSala($seduta->refresh(), $g->utente, $g->segnatoIl);
        // Il promemoria ai condòmini convocati (decisione 595 (1)): acceso di serie, la finestra lo spegne con `promemoria: false`.
        $messaggio = null;
        if ($g->dato('promemoria', true) !== false) {
            try {
                $pubblica = $g->dato('bacheca', 'pubblica') !== 'bozza';
                $esito = app(\App\Services\Assemblee\Verbale\PromemoriaDellaSospensione::class)->manda($a, $seduta, $prosegue, $g->utente, $pubblica);
                $bacheca = $pubblica ? 'l\'avviso è in bacheca.' : 'l\'avviso in bacheca è in bozza: pubblicalo dalla bacheca.';
                $messaggio = ($esito['senza_posta'] ?? false) ? 'Promemoria: senza un server di posta le email non partono; ' . $bacheca
                    : 'Promemoria mandato: ' . ($esito['email'] === 1 ? '1 email' : $esito['email'] . ' email') . ($pubblica ? ' e l\'avviso in bacheca.'
                    : '; l\'avviso in bacheca è in bozza: pubblicalo dalla bacheca.');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Promemoria della sospensione non mandato: ' . $e->getMessage());
                $messaggio = 'La sospensione è registrata; il promemoria ai condòmini non è partito: mandalo dalla bacheca.';
            }
        }

        return ['versione' => null, 'messaggio' => $messaggio];
    }

    public function rinvia(GestoInArrivo $g): array
    {
        $a = $g->assemblea;
        $motivo = $g->testo('motivo');
        if ($motivo === '') {
            throw ValidationException::withMessages(['gesto' => 'Scrivi perché l\'assemblea si rinvia.']);
        }
        $confermata = VotazioneDelPunto::query()->whereIn('seduta_id', $a->sedute()->pluck('id'))->whereNotNull('confermata_il')->with('punto')->first();
        if ($confermata !== null) {
            throw ValidationException::withMessages(['gesto' => 'Il punto ' . $confermata->punto?->numero . ' ha già una delibera: chiudi l\'assemblea con i punti rimasti «Rinviata».']);
        }
        $seduta = self::sedutaAperta($a) ?? ($g->dato('seduta_id') !== null ? self::laSeduta($a, $g->dato('seduta_id')) : null);
        $nome = GestiDelRegistro::nomeDellAssemblea($a);
        $gesto = GestiDelRegistro::dellaSala($a, '', $g->segnatoIl, $g->dalFoglio($seduta), $motivo)
            ->riepilogo(fn (Lotto $lotto, mixed $esito) => $nome . ' rinviata dalla sala e riconvocata'
                . ($esito instanceof Assemblea && $esito->data !== null ? ' per il ' . $esito->data->format('d/m/Y') : ''))
            ->rimando(fn (Lotto $lotto, mixed $esito) => $esito instanceof Assemblea ? ['tipo' => 'assemblea', 'id' => (int) $esito->id,
                'etichetta' => 'L\'assemblea riconvocata'] : null);
        $nuova = RegistroModifiche::durante($gesto, function () use ($a, $seduta, $g, $motivo) {
            $seduta?->update(['esito' => EsitoSeduta::RINVIATA->value, 'chiusa_il' => $g->segnatoIl]);

            return StatiDellAssemblea::annullaERiconvoca($a, 'Rinviata dalla sala: ' . $motivo, array_values((array) $g->dato('sedute', [])), $g->utente, true);
        });

        return ['versione' => null, 'messaggio' => null, 'assemblea_nuova_id' => (int) $nuova->id];
    }

    public function chiudi(GestoInArrivo $g): array
    {
        $a = $g->assemblea;
        $seduta = self::sedutaApertaDelGesto($g);
        self::senzaVotiDaConfermare($a, 'chiudere');
        self::aperturaDecisa($seduta, 'chiudere');
        $senza = self::puntiSenzaEsito($a);
        if ($senza->isNotEmpty() && $g->dato('non_trattati') !== 'rinviata') {
            $numeri = $senza->pluck('numero')->map(fn ($n) => (string) $n)->all();
            throw ValidationException::withMessages(['gesto' => ($senza->count() === 1 ? '1 punto senza esito (' : $senza->count() . ' punti senza esito (')
                . ConvocatiDellAssemblea::elenco($numeri) . '): segnali «Rinviata», o votali prima di chiudere.']);
        }
        $giorno = $seduta->fissata_il->format('Y-m-d');
        $frase = 'chiusa alle ' . $g->ora() . ': ' . self::riepilogoDelleDelibere((int) $a->id, $senza->count());

        RegistroModifiche::durante(GestiDelRegistro::dellaSala($a, $frase, $g->segnatoIl, $g->dalFoglio($seduta)), function () use ($a, $seduta, $senza, $giorno, $g) {
            foreach ($senza as $punto) {
                StatiDellAssemblea::deliberaSulPunto($punto, ['esito' => EsitoDelibera::RINVIATA->value], $giorno, $g->utente);
            }
            self::chiudiLePresenze($seduta, $g);
            $seduta->update(['esito' => EsitoSeduta::TENUTA->value, 'chiusa_il' => $g->segnatoIl]);
            $a->update(['stato' => StatoAssemblea::CHIUSA->value]);
            StatiDellAssemblea::memoPerLaProssima($a);
        });
        app(\App\Services\Assemblee\Verbale\VersioniDelVerbale::class)->allaChiusuraDellaSala($seduta->refresh(), $g->utente, $g->segnatoIl);

        return ['versione' => null, 'messaggio' => null];
    }

    /**
     * Che cosa è uscito dalla sala, detto in una frase sola per la riga della strada e per quella del registro: le delibere votate (approvate, con modifiche,
     * respinte), i punti trattati senza voto e i punti rinviati («2 delibere, 2 senza voto, 4 punti rinviati»). `$ancoraDaRinviare` sono i punti senza esito
     * che la chiusura sta per segnare «Rinviata» e che non hanno ancora la loro delibera. Una cosa che non c'è non si scrive; senza niente, «nessuna delibera».
     */
    public static function riepilogoDelleDelibere(int $assembleaId, int $ancoraDaRinviare = 0): string
    {
        $esiti = Delibera::query()->where('assemblea_id', $assembleaId)->pluck('esito')
            ->map(fn ($e) => $e instanceof EsitoDelibera ? $e->value : (string) $e);
        $rinviatiGia = $esiti->filter(fn ($e) => $e === EsitoDelibera::RINVIATA->value)->count();
        $senzaVoto = $esiti->filter(fn ($e) => $e === EsitoDelibera::SENZA_VOTO->value)->count();
        $votate = $esiti->count() - $senzaVoto - $rinviatiGia;
        $rinviati = $rinviatiGia + $ancoraDaRinviare;
        $pezzi = [];
        if ($votate > 0) {
            $pezzi[] = $votate === 1 ? '1 delibera' : $votate . ' delibere';
        }
        if ($senzaVoto > 0) {
            $pezzi[] = $senzaVoto . ' senza voto';
        }
        if ($rinviati > 0) {
            $pezzi[] = $rinviati === 1 ? '1 punto rinviato' : $rinviati . ' punti rinviati';
        }

        return $pezzi === [] ? 'nessuna delibera' : implode(', ', $pezzi);
    }

    /** La seduta aperta dell'assemblea: aperta, senza esito. */
    public static function sedutaAperta(Assemblea $assemblea): ?SedutaDellAssemblea
    {
        return SedutaDellAssemblea::query()->where('assemblea_id', $assemblea->id)->whereNotNull('aperta_il')->whereNull('esito')->first();
    }

    /** La seduta aperta del gesto: quella dei dati deve essere la seduta aperta. Con `$obbligatoria` falso, null se non ce n'è una. */
    public static function sedutaApertaDelGesto(GestoInArrivo $g, bool $obbligatoria = true): ?SedutaDellAssemblea
    {
        $aperta = self::sedutaAperta($g->assemblea);
        if ($aperta === null) {
            if (! $obbligatoria) {
                return null;
            }
            throw ValidationException::withMessages(['gesto' => 'Nessuna seduta è aperta: prima apri l\'assemblea.']);
        }
        if ($g->dato('seduta_id') !== null && (int) $g->dato('seduta_id') !== (int) $aperta->id) {
            self::laSeduta($g->assemblea, $g->dato('seduta_id'));
            throw ValidationException::withMessages(['gesto' => 'Questa seduta non è quella aperta.']);
        }

        return $aperta;
    }

    /** Una seduta di questa assemblea, o il gesto si rifiuta. */
    public static function laSeduta(Assemblea $assemblea, mixed $id): SedutaDellAssemblea
    {
        return SedutaDellAssemblea::query()->where('assemblea_id', $assemblea->id)->whereKey((int) $id)->first()
            ?? throw ValidationException::withMessages(['gesto' => 'La seduta non è di questa assemblea.']);
    }

    /** I punti senza un voto confermato e senza delibera. @return \Illuminate\Support\Collection<int, PuntoDellOrdineDelGiorno> */
    public static function puntiSenzaEsito(Assemblea $assemblea)
    {
        $conEsito = VotazioneDelPunto::query()->whereIn('seduta_id', $assemblea->sedute()->pluck('id'))->whereNotNull('confermata_il')->pluck('punto_id')
            ->merge(Delibera::query()->where('assemblea_id', $assemblea->id)->pluck('punto_id'))->map(fn ($id) => (int) $id)->unique()->all();

        return $assemblea->punti()->get()->reject(fn (PuntoDellOrdineDelGiorno $p) => in_array((int) $p->id, $conEsito, true))->values();
    }

    /** L'istante della seduta: l'ora come si legge a Roma, in UTC. */
    public static function istante(SedutaDellAssemblea $seduta): CarbonImmutable
    {
        return CarbonImmutable::parse($seduta->fissata_il->format('Y-m-d H:i:s'), (string) config('app.user_timezone', 'Europe/Rome'))->utc();
    }

    /** «prima convocazione», «seconda convocazione». */
    public static function convocazione(SedutaDellAssemblea $seduta): string
    {
        return ($seduta->convocazione === ConvocazioneSeduta::SECONDA ? 'seconda' : 'prima') . ' convocazione';
    }

    /** Perché un'assemblea in questo stato non ha la sala. */
    public static function perche(Assemblea $assemblea): string
    {
        return match ($assemblea->stato) {
            StatoAssemblea::IN_PREPARAZIONE => 'L\'assemblea non è convocata: la sala si apre dalla prima spedizione.',
            StatoAssemblea::IN_CORSO => 'L\'assemblea è già in corso.',
            default => 'L\'assemblea è ' . mb_strtolower((string) $assemblea->stato?->parola()) . ': la sala non si riapre.',
        };
    }

    /** Prima di sospendere o chiudere: nessun voto aperto da confermare. */
    private static function senzaVotiDaConfermare(Assemblea $assemblea, string $per): void
    {
        $aperta = VotazioneDelPunto::query()->whereIn('seduta_id', $assemblea->sedute()->pluck('id'))->whereNull('confermata_il')->with('punto')->first();
        if ($aperta !== null) {
            throw ValidationException::withMessages(['gesto' => 'Il punto ' . $aperta->punto?->numero . ' ha una votazione da confermare: confermala prima di ' . $per . '.']);
        }
    }

    /** La seduta che viene prima: per la seconda la prima convocazione, per una prosecuzione la riunione prima della stessa convocazione. */
    private static function primaDella(Assemblea $assemblea, SedutaDellAssemblea $seduta): ?SedutaDellAssemblea
    {
        if ((int) $seduta->numero > 1) {
            return SedutaDellAssemblea::query()->where('assemblea_id', $assemblea->id)->where('convocazione', $seduta->convocazione?->value)
                ->where('numero', (int) $seduta->numero - 1)->first();
        }
        if ($seduta->convocazione === ConvocazioneSeduta::SECONDA) {
            return SedutaDellAssemblea::query()->where('assemblea_id', $assemblea->id)->where('convocazione', ConvocazioneSeduta::PRIMA->value)
                ->where('numero', 1)->first();
        }

        return null;
    }

    /** Il presidente o il segretario: una persona dell'anagrafica, o un nome («L'amministratore»). @return array{anagrafica_id: ?int, nome: string} */
    private function persona(array $dati, string $chi): array
    {
        $id = isset($dati['anagrafica_id']) && (int) $dati['anagrafica_id'] > 0 ? (int) $dati['anagrafica_id'] : null;
        $nome = $id !== null ? (string) (Anagrafica::query()->whereKey($id)->value('nome') ?? '') : trim((string) ($dati['nome'] ?? ''));
        if ($nome === '') {
            throw ValidationException::withMessages(['gesto' => 'Scegli ' . $chi . '.']);
        }

        return ['anagrafica_id' => $id, 'nome' => $nome];
    }
}
