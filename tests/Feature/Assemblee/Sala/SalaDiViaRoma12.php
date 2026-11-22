<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\ConvocatoDellAssemblea;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\Convocazione\ConvocatiDellAssemblea;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__ . '/../Convocazione/ConvocazioneDiViaRoma12.php';

/**
 * Il Condominio Via Roma 12 della sala, per i test di questa cartella (non è un test): le persone della convocazione (Rossi con l'Int. 3 e il box 21, i
 * Russo comproprietari dell'Int. 5, Neri Carla usufruttuaria e Neri Paolo nudo proprietario dell'Int. 9, Colombo Rita con il conduttore Ferri Luca all'Int.
 * 12, Bianchi Anna all'Int. 14, Gallo Enzo all'Int. 7) più venti condòmini da 27,75 millesimi (Int. 30–49), così le teste sono **26** e la tabella fa
 * **1.000,00**: il quinto delle deleghe si applica. L'ordinaria del 15/12/2026 (prima lunedì 14/12 alle 8:00, seconda martedì 15/12 alle 18:30) con i sei
 * punti della bozza, convocata con un invio che vale per ognuno, ricevuto il 05/12.
 */
final class SalaDiViaRoma12
{
    /** I venti condòmini in più: unità «30»…«49», persone «c30»…«c49», 27,75 millesimi ciascuno. */
    public const ALTRI = 20;

    public static function nuovo(TestCase $t, bool $conGliAltri = true): Beta51Scenario
    {
        $s = ConvocazioneDiViaRoma12::nuovo($t);
        if ($conGliAltri) {
            $tabella = $s->tabelle['Proprietà'];
            for ($i = 30; $i < 30 + self::ALTRI; $i++) {
                $s->unitaSeManca((string) $i);
                DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $s->unita[(string) $i]->id, 'valore' => 27.75,
                    'created_at' => now(), 'updated_at' => now()]);
                $s->titolare((string) $i, 'c' . $i);
                $s->persone['c' . $i]->forceFill(['nome' => 'Condomino ' . $i, 'indirizzo' => 'Via Roma 12, 20121 Milano'])->saveQuietly();
            }
        }

        return $s;
    }

    /** L'ordinaria con la tabella «Proprietà» come tabella del valore; `$punti` sostituisce i sei della bozza. */
    public static function ordinaria(Beta51Scenario $s, ?array $punti = null, array $piu = []): Assemblea
    {
        $a = ConvocazioneDiViaRoma12::ordinaria($s, $piu, $punti);
        $a->forceFill(['tabella_id' => $s->tabelle['Proprietà']->id])->saveQuietly();

        return $a->refresh();
    }

    /**
     * La convocazione spedita, come la lascia il passo «Convocazione»: per ognuno dei convocati del giorno (tranne `$saltati`) un invio per raccomandata
     * spedito e ricevuto il giorno dato, il convocato fissato; l'assemblea «convocata».
     *
     * @param list<string> $saltati le persone senza avviso
     * @param array<string, string> $ricevute persona => giorno di ricezione diverso
     */
    public static function convoca(Assemblea $a, Beta51Scenario $s, array $saltati = [], string $ricevuta = '2026-12-05', array $ricevute = []): Assemblea
    {
        $saltati = array_map(fn ($chi) => (int) $s->persone[$chi]->id, $saltati);
        $giorno = '2026-11-26';
        foreach (app(ConvocatiDellAssemblea::class)->alGiorno($a, $giorno) as $c) {
            if (in_array((int) $c['anagrafica_id'], $saltati, true)) {
                continue;
            }
            $chi = array_search((int) $c['anagrafica_id'], array_map(fn ($p) => (int) $p->id, $s->persone), true);
            $invio = RegistroInvio::create(['condominio_id' => $a->condominio_id, 'anagrafica_id' => (int) $c['anagrafica_id'],
                'tipo' => RegistroInvio::TIPO_CONVOCAZIONE, 'canale' => RegistroInvio::CANALE_STAMPA, 'documentabile_type' => $a->getMorphClass(),
                'documentabile_id' => $a->id, 'destinatario' => (string) $c['indirizzo'], 'dati' => ['mezzo' => 'raccomandata', 'punti' => [], 'sedute' => []],
                'impronta' => hash('sha256', 'avviso-' . $c['anagrafica_id']), 'stato' => RegistroInvio::STATO_SPEDITA, 'emesso_da' => $s->admin->id,
                'emesso_il' => now(), 'ricevuta_il' => $ricevute[$chi] ?? $ricevuta, 'esito' => RegistroInvio::ESITO_CONSEGNATA]);
            ConvocatoDellAssemblea::create(['assemblea_id' => $a->id, 'anagrafica_id' => (int) $c['anagrafica_id'], 'nome' => $c['nome'],
                'indirizzo' => $c['indirizzo'], 'pec' => $c['pec'], 'unita' => $c['unita'], 'punti' => $c['punti'], 'mezzo' => 'raccomandata',
                'fissato_il' => now(), 'invio_id' => $invio->id, 'created_by' => $s->admin->id]);
        }
        $a->forceFill(['stato' => StatoAssemblea::CONVOCATA->value])->saveQuietly();

        return $a->refresh();
    }

    /** La seduta: `prima` o `seconda`, la prima riunione. */
    public static function seduta(Assemblea $a, string $convocazione = 'seconda', int $numero = 1): SedutaDellAssemblea
    {
        return SedutaDellAssemblea::query()->where('assemblea_id', $a->id)->where('convocazione', $convocazione)->where('numero', $numero)->firstOrFail();
    }

    /** Un gesto come lo manda la pagina: il numero, il tipo, i dati, l'ora del gesto (di Roma, come si legge) e la versione di partenza. @return array<string, mixed> */
    public static function gesto(string $tipo, array $dati, string $alle = '2026-12-15 18:45', ?int $base = null, ?string $numero = null): array
    {
        $istante = \Illuminate\Support\Carbon::parse($alle, 'Europe/Rome')->utc()->toIso8601ZuluString();

        return ['gesto' => $numero ?? (string) Str::uuid(), 'tipo' => $tipo, 'dati' => $dati, 'base' => $base, 'orologio' => $istante, 'segnato_il' => $istante];
    }

    /** Manda i gesti dalla rotta vera, con il dispositivo «portatile di Andrea»; rende la risposta. @param list<array<string, mixed>> $gesti */
    public static function manda(TestCase $t, Beta51Scenario $s, Assemblea $a, array $gesti, string $dispositivo = 'portatile di Andrea')
    {
        return $t->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.sala.gesti', [$s->c, $a]), ['dispositivo' => $dispositivo, 'gesti' => $gesti]);
    }

    /** Manda un gesto e pretende «applicato»; rende la risposta del gesto. */
    public static function applica(TestCase $t, Beta51Scenario $s, Assemblea $a, array $gesto): array
    {
        $r = self::manda($t, $s, $a, [$gesto])->assertOk()->json('risposte.0');
        if (($r['esito'] ?? null) !== 'applicato') {
            throw new \RuntimeException('Gesto «' . $gesto['tipo'] . '» non applicato: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        }

        return $r;
    }

    /**
     * La seconda convocazione aperta alle 18:42 del 15/12, con la prima segnata deserta, presidente Rossi e segretario l'amministratore. Poi l'orologio del
     * server va alle 21:00, così i gesti della serata (18:45–20:40) arrivano dopo l'ora in cui sono segnati, come in una sala senza rete.
     */
    public static function apriLaSeconda(TestCase $t, Beta51Scenario $s, Assemblea $a, array $piu = []): SedutaDellAssemblea
    {
        ConvocazioneDiViaRoma12::oggi('2026-12-15 18:40');
        self::applica($t, $s, $a, self::gesto('deserta', ['seduta_id' => self::seduta($a, 'prima')->id], '2026-12-15 18:40'));
        self::applica($t, $s, $a, self::gesto('apri', $piu + ['seduta_id' => self::seduta($a)->id,
            'presidente' => ['anagrafica_id' => $s->persone['rossi']->id], 'segretario' => ['nome' => 'L\'amministratore']], '2026-12-15 18:42'));
        ConvocazioneDiViaRoma12::oggi('2026-12-15 21:00');

        return self::seduta($a)->refresh();
    }

    /** Fa entrare le persone date in sala, all'ora data. @param list<string> $chi */
    public static function entrano(TestCase $t, Beta51Scenario $s, Assemblea $a, array $chi, string $alle = '2026-12-15 18:45', string $come = 'in_sala'): void
    {
        $seduta = self::seduta($a);
        foreach ($chi as $x) {
            self::applica($t, $s, $a, self::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone[$x]->id], 'come' => $come],
                $alle));
        }
    }

    /** L'amministratore della prova può modificare il condominio («Modifica condomini»): la scelta delle teste è sua. */
    public static function puoModificareIlCondominio(Beta51Scenario $s): void
    {
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => \App\Enums\Permission::EDIT_CONDOMINI->value, 'guard_name' => 'web']);
        $s->admin->givePermissionTo(\App\Enums\Permission::EDIT_CONDOMINI->value);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /** I venti condòmini in più, per nome: «c30»…; i primi `$quanti`. @return list<string> */
    public static function altri(int $quanti = self::ALTRI): array
    {
        return array_map(fn ($i) => 'c' . $i, range(30, 30 + $quanti - 1));
    }
}
