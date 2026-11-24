<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Anagrafica;
use App\Models\Immobile;
use App\Models\User;
use App\Support\EtichettaDelPiano;
use Database\Seeders\CondominioStampeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Le stampe date ai condòmini: la ripartizione spese e lo scadenziario per unità (1.12.0-beta.14, Coda 756, decisione 696 D4).
 *
 * Fino alla beta.13 il foglio aveva una riga per unità, con il nome del primo soggetto letto e la somma di tutti: l'Appartamento 7
 * portava «Luca Toscani» (l'inquilino) con € 1.207,22, che sono € 211,86 della proprietaria e € 995,36 suoi. Ora ogni soggetto ha la
 * sua riga dentro l'unità, con il suo ruolo, e il totale dell'unità sta accanto. Le prove leggono il PDF vero con `pdftotext`, come lo
 * leggerebbe un condòmino.
 */
function ripartizioneAdmin(): User
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);

    return $user;
}

function ripartizioneTesto(string $pdf): string
{
    $file = tempnam(sys_get_temp_dir(), 'rip');
    file_put_contents($file, $pdf);
    $testo = (string) shell_exec('pdftotext -layout '.escapeshellarg($file).' -');
    @unlink($file);

    return $testo;
}

/** @return array{0: \App\Models\Condominio, 1: PianoRate, 2: PianoConto, 3: \App\Models\Esercizio} */
function ripartizioneContesto(): array
{
    $seeder = new CondominioStampeSeeder();
    $seeder->run();
    $condominio = $seeder->condominio();
    $piano = $seeder->pianoRate();
    $pianoConto = PianoConto::where('gestione_id', $piano->gestione_id)->first();
    $esercizio = $condominio->esercizi()->latest('id')->first();

    return [$condominio, $piano, $pianoConto, $esercizio];
}

function stampaRipartizione($test, $condominio, $esercizio, $pianoConto): string
{
    $risposta = $test->actingAs(ripartizioneAdmin())
        ->get(route('admin.gestionale.esercizi.piani-conti.print-riparto', [$condominio->id, $esercizio->id, $pianoConto->id]));
    $risposta->assertOk();

    return ripartizioneTesto($risposta->getContent());
}

function stampaScadenziarioUnita($test, $condominio, $esercizio, $piano): string
{
    $risposta = $test->actingAs(ripartizioneAdmin())
        ->get(route('admin.gestionale.esercizi.piani-rate.print-scadenziario', [$condominio->id, $esercizio->id, $piano->id, 'modalita' => 'immobile']));
    $risposta->assertOk();

    return ripartizioneTesto($risposta->getContent());
}

/** Le righe del testo che contengono la frase, per leggere «una riga, una persona». */
function righeConLaFrase(string $testo, string $frase): array
{
    return array_values(array_filter(explode("\n", $testo), fn ($r) => str_contains($r, $frase)));
}

test('la ripartizione spese: l\'Appartamento 7 ha una riga per persona, con la sua parte e il totale dell\'unità', function () {
    [$condominio, , $pianoConto, $esercizio] = ripartizioneContesto();

    $testo = stampaRipartizione($this, $condominio, $esercizio, $pianoConto);

    $pagliaro = righeConLaFrase($testo, 'Antonietta Pagliaro');
    $toscani = righeConLaFrase($testo, 'Luca Toscani');

    expect($testo)->toContain('PER UNITÀ IMMOBILIARE E PER SOGGETTO')
        ->and($testo)->not->toContain('Condòmino / Intestatario')
        // la proprietaria legge la sua parte, e solo quella
        ->and(implode("\n", $pagliaro))->toContain('€ 211,86')
        // l'inquilino legge la sua, € 995,36, non la somma
        ->and(implode("\n", $toscani))->toContain('€ 995,36')
        // il totale dell'unità sta accanto
        ->and($testo)->toContain('€ 1.207,22')
        // il totale del foglio non cambia
        ->and($testo)->toContain('€ 18.350,00');

    // le righe di Luca Toscani e di Antonietta Pagliaro portano il loro ruolo, I e P
    $rigaDiLuca = collect($toscani)->first(fn ($r) => str_contains($r, '995,36'));
    expect($rigaDiLuca)->not->toBeNull()
        ->and($rigaDiLuca)->toMatch('/\bI\b/');
    $rigaDiAntonietta = collect($pagliaro)->first(fn ($r) => str_contains($r, '211,86'));
    expect($rigaDiAntonietta)->toMatch('/\bP\b/');

    // nessuna riga dà all'inquilino la somma come se fosse sua: la riga con «Luca Toscani» e «1.207,22» come unica cifra non c'è
    expect(collect($toscani)->contains(fn ($r) => str_contains($r, '1.207,22') && ! str_contains($r, '995,36')))->toBeFalse();
});

test('la ripartizione spese: il Box 5 porta le due parti, la mia e quella dell\'altro', function () {
    [$condominio, , $pianoConto, $esercizio] = ripartizioneContesto();

    $testo = stampaRipartizione($this, $condominio, $esercizio, $pianoConto);

    expect($testo)->toContain('€ 32,81')   // Antonietta Pagliaro, proprietaria
        ->and($testo)->toContain('€ 30,26') // Luca Toscani, inquilino
        ->and($testo)->toContain('€ 63,07'); // il totale dell'unità
});

test('lo scadenziario per unità ha la stessa forma: una riga per persona, senza «Intestatario»', function () {
    [$condominio, $piano, , $esercizio] = ripartizioneContesto();

    $testo = stampaScadenziarioUnita($this, $condominio, $esercizio, $piano);

    expect($testo)->not->toContain('Intestatario: Luca Toscani')
        ->and(implode("\n", righeConLaFrase($testo, 'Antonietta Pagliaro')))->toContain('€ 211,86')
        ->and(implode("\n", righeConLaFrase($testo, 'Luca Toscani')))->toContain('€ 995,36')
        ->and($testo)->toContain('€ 1.207,22')
        ->and($testo)->toContain('€ 18.350,00')
        ->and($testo)->toContain('PER UNITÀ IMMOBILIARE');
});

test('il piano dell\'edificio non è preceduto da «Piano» quando il campo lo dice già, in nessuna delle quattro stampe', function () {
    [$condominio, $piano, $pianoConto, $esercizio] = ripartizioneContesto();

    expect(ripartizioneTesto(
        $this->actingAs(ripartizioneAdmin())->get(route('admin.gestionale.esercizi.piani-conti.print-riparto', [$condominio->id, $esercizio->id, $pianoConto->id]))->getContent()
    ))->not->toContain('Piano Piano')->and(stampaScadenziarioUnita($this, $condominio, $esercizio, $piano))->not->toContain('Piano Piano');

    foreach (['print-riparto-tabelle', 'print-riparto-capitoli'] as $rotta) {
        $testo = ripartizioneTesto($this->actingAs(ripartizioneAdmin())
            ->get(route('admin.gestionale.esercizi.piani-rate.'.$rotta, [$condominio->id, $esercizio->id, $piano->id]))->getContent());
        expect($testo)->not->toContain('Piano Piano');
    }
});

test('EtichettaDelPiano: una regola sola per le quattro stampe', function () {
    expect(EtichettaDelPiano::per('Piano terra'))->toBe('Piano terra')
        ->and(EtichettaDelPiano::per('Primo piano'))->toBe('Primo piano')
        ->and(EtichettaDelPiano::per('1'))->toBe('piano 1')
        ->and(EtichettaDelPiano::per('Interrato'))->toBe('piano Interrato')
        ->and(EtichettaDelPiano::per('  '))->toBeNull()
        ->and(EtichettaDelPiano::per(null))->toBeNull();
});

test('con un secondo piano nella gestione, ogni rata ha la sua colonna e la sua data: la 1ª rata del primo piano non cambia', function () {
    [$condominio, $piano, $pianoConto, $esercizio] = ripartizioneContesto();

    $secondo = PianoRate::create(['gestione_id' => $piano->gestione_id, 'condominio_id' => $condominio->id, 'nome' => 'Integrativo 2026', 'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 1]);
    $rata = Rata::create(['piano_rate_id' => $secondo->id, 'numero_rata' => 1, 'data_scadenza' => '2026-06-30', 'importo_totale' => 10000]);
    $quota = RataQuote::whereIn('rata_id', $piano->rate()->pluck('id'))->first();
    RataQuote::create(['rata_id' => $rata->id, 'anagrafica_id' => $quota->anagrafica_id, 'immobile_id' => $quota->immobile_id, 'importo' => 10000, 'importo_pagato' => 0, 'stato' => 'da_pagare']);

    $testo = stampaRipartizione($this, $condominio, $esercizio, $pianoConto);

    expect($testo)->toContain('30/06/2026')
        ->and($testo)->toContain('Integrativo 2026')
        ->and($testo)->toContain('05/01/2026')
        // la colonna del 05/01 è quella di prima: € 9.175,11, non € 9.275,11
        ->and($testo)->toContain('€ 9.175,11')
        ->and($testo)->not->toContain('€ 9.275,11')
        // il totale del foglio è la somma dei due piani
        ->and($testo)->toContain('€ 18.450,00');
});

test('le quote annullate non entrano nelle due stampe', function () {
    [$condominio, $piano, $pianoConto, $esercizio] = ripartizioneContesto();

    RataQuote::whereIn('rata_id', $piano->rate()->pluck('id'))->whereHas('immobile', fn ($q) => $q->where('nome', 'like', '%Cantina 1'))
        ->update(['stato' => 'annullata']);

    // la Cantina 1 non c'è più (Martina Ricci ha anche altre unità, che restano), e il totale del foglio perde la sua parte
    $perdita = (int) RataQuote::whereIn('rata_id', $piano->rate()->pluck('id'))->where('stato', 'annullata')->sum('importo');
    expect($perdita)->toBe(747);

    foreach ([stampaRipartizione($this, $condominio, $esercizio, $pianoConto), stampaScadenziarioUnita($this, $condominio, $esercizio, $piano)] as $testo) {
        expect($testo)->not->toContain('Cantina 1')->not->toContain('S1-CA01')->toContain('€ 18.342,53');
    }
});

test('un\'unità in usufrutto: proprietario, nudo proprietario e usufruttuario hanno una riga ciascuno, con il loro ruolo', function () {
    [$condominio, $piano, $pianoConto, $esercizio] = ripartizioneContesto();

    $unita = Immobile::where('condominio_id', $condominio->id)->where('nome', 'like', '%Cantina 1')->first();
    DB::table('anagrafica_immobile')->where('immobile_id', $unita->id)->delete();
    $persone = [];
    foreach ([['Bianca Verdi', 'proprietario'], ['Carlo Neri', 'nuda_proprietario'], ['Dora Gialli', 'usufruttuario']] as $i => [$nome, $ruolo]) {
        $persone[$nome] = Anagrafica::create(['nome' => $nome, 'indirizzo' => 'Via dei Platani 12', 'email' => 'usu'.$i.'@kondomanager.test', 'codice_fiscale' => 'USUF0'.$i.'A01Z999X']);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persone[$nome]->id, 'immobile_id' => $unita->id, 'tipologia' => $ruolo, 'quota' => 100, 'attivo' => true, 'data_inizio' => now()->subYears(3), 'created_at' => now(), 'updated_at' => now()]);
    }
    $quote = RataQuote::whereIn('rata_id', $piano->rate()->pluck('id'))->where('immobile_id', $unita->id)->get();
    foreach ($quote as $q) {
        $q->delete();
    }
    foreach ($piano->rate as $rata) {
        $parti = ['Bianca Verdi' => 1000, 'Carlo Neri' => 2000, 'Dora Gialli' => 3000];
        foreach ($parti as $nome => $importo) {
            RataQuote::create(['rata_id' => $rata->id, 'anagrafica_id' => $persone[$nome]->id, 'immobile_id' => $unita->id, 'importo' => $importo, 'importo_pagato' => 0, 'stato' => 'da_pagare']);
        }
    }

    foreach ([stampaRipartizione($this, $condominio, $esercizio, $pianoConto), stampaScadenziarioUnita($this, $condominio, $esercizio, $piano)] as $testo) {
        $verdi = righeConLaFrase($testo, 'Bianca Verdi')[0] ?? '';
        $neri = righeConLaFrase($testo, 'Carlo Neri')[0] ?? '';
        $gialli = righeConLaFrase($testo, 'Dora Gialli')[0] ?? '';
        expect($verdi)->toMatch('/\bP\b/')->toContain('€ 20,00')   // 2 rate da € 10,00
            ->and($neri)->toMatch('/\bNP\b/')->toContain('€ 40,00')
            ->and($gialli)->toMatch('/\bU\b/')->toContain('€ 60,00')
            ->and($testo)->toContain('€ 120,00');  // il totale dell'unità
    }
});

test('un passaggio nell\'anno: la riga del ruolo di allora dal riparto del piano, non quello di oggi', function () {
    [$condominio, $piano, $pianoConto, $esercizio] = ripartizioneContesto();

    // L'Appartamento 7: nel riparto del piano Luca Toscani era inquilino; oggi la pivot dice proprietario.
    $unita = Immobile::where('condominio_id', $condominio->id)->where('nome', 'like', '%Appartamento 7')->first();
    $luca = Anagrafica::where('nome', 'Luca Toscani')->first();
    DB::table('anagrafica_immobile')->where('immobile_id', $unita->id)->where('anagrafica_id', $luca->id)->update(['tipologia' => 'proprietario']);
    DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->where('immobile_id', $unita->id)->where('anagrafica_id', $luca->id)->update(['ruolo_risolto' => 'inquilino']);

    $riga = collect(righeConLaFrase(stampaRipartizione($this, $condominio, $esercizio, $pianoConto), 'Luca Toscani'))->first(fn ($r) => str_contains($r, '995,36'));

    expect($riga)->not->toBeNull()->and($riga)->toMatch('/\bI\b/')->and($riga)->not->toMatch('/\bP\b/');
});

test('l\'elenco vuoto non chiede ciò che non serve: né il piano approvato né le rate emesse', function () {
    [$condominio, $piano, $pianoConto, $esercizio] = ripartizioneContesto();
    RataQuote::whereIn('rata_id', $piano->rate()->pluck('id'))->delete();

    $testo = stampaRipartizione($this, $condominio, $esercizio, $pianoConto);

    expect($testo)->not->toContain('approvato')
        ->and($testo)->toContain('Nessuna rata da mostrare')
        ->and($testo)->toContain('Ricalcola');

    $vista = view('pdf.gestionale.riparto_tabelle', [
        'condominio' => new \App\Models\Condominio(['nome' => 'Condominio di prova', 'indirizzo' => 'Via di prova 1', 'codice_fiscale' => '91000000009']),
        'esercizio'  => new \App\Models\Esercizio(['nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']),
        'pianoRate'  => new PianoRate(['nome' => 'Piano rate 2026', 'stato' => 'bozza']),
        'matrice' => ['tabelle' => [], 'righe' => [], 'gran_totale' => 0, 'tot_per_tabella' => [], 'tot_per_capitolo' => [], 'tot_quota_per_tabella' => [], 'fonte' => ['non_si_genera' => null]],
        'nTabelle' => 0, 'nota_legale_stampe' => '', 'firma_stampe_absolute_path' => null,
    ])->render();

    expect($vista)->not->toContain('Verificare')->not->toContain('abbia rate emesse')->toContain('Nessuna spesa da ripartire');
});

test('la catena: le quote del piano, il foglio dato ai condòmini, le stesse cifre per soggetto nelle due stampe', function () {
    [$condominio, $piano, $pianoConto, $esercizio] = ripartizioneContesto();

    $quote = RataQuote::whereIn('rata_id', $piano->rate()->pluck('id'))->whereHas('immobile', fn ($q) => $q->where('nome', 'like', '%Appartamento 7'))->get();
    $perNome = $quote->groupBy(fn ($q) => $q->anagrafica->nome)->map(fn ($g) => (int) $g->sum('importo'));

    expect($perNome['Antonietta Pagliaro'])->toBe(21186)->and($perNome['Luca Toscani'])->toBe(99536);

    foreach ([stampaRipartizione($this, $condominio, $esercizio, $pianoConto), stampaScadenziarioUnita($this, $condominio, $esercizio, $piano)] as $testo) {
        expect($testo)->toContain('€ 211,86')->toContain('€ 995,36')->toContain('€ 1.207,22')->toContain('€ 18.350,00');
    }
});
