<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\Assemblea;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Posta\CasellaPec;
use App\Services\Posta\ConfigurazionePec;
use App\Services\Posta\TrasportoPec;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

require_once __DIR__ . '/../Motore/ViaRoma12.php';

/**
 * Il Condominio Via Roma 12 della convocazione, per i test di questa cartella (non è un test): le persone della bozza con i loro ruoli e i loro dati,
 * l'ordinaria del 15/12/2026 con la prima convocazione lunedì 14/12 alle 8:00, la casella PEC finta e il trasporto finto della PEC.
 *
 * - Int. 3 e box 21: Rossi Mario, con la PEC comunicata il 02/03/2026.
 * - Int. 5: Russo Anna e Russo Marco, comproprietari a metà (lei a mano, lui per raccomandata).
 * - Int. 9: Neri Carla usufruttuaria (raccomandata), Neri Paolo nudo proprietario (PEC non comunicata).
 * - Int. 12: Colombo Rita proprietaria (PEC), Ferri Luca conduttore (a mano).
 * - Int. 14: Bianchi Anna, con la sola email.
 * - Int. 7: Gallo Enzo (raccomandata).
 */
final class ConvocazioneDiViaRoma12
{
    /** @var list<array{casella: ConfigurazionePec, messaggio: Email}> */
    public static array $pecMandate = [];

    public static function nuovo(TestCase $t): Beta51Scenario
    {
        $s = ViaRoma12::nuovo($t);
        $s->c->forceFill(['indirizzo' => 'Via Roma 12', 'comune' => 'Milano', 'cap' => '20121', 'codice_fiscale' => '971' . str_pad((string) $s->c->id, 8, '0', STR_PAD_LEFT)])->saveQuietly();
        $s->pertinenza('21', '3');
        $s->tabella('Proprietà', ['3' => 60, '21' => 5, '5' => 70, '9' => 80, '12' => 90, '14' => 65, '7' => 75]);
        $s->titolare('3', 'rossi')->titolare('21', 'rossi')
            ->titolare('5', 'anna', 'proprietario', 50)->titolare('5', 'marco', 'proprietario', 50)
            ->titolare('9', 'carla', 'usufruttuario')->titolare('9', 'paolo', 'nuda_proprietario')
            ->titolare('12', 'rita')->titolare('12', 'luca', 'inquilino')
            ->titolare('14', 'bianchi')
            ->titolare('7', 'gallo');
        $dati = [
            'rossi' => ['nome' => 'Rossi Mario', 'pec' => 'mario.rossi@pec.it', 'pec_comunicata_il' => '2026-03-02', 'indirizzo' => 'Via Roma 12, 20121 Milano'],
            'anna' => ['nome' => 'Russo Anna', 'indirizzo' => 'Via Roma 12, 20121 Milano', 'mezzo_convocazione' => 'a_mano'],
            'marco' => ['nome' => 'Russo Marco', 'indirizzo' => 'Via Verdi 4, 20121 Milano'],
            'carla' => ['nome' => 'Neri Carla', 'indirizzo' => 'Via Roma 12, 20121 Milano'],
            'paolo' => ['nome' => 'Neri Paolo', 'pec' => 'paolo.neri@pec.it', 'indirizzo' => 'Corso Como 8, 20154 Milano'],
            'rita' => ['nome' => 'Colombo Rita', 'pec' => 'rita.colombo@pec.it', 'pec_comunicata_il' => '2025-01-10', 'indirizzo' => 'Via Tortona 3, 20144 Milano'],
            'luca' => ['nome' => 'Ferri Luca', 'indirizzo' => 'Via Roma 12, 20121 Milano', 'mezzo_convocazione' => 'a_mano'],
            'bianchi' => ['nome' => 'Bianchi Anna', 'indirizzo' => '', 'email' => 'anna.bianchi@example.com'],
            'gallo' => ['nome' => 'Gallo Enzo', 'indirizzo' => 'Via Roma 12, 20121 Milano'],
        ];
        foreach ($dati as $chi => $campi) {
            $s->persone[$chi]->forceFill($campi)->saveQuietly();
        }
        self::$pecMandate = [];

        return $s;
    }

    /** L'ordinaria del 15/12/2026 con i sei punti della bozza: il punto 5 con il conduttore che vota, il 4 di lavori (straordinario). */
    public static function ordinaria(Beta51Scenario $s, array $piu = [], ?array $punti = null): Assemblea
    {
        // Le prove della convocazione partono con i documenti già guardati («Avanti: Convocazione»): la regola del passo «Documenti» la provano
        // le prove dei passi, che la tolgono.
        $a = self::preparata($s, $piu, $punti);
        $a->forceFill(['documenti_visti_il' => now()])->saveQuietly();

        return $a;
    }

    /** L'ordinaria appena preparata, con i documenti non ancora guardati. */
    public static function preparata(Beta51Scenario $s, array $piu = [], ?array $punti = null): Assemblea
    {
        return ViaRoma12::ordinaria($s, $punti ?? [
            ['testo' => 'Approvazione del rendiconto 2025 e dello stato di riparto', 'materia' => 'rendiconto'],
            ['testo' => 'Approvazione del preventivo 2027 e del piano di riparto', 'materia' => 'preventivo'],
            ['testo' => 'Comunicazione dei lavori urgenti alla colonna dell\'acqua e ratifica della spesa di € 1.800,00', 'materia' => 'ratifica'],
            ['testo' => 'Sostituzione del portone d\'ingresso: preventivi, impresa, spesa di € 14.500,00', 'materia' => 'lavori'],
            ['testo' => 'Riscaldamento: orari di accensione 2026/2027', 'materia' => 'riscaldamento', 'conduttore' => 'vota'],
            ['testo' => 'Varie ed eventuali', 'materia' => 'varie'],
        ], $piu);
    }

    /** Un'assemblea di soli punti ordinari: allora il nudo proprietario non è convocato. */
    public static function soloOrdinaria(Beta51Scenario $s): Assemblea
    {
        return self::ordinaria($s, [], [
            ['testo' => 'Approvazione del rendiconto 2025 e dello stato di riparto', 'materia' => 'rendiconto'],
            ['testo' => 'Varie ed eventuali', 'materia' => 'varie'],
        ]);
    }

    /** La casella PEC dello studio, finta, e il trasporto che ricorda i messaggi (o che fallisce con l'errore dato). */
    public static function conLaCasella(?string $errore = null): void
    {
        app()->instance(CasellaPec::class, new class extends CasellaPec {
            public function configurazione(): ?ConfigurazionePec
            {
                return new ConfigurazionePec('studio.costa@pec.it', 'smtps.pec.esempio.it', 465, 'studio.costa@pec.it', 'segreta');
            }
        });
        app()->instance(TrasportoPec::class, new class($errore) implements TrasportoPec {
            public function __construct(private readonly ?string $errore) {}

            public function manda(ConfigurazionePec $casella, Email $messaggio): void
            {
                if ($this->errore !== null) {
                    throw new TransportException($this->errore);
                }
                ConvocazioneDiViaRoma12::$pecMandate[] = ['casella' => $casella, 'messaggio' => $messaggio];
            }
        });
    }

    /** L'invio della convocazione di una persona, l'ultimo. */
    public static function invio(Assemblea $a, Beta51Scenario $s, string $chi): ?RegistroInvio
    {
        return RegistroInvio::query()->where('documentabile_type', $a->getMorphClass())->where('documentabile_id', $a->id)
            ->where('anagrafica_id', $s->persone[$chi]->id)->orderByDesc('id')->first();
    }

    /** Il giorno di oggi per le prove: il 26/11/2026 alle 10:00 di Roma, prima della spedizione. */
    public static function oggi(string $quando = '2026-11-26 10:00'): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse($quando, 'Europe/Rome'));
        \Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::parse($quando, 'Europe/Rome'));
    }

    public static function righeDelRegistro(int $prima): \Illuminate\Support\Collection
    {
        return \App\Models\RegistroModifica::query()->where('id', '>', $prima)->orderBy('id')->get();
    }
}
