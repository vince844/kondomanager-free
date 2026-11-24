<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission as Permesso;
use App\Models\Assemblee\CollegamentoAllaDelibera;
use App\Models\Assemblee\Delibera;
use App\Models\Condominio;
use App\Models\Gestionale\AccordoRientro;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\DispensaSeiMesi;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RegolaRecupero;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\User;
use App\Services\Assemblee\DeliberaAMano;
use App\Services\Assemblee\DeliberaInParole;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

require_once __DIR__ . '/../Motore/ViaRoma12.php';
require_once __DIR__ . '/../../Gestionale/RendicontoViaRomaScenario.php';

/**
 * 1.12.0-beta.13 (Coda 679, §1.4 e §3 P11 del progetto della tappa 2): «Scegli la delibera» nelle pagine di oggi, dalla rotta con `delibera_id`. Per ogni
 * pagina le colonne di oggi si scrivono con il giorno della riunione e il suo verbale (R1: restano la verità dei motori), e la scheda si lega alla delibera
 * con il ruolo della tabella del §1.4: la dispensa e la sua revoca, l'autorizzazione dell'accordo, la fonte della regola del recupero, la base della spesa
 * del ritardo, la deroga dello sblocco del fondo (la frase in testa alla motivazione, il testo di prima dopo), la nota del già versato, la causale del
 * giroconto. Una delibera di un altro condominio è 404, e niente si scrive (R18).
 *
 * Che cosa non copre: le pagine a video (i componenti hanno i loro test vitest); le pagine senza la delibera, che lavorano come prima (le provano i test di
 * oggi delle stesse rotte); chi non ha «Gestisce le assemblee» con una delibera nella richiesta (403, la regola è una riga sola in `DeliberaNelleSchede`).
 */

beforeEach(function () {
    $this->withoutVite();
});

/** L'amministratore delle prove, con i permessi delle assemblee. */
function apdUtente(): User
{
    $u = be52Utente();
    // «Modifica condomini»: la regola del recupero si scrive solo con quel permesso (Coda 755 (2)).
    foreach ([Permesso::VIEW_ASSEMBLEE, Permesso::MANAGE_ASSEMBLEE, Permesso::EDIT_CONDOMINI] as $p) {
        Permission::firstOrCreate(['name' => $p->value, 'guard_name' => 'web']);
        $u->givePermissionTo($p->value);
    }
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    return $u;
}

/** Una delibera registrata a mano nel condominio, approvata. */
function apdDelibera(Condominio $c, User $u, string $giorno, string $testo, ?string $verbale = '41'): Delibera
{
    return DeliberaAMano::registra($c, ['giorno' => $giorno, 'testo' => $testo, 'esito' => 'approvata', 'numero_verbale' => $verbale], null, $u);
}

/** Il collegamento di una scheda per quel ruolo. */
function apdCollegamento(string $tipo, int $id, string $ruolo): ?CollegamentoAllaDelibera
{
    return CollegamentoAllaDelibera::query()->where('oggetto_tipo', $tipo)->where('oggetto_id', $id)->where('ruolo', $ruolo)->first();
}

it('la dispensa dalla delibera: la data e il verbale della riunione, il legame «dispensa»; la revoca dalla sua delibera, il legame «revoca della dispensa»', function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-19 10:00', 'Europe/Rome'));
    $this->user = apdUtente();
    $s = be52Condominio();
    $delibera = apdDelibera($s['c'], $this->user, '2026-10-22', 'Dispensa dall\'agire entro sei mesi per i crediti del 2026');

    $this->actingAs($this->user)->post(route('admin.gestionale.dispense.store', ['condominio' => $s['c']->id]), [
        'delibera_id' => $delibera->id, 'esercizi' => [(int) $s['e']->id], 'per_tutti' => '1', 'nota' => 'Per i crediti del 2026',
    ])->assertSessionHasNoErrors();
    $dispensa = DispensaSeiMesi::query()->where('condominio_id', $s['c']->id)->firstOrFail();
    expect($dispensa->data_delibera->format('Y-m-d'))->toBe('2026-10-22')
        ->and($dispensa->numero_verbale)->toBe('41')
        ->and((int) apdCollegamento('dispensa_sei_mesi', $dispensa->id, 'dispensa')?->delibera_id)->toBe((int) $delibera->id);

    $revoca = apdDelibera($s['c'], $this->user, '2026-11-10', 'Revoca della dispensa dei sei mesi', '42');
    $this->actingAs($this->user)->post(route('admin.gestionale.dispense.revoca', ['condominio' => $s['c']->id, 'dispensa' => $dispensa->id]), [
        'delibera_id' => $revoca->id, 'nota_revoca' => 'Dall\'assemblea del 10/11',
    ])->assertSessionHasNoErrors();
    expect($dispensa->fresh()->revocata_il->format('Y-m-d'))->toBe('2026-11-10')
        ->and((int) apdCollegamento('dispensa_sei_mesi', $dispensa->id, 'revoca_della_dispensa')?->delibera_id)->toBe((int) $revoca->id);
});

it('l\'autorizzazione dell\'accordo arrivata dopo, dalla delibera: autorizzato, la data e il verbale della riunione, il legame «autorizzazione»', function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-19 10:00', 'Europe/Rome'));
    $this->user = apdUtente();
    $s = be52Condominio();
    be52Unita($s, 'A', 'ugo', $s['tabella'], 1000.0);
    $accordo = AccordoRientro::create(['condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'data_accordo' => '2026-09-01',
        'fino_al' => '2027-03-01', 'autorizzato_assemblea' => false]);
    $delibera = apdDelibera($s['c'], $this->user, '2026-10-22', 'Autorizzazione dell\'accordo di rientro di Ugo');

    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.autorizza', ['condominio' => $s['c']->id, 'accordo' => $accordo->id]), [
        'delibera_id' => $delibera->id,
    ])->assertOk();
    $accordo->refresh();
    expect((bool) $accordo->autorizzato_assemblea)->toBeTrue()
        ->and($accordo->data_autorizzazione->format('Y-m-d'))->toBe('2026-10-22')
        ->and($accordo->verbale_autorizzazione)->toBe('41')
        ->and((int) apdCollegamento('accordo_rientro', $accordo->id, 'autorizzazione')?->delibera_id)->toBe((int) $delibera->id);
});

it('la regola del recupero con il tasso da una delibera: la fonte «delibera», il riferimento è la frase della delibera, il legame «fonte»', function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-19 10:00', 'Europe/Rome'));
    $this->user = apdUtente();
    $s = be52Condominio();
    $delibera = apdDelibera($s['c'], $this->user, '2026-10-22', 'Interessi di mora al 3% l\'anno');

    $this->actingAs($this->user)->postJson(route('admin.gestionale.recupero-crediti.store', [$s['c']]), [
        'dal' => '2026-12-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '3', 'fonte' => 'delibera', 'riferimento' => null, 'delibera_id' => $delibera->id,
    ])->assertSessionHasNoErrors();
    $regola = RegolaRecupero::query()->where('condominio_id', $s['c']->id)->firstOrFail();
    expect($regola->fonte)->toBe('delibera')
        ->and($regola->riferimento)->toBe(DeliberaInParole::frase($delibera))
        ->and($regola->riferimento)->toStartWith('Delibera del 22/10/2026')
        ->and((int) apdCollegamento('regola_recupero', $regola->id, 'fonte')?->delibera_id)->toBe((int) $delibera->id);
});

it('lo sblocco d\'emergenza di un fondo dalla delibera: la frase in testa alla motivazione, il testo di prima dopo, il legame «deroga»; in modifica non si ripete', function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-19 10:00', 'Europe/Rome'));
    $this->user = apdUtente();
    $s = be52Condominio();
    $delibera = apdDelibera($s['c'], $this->user, '2026-10-22', 'Uso del fondo lavori per le spese urgenti');
    $frase = DeliberaInParole::frase($delibera);

    $this->actingAs($this->user)->post(route('admin.gestionale.casse.store', $s['c']), [
        'nome' => 'Fondo facciata', 'tipo' => 'fondo', 'sottotipo_fondo' => 'vincolato_lavori', 'saldo_iniziale' => '0,00', 'vincolo_descrizione' => 'Facciata',
        'is_override_assemblea' => true, 'motivazione_override' => 'art. 5 del regolamento', 'delibera_id' => $delibera->id,
    ])->assertSessionHasNoErrors();
    $fondo = Cassa::query()->where('condominio_id', $s['c']->id)->where('nome', 'Fondo facciata')->firstOrFail();
    expect($fondo->motivazione_override)->toBe($frase . ' — art. 5 del regolamento')
        ->and((int) apdCollegamento('cassa', $fondo->id, 'deroga')?->delibera_id)->toBe((int) $delibera->id);

    // Salvato di nuovo con il testo che la pagina mostra (senza la frase), la frase resta una volta sola, in testa.
    $this->actingAs($this->user)->put(route('admin.gestionale.casse.update', [$s['c'], $fondo]), [
        'nome' => 'Fondo facciata', 'tipo' => 'fondo', 'sottotipo_fondo' => 'vincolato_lavori', 'vincolo_descrizione' => 'Facciata',
        'is_override_assemblea' => true, 'motivazione_override' => $frase . ' — art. 5 del regolamento', 'delibera_id' => $delibera->id,
    ])->assertSessionHasNoErrors();
    expect($fondo->fresh()->motivazione_override)->toBe($frase . ' — art. 5 del regolamento');
});

it('il giroconto con la delibera accanto alla causale: la causale resta quella scritta, la scrittura si lega alla delibera con il ruolo «causale»', function () {
    vrmGiorno($this, '2026-01-02');
    $this->user = apdUtente();
    $s = be52Condominio();
    be52Unita($s, 'A', 'ugo', $s['tabella'], 1000.0);
    app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    $this->actingAs($this->user)->post(route('admin.gestionale.casse.store', $s['c']), [
        'nome' => 'Fondo riserva', 'tipo' => 'fondo', 'sottotipo_fondo' => 'generico', 'saldo_iniziale' => '0,00',
    ])->assertSessionHasNoErrors();
    $fondo = Cassa::query()->where('condominio_id', $s['c']->id)->where('nome', 'Fondo riserva')->firstOrFail();
    vrmGiorno($this, '2026-03-15');
    $delibera = apdDelibera($s['c'], $this->user, '2026-03-10', 'Accantonamento al fondo riserva di € 2.000,00');

    $this->actingAs($this->user)->post(route('admin.gestionale.giroconti.store', $s['c']), [
        'cassa_origine_id' => $s['cassa']->id, 'cassa_destinazione_id' => $fondo->id, 'importo' => 2000.00, 'data_operazione' => '2026-03-15',
        'causale' => 'Accantonamento al fondo riserva', 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'delibera_id' => $delibera->id,
    ])->assertSessionHasNoErrors();
    $scrittura = ScritturaContabile::query()->where('condominio_id', $s['c']->id)->where('causale', 'Accantonamento al fondo riserva')->firstOrFail();
    expect((int) apdCollegamento('scrittura_contabile', $scrittura->id, 'causale')?->delibera_id)->toBe((int) $delibera->id);
});

it('la spesa del ritardo fondata su una delibera: il riferimento è la frase della delibera, la quota si lega con il ruolo «base»', function () {
    $this->user = apdUtente();
    $s = vrmBase($this);
    vrmGiorno($this, '2026-12-20');
    $voce = be52Voce($this, $s, $s['pc'], 'Spese di recupero', 'entrata', '0,00');
    $delibera = apdDelibera($s['c'], $this->user, '2026-12-15', 'Addebito delle spese di sollecito ai condòmini in ritardo', '7');

    $this->actingAs($this->user)->postJson(route('admin.gestionale.addebiti.store', ['condominio' => $s['c']->id]), [
        'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'delibera_id' => $delibera->id,
        'voci' => [['anagrafica_id' => $s['persone']['ugo']->id, 'immobile_id' => $s['unita']['A']->id, 'importo' => '5,00', 'causale' => 'Spese di sollecito',
            'scadenza' => '2026-12-20', 'natura' => 'accessorio', 'tipo_accessorio' => 'sollecito', 'base' => 'delibera', 'riferimento' => null, 'conto_id' => $voce->id]],
    ])->assertCreated();
    $quota = RataQuote::query()->where('anagrafica_id', $s['persone']['ugo']->id)->get()
        ->first(fn (RataQuote $q) => (($q->regole_calcolo['addebito'] ?? [])['base'] ?? null) === 'delibera');
    expect($quota)->not->toBeNull()
        ->and($quota->regole_calcolo['addebito']['riferimento'])->toBe(DeliberaInParole::frase($delibera))
        ->and((int) apdCollegamento('rata_quote', $quota->id, 'base')?->delibera_id)->toBe((int) $delibera->id);
});

it('il già versato con la delibera, facoltativa: la frase in testa alla nota, ogni riga legata con il ruolo «nota»; salvato senza, i legami se ne vanno', function () {
    $this->user = apdUtente();
    $s = vrmBase($this);
    vrmGiorno($this, '2026-03-01');
    $delibera = apdDelibera($s['c'], $this->user, '2026-02-20', 'Fondo per le manutenzioni già versato dai condòmini');
    $corpo = fn (array $piu) => $piu + ['natura' => 'avanzo', 'vale_per' => 'tutti', 'descrizione' => 'Versato nel 2025',
        'righe' => [['immobile_id' => $s['unita']['A']->id, 'anagrafica_id' => null, 'gia_versato' => 10000],
            ['immobile_id' => $s['unita']['C']->id, 'anagrafica_id' => null, 'gia_versato' => 5000]],
        'liquidita_stato' => ContributoVersato::LIQUIDITA_GIA_IN_APERTURA, 'cassa_id' => null];

    $this->actingAs($this->user)->put(route('admin.gestionale.contributi.update', [$s['c'], $s['manutenzioni']]), $corpo(['delibera_id' => $delibera->id]))
        ->assertSessionHasNoErrors();
    $righe = ContributoVersato::query()->where('target_id', $s['manutenzioni']->id)->get();
    expect($righe)->toHaveCount(2)
        ->and($righe->pluck('descrizione')->unique()->all())->toBe([DeliberaInParole::frase($delibera) . ' — Versato nel 2025'])
        ->and($righe->filter(fn ($r) => (int) apdCollegamento('contributo_versato', $r->id, 'nota')?->delibera_id === (int) $delibera->id))->toHaveCount(2);

    $this->actingAs($this->user)->put(route('admin.gestionale.contributi.update', [$s['c'], $s['manutenzioni']]), $corpo([]))->assertSessionHasNoErrors();
    expect(CollegamentoAllaDelibera::query()->where('oggetto_tipo', 'contributo_versato')->count())->toBe(0);
});

it('una delibera di un altro condominio è 404, e la dispensa non nasce', function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-19 10:00', 'Europe/Rome'));
    $this->user = apdUtente();
    $s = be52Condominio();
    $altro = be52Condominio();
    $estranea = apdDelibera($altro['c'], $this->user, '2026-10-22', 'Dispensa di un altro condominio');

    $this->actingAs($this->user)->post(route('admin.gestionale.dispense.store', ['condominio' => $s['c']->id]), [
        'delibera_id' => $estranea->id, 'esercizi' => [(int) $s['e']->id], 'per_tutti' => '1',
    ])->assertNotFound();
    expect(DispensaSeiMesi::query()->where('condominio_id', $s['c']->id)->count())->toBe(0)
        ->and(CollegamentoAllaDelibera::query()->count())->toBe(0);
});

it('il fondo nuovo con una delibera che non si sceglie più (revocata fra il menu e il salvataggio): la frase accanto al campo, e il fondo non nasce', function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-19 10:00', 'Europe/Rome'));
    $this->user = apdUtente();
    $s = be52Condominio();
    $delibera = apdDelibera($s['c'], $this->user, '2026-10-22', 'Uso del fondo lavori per le spese urgenti');
    $delibera->forceFill(['revocata_il' => '2026-11-18'])->saveQuietly();

    $this->actingAs($this->user)->from('/casse/nuovo')->post(route('admin.gestionale.casse.store', $s['c']), [
        'nome' => 'Fondo facciata', 'tipo' => 'fondo', 'sottotipo_fondo' => 'vincolato_lavori', 'saldo_iniziale' => '0,00', 'vincolo_descrizione' => 'Facciata',
        'is_override_assemblea' => true, 'motivazione_override' => 'art. 5 del regolamento', 'delibera_id' => $delibera->id,
    ])->assertRedirect('/casse/nuovo')->assertSessionHasErrors(['delibera_id' => 'Questa delibera non si sceglie: revocata il 18/11/2026.']);
    expect(Cassa::query()->where('condominio_id', $s['c']->id)->where('nome', 'Fondo facciata')->exists())->toBeFalse();
});

it('chi ha solo «Vede le assemblee» registra la dispensa con i campi a mano; con una delibera nella richiesta è 403, e la dispensa non nasce', function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-19 10:00', 'Europe/Rome'));
    $gestisce = apdUtente();
    $s = be52Condominio();
    $delibera = apdDelibera($s['c'], $gestisce, '2026-10-22', 'Dispensa dall\'agire entro sei mesi per i crediti del 2026');
    $vede = be52Utente();
    Permission::firstOrCreate(['name' => Permesso::VIEW_ASSEMBLEE->value, 'guard_name' => 'web']);
    $vede->givePermissionTo(Permesso::VIEW_ASSEMBLEE->value);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $this->actingAs($vede)->post(route('admin.gestionale.dispense.store', ['condominio' => $s['c']->id]), [
        'delibera_id' => $delibera->id, 'esercizi' => [(int) $s['e']->id], 'per_tutti' => '1',
    ])->assertForbidden();
    expect(DispensaSeiMesi::query()->where('condominio_id', $s['c']->id)->count())->toBe(0);

    $this->actingAs($vede)->post(route('admin.gestionale.dispense.store', ['condominio' => $s['c']->id]), [
        'data_delibera' => '2026-10-22', 'numero_verbale' => '41', 'esercizi' => [(int) $s['e']->id], 'per_tutti' => '1',
    ])->assertSessionHasNoErrors();
    $dispensa = DispensaSeiMesi::query()->where('condominio_id', $s['c']->id)->firstOrFail();
    expect($dispensa->data_delibera->format('Y-m-d'))->toBe('2026-10-22')->and($dispensa->numero_verbale)->toBe('41')
        ->and(apdCollegamento('dispensa_sei_mesi', $dispensa->id, 'dispensa'))->toBeNull();
});
