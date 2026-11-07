<?php

/**
 * Il perimetro di una lettera: chi c'è e con quali rate. Chi l'amministratore toglie nell'anteprima non è «entrato dopo»; la gestione scelta
 * nell'elenco vale per la lista spuntata come per «tutte le N»; «Vedi il PDF» mostra tutte le lettere, qualunque sia il canale; la PEC
 * spuntata conta per il canale proposto; il tetto del PDF dice come stampare il resto; «riprova» con una persona di un altro condominio è
 * un 404; il testo di partenza non promette le coordinate del bonifico che la lettera non ha.
 *
 * Lo scenario delle lettere: Ugo € 60,00 a rata, Bice € 40,00; al 20/03/2026 Ugo € 180,00, Bice € 80,00 (con € 20,00 di credito).
 */

use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use App\Services\Gestionale\Lettere\TestiLettere;
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
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create(['email' => 'studio@prova.it']);
    $this->user->assignRole($ruolo);
});

/** I nomi delle persone nel PDF che la vista della lettera riceve. */
function lcfNomiNelPdf(?array &$nomi): void
{
    View::composer('pdf.gestionale.lettera', function ($v) use (&$nomi) {
        $nomi = collect($v->getData()['lettere'])->pluck('persona.nome')->all();
    });
}

it('chi l\'amministratore toglie nell\'anteprima non è «entrato nell\'elenco dopo»: né all\'invio né alla stampa; chi entra davvero dopo sì', function () {
    Queue::fake();
    config(['mail.default' => 'array']);
    $s = lttScenario($this);
    $ugo = $s['persone']['ugo']->id;
    $bice = $s['persone']['bice']->id;

    // L'anteprima con tutte e due; Bice tolta: la finestra manda le persone dell'anteprima e, a parte, quelle rimaste.
    $invio = $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), lttScelte($s, ['persone' => null, 'tutte' => 1, 'filtri' => [],
        'in_anteprima' => [$ugo, $bice], 'viste' => [$ugo], 'canali' => [$ugo => 'email', $bice => 'email']]))->assertOk()->json();
    $stampa = $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$ugo, $bice], 'in_anteprima' => [$ugo, $bice], 'viste' => [$ugo],
        'canali' => [$ugo => 'stampa', $bice => 'stampa']]))->assertOk();

    expect($invio['aggiunte'])->toBe(0)
        ->and($stampa->headers->get('X-Persone-Aggiunte'))->toBe('0')
        ->and(lttRighe($s, 'bice'))->toHaveCount(0);

    // Bice non era nell'anteprima (l'anteprima aveva solo Ugo): è entrata dopo, e la risposta lo dice.
    $dopo = $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), lttScelte($s, ['persone' => null, 'tutte' => 1, 'filtri' => [],
        'in_anteprima' => [$ugo], 'viste' => [$ugo], 'canali' => [$ugo => 'email']]))->assertOk()->json();
    expect($dopo['aggiunte'])->toBe(1);
});

it('la gestione scelta nell\'elenco vale per le persone spuntate come per «tutte le N», si scrive nella lettera, e l\'anteprima la nomina', function () {
    $s = lttScenario($this);
    $g2 = lttSecondaGestione($this, $s);
    $filtri = ['gestione_id' => $s['g']->id];

    $tutte = $this->actingAs($this->user)->postJson(lttRotta($s, 'anteprima'), lttScelte($s, ['persone' => null, 'tutte' => 1, 'filtri' => $filtri]))->assertOk()->json();
    $lista = $this->actingAs($this->user)->postJson(lttRotta($s, 'anteprima'), lttScelte($s, ['gestione_id' => $s['g']->id]))->assertOk()->json();
    $tutteLeGestioni = $this->actingAs($this->user)->postJson(lttRotta($s, 'anteprima'), lttScelte($s))->assertOk()->json();

    // Sull'ordinaria Ugo € 180,00 e Bice € 80,00; con il riscaldamento anche € 60,00 e € 40,00.
    expect(collect($tutte['persone'])->pluck('totale', 'nome')->all())->toBe(['Bice' => 8000, 'Ugo' => 18000])
        ->and(collect($lista['persone'])->pluck('totale', 'nome')->all())->toBe(['Bice' => 8000, 'Ugo' => 18000])
        ->and(collect($tutteLeGestioni['persone'])->pluck('totale', 'nome')->all())->toBe(['Bice' => 12000, 'Ugo' => 24000])
        ->and($lista['gestione'])->toBe(['id' => (int) $s['g']->id, 'nome' => 'Ordinaria 2026'])
        ->and($tutteLeGestioni['gestione'])->toBeNull();

    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id], 'gestione_id' => $s['g']->id]))->assertOk();
    expect((int) lttRighe($s, 'ugo')->sole()->dati['gestione_id'])->toBe((int) $s['g']->id)
        ->and(lttRighe($s, 'ugo')->sole()->dati['totale'])->toBe(18000);

    // Una gestione di un altro condominio: 404 per tutta la richiesta.
    $altro = lttScenario($this);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'anteprima'), lttScelte($s, ['gestione_id' => $altro['g']->id]))->assertNotFound();
    expect($g2->id)->not->toBe($s['g']->id);
});

it('«Vedi il PDF» mostra tutte le lettere dell\'anteprima, qualunque sia il canale: anche quelle per email', function () {
    $s = lttScenario($this);
    $ugo = $s['persone']['ugo']->id;
    $bice = $s['persone']['bice']->id;
    $nomi = null;
    lcfNomiNelPdf($nomi);

    $tutteEmail = $this->actingAs($this->user)->post(lttRotta($s, 'pdf-anteprima'), lttScelte($s, ['viste' => [$ugo, $bice], 'canali' => [$ugo => 'email', $bice => 'email']]));
    expect($tutteEmail->status())->toBe(200)
        ->and($tutteEmail->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($nomi)->toBe(['Bice', 'Ugo']);

    $this->actingAs($this->user)->post(lttRotta($s, 'pdf-anteprima'), lttScelte($s, ['viste' => [$ugo, $bice], 'canali' => [$ugo => 'email', $bice => 'stampa']]))->assertOk();
    expect($nomi)->toBe(['Bice', 'Ugo'])
        ->and(RegistroInvio::count())->toBe(0);
});

it('chi ha solo la PEC, con la PEC spuntata, è proposto per l\'email; senza la PEC spuntata resta la stampa, con la frase', function () {
    $s = lttScenario($this);
    $s['persone']['bice']->update(['email' => null, 'pec' => 'bice@pec.it']);

    $conPec = collect($this->actingAs($this->user)->postJson(lttRotta($s, 'anteprima'), lttScelte($s, ['indirizzi' => ['email', 'email_secondaria', 'pec']]))
        ->assertOk()->json('persone'))->firstWhere('nome', 'Bice');
    $senzaPec = collect($this->actingAs($this->user)->postJson(lttRotta($s, 'anteprima'), lttScelte($s))->assertOk()->json('persone'))->firstWhere('nome', 'Bice');

    expect($conPec)->toMatchArray(['canale' => 'email', 'solo_pec' => false, 'indirizzi' => [['tipo' => 'pec', 'indirizzo' => 'bice@pec.it']]])
        ->and($senzaPec)->toMatchArray(['canale' => 'stampa', 'solo_pec' => true]);
});

it('il tetto del PDF conta chi si stampa e dice come stampare il resto', function () {
    $tetto = new ReflectionMethod(EmissioneLettere::class, 'dentroIlTettoDelPdf');
    $persone = array_fill(1, EmissioneLettere::TETTO_PDF + 1, []);

    try {
        $tetto->invoke(app(EmissioneLettere::class), $persone);
        $frase = null;
    } catch (\App\Exceptions\Gestionale\LetteraNonEmettibileException $e) {
        $frase = $e->getMessage();
    }

    expect($frase)->toContain('Le lettere da stampare sono 201: in un PDF ne stanno al massimo 200.')
        ->and($frase)->toEndWith(EmissioneLettere::OLTRE_IL_TETTO)
        // Dopo la stampa una lettera non conta per «Nessuna lettera negli ultimi N giorni» finché non se ne registra la spedizione: la frase
        // non manda a quel filtro, che riproporrebbe le persone appena stampate; dice da dove ripartire dopo la spedizione.
        ->and($frase)->not->toContain('negli ultimi 1 giorni')
        ->and($frase)->toContain('le altre persone ricominciano dalla pagina 1.');
});

it('stampare le lettere delle persone di una pagina non cambia l\'ordine dell\'elenco: la pagina dopo ha le altre persone', function () {
    $s = lttScenario($this);
    $ordine = fn () => collect($this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id]))
        ->viewData('page')['props']['righe']['data'])->pluck('nome')->all();
    expect($ordine())->toBe(['Ugo', 'Bice']);
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id]]))->assertOk();
    expect($ordine())->toBe(['Ugo', 'Bice']);
});

it('«riprova» con una persona di un altro condominio è un 404 per tutta la richiesta', function () {
    $s = lttScenario($this);
    $t = lttScenario($this);

    $this->actingAs($this->user)->postJson(lttRotta($s, 'riprova'), ['anagrafica_id' => $t['persone']['ugo']->id])->assertNotFound();
    $this->actingAs($this->user)->postJson(lttRotta($s, 'riprova'), ['anagrafica_id' => $s['persone']['ugo']->id])->assertOk();
});

it('il testo di partenza non promette le coordinate del bonifico: le porta la lettera, nel riquadro, solo quando c\'è un conto con l\'IBAN', function () {
    $s = lttScenario($this);
    expect(TestiLettere::DI_SERIE['sollecito'])->not->toContain('coordinate')
        ->and(TestiLettere::DI_SERIE['promemoria'])->not->toContain('coordinate');

    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id], 'testo' => TestiLettere::DI_SERIE['sollecito']]))->assertOk();
    $e = app(EmissioneLettere::class);
    $conIban = View::make('pdf.gestionale.lettera', $e->datiDellaVista($s['c'], [$e->voceDallaRiga(lttRighe($s, 'ugo')->sole())]))->render();

    $s['cassa']->contoCorrente()->delete();
    $scelte = lttScelte($s, ['persone' => [$s['persone']['bice']->id], 'testo' => TestiLettere::DI_SERIE['sollecito']]);
    unset($scelte['cassa_id']);
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), $scelte)->assertOk();
    $senzaIban = View::make('pdf.gestionale.lettera', $e->datiDellaVista($s['c'], [$e->voceDallaRiga(lttRighe($s, 'bice')->sole())]))->render();

    expect($conIban)->toContain('Per il bonifico')
        ->and($senzaIban)->not->toContain('Per il bonifico')
        ->and($senzaIban)->not->toContain('coordinate');
});
