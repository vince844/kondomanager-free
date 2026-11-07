<?php

/**
 * I testi che promettevano un incrocio, un sollecito o una tracciabilità che il programma non aveva, e che con l'elenco «Rate scadute» dicono
 * quello che fa: l'avviso del deficit di un debito ereditato porta alle rate scadute del condominio (non alle fatture) e non dice «morosità»; la
 * chiave della guida dei pagamenti parziali che nessuno usava non c'è più, in nessuna lingua; la pagina delle impostazioni della posta non parla
 * di comunicazioni «ufficiali».
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

it('l\'avviso del deficit di un debito ereditato porta alle rate scadute del condominio, con parole che non giudicano nessuno', function () {
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
    expect($evento->meta['titolo_azione'])->toBe('Vedi le rate scadute')
        ->and($evento->meta['action_url'])->toBe(route('admin.gestionale.rate-scadute.index', ['condominio' => $condominio->id]))
        ->and(mb_strtolower($evento->description))->not->toContain('moros')
        ->and($evento->description)->toContain('Chi deve ancora pagare le rate è nell\'elenco delle rate scadute del condominio.');
});

it('la chiave della guida dei pagamenti parziali che nessuno usava non c\'è più in nessuna lingua, e la posta non promette comunicazioni «ufficiali»', function () {
    foreach (['it', 'en', 'es', 'pt'] as $lingua) {
        expect(\Illuminate\Support\Arr::has(require lang_path("{$lingua}/gestionale.php"), 'incassi.guide.partial_payments_description'))->toBeFalse()
            ->and((string) file_get_contents(lang_path("{$lingua}/gestionale.php")))->not->toContain('partial_payments_description');
    }
    expect(__('impostazioni.header.mail_settings_description', [], 'it'))->toBe('Configura il metodo di invio per rate, solleciti e comunicazioni ai condòmini.');
    foreach (['en', 'es', 'pt'] as $lingua) {
        expect(mb_strtolower(__('impostazioni.header.mail_settings_description', [], $lingua)))->not->toMatch('/official|oficia/');
    }
});
