<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Lo scenario delle lettere per PEC, sopra quello delle lettere ({@see lttScenario()}): Ugo ha la PEC e l'ha comunicata lui il 12/03; Bice ha una PEC che
 * lo studio ha scritto da un vecchio contratto, mai comunicata da lei. La casella PEC dello studio è finta, e il trasporto finto ricorda i messaggi (o
 * fallisce con l'errore dato): nessuna PEC parte davvero.
 */

use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use App\Services\Posta\CasellaPec;
use App\Services\Posta\ConfigurazionePec;
use App\Services\Posta\TrasportoPec;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

require_once __DIR__.'/LettereScenario.php';

final class LetterePec
{
    /** @var list<array{casella: ConfigurazionePec, messaggio: Email}> */
    public static array $mandate = [];

    /** Gli errori del trasporto, uno per messaggio nell'ordine; null fa partire il messaggio. */
    public static array $errori = [];

    /** L'utente dello studio con il pannello e le impostazioni (per il collegamento alla pagina delle caselle). */
    public static function utente(bool $impostazioni = true): User
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
        $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $ruolo->givePermissionTo($permesso);
        $utente = User::factory()->create(['email' => 'studio@prova.it', 'name' => 'Andrea Costa']);
        $utente->assignRole($ruolo);
        // Caricare un file nel gestionale chiede anche «Crea documenti archivio», che l'amministratore di serie ha.
        foreach (['Crea documenti archivio'] as $nomeDelPermesso) {
            $ruolo->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(['name' => $nomeDelPermesso, 'guard_name' => 'web']));
        }
        if ($impostazioni) {
            $utente->givePermissionTo(Permission::firstOrCreate(['name' => \App\Enums\Permission::MANAGE_GENERAL_SETTINGS->value, 'guard_name' => 'web']));
        }

        return $utente->fresh();
    }

    /** Lo scenario delle lettere con le PEC: Ugo l'ha comunicata il 12/03, Bice no. */
    public static function scenario($test): array
    {
        $s = lttScenario($test);
        $s['persone']['ugo']->update(['pec' => 'ugo@pec.it', 'pec_comunicata_il' => '2026-03-12']);
        $s['persone']['bice']->update(['pec' => 'bice@pec.it', 'pec_comunicata_il' => null]);

        return $s;
    }

    /** La casella PEC dello studio, finta, e il trasporto che ricorda i messaggi o fallisce con l'errore del suo turno. */
    public static function conLaCasella(): void
    {
        self::$mandate = [];
        self::$errori = [];
        app()->instance(CasellaPec::class, new class extends CasellaPec {
            public function configurazione(): ?ConfigurazionePec
            {
                return new ConfigurazionePec('studio.costa@pec.it', 'smtps.pec.esempio.it', 465, 'studio.costa@pec.it', 'segreta');
            }
        });
        app()->instance(TrasportoPec::class, new class implements TrasportoPec {
            public function manda(ConfigurazionePec $casella, Email $messaggio): void
            {
                $errore = array_shift(LetterePec::$errori);
                if ($errore !== null) {
                    throw new TransportException($errore);
                }
                LetterePec::$mandate[] = ['casella' => $casella, 'messaggio' => $messaggio];
            }
        });
    }

    /** Senza la casella PEC dello studio. */
    public static function senzaCasella(): void
    {
        self::$mandate = [];
        app()->instance(CasellaPec::class, new class extends CasellaPec {
            public function configurazione(): ?ConfigurazionePec
            {
                return null;
            }
        });
    }

    /** Le righe `pec` di una persona. */
    public static function righePec(array $s, string $chi): \Illuminate\Support\Collection
    {
        return RegistroInvio::where('condominio_id', $s['c']->id)->where('anagrafica_id', $s['persone'][$chi]->id)->where('canale', RegistroInvio::CANALE_PEC)
            ->orderBy('id')->get();
    }

    /**
     * Una ricevuta di consegna inventata (un gestore di prova), sul modello di `tests/fixtures/pec/ricevuta_consegna_rossi.eml`: il `daticert.xml` con il tipo,
     * l'indirizzo della consegna, l'oggetto, il giorno e l'ora dati.
     */
    public static function ricevuta(string $a, string $oggetto, string $giorno, string $ora, string $tipo = 'avvenuta-consegna'): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<postacert tipo="' . $tipo . '" errore="nessuno">'
            . '<intestazione><mittente>studio.costa@pec.it</mittente><destinatari tipo="certificato">' . htmlspecialchars($a) . '</destinatari>'
            . '<risposte>studio.costa@pec.it</risposte><oggetto>' . htmlspecialchars($oggetto, ENT_XML1) . '</oggetto></intestazione>'
            . '<dati><gestore-emittente>Gestore PEC di prova</gestore-emittente><data zona="+0100"><giorno>' . $giorno . '</giorno><ora>' . $ora . '</ora></data>'
            . '<identificativo>opec0001.prova@pec.gestore-prova.it</identificativo><msgid>&lt;prova@pec.it&gt;</msgid>'
            . '<ricevuta tipo="completa" /><consegna>' . htmlspecialchars($a) . '</consegna></dati></postacert>';

        return "From: posta-certificata@pec.gestore-prova.it\r\nTo: studio.costa@pec.it\r\nSubject: =?UTF-8?B?" . base64_encode('CONSEGNA: ' . $oggetto) . "?=\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"confine\"\r\n\r\n"
            . "--confine\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nRicevuta di avvenuta consegna\r\n"
            . "--confine\r\nContent-Type: application/xml; name=\"daticert.xml\"\r\nContent-Disposition: attachment; filename=\"daticert.xml\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($xml), 76, "\r\n") . "\r\n--confine--\r\n";
    }
}
