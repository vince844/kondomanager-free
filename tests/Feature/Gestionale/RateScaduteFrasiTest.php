<?php

/**
 * Le frasi della pagina «Rate scadute» e della sua stampa che vengono dal server: come il programma conta «più sei mesi» (una sua scelta, la
 * data più presto), accanto alla regola del festivo; a chi si mostra l'elenco, non più stretto della legge. Lo scenario delle lettere.
 */

use App\Models\User;
use App\Services\Gestionale\Debiti\SeiMesi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LettereScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

it('la pagina e la stampa dicono come il programma conta «più sei mesi», e a chi si mostra l\'elenco', function () {
    $s = lttScenario($this);
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id]))->assertOk()->viewData('page')['props'];
    expect($props['frase_sei_mesi'])->toBe(SeiMesi::FRASE_PIU_SEI_MESI)
        ->and(SeiMesi::FRASE_PIU_SEI_MESI)->toBe('Più sei mesi: lo stesso giorno del sesto mese dopo, o l\'ultimo giorno di quel mese se quel giorno manca (31/08 → 28/02, '
            . 'o 29/02 negli anni bisestili). È una scelta prudente del programma: fra i modi di aggiungere sei mesi a una data, quello che dà la data più presto.');

    $dati = null;
    View::composer('pdf.gestionale.rate_scadute', function ($vista) use (&$dati) {
        $dati = $vista->getData();
    });
    $this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.stampa', ['condominio' => $s['c']->id, 'tutte' => 1]))->assertOk();
    $html = html_entity_decode(preg_replace('/\s+/', ' ', View::make('pdf.gestionale.rate_scadute', $dati)->render()), ENT_QUOTES);
    expect($html)->toContain(SeiMesi::FRASE_PIU_SEI_MESI)
        ->and($html)->toContain('Contiene dati personali: si mostra all\'assemblea e a chi ha diritto di conoscerlo, come il condòmino o il creditore non ancora pagato che '
            . 'lo chiedono. Non va esposto in bacheca né in altri spazi che vedono anche gli estranei.')
        ->and($html)->not->toContain('Elenco per l\'amministratore e per l\'assemblea');
});
