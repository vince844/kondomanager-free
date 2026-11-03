<?php

/**
 * 1.11.0-beta.50 — l'inizio di una locazione dopo una fine con «Scrivi il conguaglio» e dopo una fermata (decisione 45; scelte
 * tecniche del verbale `docs/piano_esecutivo_beta50.md`, correzioni (2) e (3) della revisione, misure S3 e S7).
 *
 * Il rinnovo con la stessa persona non è una fermata: la fine «Scrivi» del 1/7 ha dato a Ugo i giorni dopo l'uscita (coppia Ines
 * −18148, Ugo +18148); se Ines rientra, quei giorni gliela rimborsa lei con il prospetto degli oneri accessori (decisione 45: la
 * parte pagata da chi c'era la rimborsa chi entra), e il pannello lo dice come informazione, senza spunta. Si dice anche
 * l'alternativa: annullare la fine, che è l'ultimo passaggio (decisione 27), e rifarla con «Non scriverlo». La frase di prima
 * «La parte a carico dell'inquilino dal … è già nelle rate di Ines» (in `AnteprimaPassaggio.php`, e la sua sorella nelle
 * informazioni del cancello) dopo «Scrivi» era falsa: quella parte l'aveva presa Ugo.
 *
 * Dopo una fermata (la vendita del 1/10 che lascia a Ugo € 90,74 dei giorni di Elsa), l'inizio di una locazione successiva dice
 * quanto della fermata resta da regolare: con Luca dal 1/11, solo ottobre, € 30,58 (S7: la cifra della fermata non resta valida
 * per sempre).
 *
 * LE CIFRE, fatte a mano: coppia della fine 36000 × 184/365 = 18147,95 → 18148 (vedi `FermataDopoScriviTest`). Dal 1/7 al 31/12 184
 * giorni, dal 1/10 92 (31 + 30 + 31): 18148 × 184/184 = 18148 → € 181,48; 18148 × 92/184 = 9074,00 → € 90,74. Per S7 la coppia in tre
 * tratti, Ugo 1/7–30/9 (92), Elsa 1/10–31/10 (31), Luca 1/11–31/12 (61): 18148 × 92/184 = 9074,00, × 31/184 = 3057,54, × 61/184 =
 * 6016,46; 9074 + 3057 + 6016 = 18147, il centesimo al resto più grande (,54): Elsa 3058 → € 30,58, Luca 6016. (revisione dei test
 * della Fase 1) Il piano generato dopo i tre passaggi dà Elsa 3057 e Luca 6017 (un giro solo: Elsa 73,7 → 24230,14, Luca 18,3 →
 * 6016,44, il centesimo a Luca; 24230 − 21173 = 3057): un centesimo, la tolleranza dichiarata. Il test usa 3058, la regola della
 * coppia, come il prospetto (Luca rimborsa 6016).
 *
 * Che cosa copre, dalle rotte vere: la frase falsa tolta e la frase vera con la cifra (rinnovo il 1/7 e rientro il 1/10);
 * l'informazione senza spunta e la registrazione senza presa d'atto, con il denaro fermo; l'alternativa detta nel pannello e la
 * strada seguita (annullare la fine, rifarla con «Non scriverlo», poi il rinnovo); S7.
 * ➕ Fusione della Fase 1 (11/10/2026), dalle scelte di progetto del verbale: la frase dopo una fermata nell'inizio (S7) è un motivo
 * del cancello, con la spunta, come la fermata (in coda).
 *
 * Che cosa NON copre: il cambio d'inquilino in due passi con un'altra persona dopo «Scrivi» (Luca il 1/7: area della fine e del
 * cambio d'inquilino); il prospetto degli oneri dopo il rinnovo e la fusione delle righe della stessa persona (area del
 * prospetto); la riga «se è un rinnovo, scegli Non scriverlo» accanto alla scelta della fine (pannello della fine); le
 * pertinenze; la pagina Vue; il caso in cui l'amministratore abbia già scritto a mano il saldo della fermata prima dell'inizio
 * (S7 ne dice solo la cifra).
 *
 * Presuppone che la fine locazione senza nuovo inquilino accetti il campo `conguaglio` (nuovo nella .50).
 */

use App\Models\Gestionale\Subentro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/FermataDopoScriviHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Piano generato, sei rate emesse, fine di Ines il 1/7 con la scelta data; controlla la coppia scritta (o la sua assenza). */
function fds50FineDiInes($test, string $scelta): array
{
    $s = fds50Scenario();
    fds50Genera($s);
    fds50Emetti($test, $s, '2026-06-30');
    $s['fine'] = fds50Fine($test, $s, $scelta);
    expect(fds50Coppia($s, $s['fine']))->toBe($scelta === Subentro::SCRIVI_IL_CONGUAGLIO ? ['Ines' => -18148, 'Ugo' => 18148] : []);

    return $s;
}

/** Le stringhe dell'anteprima in minuscolo, per cercare una frase sia a inizio periodo sia dentro un elenco. */
function fds50Minuscole(array $anteprima): array
{
    return array_map(fn (string $t) => mb_strtolower($t), fds50Testi($anteprima));
}

dataset('fds50Rientri', [
    // Rinnovo nello stesso giorno: tutti i 184 giorni, 18148 → € 181,48.
    'rinnovo il 1/7' => ['2026-07-01', '€ 181,48'],
    // Rientro il 1/10: 18148 × 92/184 = 9074 → € 90,74 (i giorni 1/7–30/9 restano a Ugo, che li ha pagati senza inquilino).
    'rientro il 1/10' => ['2026-10-01', '€ 90,74'],
]);

it('rinnovo dopo «Scrivi»: il pannello non dice più che la parte «è già nelle rate di Ines»; dice che l\'ha presa Ugo con il conguaglio della fine e che Ines gliela rimborsa con il prospetto, con la cifra', function (string $il, string $cifra) {
    $s = fds50FineDiInes($this, Subentro::SCRIVI_IL_CONGUAGLIO);
    $anteprima = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Ines', $il));

    // La frase falsa, nel blocco «Rate» (:796) e nelle informazioni del cancello (:2529): sparita da tutte e due.
    expect(collect(fds50Minuscole($anteprima))->contains(fn (string $t) => str_contains($t, 'è già nelle rate di ines')))
        ->toBeFalse('Il pannello dice ancora che la parte «è già nelle rate di Ines», ma con «Scrivi» l\'ha presa Ugo: ' . implode(' | ', fds50Con($anteprima, 'già nelle rate')));
    // La frase vera: la cifra, chi l'ha presa, e la strada del prospetto.
    $conLaCifra = fds50Con($anteprima, $cifra);
    expect(collect($conLaCifra)->contains(fn (string $t) => str_contains($t, 'Ugo') && str_contains($t, 'prospetto')))
        ->toBeTrue("Nessuna frase con {$cifra} nomina Ugo e il prospetto degli oneri accessori: " . implode(' | ', $conLaCifra));
})->with('fds50Rientri');

it('rinnovo dopo «Scrivi»: è un\'informazione, non una fermata — nessuna spunta per la fine, la registrazione passa senza presa d\'atto, il denaro resta com\'è', function (string $il, string $cifra) {
    $s = fds50FineDiInes($this, Subentro::SCRIVI_IL_CONGUAGLIO);
    $dati = fds50ModuloInizio($s, 'Ines', $il);
    $anteprima = fds50Anteprima($this, $s, $dati);

    // Decisione 45: i piani fermi sono un'informazione, non un motivo del cancello; nessun motivo con la cifra della fine.
    expect($anteprima['cancello']['richiesto'] ?? null)->toBeFalse('Il rinnovo chiede la spunta: ' . implode(' | ', $anteprima['cancello']['motivi'] ?? []))
        ->and(collect($anteprima['cancello']['motivi'] ?? [])->contains(fn (string $m) => str_contains($m, $cifra)))->toBeFalse();

    // Registrato senza `ho_letto`: Ines rientra; la coppia della fine resta (Ines 36000 − 18148 = 17852, Ugo 84000 + 18148 = 102148).
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $dati)->assertSessionHasNoErrors();
    expect(Subentro::where('immobile_id', $s['unita']->id)->whereNull('subentro_padre_id')->latest('id')->first()->tipo_passaggio)->toBe('inizio_locazione')
        ->and(fds50Coppia($s, $s['fine']))->toBe(['Ines' => -18148, 'Ugo' => 18148])
        ->and(fds50Netti($s))->toBe(['Ines' => 17852, 'Ugo' => 102148]);
})->with('fds50Rientri');

it('rinnovo dopo «Scrivi»: il pannello dice anche l\'alternativa, annullare la fine (l\'ultimo passaggio) e rifarla con «Non scriverlo»', function () {
    $s = fds50FineDiInes($this, Subentro::SCRIVI_IL_CONGUAGLIO);
    $anteprima = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Ines', '2026-07-01'));

    expect(collect(fds50Testi($anteprima))->contains(fn (string $t) => str_contains($t, 'Non scriverlo') && str_contains(mb_strtolower($t), 'annull')))
        ->toBeTrue('Il pannello del rinnovo non dice che si può annullare la fine e rifarla con «Non scriverlo».');
});

it('rinnovo, la strada seguita: annullata la fine «Scrivi» e rifatta con «Non scriverlo», il rinnovo trova la parte davvero nelle rate di Ines e il denaro torna Ines 36000, Ugo 84000', function () {
    $s = fds50FineDiInes($this, Subentro::SCRIVI_IL_CONGUAGLIO);
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $s['fine']]), ['nota_annullamento' => 'Era un rinnovo: annullo la fine'])
        ->assertSessionHasNoErrors();
    $fine = fds50Fine($this, $s, Subentro::NON_SCRIVERE_IL_CONGUAGLIO);
    ['anteprima' => $anteprima] = fds50Registra($this, $s, fds50ModuloInizio($s, 'Ines', '2026-07-01'));

    // Senza coppia la parte dal 1/7 è nelle rate di Ines (sei quote in bozza da 3000 e le sei emesse): qui la frase è vera.
    expect(fds50Coppia($s, $fine))->toBe([])
        ->and(collect(fds50Minuscole($anteprima))->contains(fn (string $t) => str_contains($t, 'è già nelle rate di ines')))->toBeTrue()
        // 12 × 3000 = 36000 a Ines, 12 × 7000 = 84000 a Ugo: come se la locazione non si fosse mai interrotta.
        ->and(fds50Netti($s))->toBe(['Ines' => 36000, 'Ugo' => 84000]);
});

it('S7 — dopo la fermata della vendita del 1/10, l\'inizio di Luca il 1/11 dice quanto della fermata resta da regolare fra Ugo ed Elsa: € 30,58, solo ottobre', function () {
    $s = fds50FineDiInes($this, Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($this, $s, '2026-09-30');
    $vendita = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'))['subentro'];
    // La fermata della vendita, nel registro: 18148 × 92/184 = 9074 (vedi `FermataDopoScriviTest`).
    expect(collect(fds50Fermate($vendita))->sum('cifra'))->toBe(9074);

    $anteprima = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));

    // Da regolare resta solo ottobre, Elsa 3058 (in testa il calcolo); i 61 giorni di novembre e dicembre Luca li rimborsa a Ugo
    // con il prospetto. Se l'amministratore scrivesse ancora € 90,74 a Elsa, Elsa pagherebbe € 60,16 in più.
    $conLaCifra = fds50Con($anteprima, '€ 30,58');
    expect($conLaCifra)->not->toBeEmpty('L\'inizio di Luca non dice quanto della fermata resta da regolare (€ 30,58).')
        ->and(collect($conLaCifra)->contains(fn (string $t) => str_contains($t, 'Elsa')))->toBeTrue(implode(' | ', $conLaCifra));
});

it('S7 — la frase dopo una fermata nell\'inizio è un motivo del cancello, con la spunta: l\'inizio di Luca il 1/11 la chiede con la cifra che resta da regolare (€ 30,58), e senza «Ho letto» e la nota non si registra', function () {
    // Scelte di progetto della Fase 1 (verbale, «Le scelte di progetto sulle questioni aperte dai test»): la fermata e la frase dopo una
    // fermata nell'inizio sono motivi del cancello, con la spunta. Il rinnovo dopo «Scrivi» resta un'informazione (decisione 45, sopra).
    $s = fds50FineDiInes($this, Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($this, $s, '2026-09-30');
    fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'));
    $modulo = fds50ModuloInizio($s, 'Luca', '2026-11-01');
    $anteprima = fds50Anteprima($this, $s, $modulo);

    // Da regolare resta solo ottobre, Elsa 3058 → € 30,58 (in testa il calcolo).
    $motivi = collect($anteprima['cancello']['motivi'] ?? []);
    expect($anteprima['cancello']['richiesto'] ?? null)->toBeTrue('L\'inizio dopo la fermata non chiede la spunta: ' . $motivi->implode(' | '))
        ->and($motivi->contains(fn (string $m) => str_contains($m, '€ 30,58')))->toBeTrue('Nessun motivo del cancello dice quanto resta da regolare (€ 30,58): ' . $motivi->implode(' | '));

    $prima = Subentro::where('immobile_id', $s['unita']->id)->count();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $modulo)->assertSessionHasErrors('nota_cancello');
    expect(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe($prima);
});
