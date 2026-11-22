<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission as Permesso;
use App\Models\Assemblee\Delibera;
use App\Models\Condominio;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\User;
use App\Services\Assemblee\DeliberaAMano;
use App\Services\Assemblee\PreparazioneDellAssemblea;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

require_once __DIR__ . '/../../Gestionale/Beta51PianoRateScenario.php';

/**
 * Il Condominio Via Roma 12 dei casi del progetto della tappa 2, per i test del motore delle assemblee: lo scenario dei piani rate con il nome giusto,
 * l'amministratore con i permessi delle assemblee, la fattura n. 45 di Idraulica Bianchi in sforo, le delibere registrate a mano, un'assemblea preparata.
 */
final class ViaRoma12
{
    public static function nuovo(TestCase $t, string $nome = 'Via Roma 12'): Beta51Scenario
    {
        $s = Beta51Scenario::nuovo($t);
        $s->c->forceFill(['nome' => $nome])->saveQuietly();
        foreach ([Permesso::VIEW_ASSEMBLEE, Permesso::MANAGE_ASSEMBLEE, Permesso::REGISTER_VERBALE_SOTTOSCRITTO] as $p) {
            Permission::firstOrCreate(['name' => $p->value, 'guard_name' => 'web']);
            $s->admin->givePermissionTo($p->value);
        }
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return $s;
    }

    /** Via Roma 12 con la tabella «Proprietà», la gestione straordinaria «Facciata 2026» con la sua voce e i due titolari. */
    public static function conFacciata(TestCase $t): Beta51Scenario
    {
        return self::nuovo($t)->tabella('Proprietà', ['A' => 600, 'B' => 400])->gestione('Facciata 2026', 'straordinaria')
            ->voce('Facciata 2026', 'Facciata', 1450000, ['Proprietà' => 100])->titolare('A', 'ugo')->titolare('B', 'anna');
    }

    /** Un utente dello studio con i permessi dati. @param list<Permesso> $permessi */
    public static function utente(array $permessi = []): User
    {
        $u = User::factory()->create();
        foreach ($permessi as $p) {
            Permission::firstOrCreate(['name' => $p->value, 'guard_name' => 'web']);
            $u->givePermissionTo($p->value);
        }
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return $u;
    }

    /** La fattura n. 45 di Idraulica Bianchi, € 1.800,00, in sforo motivato, con la riga della colonna dell'acqua. */
    public static function fatturaInSforo(Beta51Scenario $s, string $numero = '45', int $totale = 180000, string $stato = 'sforo_motivato'): FatturaPassiva
    {
        $fornitore = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Idraulica Bianchi', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(),
            'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico',
            'created_at' => now(), 'updated_at' => now()]);
        $fattura = FatturaPassiva::create(['condominio_id' => $s->c->id, 'fornitore_id' => $fornitore, 'esercizio_id' => $s->e->id, 'tipo_documento' => 'fattura',
            'numero_documento' => $numero, 'data_documento' => '2026-11-12', 'data_scadenza' => '2026-12-12', 'is_pregresso' => false,
            'importo_imponibile' => $totale, 'importo_iva' => 0, 'importo_ritenuta' => 0, 'totale_documento' => $totale, 'netto_a_pagare' => $totale,
            'stato_pagamento' => 'aperta', 'stato_approvazione' => $stato, 'modalita_pagamento' => 'bonifico']);
        DB::table('righe_fattura')->insert(['fattura_passiva_id' => $fattura->id, 'conto_id' => null, 'immobile_id' => null,
            'descrizione' => 'Riparazione della colonna dell\'acqua', 'aliquota_iva' => 0, 'importo_imponibile' => $totale, 'importo_iva' => 0,
            'is_sopravvenienza' => false, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()]);

        return $fattura->refresh();
    }

    /** Una delibera registrata a mano. @param array<string, mixed> $piu */
    public static function delibera(Beta51Scenario $s, string $giorno, string $testo, string $esito = 'approvata', ?string $verbale = null, array $piu = [],
        ?array $contesto = null): Delibera
    {
        return DeliberaAMano::registra($s->c, ['giorno' => $giorno, 'testo' => $testo, 'esito' => $esito, 'numero_verbale' => $verbale] + $piu, null,
            $s->admin, $contesto);
    }

    /** L'ordinaria del 15/12/2026 in preparazione: prima convocazione il 14/12 alle 8:00, seconda il 15/12 alle 18:30. @param list<array<string, mixed>> $punti */
    public static function ordinaria(Beta51Scenario $s, array $punti = [], array $piu = []): \App\Models\Assemblee\Assemblea
    {
        return PreparazioneDellAssemblea::crea($s->c, $piu + ['tipo' => 'ordinaria', 'luogo' => 'Sala condominiale, Via Roma 12', 'sedute' => [
            ['convocazione' => 'prima', 'numero' => 1, 'fissata_il' => '2026-12-14 08:00'],
            ['convocazione' => 'seconda', 'numero' => 1, 'fissata_il' => '2026-12-15 18:30'],
        ]], $punti, $s->admin);
    }

    /** Le righe del registro scritte dopo `$prima`. */
    public static function righe(int $prima, ?string $tipo = null)
    {
        return \App\Models\RegistroModifica::query()->where('id', '>', $prima)->when($tipo !== null, fn ($q) => $q->where('oggetto_tipo', $tipo))
            ->orderBy('id')->get();
    }

    public static function ultimaRiga(): int
    {
        return (int) (\App\Models\RegistroModifica::query()->max('id') ?? 0);
    }
}
