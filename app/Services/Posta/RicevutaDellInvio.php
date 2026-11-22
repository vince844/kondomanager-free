<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Posta;

use App\Helpers\DateHelper;
use App\Models\Gestionale\RegistroInvio;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * I controlli della ricevuta PEC trascinata sulla riga di un invio (decisione 577), in un posto solo per la convocazione e per le lettere a chi è in ritardo:
 *
 * - è una ricevuta PEC: dentro c'è il `daticert.xml` del gestore ({@see RicevutaPec::leggi()});
 * - è la ricevuta di **consegna**, o l'**avviso di mancata consegna** (la PEC non è arrivata: chi la usa lo registra come tale), non quella di accettazione né il
 *   preavviso delle dodici ore;
 * - è della consegna (o della mancata consegna) all'indirizzo della riga;
 * - è di quella PEC: l'oggetto è quello della riga, quando la riga lo tiene;
 * - il giorno non è dopo oggi, e **non è prima dell'invio**, all'ora con cinque minuti di tolleranza: una ricevuta datata prima è di un'altra PEC (la diffida
 *   delle 09:00, trascinata su quella delle 11:00 dello stesso giorno), o il file è sbagliato.
 *
 * Se un controllo non passa, niente si scrive e la frase dice perché, con la chiave `ricevuta`. Chi la usa scrive l'esito: la convocazione con il registro
 * delle modifiche ({@see \App\Services\Assemblee\Convocazione\ConsegneDellaConvocazione}), le lettere con il loro registro
 * ({@see \App\Services\Gestionale\Lettere\RegistroLettere::registraLaRicevuta()}).
 */
final class RicevutaDellInvio
{
    /** I minuti di tolleranza fra l'ora della PEC e quella della ricevuta: gli orologi del server e del gestore non sono lo stesso. */
    public const TOLLERANZA_IN_MINUTI = 5;

    /** Il file letto: null se non è una ricevuta PEC (nessun `daticert.xml` leggibile, o senza il giorno e l'ora). */
    public static function leggi(UploadedFile $file): ?RicevutaPec
    {
        $percorso = (string) $file->getRealPath();

        return $percorso === '' ? null : RicevutaPec::leggi((string) file_get_contents($percorso));
    }

    /**
     * La ricevuta di consegna di questa PEC a questa persona, o il suo avviso di mancata consegna ({@see RicevutaPec::eMancataConsegna()}), o la frase del
     * perché no.
     *
     * @param string $diChe di che cosa è la PEC, per la frase dell'oggetto: «della convocazione», «di questa diffida»
     *
     * @throws ValidationException
     */
    public static function controlla(RegistroInvio $invio, UploadedFile $file, string $diChe): RicevutaPec
    {
        $chi = (string) ($invio->dati['persona']['nome'] ?? $invio->anagrafica?->nome ?? 'questa persona');
        $letta = self::leggi($file);
        if ($letta === null) {
            throw ValidationException::withMessages(['ricevuta' => RicevutaPec::NON_E_UNA_RICEVUTA]);
        }
        if (! $letta->eDiConsegna() && ! $letta->eMancataConsegna()) {
            throw ValidationException::withMessages(['ricevuta' => 'Il file è ' . $letta->tipoInParole() . ': trascina la ricevuta di consegna, o l\'avviso di '
                . 'mancata consegna, che arrivano dopo.']);
        }
        $indirizzo = $letta->eMancataConsegna() && $letta->consegnataA === null
            ? (collect($letta->destinatari)->first(fn ($d) => RicevutaPec::stessoIndirizzo($d, $invio->destinatario)) ?? $letta->indirizzo())
            : $letta->consegnataA;
        if (! RicevutaPec::stessoIndirizzo($indirizzo, $invio->destinatario)) {
            throw ValidationException::withMessages(['ricevuta' => 'La ricevuta è della consegna a ' . $indirizzo . ': non è di ' . $chi
                . ', a cui la PEC è andata a ' . $invio->destinatario . '.']);
        }
        if (filled($invio->dati['oggetto'] ?? null) && $letta->oggetto !== null && ! RicevutaPec::stessoOggetto($letta->oggetto, (string) $invio->dati['oggetto'])) {
            throw ValidationException::withMessages(['ricevuta' => 'La ricevuta è di un\'altra PEC a ' . $chi . ' («' . $letta->oggetto . '»), non ' . $diChe . '.']);
        }
        $giorno = $letta->quando->format('Y-m-d');
        if ($giorno > DateHelper::oggiUtente()) {
            throw ValidationException::withMessages(['ricevuta' => 'La ricevuta dice il ' . $letta->quando->format('d/m/Y') . ', dopo oggi: controlla il file.']);
        }
        $dellInvio = self::giornoDellInvio($invio);
        if ($giorno < $dellInvio) {
            throw ValidationException::withMessages(['ricevuta' => 'La ricevuta dice il ' . $letta->quando->format('d/m/Y') . ', prima della PEC, partita il '
                . Carbon::parse($dellInvio)->format('d/m/Y') . ': controlla il file.']);
        }
        // Lo stesso giorno conta l'ora: la ricevuta della PEC delle 09:00 non è di quella delle 11:00.
        if ($letta->quando->lt($invio->emesso_il->copy()->subMinutes(self::TOLLERANZA_IN_MINUTI))) {
            $partita = self::oraDellInvio($invio);
            throw ValidationException::withMessages(['ricevuta' => 'La ricevuta dice il ' . $letta->quando->format('d/m/Y \a\l\l\e H:i') . ', prima della PEC, partita il '
                . Carbon::parse($dellInvio)->format('d/m/Y') . ' alle ' . $partita . ': controlla il file.']);
        }

        return $letta;
    }

    /** L'ora dell'invio, nel calendario dell'amministratore. */
    public static function oraDellInvio(RegistroInvio $invio): string
    {
        return $invio->emesso_il->copy()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('H:i');
    }

    /** Il giorno dell'invio, nel calendario dell'amministratore. */
    public static function giornoDellInvio(RegistroInvio $invio): string
    {
        return $invio->emesso_il->copy()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d');
    }
}
