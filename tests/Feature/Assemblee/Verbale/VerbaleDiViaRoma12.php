<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\Assemblee\VotazioneDelPunto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__ . '/../Sala/SalaDiViaRoma12.php';

/**
 * Il Condominio Via Roma 12 del verbale, per i test di questa cartella (non è un test): la sala del 15/12/2026 tenuta e chiusa, con i casi della bozza.
 *
 * - La prima convocazione del 14/12 deserta; la seconda aperta alle 18:42, presidente Rossi Mario, segretario l'amministratore.
 * - Alle 18:45 entrano Rossi Mario, Russo Marco (Int. 5), Colombo Rita (Int. 12), Gallo Enzo e i condòmini 30–36: 11 teste su 26, 494,25 millesimi.
 * - Alle 19:00 il punto 1 (rendiconto): favorevoli tutti, contraria Colombo Rita; alle 19:10 il punto 2, favorevoli tutti.
 * - Alle 19:20 entra Bianchi Anna: per i punti 1 e 2 è assente. Alle 19:25 il punto 3, favorevoli tutti. Alle 19:30 esce il condomino 36: per i punti 4–6 è
 *   assente.
 * - Alle 19:44 il punto 4 (il portone, metà del valore): favorevoli Rossi, Gallo, Bianchi e i condòmini 30–34, contraria Colombo Rita, astenuto nessuno,
 *   Russo Marco senza voto; il condomino 30 in conflitto d'interessi; la nota del presidente su chi non ha votato; la dichiarazione di Rossi Mario.
 * - Alle 20:05 il punto 5 (riscaldamento, vota il conduttore): favorevoli tutti; Ferri Luca, conduttore dell'Int. 12, non c'è.
 * - Alle 20:30 il punto 6 (varie) trattato senza voto; alle 20:55 la chiusura.
 */
final class VerbaleDiViaRoma12
{
    /** La sala chiusa. @return array{0: Beta51Scenario, 1: Assemblea, 2: SedutaDellAssemblea, 3: array<int, int>} le votazioni per numero del punto */
    public static function salaChiusa(TestCase $t, bool $chiudi = true): array
    {
        $s = SalaDiViaRoma12::nuovo($t);
        $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
        $seduta = SalaDiViaRoma12::apriLaSeconda($t, $s, $a);
        SalaDiViaRoma12::entrano($t, $s, $a, ['rossi', 'marco', 'rita', 'gallo', ...array_map(fn ($i) => 'c' . $i, range(30, 36))]);
        $v = [];
        $v[1] = self::tuttiFavorevoli($t, $s, $a, 1, '2026-12-15 19:00', ['rita' => 'contrario']);
        $v[2] = self::tuttiFavorevoli($t, $s, $a, 2, '2026-12-15 19:10');
        SalaDiViaRoma12::entrano($t, $s, $a, ['bianchi'], '2026-12-15 19:20');
        $v[3] = self::tuttiFavorevoli($t, $s, $a, 3, '2026-12-15 19:25');
        SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('esce', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone['c36']->id]],
            '2026-12-15 19:30', 1));
        $v[4] = self::ilPortone($t, $s, $a, $seduta);
        $v[5] = self::tuttiFavorevoli($t, $s, $a, 5, '2026-12-15 20:05');
        $r = SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('trattato', ['seduta_id' => $seduta->id, 'punto_id' => self::punto($a, 6)], '2026-12-15 20:30'));
        $v[6] = (int) $r['votazione_id'];
        if ($chiudi) {
            SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id], '2026-12-15 20:55'));
        }

        return [$s, $a->refresh(), $seduta->refresh(), $v];
    }

    public static function punto(Assemblea $a, int $numero): int
    {
        return (int) $a->punti()->where('numero', $numero)->value('id');
    }

    /** La testa di una persona nel voto. */
    public static function testa(int $votazione, string $nome): string
    {
        $t = collect((array) VotazioneDelPunto::query()->findOrFail($votazione)->intervenuti['teste'])
            ->first(fn ($x) => str_contains((string) $x['nome'], $nome));
        if ($t === null) {
            throw new \RuntimeException('Nessuna testa per ' . $nome);
        }

        return (string) $t['chiave'];
    }

    /** «Si vota», tutti favorevoli, i voti diversi, la conferma con l'esito proposto. @param array<string, string> $diversi */
    public static function tuttiFavorevoli(TestCase $t, Beta51Scenario $s, Assemblea $a, int $numero, string $alle, array $diversi = []): int
    {
        $seduta = SalaDiViaRoma12::seduta($a);
        $r = SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => self::punto($a, $numero)], $alle));
        $id = (int) $r['votazione_id'];
        SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $id], $alle));
        foreach ($diversi as $chi => $voto) {
            SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $id, 'testa' => self::testa($id, $s->persone[$chi]->nome),
                'voto' => $voto], $alle, 1));
        }
        self::conferma($t, $s, $a, $id, $alle);

        return $id;
    }

    public static function conferma(TestCase $t, Beta51Scenario $s, Assemblea $a, int $id, string $alle, ?string $esito = null, array $piu = []): void
    {
        $v = VotazioneDelPunto::query()->findOrFail($id);
        SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('conferma', $piu + ['votazione_id' => $id, 'esito' => $esito ?? $v->esito_proposto?->value ?? 'approvata'],
            $alle, (int) $v->versione));
    }

    /** Il punto 4: i voti uno per uno, il conflitto, la nota su chi non ha votato, la dichiarazione. */
    private static function ilPortone(TestCase $t, Beta51Scenario $s, Assemblea $a, SedutaDellAssemblea $seduta): int
    {
        $r = SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => self::punto($a, 4)], '2026-12-15 19:44'));
        $id = (int) $r['votazione_id'];
        $voti = ['rossi' => 'favorevole', 'gallo' => 'favorevole', 'bianchi' => 'favorevole', 'c30' => 'favorevole', 'c31' => 'favorevole', 'c32' => 'favorevole',
            'c33' => 'favorevole', 'c34' => 'favorevole', 'rita' => 'contrario', 'c35' => 'astenuto'];
        foreach ($voti as $chi => $voto) {
            SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $id, 'testa' => self::testa($id, $s->persone[$chi]->nome),
                'voto' => $voto], '2026-12-15 19:45', 0));
        }
        SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('conflitto', ['votazione_id' => $id, 'testa' => self::testa($id, 'Condomino 30'),
            'in_conflitto' => true, 'nota' => 'È il titolare della prima impresa.'], '2026-12-15 19:45'));
        SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('nota', ['votazione_id' => $id, 'su' => 'non_votato',
            'testo' => 'Russo Marco era in sala e non ha espresso il voto.'], '2026-12-15 19:46'));
        SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('dichiarazione', ['seduta_id' => $seduta->id, 'punto_id' => self::punto($a, 4),
            'anagrafica_id' => $s->persone['rossi']->id, 'testo' => 'I preventivi sono arrivati il giorno prima dell\'assemblea.'], '2026-12-15 19:46'));
        self::conferma($t, $s, $a, $id, '2026-12-15 19:47');

        return $id;
    }

    /** Un gesto del testo del verbale, come lo manda la pagina. @param array<string, mixed> $documento */
    public static function testo(int $verbale, string $parte, array $documento, string $alle, ?int $base, ?string $numero = null): array
    {
        $istante = \Illuminate\Support\Carbon::parse($alle, 'Europe/Rome')->utc()->toIso8601ZuluString();

        return ['gesto' => $numero ?? (string) Str::uuid(), 'tipo' => 'testo', 'dati' => ['verbale_id' => $verbale, 'parte' => $parte, 'documento' => $documento],
            'base' => $base, 'orologio' => $istante, 'segnato_il' => $istante];
    }

    /** Manda i gesti del testo dalla rotta vera; rende la risposta. @param list<array<string, mixed>> $gesti */
    public static function manda(TestCase $t, Beta51Scenario $s, Assemblea $a, array $gesti, string $dispositivo = 'portatile di Andrea')
    {
        return $t->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.verbale.gesti', [$s->c, $a]), ['dispositivo' => $dispositivo, 'gesti' => $gesti]);
    }

    /** «Genera il verbale dal modello» dalla rotta vera; rende il verbale. */
    public static function genera(TestCase $t, Beta51Scenario $s, Assemblea $a, ?int $modello = null): VerbaleDellAssemblea
    {
        $t->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.genera', [$s->c, $a]), ['seduta_id' => SalaDiViaRoma12::seduta($a)->id,
            'modello_id' => $modello])->assertSessionHasNoErrors();

        return VerbaleDellAssemblea::query()->where('assemblea_id', $a->id)->orderByDesc('id')->firstOrFail();
    }

    /** La copia firmata: un PDF vero di una pagina («Verbale n. 42 firmato»), o la sua busta `.p7m` quando il nome finisce con `.p7m`. */
    public static function copia(string $nome = 'verbale_15_12_2026_firmato.pdf'): UploadedFile
    {
        $pdf = self::pdfVero('Verbale firmato da presidente e segretario');

        return UploadedFile::fake()->createWithContent($nome, str_ends_with($nome, '.p7m') ? self::p7m($pdf) : $pdf);
    }

    /** Un PDF vero di una pagina con il testo dato. */
    public static function pdfVero(string $testo): string
    {
        static $fatti = [];

        return $fatti[$testo] ??= (function () use ($testo) {
            $m = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => sys_get_temp_dir()]);
            $m->WriteHTML('<p>' . e($testo) . '</p>');

            return $m->Output('', \Mpdf\Output\Destination::STRING_RETURN);
        })();
    }

    /** La busta `.p7m` (CMS, DER) di un contenuto, firmata con un certificato fatto qui: come la firma digitale di un verbale. */
    public static function p7m(string $contenuto): string
    {
        $chiave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'Presidente di prova'], $chiave), null, $chiave, 1);
        $dentro = tempnam(sys_get_temp_dir(), 'v_in_');
        $fuori = tempnam(sys_get_temp_dir(), 'v_out_');
        file_put_contents($dentro, $contenuto);
        openssl_cms_sign($dentro, $fuori, $cert, $chiave, null, OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER);
        $busta = (string) file_get_contents($fuori);
        @unlink($dentro);
        @unlink($fuori);

        return $busta;
    }

    /**
     * «Registra il verbale sottoscritto» dalla rotta vera, con la partenza del registro se serve. Di serie con la conferma «registro il testo di adesso»
     * (`senza_la_bozza`), che vale solo quando la bozza non è stata stampata: le prove della bozza la tolgono. @param array<string, mixed> $piu
     */
    public static function registra(TestCase $t, Beta51Scenario $s, VerbaleDellAssemblea $v, array $piu = [])
    {
        return $t->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.registra', [$s->c, $v->assemblea_id, $v->id]), $piu + [
            'copia' => self::copia(), 'sottoscritto_il' => '2026-12-15', 'partenza' => ['scelta' => 'libro', 'ultimo' => 41, 'del' => '2026-10-22'],
            'senza_la_bozza' => true,
        ]);
    }

    /** «Stampa la bozza da firmare» dalla rotta vera. */
    public static function stampaLaBozza(TestCase $t, Beta51Scenario $s, VerbaleDellAssemblea $v, bool $ancheConLeCifre = false)
    {
        return $t->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.bozza', [$s->c, $v->assemblea_id, $v->id]),
            $ancheConLeCifre ? ['anche_con_le_cifre' => 1] : []);
    }
}
