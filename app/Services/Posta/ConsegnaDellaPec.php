<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Posta;

use App\Models\Gestionale\RegistroInvio;
use Carbon\Carbon;

/**
 * Le parole dello stato di una PEC mandata dal programma a una persona, per le lettere a chi è in ritardo (decisioni 569 e 577). **«Consegnata» si dice solo
 * qui, e solo con la consegna provata**: la ricevuta di consegna del gestore letta dal file, o il giorno e l'ora scritti a mano con la prova allegata (quando la
 * ricevuta non si legge). Senza, la PEC è «partita»: il gestore l'ha accettata, e basta. Le pagine e le frasi delle lettere che altrove non dicono mai
 * «consegnata» (l'email, la stampa, la raccomandata: il programma non lo sa) prendono la parola da qui.
 *
 * La consegna sta nella riga: le colonne `esito`, `esito_il` (il giorno e l'ora come li dà il gestore, nel calendario dell'amministratore) e `ricevuta_il`, e
 * in `dati.consegna` da dove viene (`a_mano`, `gestore`, il documento della prova). **L'avviso di mancata consegna** del gestore dà l'esito `non_consegnata`,
 * con il giorno e l'ora dell'avviso in `dati.mancata_consegna`: la PEC è partita, ma non è arrivata nella casella; la lettera non conta fra le arrivate.
 * Una PEC rimasta **in partenza** più di {@see MINUTI_IN_PARTENZA} minuti si è fermata mentre partiva: il programma non sa se il gestore l'ha presa.
 */
final class ConsegnaDellaPec
{
    /** Dopo quanti minuti una PEC rimasta in partenza si dice ferma, e si manda di nuovo: il tempo del blocco dell'invio. */
    public const MINUTI_IN_PARTENZA = 15;

    /** La riga ha l'avviso di mancata consegna del gestore. */
    public static function nonConsegnata(RegistroInvio $r): bool
    {
        return $r->canale === RegistroInvio::CANALE_PEC && $r->esito === RegistroInvio::ESITO_NON_CONSEGNATA;
    }

    /** La PEC è rimasta in partenza oltre il tempo del blocco: il lavoro si è fermato mentre la mandava. */
    public static function ferma(RegistroInvio $r): bool
    {
        return $r->canale === RegistroInvio::CANALE_PEC && $r->stato === RegistroInvio::STATO_IN_CODA && $r->esito === null
            && $r->emesso_il !== null && $r->emesso_il->lt(now()->subMinutes(self::MINUTI_IN_PARTENZA));
    }

    /** La riga ha la consegna provata: l'esito «consegnata» con il giorno. */
    public static function provata(RegistroInvio $r): bool
    {
        return $r->canale === RegistroInvio::CANALE_PEC && $r->esito === RegistroInvio::ESITO_CONSEGNATA && $r->ricevuta_il !== null;
    }

    /** Il badge della riga di una PEC: «Consegnata», «Non consegnata», «Partita», «Non partita», «In partenza». */
    public static function badge(RegistroInvio $r): string
    {
        return match (true) {
            self::provata($r) => 'Consegnata',
            self::nonConsegnata($r) => 'Non consegnata',
            $r->stato === RegistroInvio::STATO_INVIATA => 'Partita',
            $r->stato === RegistroInvio::STATO_NON_PARTITA => 'Non partita',
            default => 'In partenza',
        };
    }

    /**
     * Lo stato in parole: «Partita per PEC il 03/12 alle 10:12 a neri.carla@pec.it, dalla casella dello studio. Il gestore l'ha accettata.»; «Consegnata il 03/12
     * alle 10:14 a neri.carla@pec.it, dalla ricevuta del gestore (Gestore di prova).»; «Consegnata il 03/12 alle 10:14 a …, scritta a mano, con la prova.»;
     * «Non partita il 03/12 alle 10:12, per …: » e la frase dell'errore.
     */
    public static function frase(RegistroInvio $r, ?ConfigurazionePec $casella = null): string
    {
        $quando = self::quando($r->emesso_il);
        $a = filled($r->destinatario) ? ' a ' . $r->destinatario : '';
        if (self::provata($r)) {
            return 'Consegnata ' . self::ilGiornoEOra($r) . $a . self::daDove($r) . '.';
        }
        if (self::nonConsegnata($r)) {
            $avviso = (array) ($r->dati['mancata_consegna'] ?? []);
            $giorno = (string) ($avviso['giorno'] ?? $r->ricevuta_il?->format('Y-m-d') ?? '');
            $ora = (string) ($avviso['ora'] ?? '');

            return 'Non consegnata: l\'avviso di mancata consegna del gestore' . (filled($avviso['gestore'] ?? null) ? ' (' . $avviso['gestore'] . ')' : '')
                . ($giorno !== '' ? ', del ' . Carbon::parse($giorno)->format('d/m') . ($ora !== '' ? ' alle ' . $ora : '') . ',' : '')
                . ' dice che la PEC non è arrivata nella casella' . (filled($r->destinatario) ? ' di ' . $r->destinatario : '') . '.';
        }
        if (self::ferma($r)) {
            return 'In partenza ' . str_replace('il ', 'dal ', $quando) . $a . ': si è fermata mentre la mandava, e il programma non sa se il gestore l\'ha presa. Guarda '
                . 'nella casella dello studio fra le PEC inviate: se non c\'è, mandala di nuovo.';
        }

        return match ($r->stato) {
            RegistroInvio::STATO_INVIATA => 'Partita per PEC ' . $quando . $a . ', dalla casella dello studio. Il gestore l\'ha accettata.',
            RegistroInvio::STATO_NON_PARTITA => 'Non partita ' . $quando . (filled($r->destinatario) ? ', per ' . $r->destinatario : '') . ': '
                . ErroreDellaPec::frase($r->errore, $casella),
            default => 'In partenza ' . $quando . $a . ': il programma la stava mandando dalla casella dello studio.',
        };
    }

    /** «consegnata per PEC il 03/12/2026» del riquadro e della tesoreria: il participio accordato con il tipo della lettera. */
    public static function participio(string $tipo): string
    {
        return in_array($tipo, ['diffida', 'messa_in_mora'], true) ? 'consegnata' : 'consegnato';
    }

    /** «PEC consegnata il 03/12», per la colonna «Ultima lettera». */
    public static function perLaColonna(string $giorno): string
    {
        return 'PEC consegnata il ' . Carbon::parse($giorno)->format('d/m');
    }

    /** L'evento dell'avviso di mancata consegna, in parole: «non consegnata: avviso del 21/03 alle 11:30». */
    public static function perLaMancataConsegna(string $giorno, string $ora): string
    {
        return 'non consegnata: avviso del ' . Carbon::parse($giorno)->format('d/m') . ' alle ' . $ora;
    }

    /**
     * «È l'avviso di mancata consegna: la PEC non è arrivata nella casella di ugo@pec.it. La diffida non è consegnata e il termine non corre: mandala di nuovo
     * per PEC o stampala per la posta.»: il messaggio dopo l'avviso registrato su una lettera.
     */
    public static function fraseDellaMancataConsegna(RegistroInvio $riga): string
    {
        $femminile = in_array($riga->tipo, ['diffida', 'messa_in_mora'], true);
        $nome = ['diffida' => 'La diffida', 'messa_in_mora' => 'La messa in mora', 'sollecito' => 'Il sollecito', 'promemoria' => 'Il promemoria'][$riga->tipo] ?? 'La lettera';

        return 'È l\'avviso di mancata consegna: la PEC non è arrivata nella casella' . (filled($riga->destinatario) ? ' di ' . $riga->destinatario : '') . '. '
            . $nome . ' non è ' . ($femminile ? 'consegnata' : 'consegnato') . ' e il termine non corre: ' . ($femminile ? 'mandala' : 'mandalo')
            . ' di nuovo per PEC o ' . ($femminile ? 'stampala' : 'stampalo') . ' per la posta.';
    }

    /** L'evento della ricevuta, in parole: «consegnata il 03/12 alle 10:14». */
    public static function perLEvento(string $giorno, ?string $ora): string
    {
        return 'consegnata il ' . Carbon::parse($giorno)->format('d/m') . ($ora !== null && $ora !== '' ? ' alle ' . $ora : '');
    }

    /** «il 03/12 alle 10:14», dalla consegna della riga. */
    private static function ilGiornoEOra(RegistroInvio $r): string
    {
        $ora = (string) ($r->dati['consegna']['ora'] ?? $r->esito_il?->format('H:i') ?? '');

        return 'il ' . $r->ricevuta_il->format('d/m') . ($ora !== '' ? ' alle ' . $ora : '');
    }

    private static function daDove(RegistroInvio $r): string
    {
        $consegna = (array) ($r->dati['consegna'] ?? []);
        if (! empty($consegna['a_mano'])) {
            return ', scritta a mano, con la prova';
        }

        return ', dalla ricevuta del gestore' . (filled($consegna['gestore'] ?? null) ? ' (' . $consegna['gestore'] . ')' : '');
    }

    private static function quando(?\DateTimeInterface $il): string
    {
        if ($il === null) {
            return '';
        }
        $locale = Carbon::instance($il)->setTimezone((string) config('app.user_timezone', 'Europe/Rome'));

        return 'il ' . $locale->format('d/m') . ' alle ' . $locale->format('H:i');
    }
}
