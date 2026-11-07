<?php

/**
 * Le lettere rifatte: «Invia di nuovo» e «riprova» rifanno la lettera con le cifre di oggi, solo per promemoria e sollecito, e non mandano due
 * volte la stessa lettera (non su una riga in coda, non dopo un'altra lettera dello stesso tipo); una lettera a due indirizzi resta una
 * lettera; la lettera rifatta tiene la gestione di quella di prima; «riprova» rispetta il tetto dei messaggi quando le email partono subito;
 * un promemoria che non serve più dice perché; una stampa che non riesce non lascia file sul disco; l'allegato porta la data della lettera.
 *
 * Nessuna email vera: la coda è finta e il trasporto è `array`.
 */

use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use App\Notifications\Gestionale\LetteraNotification;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use App\Services\Gestionale\Lettere\RegistroLettere;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
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
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create(['email' => 'studio@prova.it']);
    $this->user->assignRole($ruolo);
});

/** Un sollecito per email a Ugo (o a chi si dice), dalla rotta della finestra. */
function ldnInvia($test, array $s, string $chi = 'ugo', array $altro = []): void
{
    $test->actingAs($test->user)->postJson(lttRotta($s, 'invia'), lttScelte($s, ['persone' => [$s['persone'][$chi]->id]] + $altro))->assertOk();
}

it('«Invia di nuovo» non vale per una riga in coda, e non due volte: dopo la lettera rifatta la riga di prima non si rimanda più', function () {
    $s = lttScenario($this);
    ldnInvia($this, $s);
    $riga = lttRighe($s, 'ugo')->sole();

    // In coda: niente.
    $inCoda = $this->actingAs($this->user)->postJson(lttRotta($s, 'invia-di-nuovo', ['invio' => $riga->id]));
    expect($inCoda->status())->toBe(422)->and(lttRighe($s, 'ugo'))->toHaveCount(1);

    // Non partita: il primo clic rifà la lettera, il secondo no.
    $riga->update(['stato' => RegistroInvio::STATO_NON_PARTITA, 'errore' => 'x']);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'invia-di-nuovo', ['invio' => $riga->id]))->assertOk();
    $secondo = $this->actingAs($this->user)->postJson(lttRotta($s, 'invia-di-nuovo', ['invio' => $riga->id]));
    expect($secondo->status())->toBe(422)
        ->and(lttRighe($s, 'ugo'))->toHaveCount(2)
        ->and(Queue::pushed(SendQueuedNotifications::class))->toHaveCount(2);

    // Il registro lo dice: la riga di prima non ha più «Invia di nuovo»; quella nuova, in coda, nemmeno.
    $righe = collect(app(RegistroLettere::class)->perPersona($s['c'], $s['persone']['ugo']))->flatMap(fn ($l) => $l['righe'])->keyBy('id');
    expect($righe[$riga->id]['rimandabile'])->toBeFalse()
        ->and($righe->where('id', '!=', $riga->id)->first()['rimandabile'])->toBeFalse();
});

it('«Invia di nuovo» di una lettera partita: si rimanda, una volta, con le cifre di oggi; la diffida non si rimanda per email', function () {
    $s = lttScenario($this);
    ldnInvia($this, $s);
    $riga = lttRighe($s, 'ugo')->sole();
    $riga->update(['stato' => RegistroInvio::STATO_INVIATA]);
    lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 6000], '2026-03-20');

    $this->actingAs($this->user)->postJson(lttRotta($s, 'invia-di-nuovo', ['invio' => $riga->id]))->assertOk();
    $nuova = lttRighe($s, 'ugo')->last();
    expect($nuova->dati['totale'])->toBe(12000)
        ->and($this->actingAs($this->user)->postJson(lttRotta($s, 'invia-di-nuovo', ['invio' => $riga->id]))->status())->toBe(422);

    $diffida = lttRigaScritta($s, 'ugo', 'diffida', RegistroInvio::STATO_NON_PARTITA, 0, RegistroInvio::CANALE_EMAIL);
    expect($this->actingAs($this->user)->postJson(lttRotta($s, 'invia-di-nuovo', ['invio' => $diffida->id]))->status())->toBe(422);
});

it('una lettera a due indirizzi resta una lettera: la riga partita della stessa lettera non ferma l\'altra, «riprova» ne fa una con tutti e due, e l\'avviso conta le lettere', function () {
    $s = lttScenario($this);
    $s['persone']['ugo']->update(['email_secondaria' => 'ugo.casa@prova.it']);
    ldnInvia($this, $s);
    $righe = lttRighe($s, 'ugo');
    expect($righe)->toHaveCount(2)->and($righe->pluck('dati.lettera')->unique())->toHaveCount(1);

    // La prima non partita, la seconda partita (e viceversa): da rifare c'è l'indirizzo non partito, in tutti e due i casi.
    $righe[0]->update(['stato' => 'non_partita', 'errore' => 'x']);
    $righe[1]->update(['stato' => 'inviata']);
    expect(app(EmissioneLettere::class)->daRiprovare($s['c'])['da_rifare']->pluck('destinatario')->all())->toBe([$righe[0]->destinatario]);
    $righe[0]->update(['stato' => 'inviata', 'errore' => null]);
    $righe[1]->update(['stato' => 'non_partita', 'errore' => 'x']);
    expect(app(EmissioneLettere::class)->daRiprovare($s['c'])['da_rifare']->pluck('destinatario')->all())->toBe([$righe[1]->destinatario]);

    // Tutti e due non partiti: l'avviso dice una lettera; «riprova» ne fa una, con due righe e un PDF conservato.
    RegistroInvio::query()->update(['stato' => 'non_partita', 'errore' => 'x']);
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id]))->viewData('page')['props'];
    expect($pagina['lettere_da_riprovare'])->toBe(1);
    $documenti = DB::table('documenti')->count();
    $this->actingAs($this->user)->postJson(lttRotta($s, 'riprova'), [])->assertOk();
    $nuove = lttRighe($s, 'ugo')->where('stato', 'in_coda');
    expect($nuove)->toHaveCount(2)
        ->and($nuove->pluck('dati.lettera')->unique())->toHaveCount(1)
        ->and(DB::table('documenti')->count())->toBe($documenti + 1)
        ->and(app(RegistroLettere::class)->perPersona($s['c'], $s['persone']['ugo']))->toHaveCount(2);

    // In coda da più di un'ora: una lettera, non due righe.
    $this->travelTo('2026-03-20 12:00:00');
    expect($this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id]))->viewData('page')['props']['lettere_in_coda_da_tempo'])->toBe(1);
});

it('la lettera rifatta tiene la gestione di quella di prima: «riprova» non aggiunge le rate delle altre gestioni', function () {
    $s = lttScenario($this);
    lttSecondaGestione($this, $s);
    ldnInvia($this, $s, 'ugo', ['gestione_id' => $s['g']->id]);
    expect(lttRighe($s, 'ugo')->sole()->dati['totale'])->toBe(18000);
    lttRighe($s, 'ugo')->sole()->update(['stato' => 'non_partita', 'errore' => 'x']);

    $this->actingAs($this->user)->postJson(lttRotta($s, 'riprova'), [])->assertOk();

    $nuova = lttRighe($s, 'ugo')->last();
    expect($nuova->stato)->toBe('in_coda')
        ->and($nuova->dati['totale'])->toBe(18000)
        ->and((int) $nuova->dati['gestione_id'])->toBe((int) $s['g']->id);
});

it('«riprova» quando le email partono subito: al massimo cinquanta messaggi per volta, a persone intere, e dice quante restano', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $unita = [];
    for ($i = 1; $i <= 55; $i++) {
        $unita['U' . $i] = ['p' . $i, 1];
    }
    $s = lcdScenario($unita, 12, 'prima_rata', [], 660000);
    lcdEmetti($this, $s, [1], '2026-01-02');
    $this->travelTo('2026-03-20 10:00:00');
    $s['cassa']->contoCorrente()->create(['iban' => 'IT60X0542811101000000123456', 'intestatario' => 'Condominio']);
    $scelte = ['tipo' => 'sollecito', 'tutte' => 1, 'filtri' => [], 'testo' => 'Gentile [nome]', 'termine' => 15, 'cassa_id' => $s['cassa']->id, 'indirizzi' => ['email']];
    config(['queue.default' => 'database']);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), $scelte)->assertOk();
    RegistroInvio::query()->update(['stato' => 'non_partita', 'errore' => 'x']);
    config(['queue.default' => 'sync']);
    $prima = Queue::pushed(SendQueuedNotifications::class)->count();

    $r = $this->actingAs($this->user)->postJson(lttRotta($s, 'riprova'), [])->assertOk()->json();

    expect(Queue::pushed(SendQueuedNotifications::class)->count() - $prima)->toBe(50)
        ->and($r['messaggi'])->toBe(50)
        ->and($r['restanti'])->toBe(['lettere' => 5, 'messaggi' => 5]);
    $poi = $this->actingAs($this->user)->postJson(lttRotta($s, 'riprova'), [])->assertOk()->json();
    expect($poi['messaggi'])->toBe(5)->and($poi['restanti'])->toBeNull();
});

it('un promemoria non partito che non serve più perché la rata è scaduta: «riprova» lo conta a parte, perché per quella persona c\'è il sollecito', function () {
    $s = lttScenario($this);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), lttScelte($s, ['tipo' => 'promemoria', 'persone' => [$s['persone']['bice']->id], 'testo' => 'P']))->assertOk();
    lttRighe($s, 'bice')->sole()->update(['stato' => 'non_partita', 'errore' => 'x']);
    $this->travelTo('2026-04-10 10:00:00');

    $r = $this->actingAs($this->user)->postJson(lttRotta($s, 'riprova'), [])->assertOk()->json();

    expect($r['messaggi'])->toBe(0)
        ->and($r['saldate'])->toBe(1)
        ->and($r['promemoria_scaduti'])->toBe(1);
});

it('una stampa che non riesce dopo aver salvato i PDF conservati non lascia file sul disco', function () {
    $s = lttScenario($this);
    View::composer('pdf.gestionale.lettera', function ($v) {
        if (count($v->getData()['lettere']) > 1) {
            throw new RuntimeException('la stampa unica si rompe');
        }
    });

    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s));

    expect(RegistroInvio::count())->toBe(0)
        ->and(DB::table('documenti')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('l\'allegato dell\'email porta la data della lettera, non quella della partenza', function () {
    $s = lttScenario($this);
    ldnInvia($this, $s);
    $riga = lttRighe($s, 'ugo')->sole();
    $this->travelTo('2026-03-23 10:00:00');

    $messaggio = (new LetteraNotification([$riga->id]))->toMail(new \Illuminate\Notifications\AnonymousNotifiable);

    expect($messaggio->rawAttachments[0]['name'])->toContain('2026-03-20')
        ->and($messaggio->rawAttachments[0]['name'])->not->toContain('2026-03-23');
});
