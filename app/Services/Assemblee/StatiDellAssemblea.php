<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\MemoDellAssemblea;
use App\Models\Assemblee\OggettoDelPunto;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\User;
use App\Services\Assemblee\Convocazione\CompitiDellaConvocazione;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Validation\ValidationException;

/**
 * Gli stati di un'assemblea del programma in questa tappa (§1.1, §4.7, R16 del progetto della tappa 2): «convocata» alla prima spedizione, «annullata» con il
 * motivo e la riconvocazione che ne crea una nuova, e **«Tenuta fuori dal programma»** per l'assemblea preparata nel programma e tenuta su carta (§13,
 * rilievo 8).
 */
final class StatiDellAssemblea
{
    /** In preparazione → convocata: lo chiama la prima spedizione. Una riga sola. */
    public static function convocata(Assemblea $assemblea, User $utente): Assemblea
    {
        if ($assemblea->stato === StatoAssemblea::CONVOCATA) {
            return $assemblea;
        }
        OrdineDelGiorno::siPuoCambiare($assemblea);
        RegistroModifiche::durante(GestiDelRegistro::assembleaConvocata($assemblea), function () use ($assemblea, $utente) {
            $assemblea->update(['stato' => StatoAssemblea::CONVOCATA->value, 'updated_by' => $utente->id]);
        });

        return $assemblea->refresh();
    }

    /**
     * «Annulla e riconvoca» (§4.7, §13 rilievo 15): l'assemblea si annulla con il motivo (invii, convocati e prove restano), e ne nasce **una nuova** che copia
     * l'ordine del giorno con i suoi oggetti e rimette nei suoi punti i memo, con `riconvocata_da_id`; le date nuove si scrivono, i termini ripartono, l'elenco
     * dei convocati si fissa di nuovo. Una riga sola: «Assemblea ordinaria del 15/12/2026 annullata e riconvocata per il 22/12/2026».
     *
     * Dalla sala («Rinvia l'assemblea», `$dallaSala`) si rinvia anche un'assemblea in corso: il motivo dice «Rinviata dalla sala» (lo scrive chi chiama).
     *
     * @param list<array<string, mixed>> $dateNuove le sedute della nuova
     */
    public static function annullaERiconvoca(Assemblea $assemblea, string $motivo, array $dateNuove, User $utente, bool $dallaSala = false): Assemblea
    {
        $si = $dallaSala ? [StatoAssemblea::CONVOCATA, StatoAssemblea::IN_CORSO] : [StatoAssemblea::IN_PREPARAZIONE, StatoAssemblea::CONVOCATA];
        if (! in_array($assemblea->stato, $si, true)) {
            throw ValidationException::withMessages(['assemblea' => $dallaSala ? 'Dalla sala si rinvia un\'assemblea convocata o in corso.'
                : 'Si annulla solo un\'assemblea in preparazione o convocata.']);
        }
        if (trim($motivo) === '') {
            throw ValidationException::withMessages(['motivo' => 'Scrivi perché l\'assemblea si annulla.']);
        }
        $vecchia = $assemblea;
        $nuova = RegistroModifiche::durante(GestiDelRegistro::annullataERiconvocata($vecchia, trim($motivo)),
            function () use ($vecchia, $motivo, $dateNuove, $utente) {
                $vecchia->update(['stato' => StatoAssemblea::ANNULLATA->value, 'annullata_il' => now(), 'annullata_motivo' => trim($motivo),
                    'annullata_da' => $utente->id, 'updated_by' => $utente->id]);
                $copia = $vecchia->only(['condominio_id', 'ambito', 'palazzina_id', 'scala_id', 'tipo', 'origine', 'luogo', 'modalita', 'piattaforma',
                    'consenso_videoconferenza_il', 'consenso_videoconferenza_modo', 'tabella_id']);
                $nuova = Assemblea::create(array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $copia) + [
                    'stato' => StatoAssemblea::IN_PREPARAZIONE->value, 'riconvocata_da_id' => $vecchia->id, 'created_by' => $utente->id,
                    'updated_by' => $utente->id]);
                foreach ($vecchia->punti()->with('oggetti')->get() as $punto) {
                    $dati = $punto->only(['numero', 'testo', 'testo_pubblico', 'materia', 'maggioranza', 'maggioranza_libera', 'amministrazione', 'conduttore',
                        'ambito', 'palazzina_id', 'scala_id', 'tabella_id', 'esercizio_id', 'importo', 'dati', 'fonte', 'chiave_fonte']);
                    $nuovo = PuntoDellOrdineDelGiorno::create(array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $dati)
                        + ['assemblea_id' => $nuova->id, 'created_by' => $utente->id]);
                    foreach ($punto->oggetti as $o) {
                        OggettoDelPunto::create(['punto_id' => $nuovo->id, 'oggetto_tipo' => $o->oggetto_tipo, 'oggetto_id' => $o->oggetto_id,
                            'ruolo' => $o->ruolo?->value]);
                    }
                    foreach (MemoDellAssemblea::query()->where('punto_id', $punto->id)->get() as $memo) {
                        $memo->update(['punto_id' => $nuovo->id, 'assemblea_id' => $nuova->id]);
                    }
                }
                self::memoPerLaProssima($vecchia);
                PreparazioneDellAssemblea::aggiorna($nuova, ['sedute' => $dateNuove], $utente);
                AgendaDellAssemblea::sincronizza($vecchia);
                app(CompitiDellaConvocazione::class)->sincronizza($vecchia);

                return $nuova->refresh();
            });

        return $nuova;
    }

    /**
     * «Tenuta su carta» (R16; nel codice `tenutaFuoriDalProgramma`): l'assemblea preparata nel programma e tenuta su carta diventa `registrata_a_mano` (l'origine resta «programma»),
     * nel giorno della seduta tenuta (di serie il giorno della riunione). Per i punti con un esito (`$esiti`: punto => `esito`, `testo`, `importo`) nasce la
     * delibera sul punto, e i memo di quei punti si chiudono; i memo dei punti senza delibera tornano «in attesa» con «non trattato il 15/12/2026». Altre
     * delibere si registrano dopo sul punto con {@see DeliberaAMano::registraSulPunto()}. Una riga sola: «… tenuta su carta: 5 delibere registrate
     * a mano, 1 punto non trattato».
     *
     * @param array<int, array{esito: string, testo?: ?string, importo?: ?int}> $esiti
     */
    public static function tenutaFuoriDalProgramma(Assemblea $assemblea, User $utente, array $esiti = [], ?string $giorno = null, ?int $numeroVerbale = null): Assemblea
    {
        if (! in_array($assemblea->stato, [StatoAssemblea::IN_PREPARAZIONE, StatoAssemblea::CONVOCATA], true) || $assemblea->eAMano()) {
            throw ValidationException::withMessages(['assemblea' => 'Si segna tenuta su carta un\'assemblea preparata nel programma, in preparazione o convocata.']);
        }
        $giorno ??= $assemblea->data?->format('Y-m-d');
        if ($giorno === null || $giorno > \App\Helpers\DateHelper::oggiUtente()) {
            throw ValidationException::withMessages(['giorno' => 'Il giorno della seduta tenuta non può essere dopo oggi.']);
        }
        // Il numero del verbale di carta nel registro dei verbali (decisione 595 (5) (a)): proposto, correggibile, mai uno già usato né più basso.
        if ($numeroVerbale !== null) {
            $errore = app(\App\Services\Assemblee\Verbale\RegistroDeiVerbali::class)->numeroUsato($assemblea->condominio()->firstOrFail(),
                \App\Services\Assemblee\Verbale\VerbaliDellAssemblea::registroDi($assemblea), $numeroVerbale);
            if ($errore !== null) {
                throw ValidationException::withMessages(['numero_verbale' => $errore]);
            }
        }

        RegistroModifiche::durante(GestiDelRegistro::tenutaFuoriDalProgramma($assemblea), function () use ($assemblea, $utente, $esiti, $giorno, $numeroVerbale) {
            $assemblea->update(['stato' => StatoAssemblea::REGISTRATA_A_MANO->value, 'data' => $giorno, 'updated_by' => $utente->id]
                + ($numeroVerbale !== null ? ['numero_verbale' => (string) $numeroVerbale] : []));
            $delibere = 0;
            $nonTrattati = 0;
            foreach ($assemblea->punti()->get() as $punto) {
                $esito = $esiti[$punto->id] ?? null;
                if ($esito !== null && EsitoDelibera::tryFrom((string) ($esito['esito'] ?? '')) !== null) {
                    self::deliberaSulPunto($punto, $esito, $giorno, $utente);
                    $delibere++;
                    continue;
                }
                $nonTrattati++;
                foreach (MemoriaDelCondominio::delPunto($punto) as $memo) {
                    MemoriaDelCondominio::tornaInAttesa($memo, true);
                }
            }
            // I memo «per questa assemblea» che non sono entrati nell'ordine del giorno tornano «per la prossima», come quando l'assemblea si elimina.
            self::memoPerLaProssima($assemblea);
            AgendaDellAssemblea::sincronizza($assemblea);
            app(CompitiDellaConvocazione::class)->sincronizza($assemblea);

            return ['delibere' => $delibere, 'non_trattati' => $nonTrattati];
        });

        return $assemblea->refresh();
    }

    /**
     * La delibera su un punto di un'assemblea tenuta: il giorno della seduta tenuta; i memo del punto si chiudono. Un punto **rinviato** non è deciso: i suoi
     * memo tornano in attesa, ricordano il punto («rinviato il 15/12/2026») e il compito torna se il giorno è ancora davanti (R10, R16).
     *
     * @param array<string, mixed> $esito
     */
    public static function deliberaSulPunto(PuntoDellOrdineDelGiorno $punto, array $esito, string $giorno, User $utente): Delibera
    {
        $assemblea = $punto->assemblea;
        $delibera = Delibera::create([
            'condominio_id' => $assemblea->condominio_id, 'assemblea_id' => $assemblea->id, 'punto_id' => $punto->id,
            'votazione' => (int) ($esito['votazione'] ?? 1), 'oggetto' => $esito['oggetto'] ?? null,
            'testo' => trim((string) ($esito['testo'] ?? '')) !== '' ? trim((string) $esito['testo']) : $punto->testo,
            'esito' => (string) $esito['esito'], 'deliberata_il' => $giorno, 'importo' => $esito['importo'] ?? $punto->importo, 'created_by' => $utente->id,
        ]);
        $rinviata = $delibera->esito === EsitoDelibera::RINVIATA;
        $memoDelPunto = MemoriaDelCondominio::delPunto($punto);
        foreach ($memoDelPunto as $memo) {
            $rinviata ? MemoriaDelCondominio::tornaInAttesa($memo, true) : MemoriaDelCondominio::deliberato($memo);
        }
        // Un punto scritto a mano e rinviato non ha né un memo né una fonte che lo riproponga (R8 rilegge solo i sospesi con la loro chiave): torna come un
        // memo in attesa che ricorda il punto, come dice il seguito («Il punto torna nella memoria del condominio»). Rilievo C62.
        if ($rinviata && $memoDelPunto->isEmpty() && blank($punto->chiave_fonte)) {
            MemoriaDelCondominio::dalPuntoRinviato($punto, $utente);
        }

        return $delibera;
    }

    /**
     * Una delibera già scritta cambia esito (il voto corretto dopo la conferma): i memo del punto seguono come alla prima scrittura. Diventata «rinviata», i memo
     * tornano in attesa, e il punto scritto a mano torna come memo (C62); non più «rinviata», il memo in attesa del punto si chiude (revisione del 22/11).
     */
    public static function esitoCambiato(PuntoDellOrdineDelGiorno $punto, EsitoDelibera $prima, EsitoDelibera $dopo, User $utente): void
    {
        if ($prima === $dopo) {
            return;
        }
        $memo = MemoriaDelCondominio::delPunto($punto);
        if ($dopo === EsitoDelibera::RINVIATA) {
            foreach ($memo as $m) {
                MemoriaDelCondominio::tornaInAttesa($m, true);
            }
            if ($memo->isEmpty() && blank($punto->chiave_fonte)) {
                MemoriaDelCondominio::dalPuntoRinviato($punto, $utente);
            }
        } elseif ($prima === EsitoDelibera::RINVIATA) {
            foreach ($memo as $m) {
                MemoriaDelCondominio::deliberato($m);
            }
        }
    }

    /** I memo in attesa destinati a un'assemblea che non si prepara più tornano «per la prossima» (`assemblea_id` vuoto). */
    public static function memoPerLaProssima(Assemblea $assemblea): void
    {
        foreach (MemoDellAssemblea::query()->where('assemblea_id', $assemblea->id)->where('stato', \App\Enums\Assemblee\StatoMemo::IN_ATTESA->value)->get() as $memo) {
            $memo->update(['assemblea_id' => null]);
        }
    }
}
