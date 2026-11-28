<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I testi che promettevano un incrocio, un sollecito o una tracciabilità che il programma non aveva, e che con l'elenco «Rate scadute» dicono
 * quello che fa: l'avviso del deficit di un debito ereditato chiede un piano di ripianamento per la parte che nessuna rata chiede (non le rate scadute, che
 * non la portano, né le fatture) e non dice «morosità»; la
 * chiave della guida dei pagamenti parziali che nessuno usava non c'è più, in nessuna lingua; la pagina delle impostazioni della posta non parla
 * di comunicazioni «ufficiali».
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

it('l\'avviso del deficit di un debito ereditato chiede il piano di ripianamento per la parte che nessuna rata chiede, con parole che non giudicano nessuno', function () {
    [$condominio, $esercizio, $gestione, $fornitore] = setupContabile();
    \App\Models\CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'prova']);
    $user = \App\Models\User::factory()->create();
    $saldoFornitoreId = DB::table('saldi')->insertGetId([
        'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $gestione->id, 'fornitore_id' => $fornitore->id,
        'saldo_iniziale' => -12200, 'is_applicato' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $dati = datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'data_documento' => '2025-12-01', 'data_scadenza' => '2025-12-31', 'is_pregresso' => true,
        'imponibile_pregresso' => 100.00, 'aliquota_iva_pregressa' => 22, 'saldo_patrimoniale_id' => $saldoFornitoreId, 'righe' => [],
        'coperture' => [['tipo_copertura' => 'rata_0', 'importo' => 122.00, 'fonte_id' => $saldoFornitoreId]],
    ]);
    $fattura = (new \App\Services\Gestionale\FatturaPassivaService())->registraFattura($dati, $condominio->id);
    (new \App\Listeners\Gestionale\SyncScadenziarioWithFattura())->handle(new \App\Events\Gestionale\FatturaRegistrata($fattura, $user->id));

    $evento = \App\Models\Evento::where('meta->type', 'pianifica_ripianamento_deficit')->sole();
    expect($evento->meta['titolo_azione'])->toBe('Prepara il piano di ripianamento')
        ->and($evento->meta['action_url'])->toBe(route('admin.gestionale.esercizi.piani-rate.create', ['condominio' => $condominio->id, 'esercizio' => $esercizio->id,
            'tipo' => 'straordinario'], false))
        ->and(mb_strtolower($evento->description))->not->toContain('moros')
        // La cifra dello scoperto non sta nella descrizione, che resterebbe quella della nascita: la dice la riga sotto il titolo, e il compito la tiene in `importo_buco`.
        ->and($evento->description)->toContain('ha una parte scoperta: è quella che i pregressi chiesti ai condòmini non coprono, e nessuna rata la chiede.')
        ->and($evento->description)->not->toContain('€ 122,00')
        ->and((int) $evento->meta['importo_buco'])->toBe(12200)
        ->and($evento->description)->toContain('Si ripiana con un piano rate che chieda ai condòmini la cifra che manca: «Prepara il piano di ripianamento».')
        ->and($evento->description)->not->toContain('rate scadute');
});

it('la chiave della guida dei pagamenti parziali che nessuno usava non c\'è più in nessuna lingua, e la posta non promette comunicazioni «ufficiali»', function () {
    foreach (['it', 'en', 'es', 'pt'] as $lingua) {
        expect(\Illuminate\Support\Arr::has(require lang_path("{$lingua}/gestionale.php"), 'incassi.guide.partial_payments_description'))->toBeFalse()
            ->and((string) file_get_contents(lang_path("{$lingua}/gestionale.php")))->not->toContain('partial_payments_description');
    }
    expect(__('impostazioni.header.mail_settings_description', [], 'it'))->toBe('Le caselle da cui il programma manda la posta dello studio. Ognuna serve a una cosa: le email ai condòmini partono dalla posta, le convocazioni per PEC dalla casella PEC.');
    foreach (['en', 'es', 'pt'] as $lingua) {
        expect(mb_strtolower(__('impostazioni.header.mail_settings_description', [], $lingua)))->not->toMatch('/official|oficia/');
    }
});
