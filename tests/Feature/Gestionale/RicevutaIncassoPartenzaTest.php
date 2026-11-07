<?php

use App\Actions\Gestionale\Movimenti\StornoIncassoRateAction;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use App\Notifications\Gestionale\RicevutaIncassoNotification;
use App\Services\Gestionale\Ricevute\ErroreDiPosta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/**
 * Quando un messaggio con le ricevute parte (o non parte): le righe del registro che portano avanti solo le ricevute che sono davvero nel
 * messaggio, e la frase con cui chi lavora legge l'errore, che non inventa un codice e non dà al server di posta la colpa di un errore del
 * programma.
 *
 * Che cosa NON copre: la consegna (il programma sa solo che il server ha accettato).
 */
function partenzaStorna(ScritturaContabile $incasso, object $s): void
{
    app(StornoIncassoRateAction::class)->execute(ScritturaContabile::with(['righe', 'quotePagate', 'figlie'])->find($incasso->id), $s->condominio, \App\Enums\NaturaStorno::VERSAMENTO_TORNATO);
}

function partenzaTrasportoCheSbaglia(\Throwable $errore): void
{
    Mail::extend('partenza_sbaglia', fn () => new class($errore) extends AbstractTransport {
        public function __construct(private \Throwable $errore)
        {
            parent::__construct();
        }

        protected function doSend(SentMessage $message): void
        {
            throw $this->errore;
        }

        public function __toString(): string
        {
            return 'partenza_sbaglia';
        }
    });
    config(['mail.mailers.partenza_sbaglia' => ['transport' => 'partenza_sbaglia'], 'mail.default' => 'partenza_sbaglia']);
    app('mail.manager')->forgetMailers();
}

test('un messaggio con due ricevute di una persona: quella il cui incasso è annullato prima della partenza resta «non partita», e il messaggio che parte non la porta a «inviata»', function () {
    Queue::fake();
    config(['mail.default' => 'array']);
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $a1 = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $a2 = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], '2026-03-01', 120.50);
    [$q1] = incassiDiPiuPersoneConDueIndirizzi($s, 1);
    $persona = \App\Models\Anagrafica::where('nome', 'Persona 01')->firstOrFail();
    $quota = RataQuote::create(['rata_id' => $s->rate[2]->id, 'anagrafica_id' => $persona->id,
        'immobile_id' => RataQuote::where('anagrafica_id', $persona->id)->value('immobile_id'),
        'importo' => 1000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => $s->rate[2]->data_scadenza]);
    $q2 = incassaPerRicevuta($s, $persona, [[$quota, 10.00]], '2026-03-01', 10.00)->id;
    $route = fn (string $nome, array $altro = []) => route('admin.gestionale.ricevute.' . $nome, ['condominio' => $s->condominio->id] + $altro);

    $this->actingAs($u)->postJson($route('invia'), [
        'incassi' => [$a1->id, $a2->id, $q1, $q2], 'indirizzi' => ['email'], 'bollo_sopra' => 'assolta', 'modo_bollo' => 'virtuale', 'bollo_sotto' => 'non_dovuta',
    ])->assertOk()->assertJson(['messaggi' => 2, 'in_coda' => 2]);
    $lavori = Queue::pushed(SendQueuedNotifications::class);
    expect($lavori)->toHaveCount(2);

    // Prima che il servizio della coda mandi i messaggi si annullano due delle quattro ricevute, una per persona.
    $this->travel(5)->minutes();
    partenzaStorna($a1, $s);
    partenzaStorna(ScritturaContabile::find($q1), $s);
    foreach ($lavori as $lavoro) {
        $lavoro->handle(app(ChannelManager::class));
    }

    $riga = fn (int $id) => RegistroInvio::where('documentabile_id', $id)->sole();
    expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(2)
        ->and($riga($a2->id)->stato)->toBe('inviata')
        ->and($riga($q2)->stato)->toBe('inviata')
        ->and($riga($a1->id)->stato)->toBe('non_partita')
        ->and($riga($a1->id)->errore)->toBe(RicevutaIncassoNotification::ANNULLATO_PRIMA_DELL_INVIO)
        ->and($riga($a1->id)->mail_log_id)->toBeNull()
        ->and($riga($q1)->stato)->toBe('non_partita')
        ->and($riga($q1)->mail_log_id)->toBeNull();

    // La ricevuta mai partita non si propone di rimandarla, non conta come uscita e non è fra quelle che la persona ha avuto.
    $opzioni = $this->actingAs($u)->getJson($route('opzioni', ['scrittura' => $a1->id]))->assertOk()->json();
    $avviso = collect($this->actingAs($u)->get($route('index'))->viewData('page')['props']['annullate_da_comunicare'])->pluck('id')->all();
    expect($opzioni['inviabile'])->toBeFalse()->and($opzioni['copia'])->toBeNull()
        ->and($avviso)->not->toContain($a1->id)->not->toContain($q1);
});

test('la frase dell\'errore non inventa un codice quando il server non risponde, e non dà al server di posta la colpa di un errore del programma', function () {
    // Il server non ha risposto: il «250» è il codice che il programma si aspettava, non quello che il server ha dato.
    $rispostaVuota = 'Expected response code "250/251/252" but got empty code.';
    expect(ErroreDiPosta::codice($rispostaVuota))->toBeNull()
        ->and(ErroreDiPosta::frase($rispostaVuota))->toBe('Il server di posta non ha accettato il messaggio.')
        ->and(ErroreDiPosta::frase('Expected response code "250" but got code "554", with message "554 5.7.1 Relay access denied".'))
        ->toBe('Il server di posta non ha accettato il messaggio (codice 554).');

    // Un errore che non viene dal trasporto di posta si scrive come errore del programma, e così lo legge chi lavora.
    $delProgramma = ErroreDiPosta::daEccezione(new \RuntimeException('Impossibile creare il PDF della ricevuta'));
    $delServer = ErroreDiPosta::daEccezione(new TransportException('Connection could not be established with host "smtp.esempio.it:587"'));
    expect(ErroreDiPosta::frase($delProgramma))->toBe(ErroreDiPosta::FRASE_DEL_PROGRAMMA)
        ->and(ErroreDiPosta::FRASE_DEL_PROGRAMMA)->not->toContain('server di posta')
        ->and($delServer)->toBe('Connection could not be established with host "smtp.esempio.it:587"')
        ->and(ErroreDiPosta::frase($delServer))->toBe('Il server di posta non ha accettato il messaggio.');
});

test('un errore del programma mentre il messaggio parte non è presentato come un rifiuto del server di posta: la risposta, la riga e la finestra dicono errore del programma; chi gestisce le impostazioni legge il testo intero', function () {
    partenzaTrasportoCheSbaglia(new \RuntimeException('Allowed memory size exhausted mentre scrivo il PDF'));
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $collaboratore = partenzaUtente('collaboratore', false);
    $chiGestisce = partenzaUtente('amministratore', true);
    $campi = ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'assolta'];
    $c = ['condominio' => $s->condominio->id];

    $perTutti = $this->actingAs($collaboratore)->postJson(route('admin.gestionale.ricevute.invia-una', $c + ['scrittura' => $incasso->id]), $campi)->assertOk()->json();
    $perChiGestisce = $this->actingAs($chiGestisce)->postJson(route('admin.gestionale.ricevute.invia-una', $c + ['scrittura' => $incasso->id]), $campi)->assertOk()->json();
    $finestra = fn ($u) => $this->actingAs($u)->getJson(route('admin.gestionale.ricevute.opzioni', $c + ['scrittura' => $incasso->id]))->assertOk()->json('emissioni.0');

    expect($perTutti['errori'])->toBe([ErroreDiPosta::FRASE_DEL_PROGRAMMA])
        ->and($perTutti)->not->toHaveKey('errori_completi')
        ->and($finestra($collaboratore)['errore'])->toBe(ErroreDiPosta::FRASE_DEL_PROGRAMMA)
        ->and($finestra($collaboratore)['errore_completo'])->toBeNull()
        ->and(json_encode($finestra($collaboratore)))->not->toContain('Allowed memory size')
        ->and($perChiGestisce['errori_completi'][0])->toContain('Allowed memory size exhausted')
        ->and($finestra($chiGestisce)['errore_completo'])->toContain('Allowed memory size exhausted');
});

/** Un utente del pannello, con o senza il permesso delle impostazioni generali (quello che apre il registro delle email). */
function partenzaUtente(string $ruolo, bool $impostazioni): \App\Models\User
{
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $r = \Spatie\Permission\Models\Role::firstOrCreate(['name' => $ruolo, 'guard_name' => 'web']);
    $r->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    if ($impostazioni) {
        $r->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(['name' => \App\Enums\Permission::MANAGE_GENERAL_SETTINGS->value, 'guard_name' => 'web']));
    }
    $u = \App\Models\User::factory()->create(['email_verified_at' => now()]);
    $u->assignRole($r);

    return $u;
}
