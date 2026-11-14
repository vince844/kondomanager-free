<?php

/**
 * Le rotte della scheda «Conguaglio» e dell'invio dei fogli, tutte e undici (la scheda, le due stampe, l'archivio, il foglio; le opzioni, lo stato,
 * il PDF partito, l'anteprima, l'invio, il rinvio): chi non entra nel pannello non apre niente e non scrive niente (un condòmino, un utente con ogni
 * permesso tranne il pannello, un ospite); l'esercizio di un altro condominio fa 404 su tutte e non scrive niente, né qui né là; la stessa persona
 * proprietaria in due condomini ha il foglio, l'email e il PDF partito di ognuno con le cifre del suo condominio, e la scheda di uno non porta
 * niente dell'altro.
 *
 * Le cifre: il condominio A è Via Verdi (Neri, U1 500 millesimi su 1.000; Pulizie € 3.300,00 e Manutenzioni € 900,00: A = € 2.100,00); il condominio
 * B è un secondo Via Verdi con Pulizie € 4.500,00 e Manutenzioni € 1.500,00 (U1 500 su 1.000: A = € 3.000,00).
 *
 * Nessuna email vera: il trasporto è `array`, o la coda è finta.
 */

use App\Enums\Permission as PermessoEnum;
use App\Models\Documento;
use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use App\Services\Gestionale\InvioDeiFogli;
use App\Services\PDF\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config(['mail.default' => 'array']);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    foreach (['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create(['email' => 'studio@prova.it']);
    $this->user->assignRole($ruolo);
    $this->withoutVite();
});

/** Via Verdi con le rate, il passaggio di U2 e le spese (le cifre date, o quelle del caso). */
function crpViaVerdi($test, int $pulizie = 330000, int $manutenzioni = 90000): array
{
    $s = cpcViaVerdi($test);
    cpcRateEIncassi($test, $s);
    cpcPassaggio($test, $s);
    cpcSpese($s, $pulizie, $manutenzioni);

    return $s;
}

function crpScelte(array $altro = []): array
{
    return array_merge(['chi' => 'tutte', 'indirizzi' => ['email'], 'oggetto' => InvioDeiFogli::OGGETTO_DI_SERIE, 'testo' => InvioDeiFogli::TESTO_DI_SERIE], $altro);
}

/**
 * Le undici rotte: [metodo, nome, parametri in più].
 *
 * @return list<array{0: string, 1: string, 2: array<string, int>}>
 */
function crpRotte(int $persona, int $invio): array
{
    $p = 'admin.gestionale.esercizi.rendiconto.conguaglio';

    return [
        ['get', $p, []], ['get', $p . '.stampa', []], ['get', $p . '.per-tabella.stampa', []], ['get', $p . '.fogli', []],
        ['get', $p . '.foglio', ['persona' => $persona]], ['get', $p . '.invio.opzioni', []], ['get', $p . '.invio.stato', []],
        ['get', $p . '.invio.pdf', ['invio' => $invio]], ['post', $p . '.invio.anteprima', []], ['post', $p . '.invio', []], ['post', $p . '.invio.riprova', []],
    ];
}

/** Quello che una richiesta potrebbe scrivere: le righe del registro, i documenti, i lavori in coda. */
function crpConta(): array
{
    return ['righe' => RegistroInvio::count(), 'documenti' => Documento::count(), 'lavori' => Queue::pushed(\Illuminate\Notifications\SendQueuedNotifications::class)->count()];
}

it('chi non entra nel pannello non apre e non scrive niente, su tutte e undici le rotte: un condòmino e chi ha ogni permesso tranne il pannello 403, un ospite 401', function () {
    Queue::fake();
    $s = crpViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    $url = fn (array $r) => route($r[1], ['condominio' => $s['c']->id, 'esercizio' => $s['e']->id] + $r[2]);
    $this->actingAs($this->user)->postJson(route('admin.gestionale.esercizi.rendiconto.conguaglio.invio', [$s['c'], $s['e']]), crpScelte())->assertOk();
    $riga = RegistroInvio::where('tipo', InvioDeiFogli::TIPO)->firstOrFail();
    $prima = crpConta();

    $condomino = User::factory()->create();
    $largo = User::factory()->create();
    $ruoloLargo = Role::firstOrCreate(['name' => 'tutto tranne il pannello', 'guard_name' => 'web']);
    foreach (PermessoEnum::cases() as $permesso) {
        if ($permesso !== PermessoEnum::ACCESS_ADMIN_PANEL) {
            $ruoloLargo->givePermissionTo(Permission::firstOrCreate(['name' => $permesso->value, 'guard_name' => 'web']));
        }
    }
    $largo->assignRole($ruoloLargo);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $rotte = crpRotte((int) $s['persone']['Neri']->id, (int) $riga->id);
    expect($rotte)->toHaveCount(11);
    foreach ($rotte as $r) {
        foreach (['un condòmino' => $condomino, 'chi ha ogni permesso tranne il pannello' => $largo] as $chi => $u) {
            $risposta = $r[0] === 'get' ? $this->actingAs($u)->getJson($url($r)) : $this->actingAs($u)->postJson($url($r), crpScelte(['anagrafica_id' => $s['persone']['Neri']->id]));
            expect($risposta->status())->toBe(403, $r[1] . ', ' . $chi);
        }
    }
    $this->app['auth']->forgetGuards();
    foreach ($rotte as $r) {
        $risposta = $r[0] === 'get' ? $this->getJson($url($r)) : $this->postJson($url($r), crpScelte());
        expect($risposta->status())->toBe(401, $r[1] . ', un ospite');
    }
    expect(crpConta())->toBe($prima);
});

it('l\'esercizio di un altro condominio fa 404 su tutte e undici le rotte, e non scrive niente né qui né là', function () {
    Queue::fake();
    $s = crpViaVerdi($this);
    $altro = crpViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.esercizi.rendiconto.conguaglio.invio', [$s['c'], $s['e']]), crpScelte())->assertOk();
    $riga = RegistroInvio::where('tipo', InvioDeiFogli::TIPO)->where('condominio_id', $s['c']->id)->firstOrFail();
    $prima = crpConta();

    foreach (crpRotte((int) $s['persone']['Neri']->id, (int) $riga->id) as $r) {
        $url = route($r[1], ['condominio' => $s['c']->id, 'esercizio' => $altro['e']->id] + $r[2]);
        $risposta = $r[0] === 'get' ? $this->actingAs($this->user)->getJson($url)
            : $this->actingAs($this->user)->postJson($url, crpScelte(['anagrafica_id' => $s['persone']['Neri']->id]));
        expect($risposta->status())->toBe(404, $r[1]);
    }
    expect(crpConta())->toBe($prima)
        ->and(RegistroInvio::where('condominio_id', $altro['c']->id)->count())->toBe(0);
});

it('la stessa persona proprietaria in due condomini: il foglio, l\'email e il PDF partito di ognuno hanno le cifre del suo condominio, e la scheda di uno non porta niente dell\'altro', function () {
    $a = crpViaVerdi($this);
    $b = cpcViaVerdi($this);
    $p = $a['persone']['Neri'];
    // Neri di A è anche il proprietario di U1 in B, al posto di Neri di B (che resta legato a B, senza unità).
    DB::table('anagrafica_immobile')->where('id', $b['righe']['Neri'])->update(['anagrafica_id' => $p->id]);
    $p->condomini()->syncWithoutDetaching([$b['c']->id]);
    cpcRateEIncassi($this, $b);
    cpcPassaggio($this, $b);
    cpcSpese($b, 450000, 150000);
    $neriB = $b['persone']['Neri'];
    $this->travelTo('2027-01-10 10:00:00');
    $spia = new class extends PdfService
    {
        public array $fogli = [];

        public function generate(string $view, array $data = [], array $config = []): \Mpdf\Mpdf
        {
            if ($view === 'pdf.gestionale.foglio_conguaglio') {
                $this->fogli[] = ['condominio' => $data['condominio']->id, 'foglio' => $data['foglio']];
            }

            return parent::generate($view, $data, $config);
        }
    };
    app()->instance(PdfService::class, $spia);
    $foglio = fn ($c, $e, $persona) => $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio.foglio', [$c, $e, $persona]));

    // Il foglio di A ha A = € 2.100,00 (500 su 1.000 di € 4.200,00), quello di B € 3.000,00 (500 su 1.000 di € 6.000,00).
    $foglio($a['c'], $a['e'], $p->id)->assertOk();
    $foglio($b['c'], $b['e'], $p->id)->assertOk();
    expect(array_map(fn ($f) => [$f['condominio'], $f['foglio']['totale']['a']], $spia->fogli))->toBe([[$a['c']->id, 210000], [$b['c']->id, 300000]]);
    // Il foglio con l'esercizio dell'altro condominio: 404. Neri di B, che non è nella lettura di A: 404 da A.
    $foglio($b['c'], $a['e'], $p->id)->assertNotFound();
    $foglio($a['c'], $a['e'], $neriB->id)->assertNotFound();

    // L'email da A e da B: una riga per condominio con le sue cifre, e un messaggio ciascuna con il PDF della sua riga, a lui solo.
    $this->actingAs($this->user)->postJson(route('admin.gestionale.esercizi.rendiconto.conguaglio.invio', [$a['c'], $a['e']]), crpScelte())->assertOk();
    $this->actingAs($this->user)->postJson(route('admin.gestionale.esercizi.rendiconto.conguaglio.invio', [$b['c'], $b['e']]), crpScelte())->assertOk();
    $rigaA = RegistroInvio::where('condominio_id', $a['c']->id)->where('anagrafica_id', $p->id)->sole();
    $rigaB = RegistroInvio::where('condominio_id', $b['c']->id)->where('anagrafica_id', $p->id)->sole();
    $suoi = collect(app('mailer')->getSymfonyTransport()->messages())->map->getOriginalMessage()
        ->filter(fn ($m) => $m->getTo()[0]->getAddress() === $p->email)->values();
    expect($rigaA->dati['cifre']['a'])->toBe(210000)->and($rigaB->dati['cifre']['a'])->toBe(300000)
        ->and($rigaA->impronta)->not->toBe($rigaB->impronta)
        ->and($suoi)->toHaveCount(2)
        ->and($suoi->map(fn ($m) => hash('sha256', $m->getAttachments()[0]->getBody()))->sort()->values()->all())
        ->toBe(collect([$rigaA->impronta, $rigaB->impronta])->sort()->values()->all());
    foreach ($suoi as $m) {
        expect($m->getTo())->toHaveCount(1)->and($m->getCc())->toBe([])->and($m->getBcc())->toBe([]);
    }

    // Il PDF partito della riga di B dalla scheda di A: 404; dalla sua: lo stesso file.
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio.invio.pdf', [$a['c'], $a['e'], $rigaB->id]))->assertNotFound();
    $pdf = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio.invio.pdf', [$b['c'], $b['e'], $rigaB->id]))->assertOk();
    expect(hash('sha256', $pdf->streamedContent()))->toBe($rigaB->impronta);
    // Lo stato della scheda di A chiesto anche per le persone di B: solo le righe di A.
    $stato = $this->actingAs($this->user)->getJson(route('admin.gestionale.esercizi.rendiconto.conguaglio.invio.stato', [$a['c'], $a['e']]) . '?'
        . http_build_query(['persone' => [$neriB->id, $b['persone']['Verdi']->id, $p->id]]))->assertOk()->json();
    expect(array_keys($stato['ultimi']))->toBe([$p->id])->and($stato['ultimi'][$p->id]['pdf'])->toBe($rigaA->id);
});
