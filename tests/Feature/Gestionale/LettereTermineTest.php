<?php

/**
 * Il termine per il versamento, il testo di partenza di diffida e messa in mora, le rate dell'accordo dopo «fino al».
 *
 * - **Il termine** ha tre forme: «N giorni dal ricevimento», «entro il [data]», «nessun termine». Per diffida e messa in mora nessuna è già scelta;
 *   per il sollecito si propone quella dell'ultimo sollecito. La riga a parte «Termine per il versamento» c'è solo se il testo non usa `[termine]`.
 *   Il promemoria non ha un termine. Il termine si ricorda per tipo di lettera.
 * - **Diffida e messa in mora** partono da un testo di partenza del programma, come promemoria e sollecito; con il testo vuoto il server rifiuta.
 * - **Dopo «fino al»** le rate dell'accordo tornano nelle lettere proposte, e l'anteprima lo dice per la persona.
 *
 * Lo scenario delle lettere: Ugo e Bice, oggi 20/03/2026.
 */

use App\Models\User;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use App\Services\Gestionale\Lettere\TestiLettere;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LettereScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    config(['mail.default' => 'array']);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create(['email' => 'studio@prova.it']);
    $this->user->assignRole($ruolo);
});

/** La lettera di Ugo come la vede il PDF dell'anteprima: il testo e la riga del termine (null se non c'è). */
function ltrLettera($test, array $s, array $altro): array
{
    $dati = null;
    View::composer('pdf.gestionale.lettera', function ($vista) use (&$dati) {
        $dati = $vista->getData();
    });
    $test->actingAs($test->user)->post(lttRotta($s, 'pdf-anteprima'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id]] + $altro))->assertOk();
    $html = html_entity_decode(preg_replace('/\s+/', ' ', View::make('pdf.gestionale.lettera', $dati)->render()), ENT_QUOTES);
    preg_match('/Termine per il versamento: <strong>(.*?)<\/strong>/', $html, $m);

    return ['testo' => $dati['lettere'][0]['testo'], 'riga' => $m[1] ?? null];
}

function ltrRifiuto($test, array $s, array $altro): string
{
    $r = $test->actingAs($test->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id]] + $altro));
    $r->assertStatus(422);

    return (string) $r->getContent();
}

it('il termine in giorni: nel testo con [termine], e la riga a parte solo se il testo non lo usa', function () {
    $s = lttScenario($this);
    $con = ltrLettera($this, $s, ['termine_forma' => 'giorni', 'termine' => 15]);
    expect($con['testo'])->toContain('Le chiediamo di versare entro 15 giorni dal ricevimento di questa lettera.')
        ->and($con['riga'])->toBeNull();
    $senza = ltrLettera($this, $s, ['termine_forma' => 'giorni', 'termine' => 15, 'testo' => 'Gentile [nome], risultano da versare [totale].']);
    expect($senza['riga'])->toBe('15 giorni dal ricevimento di questa lettera');
});

it('il termine con una data: «entro il …» nel testo, o nella riga a parte; una data non dopo oggi si rifiuta', function () {
    $s = lttScenario($this);
    expect(ltrLettera($this, $s, ['termine_forma' => 'data', 'termine_data' => '2026-04-30', 'termine' => null])['testo'])
        ->toContain('Le chiediamo di versare entro il 30/04/2026.')
        ->and(ltrLettera($this, $s, ['termine_forma' => 'data', 'termine_data' => '2026-04-30', 'termine' => null, 'testo' => 'Gentile [nome].'])['riga'])
        ->toBe('entro il 30/04/2026');
    expect(ltrRifiuto($this, $s, ['termine_forma' => 'data', 'termine_data' => '2026-03-20', 'termine' => null]))->toBe('Lettere non stampate. ' . EmissioneLettere::DATA_DEL_TERMINE);
});

it('nessun termine: niente riga a parte; un testo che usa [termine] si rifiuta con la strada', function () {
    $s = lttScenario($this);
    $l = ltrLettera($this, $s, ['termine_forma' => 'nessuno', 'termine' => null, 'testo' => 'Gentile [nome], risultano da versare [totale].']);
    expect($l['riga'])->toBeNull()->and($l['testo'])->toBe('Gentile Ugo, risultano da versare € 180,00.');
    expect(ltrRifiuto($this, $s, ['termine_forma' => 'nessuno', 'termine' => null]))->toBe('Lettere non stampate. ' . EmissioneLettere::TERMINE_NEL_TESTO);
});

it('per diffida e messa in mora nessuna forma del termine è già scelta: senza la scelta il server rifiuta', function () {
    $s = lttScenario($this);
    foreach (['diffida', 'messa_in_mora'] as $tipo) {
        expect(ltrRifiuto($this, $s, ['tipo' => $tipo, 'termine' => null, 'testo' => TestiLettere::DI_SERIE[$tipo]]))->toBe('Lettere non stampate. ' . EmissioneLettere::SCEGLI_IL_TERMINE);
    }
    expect(lttRighe($s, 'ugo'))->toHaveCount(0);
});

it('il promemoria non ha un termine: un termine mandato non entra nella lettera, e un testo con [termine] si rifiuta', function () {
    $s = lttScenario($this);
    $this->travelTo('2026-03-25 10:00:00');
    $l = ltrLettera($this, $s, ['tipo' => 'promemoria', 'termine_forma' => 'giorni', 'termine' => 15, 'testo' => TestiLettere::DI_SERIE['promemoria'], 'giorni' => 30]);
    expect($l['riga'])->toBeNull();
    expect(ltrRifiuto($this, $s, ['tipo' => 'promemoria', 'termine' => null, 'giorni' => 30, 'testo' => 'Gentile [nome], versi entro [termine].']))
        ->toBe('Lettere non stampate. ' . EmissioneLettere::PROMEMORIA_SENZA_TERMINE);
});

it('il termine si ricorda solo per il sollecito: per diffida e messa in mora la forma si sceglie ogni volta, e il promemoria non ne ha', function () {
    $s = lttScenario($this);
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id], 'termine_forma' => 'giorni', 'termine' => 15]))->assertOk();
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['bice']->id], 'tipo' => 'diffida', 'termine_forma' => 'giorni', 'termine' => 10,
        'testo' => TestiLettere::DI_SERIE['diffida']]))->assertOk();
    $o = $this->actingAs($this->user)->getJson(lttRotta($s, 'opzioni'))->assertOk()->json();
    expect($o['scelta_ricordata']['termini'])->toBe(['sollecito' => ['forma' => 'giorni', 'giorni' => 15]])
        ->and($o['scelta_ricordata'])->not->toHaveKey('termine');

    // Un sollecito «entro il 30/04»: si ricorda la forma, non la data.
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['bice']->id], 'termine_forma' => 'data',
        'termine_data' => '2026-04-30', 'termine' => null]))->assertOk();
    expect($this->actingAs($this->user)->getJson(lttRotta($s, 'opzioni'))->json('scelta_ricordata.termini'))->toBe(['sollecito' => ['forma' => 'data', 'giorni' => null]]);
});

it('diffida e messa in mora partono da un testo del programma, neutro; il testo vuoto si rifiuta con la strada', function () {
    $s = lttScenario($this);
    $o = $this->actingAs($this->user)->getJson(lttRotta($s, 'opzioni'))->assertOk()->json();
    expect($o['testi']['diffida'])->toBe(TestiLettere::DI_SERIE['diffida'])->not->toBe('')
        ->and($o['testi']['messa_in_mora'])->toBe(TestiLettere::DI_SERIE['messa_in_mora'])->not->toBe('');
    foreach (['diffida', 'messa_in_mora'] as $tipo) {
        expect(ltrRifiuto($this, $s, ['tipo' => $tipo, 'termine_forma' => 'giorni', 'termine' => 15, 'testo' => '  ']))->toBe('Lettere non stampate. ' . EmissioneLettere::SERVE_IL_TESTO);
    }

    // Un modello della diffida dello studio non cambia il testo del programma: è un'altra voce del selettore.
    \App\Models\ModelloTesto::create(['ambito' => 'lettera', 'tipo' => 'diffida', 'nome' => 'Diffida dello studio', 'testo' => 'Il testo dello studio.']);
    $o = $this->actingAs($this->user)->getJson(lttRotta($s, 'opzioni'))->json();
    expect($o['testi']['diffida'])->toBe(TestiLettere::DI_SERIE['diffida'])
        ->and(collect($o['modelli'])->pluck('nome')->all())->toBe(['Diffida dello studio']);
});

it('dopo «fino al» le rate dell\'accordo tornano nella lettera, e l\'anteprima lo dice per la persona', function () {
    $s = lttScenario($this);
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.store', ['condominio' => $s['c']->id]), [
        'anagrafica_id' => $s['persone']['ugo']->id, 'data_accordo' => '2026-03-20', 'fino_al' => '2026-03-31', 'autorizzato_assemblea' => '0',
        'rate' => [['rata_id' => lcdRata($s, 1), 'immobile_id' => $s['immobili']['A']->id, 'importo' => '60,00']],
    ])->assertCreated();
    $ugo = fn () => collect($this->actingAs($this->user)->postJson(lttRotta($s, 'anteprima'), lttScelte($s))->assertOk()->json('persone'))->firstWhere('nome', 'Ugo');
    // Fino al 31/03 la rata 1 è fuori dalla lettera.
    expect($ugo())->toMatchArray(['totale' => 12000, 'in_accordo' => 6000, 'accordo_passato' => null]);
    // Il 01/04 (alle 00:30 di Roma) «fino al» è passato: la rata torna nella lettera, e l'anteprima lo dice.
    $this->travelTo(Carbon::parse('2026-04-01 00:30', 'Europe/Rome'));
    expect($ugo())->toMatchArray(['in_accordo' => 0, 'accordo_passato' => ['fino_al' => '2026-03-31', 'importo' => 6000]]);
});

it('i testi di partenza: la richiesta di pagare entro il termine detta chiara; per diffida e messa in mora il recupero del credito e niente altro', function () {
    expect(TestiLettere::DI_SERIE['diffida'])->toBe("Gentile [nome],\ndalla contabilità del condominio [condominio] risultano ancora da versare le rate indicate qui sotto, "
        . "per un totale di [totale].\nLe chiediamo con fermezza di versare il totale entro [termine].\nSe il versamento non arriva entro quel termine, "
        . "il condominio potrà procedere al recupero del credito.\nSe ha già versato, ci mandi la ricevuta del bonifico.")
        ->and(TestiLettere::DI_SERIE['messa_in_mora'])->toBe("Gentile [nome],\ncon questa lettera il condominio [condominio] le chiede formalmente il pagamento delle "
            . "rate indicate qui sotto, per un totale di [totale], entro [termine].\nSe il pagamento non arriva entro quel termine, il condominio potrà procedere al recupero del "
            . "credito.\nSe ha già versato, ci mandi la ricevuta del bonifico.");
    foreach (TestiLettere::DI_SERIE as $tipo => $testo) {
        $minuscolo = mb_strtolower($testo);
        // Niente articoli di legge, interessi, tassi, spese, distacco dei servizi; solo i dati della persona che riceve.
        expect(preg_match('/\bart\.|\bartico|interess|tasso|spes[ae]|distacc|sospen|penal|giudic|decreto|avvocat/u', $minuscolo))->toBe(0, $tipo)
            ->and($testo)->toStartWith('Gentile [nome],')->toContain('[condominio]')->toContain('[totale]');
        if ($tipo !== 'promemoria') {
            expect($testo)->toContain('entro [termine]');
        }
        if (in_array($tipo, ['diffida', 'messa_in_mora'], true)) {
            expect($testo)->toContain('il condominio potrà procedere al recupero del credito.');
        }
    }
});
