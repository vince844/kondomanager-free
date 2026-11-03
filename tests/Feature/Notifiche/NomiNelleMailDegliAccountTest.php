<?php

/**
 * # Le mail degli account: il nome nel saluto è testo (decisione 136.3)
 *
 * Le mail sono Markdown. Il saluto della mail di benvenuto («Salve :name») porta il nome del nuovo utente, che scrive chi
 * crea l'account (l'amministratore o il collaboratore), e la mail va al titolare. Il saluto della mail «nuovo utente
 * registrato» porta il nome dell'amministratore che la riceve, che può aver scritto un altro amministratore.
 *
 * Il nome arriva come testo, con la regola dei nomi delle altre mail (`TestoScrittoDaAltri::nomeComeTesto()`): senza
 * i caratteri che il Markdown interpreta, su una riga, al più sessanta caratteri. La sola destinazione della mail resta il
 * pulsante che costruisce il gestionale.
 *
 * Le altre mail degli account non portano testi scritti da altri: l'invito (`InviteUserNotification`) non nomina nessuno,
 * la verifica dell'email e il recupero della password salutano senza nome, gli avvisi di sicurezza usano già la regola
 * (`AvvisiDiSicurezzaTest`).
 *
 * Cosa NON copre: chi può creare un account o reinviare il benvenuto (`PrivilegiUtentiTest`, `ReinviaTest`).
 */

use App\Enums\Role;
use App\Models\User;
use App\Notifications\NewUserEmailNotification;
use App\Notifications\Users\RegisteredUserNotification;
use App\Settings\GeneralSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role as RuoloSpatie;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->withoutVite();

    $this->amministratore = User::factory()->create(['email_verified_at' => now(), 'name' => 'Studio Bianchi']);
    $this->amministratore->assignRole(Role::AMMINISTRATORE->value);
    $this->collaboratore = User::factory()->create(['email_verified_at' => now(), 'name' => 'Carla Collaboratrice']);
    $this->collaboratore->assignRole(Role::COLLABORATORE->value);
});

/**
 * La mail resa in HTML per chi la riceve: il testo che si legge e gli indirizzi di ogni collegamento e di ogni immagine,
 * presi dagli attributi del documento.
 *
 * @return array{testo: string, indirizzi: list<string>}
 */
function nomiAccountMailResa(object $avviso, object $destinatario): array
{
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8"?>'.(string) $avviso->toMail($destinatario)->render(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $indirizzi = [];
    foreach ((new DOMXPath($dom))->query('//@href | //@src') as $attributo) {
        $indirizzi[] = $attributo->value;
    }

    return [
        'testo' => trim(preg_replace('/\s+/u', ' ', $dom->getElementsByTagName('body')->item(0)?->textContent ?? '') ?? ''),
        'indirizzi' => $indirizzi,
    ];
}

/** Il benvenuto mandato a `$utente`, reso: deve essercene esattamente uno. */
function nomiAccountBenvenuto(User $utente): array
{
    $mandati = Notification::sent($utente, NewUserEmailNotification::class);
    expect($mandati)->toHaveCount(1);

    return nomiAccountMailResa($mandati->first(), $utente);
}

function nomiAccountFuoriDalGestionale(array $resa): array
{
    return array_values(array_filter($resa['indirizzi'], fn (string $i) => str_contains($i, 'altrove.example')));
}

test('decisione 136 — il nome del nuovo utente, scritto da chi crea l\'account, arriva come testo nel saluto della mail di benvenuto', function (string $chi) {
    $idUtente = RuoloSpatie::where('name', Role::UTENTE->value)->firstOrFail()->id;

    $this->actingAs($this->{$chi})->from(route('utenti.create'))->post(route('utenti.store'), [
        'name' => 'Anna [clicca qui](https://altrove.example/benvenuto) ![logo](https://altrove.example/logo.png)',
        'email' => 'anna@example.com', 'roles' => $idUtente, 'permissions' => [], 'anagrafica' => null,
    ])->assertSessionHasNoErrors()->assertRedirect(route('utenti.index'));

    $resa = nomiAccountBenvenuto(User::where('email', 'anna@example.com')->firstOrFail());

    // Il nome come testo: senza parentesi, su una riga, i primi sessanta caratteri e i puntini.
    expect(nomiAccountFuoriDalGestionale($resa))->toBe([])
        ->and($resa['testo'])->toContain('Salve Anna clicca quihttps://altrove.example/benvenuto !logohttps:…,');
})->with(['amministratore', 'collaboratore']);

test('decisione 136 — «Reinvia» manda il benvenuto con il nome come testo', function () {
    $anna = User::factory()->create(['email_verified_at' => now(), 'name' => 'Anna [clicca qui](https://altrove.example/reinvio)']);
    $anna->assignRole(Role::UTENTE->value);

    $this->actingAs($this->amministratore)->post(route('utenti.reinvite', ['email' => $anna->email]))
        ->assertRedirect(route('utenti.index'));

    $resa = nomiAccountBenvenuto($anna);

    expect(nomiAccountFuoriDalGestionale($resa))->toBe([])
        ->and($resa['testo'])->toContain('Salve Anna clicca quihttps://altrove.example/reinvio,');
});

test('decisione 136 — il nome dell\'amministratore arriva come testo nel saluto della mail «nuovo utente registrato»', function () {
    $this->amministratore->update(['name' => 'Studio [Bianchi](https://altrove.example/registrato)']);
    $impostazioni = app(GeneralSettings::class);
    $impostazioni->user_frontend_registration = true;
    $impostazioni->save();

    $this->post('/register', [
        'name' => 'Nuovo Iscritto', 'email' => 'nuovo@example.com',
        'password' => 'password-nuova', 'password_confirmation' => 'password-nuova',
    ])->assertRedirect();

    $mandati = Notification::sent($this->amministratore, RegisteredUserNotification::class);
    expect($mandati)->toHaveCount(1);
    $resa = nomiAccountMailResa($mandati->first(), $this->amministratore->fresh());

    expect(nomiAccountFuoriDalGestionale($resa))->toBe([])
        ->and($resa['testo'])->toContain('Salve Studio Bianchihttps://altrove.example/registrato');
});
