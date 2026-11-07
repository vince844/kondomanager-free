<?php

/**
 * I modelli delle lettere, nella pagina «Impostazioni → Stampe → Modelli delle lettere»: per ogni tipo il testo del programma (che non si
 * cambia e non sta nel database) e i modelli dello studio, con un nome, per tutti i condomini o per uno; creare, cambiare, duplicare ed
 * eliminare li fa chi gestisce le impostazioni di stampa, vederli chi entra nel pannello. Il nome è unico per tipo e per «vale per».
 */

use App\Models\Condominio;
use App\Models\ModelloTesto;
use App\Models\User;
use App\Services\Gestionale\Lettere\TestiLettere;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $pannello = Permission::firstOrCreate(['name' => \App\Enums\Permission::ACCESS_ADMIN_PANEL->value, 'guard_name' => 'web']);
    $impostazioni = Permission::firstOrCreate(['name' => \App\Enums\Permission::MANAGE_GENERAL_SETTINGS->value, 'guard_name' => 'web']);
    $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin->givePermissionTo([$pannello, $impostazioni]);
    $collaboratore = Role::firstOrCreate(['name' => 'collaboratore di prova', 'guard_name' => 'web']);
    $collaboratore->givePermissionTo($pannello);
    $this->admin = User::factory()->create(['name' => 'Marta Neri']);
    $this->admin->assignRole($admin);
    $this->collaboratore = User::factory()->create(['name' => 'Piero Blu']);
    $this->collaboratore->assignRole($collaboratore);
    $this->condomino = User::factory()->create();
    $this->rose = Condominio::factory()->create(['nome' => 'Le Rose']);
    $this->verdi = Condominio::factory()->create(['nome' => 'Via Verdi 3']);
});

function mltPagina($test, ?User $chi = null): array
{
    return $test->actingAs($chi ?? $test->admin)->get(route('impostazioni.stampe.modelli-lettere'))->assertOk()->viewData('page')['props'];
}

function mltNuovo($test, array $dati, ?User $chi = null)
{
    return $test->actingAs($chi ?? $test->admin)->post(route('impostazioni.stampe.modelli-lettere.store'), $dati + ['condominio_id' => null]);
}

it('la pagina: il testo del programma di ogni tipo, poi i modelli con «vale per», chi l\'ha cambiato e il giorno; i testi del programma non sono nel database', function () {
    $this->travelTo(Carbon::parse('2026-03-20 10:00:00', 'Europe/Rome'));
    mltNuovo($this, ['tipo' => 'sollecito', 'nome' => 'Sollecito cortese', 'testo' => "Gentile [nome],\nrestano [totale]."])->assertSessionHasNoErrors()->assertRedirect();
    mltNuovo($this, ['tipo' => 'sollecito', 'nome' => 'Per Le Rose', 'testo' => 'Gentile [nome], il condominio [condominio].', 'condominio_id' => $this->rose->id])
        ->assertSessionHasNoErrors();

    $p = mltPagina($this);
    expect($p['testi_del_programma'])->toBe(TestiLettere::DI_SERIE)
        ->and(collect($p['tipi'])->pluck('tipo')->all())->toBe(['promemoria', 'sollecito', 'diffida', 'messa_in_mora'])
        ->and($p['puo_modificare'])->toBeTrue()
        ->and($p['campi'])->toBe(TestiLettere::CAMPI)
        ->and(collect($p['condomini'])->pluck('nome')->all())->toBe(['Le Rose', 'Via Verdi 3']);
    $modelli = collect($p['modelli'])->keyBy('nome');
    expect($modelli['Sollecito cortese'])->toMatchArray(['tipo' => 'sollecito', 'testo' => "Gentile [nome],\nrestano [totale].", 'condominio' => null,
        'cambiato_da' => 'Marta Neri', 'cambiato_il' => '2026-03-20'])
        ->and($modelli['Per Le Rose']['condominio'])->toBe(['id' => $this->rose->id, 'nome' => 'Le Rose']);

    // Nel database ci sono i due modelli dello studio, per le lettere; i testi del programma no.
    expect(DB::table('modelli_testo')->count())->toBe(2)
        ->and(DB::table('modelli_testo')->distinct()->pluck('ambito')->all())->toBe(['lettera'])
        ->and(DB::table('modelli_testo')->whereIn('testo', array_values(TestiLettere::DI_SERIE))->count())->toBe(0);
});

it('il giorno del cambio è quello dell\'amministratore: alle 00:30 di Roma è già il giorno dopo', function () {
    $this->travelTo(Carbon::parse('2026-03-21 00:30:00', 'Europe/Rome'));
    mltNuovo($this, ['tipo' => 'promemoria', 'nome' => 'Promemoria breve', 'testo' => 'Gentile [nome], scadono rate per [totale].'])->assertSessionHasNoErrors();

    expect(collect(mltPagina($this)['modelli'])->sole()['cambiato_il'])->toBe('2026-03-21');
});

it('chi entra nel pannello senza il permesso delle impostazioni vede la pagina, ma non crea, non cambia e non elimina; chi non entra nel pannello non la apre', function () {
    $m = ModelloTesto::create(['ambito' => 'lettera', 'tipo' => 'sollecito', 'nome' => 'Sollecito cortese', 'testo' => 'Gentile [nome].', 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id]);

    $p = mltPagina($this, $this->collaboratore);
    expect($p['puo_modificare'])->toBeFalse()->and(collect($p['modelli'])->pluck('nome')->all())->toBe(['Sollecito cortese']);
    mltNuovo($this, ['tipo' => 'sollecito', 'nome' => 'Altro', 'testo' => 'Gentile [nome].'], $this->collaboratore)->assertForbidden();
    $this->actingAs($this->collaboratore)->put(route('impostazioni.stampe.modelli-lettere.update', ['modello' => $m->id]), ['nome' => 'Cambiato', 'testo' => 'X', 'condominio_id' => null])->assertForbidden();
    $this->actingAs($this->collaboratore)->delete(route('impostazioni.stampe.modelli-lettere.destroy', ['modello' => $m->id]))->assertForbidden();
    expect($m->fresh()->nome)->toBe('Sollecito cortese');

    $this->actingAs($this->condomino)->get(route('impostazioni.stampe.modelli-lettere'))->assertForbidden();
    $this->actingAs($this->condomino)->post(route('impostazioni.stampe.modelli-lettere.store'), ['tipo' => 'sollecito', 'nome' => 'X', 'testo' => 'Y'])->assertForbidden();
    expect(ModelloTesto::count())->toBe(1);
});

it('il nome è unico per tipo e per «vale per»: lo stesso nome con le maiuscole diverse si rifiuta; con un altro tipo o un altro «vale per» no', function () {
    mltNuovo($this, ['tipo' => 'sollecito', 'nome' => 'Sollecito cortese', 'testo' => 'Gentile [nome].'])->assertSessionHasNoErrors();
    mltNuovo($this, ['tipo' => 'sollecito', 'nome' => '  sollecito CORTESE ', 'testo' => 'Altro.'])->assertSessionHasErrors('nome');
    mltNuovo($this, ['tipo' => 'diffida', 'nome' => 'Sollecito cortese', 'testo' => 'Gentile [nome].'])->assertSessionHasNoErrors();
    mltNuovo($this, ['tipo' => 'sollecito', 'nome' => 'Sollecito cortese', 'testo' => 'Gentile [nome].', 'condominio_id' => $this->rose->id])->assertSessionHasNoErrors();
    mltNuovo($this, ['tipo' => 'sollecito', 'nome' => 'Sollecito cortese', 'testo' => 'Gentile [nome].', 'condominio_id' => $this->rose->id])->assertSessionHasErrors('nome');
    expect(ModelloTesto::count())->toBe(3);

    // Cambiare un modello tenendo il suo nome va bene; dargli il nome di un altro dello stesso tipo e dello stesso «vale per» no.
    $altro = ModelloTesto::create(['ambito' => 'lettera', 'tipo' => 'sollecito', 'nome' => 'Sollecito fermo', 'testo' => 'X.']);
    $cortese = ModelloTesto::where('nome', 'Sollecito cortese')->where('tipo', 'sollecito')->whereNull('condominio_id')->sole();
    $this->actingAs($this->admin)->put(route('impostazioni.stampe.modelli-lettere.update', ['modello' => $cortese->id]), ['nome' => 'Sollecito cortese', 'testo' => 'Nuovo.', 'condominio_id' => null])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->put(route('impostazioni.stampe.modelli-lettere.update', ['modello' => $altro->id]), ['nome' => 'SOLLECITO CORTESE', 'testo' => 'X.', 'condominio_id' => null])
        ->assertSessionHasErrors('nome');
    expect($altro->fresh()->nome)->toBe('Sollecito fermo')->and($cortese->fresh()->testo)->toBe('Nuovo.');
});

it('«Modifica» cambia nome, testo e «vale per», non il tipo, e dice chi; «Duplica» è un modello nuovo; «Elimina» toglie il modello e basta', function () {
    $this->travelTo(Carbon::parse('2026-03-20 10:00:00', 'Europe/Rome'));
    mltNuovo($this, ['tipo' => 'sollecito', 'nome' => 'Sollecito cortese', 'testo' => 'Gentile [nome].'])->assertSessionHasNoErrors();
    $m = ModelloTesto::sole();
    $secondo = User::factory()->create(['name' => 'Gino Rossi']);
    $secondo->assignRole('admin');
    $this->travelTo(Carbon::parse('2026-03-22 10:00:00', 'Europe/Rome'));
    $this->actingAs($secondo)->put(route('impostazioni.stampe.modelli-lettere.update', ['modello' => $m->id]),
        ['tipo' => 'diffida', 'nome' => 'Sollecito per Le Rose', 'testo' => "Gentile [nome],\nil condominio [condominio].", 'condominio_id' => $this->rose->id])
        ->assertSessionHasNoErrors()->assertRedirect();
    $m->refresh();
    expect([$m->tipo, $m->nome, $m->testo, (int) $m->condominio_id, (int) $m->created_by, (int) $m->updated_by])
        ->toBe(['sollecito', 'Sollecito per Le Rose', "Gentile [nome],\nil condominio [condominio].", $this->rose->id, $this->admin->id, $secondo->id]);
    expect(collect(mltPagina($this)['modelli'])->sole())->toMatchArray(['cambiato_da' => 'Gino Rossi', 'cambiato_il' => '2026-03-22']);

    // «Duplica» manda un modello nuovo con lo stesso testo: due righe.
    mltNuovo($this, ['tipo' => 'sollecito', 'nome' => 'Sollecito per Le Rose (copia)', 'testo' => $m->testo, 'condominio_id' => $this->rose->id])->assertSessionHasNoErrors();
    expect(ModelloTesto::count())->toBe(2);

    $this->actingAs($this->admin)->delete(route('impostazioni.stampe.modelli-lettere.destroy', ['modello' => $m->id]))->assertSessionHasNoErrors()->assertRedirect();
    expect(ModelloTesto::pluck('nome')->all())->toBe(['Sollecito per Le Rose (copia)']);
    // Un modello che non c'è, o che non è delle lettere: 404.
    $this->actingAs($this->admin)->delete(route('impostazioni.stampe.modelli-lettere.destroy', ['modello' => $m->id]))->assertNotFound();
    $altro = ModelloTesto::create(['ambito' => 'comunicazione', 'tipo' => 'avviso', 'nome' => 'Avviso', 'testo' => 'X']);
    $this->actingAs($this->admin)->put(route('impostazioni.stampe.modelli-lettere.update', ['modello' => $altro->id]), ['nome' => 'Y', 'testo' => 'Y', 'condominio_id' => null])->assertNotFound();
    $this->actingAs($this->admin)->delete(route('impostazioni.stampe.modelli-lettere.destroy', ['modello' => $altro->id]))->assertNotFound();
    expect(collect(mltPagina($this)['modelli'])->pluck('nome')->all())->toBe(['Sollecito per Le Rose (copia)']);
});

it('il testo: vuoto no, oltre 5000 caratteri no, il promemoria senza [termine]; un tipo o un condominio che non ci sono no', function () {
    mltNuovo($this, ['tipo' => 'sollecito', 'nome' => 'Vuoto', 'testo' => '   '])->assertSessionHasErrors('testo');
    mltNuovo($this, ['tipo' => 'sollecito', 'nome' => 'Lungo', 'testo' => str_repeat('a', TestiLettere::LUNGHEZZA_MASSIMA + 1)])->assertSessionHasErrors('testo');
    mltNuovo($this, ['tipo' => 'promemoria', 'nome' => 'Con termine', 'testo' => 'Versi entro [termine].'])
        ->assertSessionHasErrors(['testo' => \App\Services\Gestionale\Lettere\EmissioneLettere::PROMEMORIA_SENZA_TERMINE]);
    mltNuovo($this, ['tipo' => 'avviso', 'nome' => 'Tipo strano', 'testo' => 'X'])->assertSessionHasErrors('tipo');
    mltNuovo($this, ['tipo' => 'sollecito', 'nome' => '', 'testo' => 'X'])->assertSessionHasErrors('nome');
    mltNuovo($this, ['tipo' => 'sollecito', 'nome' => str_repeat('n', 121), 'testo' => 'X'])->assertSessionHasErrors('nome');
    mltNuovo($this, ['tipo' => 'sollecito', 'nome' => 'Fuori', 'testo' => 'X', 'condominio_id' => 999999])->assertSessionHasErrors('condominio_id');
    expect(ModelloTesto::count())->toBe(0);

    // Il testo si salva come si salva quello di una lettera: senza gli spazi in testa e in coda, con gli a capo «\n».
    mltNuovo($this, ['tipo' => 'sollecito', 'nome' => 'Giusto', 'testo' => "  Gentile [nome],\r\nrestano [totale].  "])->assertSessionHasNoErrors();
    expect(ModelloTesto::sole()->testo)->toBe("Gentile [nome],\nrestano [totale].");
});

it('aperta e rimandata senza toccare niente, un modello resta com\'era', function () {
    mltNuovo($this, ['tipo' => 'diffida', 'nome' => 'Diffida «ferma»', 'testo' => "Gentile [nome],\n\nentro [termine], per € 10,00 e oltre.", 'condominio_id' => $this->verdi->id])->assertSessionHasNoErrors();
    $ricevuto = collect(mltPagina($this)['modelli'])->sole();
    $prima = ModelloTesto::sole()->only(['ambito', 'tipo', 'nome', 'testo', 'condominio_id']);

    $this->actingAs($this->admin)->put(route('impostazioni.stampe.modelli-lettere.update', ['modello' => $ricevuto['id']]),
        ['nome' => $ricevuto['nome'], 'testo' => $ricevuto['testo'], 'condominio_id' => $ricevuto['condominio']['id'] ?? null])->assertSessionHasNoErrors();
    expect(ModelloTesto::sole()->only(['ambito', 'tipo', 'nome', 'testo', 'condominio_id']))->toBe($prima);
});

it('cancellato il condominio, i suoi modelli se ne vanno e quelli per tutti restano; cancellato l\'utente, il modello resta senza il suo nome', function () {
    $condominio = Condominio::factory()->create(['nome' => 'Da togliere']);
    ModelloTesto::create(['ambito' => 'lettera', 'tipo' => 'sollecito', 'nome' => 'Suo', 'testo' => 'X', 'condominio_id' => $condominio->id]);
    $autore = User::factory()->create();
    ModelloTesto::create(['ambito' => 'lettera', 'tipo' => 'sollecito', 'nome' => 'Per tutti', 'testo' => 'X', 'created_by' => $autore->id, 'updated_by' => $autore->id]);

    DB::table('condomini')->where('id', $condominio->id)->delete();
    DB::table('users')->where('id', $autore->id)->delete();

    $rimasto = ModelloTesto::sole();
    expect($rimasto->nome)->toBe('Per tutti')->and($rimasto->created_by)->toBeNull()->and($rimasto->updated_by)->toBeNull()
        ->and(collect(mltPagina($this)['modelli'])->sole()['cambiato_da'])->toBeNull();
});

it('«vale per» va detto: un modello nuovo o cambiato senza la chiave del condominio si rifiuta, invece di diventare un modello per tutti', function () {
    $m = ModelloTesto::create(['ambito' => 'lettera', 'tipo' => 'sollecito', 'nome' => 'Solo Le Rose', 'testo' => 'Gentile [nome].', 'condominio_id' => $this->rose->id]);
    $this->actingAs($this->admin)->put(route('impostazioni.stampe.modelli-lettere.update', ['modello' => $m->id]), ['nome' => 'Solo Le Rose', 'testo' => 'Gentile [nome].'])
        ->assertSessionHasErrors(['condominio_id' => 'Scegli per quali condomini vale il modello: tutti, o uno.']);
    expect($m->fresh()->condominio_id)->toBe($this->rose->id);
    $this->actingAs($this->admin)->post(route('impostazioni.stampe.modelli-lettere.store'), ['tipo' => 'diffida', 'nome' => 'Senza vale per', 'testo' => 'X'])
        ->assertSessionHasErrors('condominio_id');
    expect(ModelloTesto::where('nome', 'Senza vale per')->exists())->toBeFalse();
    // Con la chiave e il valore nullo: per tutti, scelto.
    $this->actingAs($this->admin)->post(route('impostazioni.stampe.modelli-lettere.store'), ['tipo' => 'diffida', 'nome' => 'Per tutti', 'testo' => 'X', 'condominio_id' => null])
        ->assertSessionHasNoErrors();
    expect(ModelloTesto::where('nome', 'Per tutti')->sole()->condominio_id)->toBeNull();
});
