<?php

/**
 * La sezione «Compensazioni senza avviso» della pagina delle ricevute: gli incassi in cui il credito ha coperto le rate di una persona che non
 * riceve la ricevuta di quell'incasso. Due casi: l'incasso fatto solo di credito (che la pagina delle ricevute non ha), e il credito che copre le
 * rate di una persona dentro un incasso con denaro la cui ricevuta va a un'altra. Non ci sono gli incassi con il denaro di più persone senza chi
 * ha versato: non sono compensazioni, e la ricevuta non saprebbe per conto di chi.
 *
 * L'avviso va alla persona la cui rata è stata coperta; il registro ha una riga per avviso e per persona (per email una per indirizzo), con
 * l'incasso come «documentabile». La riga del bollo come la ricevuta, a scelta dell'amministratore, senza dire se è dovuto.
 *
 * Le cifre sono quelle dello scenario della ricevuta: Anna Bruni ha la rata 1 da € 150,00 e la rata 2 da € 120,50; versando € 170,00 sulla rata 1
 * le restano € 20,00 di credito.
 */

use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\Ricevute\AvvisiCompensazione;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/** Le compensazioni della pagina delle ricevute, per il periodo dato. */
function acoSezione($test, object $s, $utente, array $query = ['ricevuta' => 'tutte', 'data_da' => '2026-01-01', 'data_a' => '2026-12-31']): array
{
    return $test->actingAs($utente)->get(route('admin.gestionale.ricevute.index', ['condominio' => $s->condominio->id] + $query))->assertOk()
        ->viewData('page')['props']['compensazioni'];
}

function acoRotta(object $s, string $azione): string
{
    return route('admin.gestionale.ricevute.avvisi-compensazione.' . $azione, ['condominio' => $s->condominio->id]);
}

test('un incasso fatto solo di credito: la persona è fra le compensazioni senza avviso, con la rata coperta e il credito usato', function () {
    $s = scenarioRicevutaIncasso();
    $utente = amministratoreRicevute();
    $this->travelTo('2026-03-02 10:00:00');
    incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 170.00);
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1->fresh(), -20.00], [$s->anna2, 20.00]], '2026-03-01', 0.00);

    $c = acoSezione($this, $s, $utente);
    expect($c['righe'])->toHaveCount(1)
        ->and($c['righe'][0])->toMatchArray(['incasso_id' => $incasso->id, 'data' => '2026-03-01', 'importo' => 2000, 'caso' => 'senza_denaro',
            'persona' => ['id' => $s->anna->id, 'nome' => 'Anna Bruni'], 'credito_di' => ['Anna Bruni'], 'ha_email' => true, 'ultimo' => null])
        ->and($c['righe'][0]['quote'])->toHaveCount(1)
        ->and($c['righe'][0]['quote'][0])->toMatchArray(['rata' => 'Rata 2', 'unita' => 'Int. 4', 'scadenza' => '2026-03-31', 'importo' => 2000])
        ->and($c['senza_avviso'])->toBe(1);
});

test('il credito che copre la rata di una persona dentro l\'incasso con il denaro di un\'altra: la persona coperta è fra le compensazioni, la ricevuta va a chi ha versato', function () {
    $s = scenarioRicevutaIncasso();
    $utente = amministratoreRicevute();
    $this->travelTo('2026-03-02 10:00:00');
    quotaDaPagareDiCarlo($s);
    incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 170.00);
    // Carlo versa € 50,00 «per Anna», il credito si usa adesso: copre la rata 2 di Anna, e i € 50,00 vanno sulla rata 3 di Carlo. La ricevuta è di
    // Carlo; Anna non la riceve, e la sua rata è stata coperta.
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1->fresh(), -20.00], [$s->anna2, 20.00]], '2026-03-01', 50.00,
        ['versato_da_id' => $s->carlo->id, 'credito_prima' => true]);

    $c = acoSezione($this, $s, $utente);
    expect($c['righe'])->toHaveCount(1)
        ->and($c['righe'][0])->toMatchArray(['incasso_id' => $incasso->id, 'importo' => 2000, 'caso' => 'fuori_dalla_ricevuta', 'ricevuta_di' => 'Carlo Bruni',
            'persona' => ['id' => $s->anna->id, 'nome' => 'Anna Bruni']]);

    // La stessa regola della ricevuta: la ricevuta nomina Anna fra le compensazioni che non contiene, con la stessa cifra.
    $ricevuta = app(\App\Services\Gestionale\Ricevute\RicevutaIncasso::class)->per($incasso->fresh());
    expect($ricevuta['compensazioni_escluse'])->toBe([['persona' => 'Anna Bruni', 'importo' => $c['righe'][0]['importo']]]);
});

test('un incasso con il denaro di più persone e senza chi ha versato non è una compensazione da avvisare; un incasso annullato nemmeno', function () {
    $s = scenarioRicevutaIncasso();
    $utente = amministratoreRicevute();
    $this->travelTo('2026-03-02 10:00:00');
    incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 170.00);
    // Il credito di Anna sulla sua rata 2, nell'incasso con il suo denaro: la ricevuta è sua e lo dice («di cui dal credito»). Niente da avvisare.
    $suo = incassaPerRicevuta($s, $s->anna, [[$s->anna1->fresh(), -20.00], [$s->anna2, 120.50]], '2026-02-20', 100.50);
    expect(app(\App\Services\Gestionale\Ricevute\RicevutaIncasso::class)->per($suo->fresh())['credito_usato'])->toBe(2000)
        ->and(acoSezione($this, $s, $utente)['righe'])->toBe([]);
    // Anna versa € 150,00 sulla rata 3; nello stesso incasso, come lo lasciava una versione di prima, una compensazione con il credito di Anna
    // copre € 20,00 della rata 1 di Ugo: Ugo è fra le compensazioni (la ricevuta va ad Anna).
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna3, 150.00]], '2026-03-01', 150.00);
    $figlia = $incasso->replicate(['numero_protocollo']);
    $figlia->forceFill(['tipo_movimento' => 'storno_credito', 'scrittura_padre_id' => $incasso->id, 'numero_protocollo' => null])->save();
    foreach ([[$s->ugo1->id, 2000], [$s->anna1->id, -2000]] as [$quota, $importo]) {
        \Illuminate\Support\Facades\DB::table('quota_scrittura')->insert(['scrittura_contabile_id' => $figlia->id, 'rate_quota_id' => $quota, 'importo_pagato' => $importo,
            'data_pagamento' => '2026-03-01', 'created_at' => now(), 'updated_at' => now()]);
    }
    expect(collect(acoSezione($this, $s, $utente)['righe'])->pluck('persona.nome')->all())->toBe(['Ugo Neri']);

    // Lo stesso incasso con anche il denaro sulla rata 2 di Ugo, senza «Versato da»: le quote di due persone, nessuno ha versato per l'altro.
    // La ricevuta non saprebbe per conto di chi: non è una compensazione da avvisare.
    \Illuminate\Support\Facades\DB::table('quota_scrittura')->insert(['scrittura_contabile_id' => $incasso->id, 'rate_quota_id' => $s->ugo2->id, 'importo_pagato' => 6000,
        'data_pagamento' => '2026-03-01', 'created_at' => now(), 'updated_at' => now()]);
    expect(app(\App\Services\Gestionale\Ricevute\PersoneDegliIncassi::class)->di([$incasso->id])[$incasso->id]['piu_persone'])->not->toBe([]);
    expect(acoSezione($this, $s, $utente)['righe'])->toBe([]);
    \Illuminate\Support\Facades\DB::table('quota_scrittura')->where('scrittura_contabile_id', $incasso->id)->where('rate_quota_id', $s->ugo2->id)->delete();
    \Illuminate\Support\Facades\DB::table('quota_scrittura')->where('scrittura_contabile_id', $figlia->id)->delete();

    // Un incasso di solo credito, poi annullato: niente.
    incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-02-01', 70.00);
    $solo = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1->fresh(), -10.00], [$s->ugo2, 10.00]], '2026-03-01', 0.00);
    expect(acoSezione($this, $s, $utente)['righe'])->toHaveCount(1);
    app(\App\Actions\Gestionale\Movimenti\StornoIncassoRateAction::class)->execute($solo->fresh(['righe', 'quotePagate', 'figlie']), $s->condominio, \App\Enums\NaturaStorno::VERSAMENTO_TORNATO);
    expect(acoSezione($this, $s, $utente)['righe'])->toBe([]);
});

test('la stampa dell\'avviso: un PDF, una riga «stampata» del registro con l\'incasso e la persona; la riga del bollo come la ricevuta, a scelta; poi la compensazione è avvisata', function () {
    $s = scenarioRicevutaIncasso();
    $utente = amministratoreRicevute();
    $this->travelTo('2026-03-02 10:00:00');
    incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 170.00);
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1->fresh(), -20.00], [$s->anna2, 20.00]], '2026-03-01', 0.00);
    $chiave = $incasso->id . ':' . $s->anna->id;

    $dati = null;
    View::composer('pdf.gestionale.avviso_compensazione', function ($vista) use (&$dati) {
        $dati ??= $vista->getData();
    });
    $r = $this->actingAs($utente)->post(acoRotta($s, 'stampa'), ['avvisi' => [$chiave], 'bollo' => 'non_dovuta']);
    $r->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $riga = RegistroInvio::where('tipo', AvvisiCompensazione::TIPO)->sole();
    expect($riga)->toMatchArray(['anagrafica_id' => $s->anna->id, 'documentabile_type' => ScritturaContabile::class, 'documentabile_id' => $incasso->id,
        'canale' => RegistroInvio::CANALE_STAMPA, 'stato' => RegistroInvio::STATO_STAMPATA])
        ->and($riga->dati['importo'])->toBe(2000)
        ->and($riga->dati['bollo'])->toBe('non_dovuta')
        ->and($riga->impronta)->toBe(hash('sha256', $r->getContent()));

    $html = View::make('pdf.gestionale.avviso_compensazione', $dati)->render();
    expect($html)->toContain('Avviso di compensazione')
        ->and($html)->toContain('il suo credito')
        ->and($html)->toContain('€ 20,00')
        ->and($html)->toContain('Rata 2')
        ->and($html)->toContain('Non dovuta')
        ->and($html)->toContain($incasso->numero_protocollo);

    $c = acoSezione($this, $s, $utente);
    expect($c['righe'][0]['ultimo'])->toMatchArray(['canale' => 'stampa', 'stato' => 'stampata'])
        ->and($c['senza_avviso'])->toBe(0);
    // L'avviso non è una ricevuta: la pagina delle ricevute non lo conta, e non è una lettera per «mai scritto».
    expect(RegistroInvio::where('tipo', RegistroInvio::TIPO_RICEVUTA)->count())->toBe(0);
});

test('l\'invio per email: un messaggio per indirizzo, la stessa riga per avviso; chi non ha l\'email resta da stampare; senza la scelta del bollo la riga dice «—»', function () {
    Queue::fake();
    config(['mail.default' => 'array']);
    $s = scenarioRicevutaIncasso();
    $utente = amministratoreRicevute();
    $this->travelTo('2026-03-02 10:00:00');
    incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 170.00);
    $anna = incassaPerRicevuta($s, $s->anna, [[$s->anna1->fresh(), -20.00], [$s->anna2, 20.00]], '2026-03-01', 0.00);
    incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-02-01', 70.00);
    $ugo = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1->fresh(), -10.00], [$s->ugo2, 10.00]], '2026-03-01', 0.00);

    $r = $this->actingAs($utente)->postJson(acoRotta($s, 'invia'), ['avvisi' => [$anna->id . ':' . $s->anna->id, $ugo->id . ':' . $s->ugo->id]])->assertOk();
    expect($r->json('messaggi'))->toBe(2)
        ->and($r->json('senza_indirizzo'))->toBe(['Ugo Neri']);
    $righe = RegistroInvio::where('tipo', AvvisiCompensazione::TIPO)->orderBy('id')->get();
    expect($righe)->toHaveCount(2)
        ->and($righe->pluck('stato')->unique()->all())->toBe([RegistroInvio::STATO_IN_CODA])
        ->and($righe->pluck('anagrafica_id')->unique()->all())->toBe([$s->anna->id])
        ->and($righe->pluck('dati.avviso')->unique())->toHaveCount(1)
        ->and($righe->pluck('dati.gettone')->unique())->toHaveCount(2);

    // Il messaggio porta l'avviso in PDF; la riga del bollo senza una scelta dice «—».
    $messaggio = (new \App\Notifications\Gestionale\AvvisoCompensazioneNotification([$righe[0]->id]))->toMail(new \Illuminate\Notifications\AnonymousNotifiable);
    expect($messaggio->rawAttachments)->toHaveCount(1)
        ->and((string) $messaggio->render())->toContain('Anna Bruni');
});

test('un avviso in coda il cui incasso è stato annullato prima della partenza non parte, e la riga lo dice con la frase del programma', function () {
    Queue::fake();
    config(['mail.default' => 'array']);
    $s = scenarioRicevutaIncasso();
    $utente = amministratoreRicevute();
    $this->travelTo('2026-03-02 10:00:00');
    incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 170.00);
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1->fresh(), -20.00], [$s->anna2, 20.00]], '2026-03-01', 0.00);
    $this->actingAs($utente)->postJson(acoRotta($s, 'invia'), ['avvisi' => [$incasso->id . ':' . $s->anna->id], 'indirizzi' => ['email']])->assertOk();
    $riga = RegistroInvio::where('tipo', AvvisiCompensazione::TIPO)->sole();

    app(\App\Actions\Gestionale\Movimenti\StornoIncassoRateAction::class)->execute($incasso->fresh(['righe', 'quotePagate', 'figlie']), $s->condominio, \App\Enums\NaturaStorno::VERSAMENTO_TORNATO);
    $notifica = new \App\Notifications\Gestionale\AvvisoCompensazioneNotification([$riga->id]);
    expect($notifica->shouldSend(new \Illuminate\Notifications\AnonymousNotifiable, 'mail'))->toBeFalse()
        ->and($riga->fresh()->stato)->toBe(RegistroInvio::STATO_NON_PARTITA)
        ->and($riga->fresh()->errore)->toBe(\App\Notifications\Gestionale\AvvisoCompensazioneNotification::ANNULLATO_PRIMA_DELL_INVIO)
        ->and(\App\Services\Gestionale\Ricevute\ErroreDiPosta::delProgrammaStesso($riga->fresh()->errore))->toBeTrue();
});

test('le rotte degli avvisi stanno nel pannello e nel condominio giusto: un incasso di un altro condominio, o una persona non coperta, fa 404 per tutta la richiesta', function () {
    $s = scenarioRicevutaIncasso();
    $altro = scenarioRicevutaIncasso('Condominio Via dei Tigli');
    $utente = amministratoreRicevute();
    $this->travelTo('2026-03-02 10:00:00');
    incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 170.00);
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1->fresh(), -20.00], [$s->anna2, 20.00]], '2026-03-01', 0.00);

    $this->actingAs($utente)->post(route('admin.gestionale.ricevute.avvisi-compensazione.stampa', ['condominio' => $altro->condominio->id]),
        ['avvisi' => [$incasso->id . ':' . $s->anna->id]])->assertNotFound();
    $this->actingAs($utente)->post(acoRotta($s, 'stampa'), ['avvisi' => [$incasso->id . ':' . $s->ugo->id]])->assertNotFound();
    $this->actingAs($utente)->post(acoRotta($s, 'stampa'), ['avvisi' => ['niente']])->assertNotFound();
    expect(RegistroInvio::count())->toBe(0);

    $condomino = \App\Models\User::factory()->create();
    $this->actingAs($condomino)->post(acoRotta($s, 'stampa'), ['avvisi' => [$incasso->id . ':' . $s->anna->id]])->assertForbidden();
});

test('alle 00:30 del 02/03 a Roma l\'avviso stampato è del 02/03: il giorno lo scrive il server, in quello dell\'amministratore', function () {
    config(['app.user_timezone' => 'Europe/Rome']);
    $s = scenarioRicevutaIncasso();
    $utente = amministratoreRicevute();
    $this->travelTo('2026-03-01 12:00:00');
    incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 170.00);
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1->fresh(), -20.00], [$s->anna2, 20.00]], '2026-03-01', 0.00);
    $this->travelTo(\Carbon\Carbon::parse('2026-03-02 00:30', 'Europe/Rome'));
    $this->actingAs($utente)->post(acoRotta($s, 'stampa'), ['avvisi' => [$incasso->id . ':' . $s->anna->id]])->assertOk();
    expect(RegistroInvio::where('tipo', AvvisiCompensazione::TIPO)->sole()->dati['emesso_il'])->toBe('2026-03-02');
});
