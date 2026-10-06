<?php

/**
 * B2, S3 — «Registra passaggio»: la pagina, l'anteprima «Cosa cambierà» e le rotte per periodo.
 *
 * Progetto `docs/subentro_e_competenza_temporale.md` (decisioni 13 e 14) e §6 di
 * `docs/pertinenze_vendita_locazione.md`, piano `docs/piano_esecutivo_beta31_b2.md` (S3).
 *
 * Cosa si prova qui:
 * - la pagina rende con i **titolari attuali** e il tipo di passaggio scelto;
 * - l'anteprima risponde con i blocchi 1 (anagrafica), 3 (chi resta obbligato) e 4 (cosa non cambia),
 *   e con il blocco 2 (rate emesse) in stato «in arrivo»: elenca le rate, **non** mostra un conguaglio
 *   — mai uno zero al posto di un numero che non c'è — finché S5 non lo collega;
 * - il cancello (1) scatta con rate emesse **o** con quote in un piano anche in bozza (decisione 14);
 * - «Dissocia» e «Modifica» con cambio persona rifiutano con 422 una riga con storia (decisione 13);
 * - `edit`/`update`/`destroy` ricevono l'`id` della riga, non della persona (`TitolaritaImmobile`).
 *
 * Da S5 si provano anche i numeri del conguaglio nell'anteprima (blocco 2 vero, `ConguaglioPassaggio`)
 * e le guardie per giorno; la scrittura del passaggio (`store`) sta in `RegistraSubentroTest`.
 */

require_once __DIR__.'/GestionaleTestHelpers.php';

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);

    $this->condominio = Condominio::factory()->create();
    $this->esercizio = Esercizio::factory()->create([
        'condominio_id' => $this->condominio->id,
        'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto',
    ]);
    $this->gestione = Gestione::factory()->create([
        'condominio_id' => $this->condominio->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria',
    ]);
    // Nel prodotto una gestione nasce dentro un esercizio: senza il legame la competenza del piano è
    // nulla e il conguaglio non si può calcolare (lo dice, non inventa: vedi il test dedicato).
    legaAEsercizio($this->esercizio, $this->gestione->id);

    $this->immobile = Immobile::forceCreate([
        'condominio_id' => $this->condominio->id, 'nome' => 'Interno 3', 'descrizione' => 'Appartamento', 'interno' => '3',
    ]);
});

/** Una persona del condominio, con nome fisso perché le frasi dell'anteprima la nominano. */
function personaDelCondominio(Condominio $c, string $nome): Anagrafica
{
    static $seq = 0;
    $seq++;
    $a = Anagrafica::forceCreate([
        'nome' => $nome, 'email' => "passaggio{$seq}@test.it", 'indirizzo' => 'Via Verdi 1',
        'codice_fiscale' => 'PSSGGT' . str_pad((string) $seq, 10, '0', STR_PAD_LEFT),
    ]);
    $a->condomini()->syncWithoutDetaching([$c->id]);

    return $a;
}

/** Una riga della pivot scritta direttamente: le guardie delle FormRequest qui non c'entrano. */
function rigaTitolarita(Immobile $i, Anagrafica $a, string $tipologia, string $dal, ?string $al = null, float $quota = 100.0): int
{
    return DB::table('anagrafica_immobile')->insertGetId([
        'immobile_id' => $i->id, 'anagrafica_id' => $a->id, 'tipologia' => $tipologia,
        'quota' => $quota, 'attivo' => true, 'data_inizio' => $dal, 'data_fine' => $al,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Un piano con una rata e la quota dell'unità intestata a chi si indica; `emessa` decide lo stato della rata. */
function pianoConQuotaSu(Gestione $g, Condominio $c, Immobile $i, Anagrafica $intestatario, int $importo, bool $emessa): void
{
    $pianoId = DB::table('piani_rate')->insertGetId([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'nome' => 'Piano 2026', 'numero_rate' => 1,
        'giorno_scadenza' => 30, 'metodo_distribuzione' => 'tutte_rate', 'attivo' => true,
        'stato' => $emessa ? 'approvato' : 'bozza', 'tipo' => 'ordinario', 'contesto_creazione' => 'preventivo_iniziale',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $rataId = DB::table('rate')->insertGetId([
        'piano_rate_id' => $pianoId, 'numero_rata' => 1, 'data_scadenza' => '2026-06-30',
        'data_emissione' => $emessa ? '2026-06-01' : null, 'importo_totale' => $importo,
        'stato' => $emessa ? 'emessa' : 'bozza', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('rate_quote')->insert([
        'rata_id' => $rataId, 'anagrafica_id' => $intestatario->id, 'immobile_id' => $i->id,
        'importo' => $importo, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'tipo' => 'ordinaria',
        'data_scadenza' => '2026-06-30', 'created_at' => now(), 'updated_at' => now(),
    ]);
    if ($emessa) {
        aGiornaleNeiTest($pianoId);
    }
}

function corpoAnteprimaVendita(int $rigaUscenteId, Anagrafica $entrante, array $extra = []): array
{
    return array_merge([
        'tipo' => 'vendita',
        'riga_uscente_id' => $rigaUscenteId,
        'anagrafica_entrante_id' => $entrante->id,
        'decorrenza' => '2026-05-01',
        'quota' => 100,
        'tipologia' => 'proprietario',
        'copia_autentica' => false,
        'copia_autentica_il' => null,
        'pertinenze' => [],
    ], $extra);
}

/*
|--------------------------------------------------------------------------
| La pagina
|--------------------------------------------------------------------------
*/

it('la pagina «Registra passaggio» rende con i soli titolari attuali, il tipo scelto e le persone del condominio', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    $verdi = personaDelCondominio($this->condominio, 'Verdi Luca');
    personaDelCondominio($this->condominio, 'Bianchi Anna');

    $rigaRossi = rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03');
    // Un inquilino uscito l'anno scorso: è storia, non un titolare da cui «uscire».
    rigaTitolarita($this->immobile, $verdi, 'inquilino', '2024-01-01', '2025-12-31');

    $props = $this->actingAs($this->user)
        ->get(route('admin.gestionale.immobili.passaggi.create', [$this->condominio, $this->immobile, 'tipo' => 'vendita']))
        ->assertOk()
        ->viewData('page');

    expect($props['component'])->toBe('gestionale/immobili/anagrafiche/PassaggioNew');

    $p = $props['props'];
    expect($p['tipo'])->toBe('vendita')
        ->and(collect($p['titolari'])->pluck('id')->all())->toBe([$rigaRossi])
        ->and($p['titolari'][0]['anagrafica']['nome'])->toBe('Rossi Mario')
        ->and($p['titolari'][0]['tipologia'])->toBe('proprietario')
        ->and($p['titolari'][0]['data_inizio'])->toBe('2019-03-03')
        ->and(collect($p['anagrafiche'])->pluck('nome')->sort()->values()->all())->toBe(['Bianchi Anna', 'Rossi Mario', 'Verdi Luca']);
});

it('un tipo di passaggio che non esiste non rende una pagina a caso', function () {
    $this->actingAs($this->user)
        ->get(route('admin.gestionale.immobili.passaggi.create', [$this->condominio, $this->immobile, 'tipo' => 'permuta']))
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| L'anteprima «Cosa cambierà»
|--------------------------------------------------------------------------
*/

it('l\'anteprima di una vendita risponde con i blocchi 1, 3 e 4, la frase delle due date e, senza rate emesse, il blocco 2 senza un conguaglio finto', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    $bianchi = personaDelCondominio($this->condominio, 'Bianchi Anna');
    $riga = rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03');

    $json = $this->actingAs($this->user)
        ->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), corpoAnteprimaVendita($riga, $bianchi))
        ->assertOk()
        ->json();

    // Una data sola: il giorno prima lo calcola il programma, non lo chiede (§6.3, campo 2).
    expect($json['riferimento']['uscente_fino_al'])->toBe('2026-04-30')
        ->and($json['riferimento']['entrante_dal'])->toBe('2026-05-01')
        ->and($json['riferimento']['frase'])->toBe('Rossi Mario risulterà titolare fino al 30 aprile 2026 compreso. Bianchi Anna dal 1 maggio 2026.');

    // Blocco 1.
    expect($json['anagrafica']['frasi'][0])->toContain('Bianchi Anna dal 1 maggio 2026, come proprietario al 100 %');

    // Blocco 2: nessuna rata, e il conguaglio **non c'è** — non «€ 0,00».
    expect($json['rate']['stato'])->toBe('nessuno')
        ->and($json['rate']['emesse'])->toBe([])
        ->and($json['rate']['conguaglio'])->toBeNull()
        ->and($json['rate']['frasi'][0])->toBe('Nessuna rata emessa su questa unità. Non c\'è niente da conguagliare.');

    // Blocco 3: la solidarietà dell'art. 63 co. 4 nomina i due esercizi e la regola del regresso.
    $obbligati = implode(' ', $json['obbligati']['frasi']);
    expect($obbligati)->toContain('Bianchi Anna risponde in solido con Rossi Mario')
        ->toContain('esercizio 2026')
        ->toContain('art. 63 co. 4 disp. att. c.c.')
        ->toContain('per quanto ha pagato al condominio, salvo diverso accordo fra le parti (Cass. 11199/2021)');

    // Blocco 4: quattro righe, sempre.
    expect($json['invarianti']['frasi'])->toHaveCount(4)
        ->and($json['invarianti']['frasi'][0])->toStartWith('Millesimi: invariati');

    // Nessuna rata, nessun piano: il cancello non scatta (decisione 14).
    expect($json['cancello']['richiesto'])->toBeFalse();
});

it('con rate emesse sull\'unità l\'anteprima le elenca con l\'intestatario, calcola il conguaglio per giorni sulla competenza dell\'esercizio (§6.4: € 412,00 → 120/245), e il cancello scatta', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    $bianchi = personaDelCondominio($this->condominio, 'Bianchi Anna');
    $riga = rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03');
    pianoConQuotaSu($this->gestione, $this->condominio, $this->immobile, $rossi, 41200, emessa: true);

    $json = $this->actingAs($this->user)
        ->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), corpoAnteprimaVendita($riga, $bianchi))
        ->assertOk()
        ->json();

    expect($json['rate']['emesse'])->toHaveCount(1)
        ->and($json['rate']['emesse'][0])->toMatchArray([
            'rata' => 1, 'scadenza' => '2026-06-30', 'importo' => 41200, 'importo_formattato' => '€ 412,00', 'intestatario' => 'Rossi Mario',
        ])
        ->and($json['cancello']['richiesto'])->toBeTrue()
        ->and(implode(' ', $json['cancello']['motivi']))->toMatch('/1 quota di rata già emessa a Rossi Mario/');

    // Il conguaglio (D9): 41200 × 245/365 = 27654,79 → 27655 all'entrante; una coppia sulla gestione, gradino esercizio.
    $c = $json['rate']['conguaglio'];
    expect($json['rate']['stato'])->toBe('calcolato')
        ->and($c['coppie'])->toHaveCount(1)
        ->and($c['coppie'][0])->toMatchArray(['gestione_id' => $this->gestione->id, 'immobile_id' => $this->immobile->id, 'esercizio_id' => $this->esercizio->id, 'importo' => 27655])
        ->and($c['quote'][0])->toMatchArray(['quota_pura' => 41200, 'pregresso' => 0, 'gradino' => 'esercizio', 'giorni_uscente' => 120, 'giorni_entrante' => 245, 'uscente' => 13545, 'entrante' => 27655])
        ->and($c['totale_entrante_formattato'])->toBe('€ 276,55')
        ->and(implode(' ', $json['rate']['frasi']))->toContain('Le rate già emesse non si toccano')
        ->toContain('credito € 276,55 a Rossi Mario, debito € 276,55 a Bianchi Anna')
        ->toContain('120 a Rossi Mario, 245 a Bianchi Anna');
});

it('inv. 20 — il conguaglio guarda la competenza, non i pagamenti: con la quota non pagata il conguaglio è lo stesso, e la morosità resta di chi esce; la parte da saldo pregresso non si divide', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    $bianchi = personaDelCondominio($this->condominio, 'Bianchi Anna');
    $riga = rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03');
    pianoConQuotaSu($this->gestione, $this->condominio, $this->immobile, $rossi, 41200, emessa: true);
    // La quota è scaduta e non pagata, e porta dentro un saldo pregresso di € 100,00: quota pura € 312,00.
    DB::table('rate_quote')->update([
        'data_scadenza' => '2026-03-31', 'importo_pagato' => 0,
        'regole_calcolo' => json_encode(['origine' => 'calcolo_automatico', 'importi' => ['quota_pura_gestione' => 31200, 'saldo_usato' => 10000, 'totale_calcolato' => 41200]]),
    ]);
    DB::table('rate')->update(['data_scadenza' => '2026-03-31']);

    $json = $this->actingAs($this->user)
        ->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), corpoAnteprimaVendita($riga, $bianchi))
        ->assertOk()->json();

    // 31200 × 245/365 = 20942,47 → 20942 all'entrante (resto .47 < .53 dell'uscente: 10257,53 → 10258).
    $c = $json['rate']['conguaglio'];
    expect($c['quote'][0])->toMatchArray(['quota_pura' => 31200, 'pregresso' => 10000, 'uscente' => 10258, 'entrante' => 20942])
        ->and($c['coppie'][0]['importo'])->toBe(20942)
        ->and($json['rate']['morosita'])->toMatchArray(['importo' => 41200, 'intestatario' => 'Rossi Mario'])
        ->and(implode(' ', $json['rate']['frasi']))->toContain('€ 100,00 delle quote intestate a Rossi Mario sono saldi pregressi')
        ->toContain('Rossi Mario ha € 412,00 scaduti e non pagati. Restano suoi');
});

it('una gestione senza esercizio o uno straordinario senza data della delibera non fanno inventare un periodo: quel piano si salta e il pannello lo dice (decisione 12)', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    $bianchi = personaDelCondominio($this->condominio, 'Bianchi Anna');
    $riga = rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03');
    $straordinaria = Gestione::factory()->create(['condominio_id' => $this->condominio->id, 'nome' => 'Facciata', 'tipo' => 'straordinaria']);
    legaAEsercizio($this->esercizio, $straordinaria->id);
    pianoConQuotaSu($straordinaria, $this->condominio, $this->immobile, $rossi, 120000, emessa: true); // senza data_delibera_assemblea

    $json = $this->actingAs($this->user)
        ->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), corpoAnteprimaVendita($riga, $bianchi))
        ->assertOk()->json();

    $c = $json['rate']['conguaglio'];
    expect($c['coppie'])->toBe([])
        ->and($c['non_risolte'])->toHaveCount(1)
        ->and($c['non_risolte'][0]['motivo'])->toContain('manca la data della delibera')
        ->and(implode(' ', $json['rate']['frasi']))->toContain('Piano «Piano 2026»: manca la data della delibera')
        ->toContain('Nessun conguaglio proposto su quelle quote');

    // Con la delibera prima del rogito: tutto resta al venditore, e la frase cita la data (Cass. 24236/2025).
    DB::table('piani_rate')->update(['data_delibera_assemblea' => '2026-02-12']);
    $json = $this->actingAs($this->user)
        ->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), corpoAnteprimaVendita($riga, $bianchi))
        ->assertOk()->json();
    expect($json['rate']['conguaglio']['coppie'])->toBe([])
        ->and(implode(' ', $json['rate']['frasi']))->toContain('€ 1.200,00 restano interamente a Rossi Mario, perché l\'assemblea ha deliberato il 12 febbraio 2026');
});

/** S8-4: una riga congelata dello straordinario con la competenza dichiarata sulla fattura (o la delibera), per la quota di chi esce. */
function rigaStraordinariaCongelata(Anagrafica $intestatario, Immobile $i, int $importo, string $dal, string $al, string $gradino = 'dichiarata'): void
{
    $pianoId = (int) DB::table('piani_rate')->orderByDesc('id')->value('id');
    DB::table('righe_riparto')->insert([
        'piano_rate_id' => $pianoId, 'tipo' => 'ad_personam', 'anagrafica_id' => $intestatario->id, 'immobile_id' => $i->id,
        'conto_id' => null, 'conto_nome' => 'Riparazione urgente ascensore', 'conto_radice_id' => null, 'conto_radice_nome' => 'Riparazione urgente ascensore',
        'importo' => $importo, 'competenza_dal' => $dal, 'competenza_al' => $al, 'gradino_competenza' => $gradino, 'giorni_titolarita' => null,
        'versione_calcolo' => 'test', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('S8-4 — straordinario d\'urgenza senza delibera ma con la competenza dichiarata sulla fattura: il conguaglio si calcola dalle righe congelate, per intero a chi entra se il costo matura dopo il rogito', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    $bianchi = personaDelCondominio($this->condominio, 'Bianchi Anna');
    $riga = rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03');
    $straordinaria = Gestione::factory()->create(['condominio_id' => $this->condominio->id, 'nome' => 'Ascensore', 'tipo' => 'straordinaria']);
    legaAEsercizio($this->esercizio, $straordinaria->id);
    pianoConQuotaSu($straordinaria, $this->condominio, $this->immobile, $rossi, 100001, emessa: true);
    DB::table('piani_rate')->update(['tipo' => 'straordinario', 'tipo_autorizzazione' => 'urgenza', 'data_delibera_assemblea' => null]);
    rigaStraordinariaCongelata($rossi, $this->immobile, 100001, '2026-06-01', '2026-06-15');

    $json = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), corpoAnteprimaVendita($riga, $bianchi))->assertOk()->json();
    $c = $json['rate']['conguaglio'];
    // Prima della correzione: «manca la data della delibera» e nessuna coppia; l'urgenza non ha una delibera da registrare.
    expect($c['non_risolte'])->toBe([])
        ->and($c['coppie'])->toHaveCount(1)->and($c['coppie'][0]['importo'])->toBe(100001)
        ->and($c['quote'][0])->toMatchArray(['gradino' => 'dichiarata', 'entrante' => 100001, 'uscente' => 0])
        ->and($c['quote'][0]['per_capitolo'][0])->toMatchArray(['gradino' => 'dichiarata', 'entrante' => 100001, 'periodo' => [['dal' => '2026-06-01', 'al' => '2026-06-15']]])
        ->and(implode("\n", $json['rate']['frasi']))->toContain('competenza dichiarata 1 giugno 2026–15 giugno 2026 — maturata dal giorno del passaggio in poi: la spesa è di chi entra per intero → € 1.000,01 a chi entra')
        ->not->toContain('ha deliberato')->not->toContain('manca la data della delibera');
});

it('S8-4 — competenza dichiarata a cavallo del rogito (1º aprile–31 maggio, vendita al 1º maggio): divisa per giorni, 30 a chi esce e 31 a chi entra → 50.820 (resti maggiori)', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    $bianchi = personaDelCondominio($this->condominio, 'Bianchi Anna');
    $riga = rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03');
    $straordinaria = Gestione::factory()->create(['condominio_id' => $this->condominio->id, 'nome' => 'Ascensore', 'tipo' => 'straordinaria']);
    legaAEsercizio($this->esercizio, $straordinaria->id);
    pianoConQuotaSu($straordinaria, $this->condominio, $this->immobile, $rossi, 100001, emessa: true);
    DB::table('piani_rate')->update(['tipo' => 'straordinario', 'tipo_autorizzazione' => 'urgenza', 'data_delibera_assemblea' => null]);
    rigaStraordinariaCongelata($rossi, $this->immobile, 100001, '2026-04-01', '2026-05-31');

    $json = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), corpoAnteprimaVendita($riga, $bianchi))->assertOk()->json();
    $c = $json['rate']['conguaglio'];
    expect($c['coppie'][0]['importo'])->toBe(50820)
        ->and($c['quote'][0]['per_capitolo'][0])->toMatchArray(['giorni_uscente' => 30, 'giorni_entrante' => 31, 'giorni_periodo' => 61, 'entrante' => 50820, 'uscente' => 49181])
        ->and(implode("\n", $json['rate']['frasi']))->toContain('30 giorni a Rossi Mario, 31 a Bianchi Anna → € 508,20 a chi entra');
});

it('S8-4 — con la delibera del 12 febbraio ma la fattura con competenza dichiarata a giugno vince la riga: la coppia va a chi entra e la frase non dice «ha deliberato»; una fattura senza competenza segue la delibera e resta a chi esce', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    $bianchi = personaDelCondominio($this->condominio, 'Bianchi Anna');
    $riga = rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03');
    $straordinaria = Gestione::factory()->create(['condominio_id' => $this->condominio->id, 'nome' => 'Facciata', 'tipo' => 'straordinaria']);
    legaAEsercizio($this->esercizio, $straordinaria->id);
    pianoConQuotaSu($straordinaria, $this->condominio, $this->immobile, $rossi, 150000, emessa: true);
    DB::table('piani_rate')->update(['tipo' => 'straordinario', 'data_delibera_assemblea' => '2026-02-12']);
    rigaStraordinariaCongelata($rossi, $this->immobile, 100000, '2026-06-01', '2026-06-15');
    rigaStraordinariaCongelata($rossi, $this->immobile, 50000, '2026-02-12', '2026-02-12', 'delibera');

    $json = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), corpoAnteprimaVendita($riga, $bianchi))->assertOk()->json();
    $c = $json['rate']['conguaglio'];
    $frasi = implode("\n", $json['rate']['frasi']);
    expect($c['coppie'][0]['importo'])->toBe(100000)
        ->and(collect($c['quote'][0]['per_capitolo'])->pluck('entrante')->all())->toBe([100000, 0])
        ->and($frasi)->toContain('credito € 1.000,00 a Rossi Mario, debito € 1.000,00 a Bianchi Anna — voce per voce')
        ->toContain('delibera del 12 febbraio 2026 — deliberata, quando l\'unità era di Rossi Mario: la spesa resta sua')
        ->not->toContain('ha deliberato il');
});

it('S8-4 — urgenza senza delibera e senza competenza dichiarata: il pannello non promette una delibera che non esiste, dice di dichiarare la competenza sulle fatture e come regolare le quote già emesse', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    $bianchi = personaDelCondominio($this->condominio, 'Bianchi Anna');
    $riga = rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03');
    $straordinaria = Gestione::factory()->create(['condominio_id' => $this->condominio->id, 'nome' => 'Ascensore', 'tipo' => 'straordinaria']);
    legaAEsercizio($this->esercizio, $straordinaria->id);
    pianoConQuotaSu($straordinaria, $this->condominio, $this->immobile, $rossi, 100001, emessa: true);
    DB::table('piani_rate')->update(['tipo' => 'straordinario', 'tipo_autorizzazione' => 'urgenza', 'data_delibera_assemblea' => null]);

    $json = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), corpoAnteprimaVendita($riga, $bianchi))->assertOk()->json();
    expect($json['rate']['conguaglio']['coppie'])->toBe([])
        ->and($json['rate']['conguaglio']['non_risolte'][0]['motivo'])->toContain('intervento urgente senza delibera')->toContain('si dichiara sulle fatture del piano')->toContain('saldo manuale dal Wallet')->not->toContain('rinuncia motivata')
        ->not->toContain('registrala sul piano e ricalcola');
});

it('un piano ancora in bozza con quote intestate a chi esce fa scattare il cancello: cambierebbe un destinatario (decisione 14)', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    $bianchi = personaDelCondominio($this->condominio, 'Bianchi Anna');
    $riga = rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03');
    pianoConQuotaSu($this->gestione, $this->condominio, $this->immobile, $rossi, 41200, emessa: false);

    $json = $this->actingAs($this->user)
        ->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), corpoAnteprimaVendita($riga, $bianchi))
        ->assertOk()
        ->json();

    // In bozza non è «emessa»: il blocco 2 non la elenca, ma il cancello la vede.
    expect($json['rate']['emesse'])->toBe([])
        ->and($json['cancello']['richiesto'])->toBeTrue()
        ->and(implode(' ', $json['cancello']['motivi']))->toContain('destinatario');
});

it('senza la copia autentica del titolo il blocco 3 aggiunge la frase dell\'art. 63 co. 5; con la copia, no', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    $bianchi = personaDelCondominio($this->condominio, 'Bianchi Anna');
    $riga = rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03');
    $rotta = route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]);

    $senza = $this->actingAs($this->user)->postJson($rotta, corpoAnteprimaVendita($riga, $bianchi))->json();
    $con = $this->actingAs($this->user)->postJson($rotta, corpoAnteprimaVendita($riga, $bianchi, [
        'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06',
    ]))->json();

    expect($senza['obbligati']['copia_autentica_mancante'])->toBeTrue()
        ->and(implode(' ', $senza['obbligati']['frasi']))->toContain('Finché non ricevi copia autentica del titolo, l\'obbligo verso il condominio resta a Rossi Mario')
        ->and($con['obbligati']['copia_autentica_mancante'])->toBeFalse()
        ->and(implode(' ', $con['obbligati']['frasi']))->not->toContain('Finché non ricevi copia autentica');
});

it('l\'inizio di una locazione dice che verso il condominio risponde il proprietario e, senza voci intestate all\'inquilino, che nessun importo cambierà', function () {
    $bianchi = personaDelCondominio($this->condominio, 'Bianchi Anna');
    $verdi = personaDelCondominio($this->condominio, 'Verdi Luca');
    rigaTitolarita($this->immobile, $bianchi, 'proprietario', '2026-05-01');

    $json = $this->actingAs($this->user)
        ->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), [
            'tipo' => 'inizio_locazione', 'riga_uscente_id' => null, 'anagrafica_entrante_id' => $verdi->id,
            'decorrenza' => '2026-07-01', 'quota' => 100, 'tipologia' => 'inquilino',
            'copia_autentica' => false, 'copia_autentica_il' => null, 'pertinenze' => [],
        ])
        ->assertOk()
        ->json();

    expect($json['riferimento']['frase'])->toBe('Il proprietario resta Bianchi Anna. Verdi Luca risulterà inquilino dal 1 luglio 2026.');

    $obbligati = implode(' ', $json['obbligati']['frasi']);
    expect($obbligati)->toContain('Verso il condominio continua a rispondere il proprietario')
        ->toContain('Nessuna voce di spesa è oggi intestata all\'inquilino: registrare la locazione non cambierà nessun importo.');

    // Nessun piano: niente cancello.
    expect($json['cancello']['richiesto'])->toBeFalse();
});

it('l\'anteprima rifiuta una decorrenza anteriore all\'inizio di chi esce e un entrante uguale a chi esce', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    $bianchi = personaDelCondominio($this->condominio, 'Bianchi Anna');
    $riga = rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03');
    $rotta = route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]);

    $this->actingAs($this->user)
        ->postJson($rotta, corpoAnteprimaVendita($riga, $bianchi, ['decorrenza' => '2019-03-03']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['decorrenza']);

    $this->actingAs($this->user)
        ->postJson($rotta, corpoAnteprimaVendita($riga, $rossi))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['anagrafica_entrante_id']);
});

it('un proprietario dell\'unità non può entrare come inquilino della stessa unità; nella vendita un comproprietario può comprare la quota dell\'altro', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    $bianchi = personaDelCondominio($this->condominio, 'Bianchi Anna');
    $rigaRossi = rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03', null, 50.0);
    rigaTitolarita($this->immobile, $bianchi, 'proprietario', '2019-03-03', null, 50.0);
    $rotta = route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]);

    $this->actingAs($this->user)
        ->postJson($rotta, [
            'tipo' => 'inizio_locazione', 'riga_uscente_id' => null, 'anagrafica_entrante_id' => $bianchi->id,
            'decorrenza' => '2026-07-01', 'quota' => 100, 'tipologia' => 'inquilino',
            'copia_autentica' => false, 'copia_autentica_il' => null, 'pertinenze' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.anagrafica_entrante_id.0', 'Bianchi Anna è già proprietario di questa unità: non può entrare anche come inquilino.');

    // Vendita fra comproprietari: Bianchi compra la metà di Rossi.
    $this->actingAs($this->user)
        ->postJson($rotta, corpoAnteprimaVendita($rigaRossi, $bianchi, ['quota' => 50]))
        ->assertOk();
});

it('un inquilino con una scadenza futura è in corso e può uscire prima della scadenza; un periodo finito prima della data dell\'atto no', function () {
    $bianchi = personaDelCondominio($this->condominio, 'Bianchi Anna');
    $verdi = personaDelCondominio($this->condominio, 'Verdi Luca');
    rigaTitolarita($this->immobile, $bianchi, 'proprietario', '2020-01-01');
    // Contratto con scadenza 30/06/2027: recede il 30/09/2026 (§6.6: la scadenza non è un automatismo).
    $rigaVerdi = rigaTitolarita($this->immobile, $verdi, 'inquilino', '2024-07-01', '2027-06-30');
    $rotta = route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]);
    $corpo = [
        'tipo' => 'fine_locazione', 'riga_uscente_id' => $rigaVerdi, 'anagrafica_entrante_id' => null,
        'decorrenza' => '2026-10-01', 'quota' => 100, 'tipologia' => 'inquilino',
        'copia_autentica' => false, 'copia_autentica_il' => null, 'pertinenze' => [],
    ];

    $json = $this->actingAs($this->user)->postJson($rotta, $corpo)->assertOk()->json();
    expect($json['riferimento']['frase'])->toBe('Verdi Luca risulterà inquilino fino al 30 settembre 2026 compreso. L\'unità resta sfitta dal 1 ottobre 2026.');

    // Periodo finito il 31/12/2025: alla data dell'atto non era più in corso.
    DB::table('anagrafica_immobile')->where('id', $rigaVerdi)->update(['data_fine' => '2025-12-31']);
    $this->actingAs($this->user)->postJson($rotta, $corpo)
        ->assertUnprocessable()
        ->assertJsonPath('errors.riga_uscente_id.0', 'Questo periodo si è chiuso il 31 dicembre 2025: il passaggio si registra da un titolare in corso alla data dell\'atto.');
});

it('il ruolo segue il tipo: una vendita non fa entrare un inquilino, e un inquilino non «vende»', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    $bianchi = personaDelCondominio($this->condominio, 'Bianchi Anna');
    $verdi = personaDelCondominio($this->condominio, 'Verdi Luca');
    $rigaRossi = rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03');
    $rigaVerdi = rigaTitolarita($this->immobile, $verdi, 'inquilino', '2024-01-01');
    $rotta = route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]);

    $this->actingAs($this->user)
        ->postJson($rotta, corpoAnteprimaVendita($rigaRossi, $bianchi, ['tipologia' => 'inquilino']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tipologia']);

    $this->actingAs($this->user)
        ->postJson($rotta, corpoAnteprimaVendita($rigaVerdi, $bianchi))
        ->assertUnprocessable()
        ->assertJsonPath('errors.riga_uscente_id.0', 'Verdi Luca è inquilino: per registrare la sua uscita usa «Fine locazione».');
});

it('le teste in assemblea guardano entrambi: chi vende la sua unica unità a un estraneo non cambia il numero; a un condòmino lo fa scendere', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    $bianchi = personaDelCondominio($this->condominio, 'Bianchi Anna');
    $neri = personaDelCondominio($this->condominio, 'Neri Paolo');
    $rigaRossi = rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03');
    $altra = Immobile::forceCreate(['condominio_id' => $this->condominio->id, 'nome' => 'Interno 5', 'descrizione' => 'Appartamento', 'interno' => '5']);
    rigaTitolarita($altra, $neri, 'proprietario', '2018-01-01');
    $rotta = route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]);

    $aEstraneo = $this->actingAs($this->user)->postJson($rotta, corpoAnteprimaVendita($rigaRossi, $bianchi))->json();
    expect($aEstraneo['invarianti']['frasi'][2])->toBe('Teste in assemblea: si contano per persona, e il numero non cambia — Rossi Mario esce dal conteggio, Bianchi Anna vi entra.');

    $aCondomino = $this->actingAs($this->user)->postJson($rotta, corpoAnteprimaVendita($rigaRossi, $neri))->json();
    expect($aCondomino['invarianti']['frasi'][2])->toBe('Teste in assemblea: si contano per persona, e il numero scende di uno — Rossi Mario esce dal conteggio, Neri Paolo era già nel conteggio.');
});

it('la copia autentica spuntata senza data risponde con una frase in italiano, non con lo slug di Laravel', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    $bianchi = personaDelCondominio($this->condominio, 'Bianchi Anna');
    $riga = rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03');

    $this->actingAs($this->user)
        ->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), corpoAnteprimaVendita($riga, $bianchi, ['copia_autentica' => true]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.copia_autentica_il.0', 'Indica il giorno in cui hai ricevuto la copia autentica del titolo.');
});

/*
|--------------------------------------------------------------------------
| Decisione 13: una riga con storia si chiude, non si cancella
|--------------------------------------------------------------------------
*/

it('«Dissocia» su una riga con data_fine risponde 422 e rimanda a «Registra passaggio»; su una riga senza storia cancella', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    $verdi = personaDelCondominio($this->condominio, 'Verdi Luca');
    $rigaViva = rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03');
    $rigaChiusa = rigaTitolarita($this->immobile, $verdi, 'inquilino', '2024-01-01', '2025-12-31');

    $this->actingAs($this->user)
        ->deleteJson(route('admin.gestionale.immobili.anagrafiche.destroy', [$this->condominio, $this->immobile, $rigaChiusa]))
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Registra passaggio'));

    expect(DB::table('anagrafica_immobile')->where('id', $rigaChiusa)->exists())->toBeTrue();

    $this->actingAs($this->user)
        ->delete(route('admin.gestionale.immobili.anagrafiche.destroy', [$this->condominio, $this->immobile, $rigaViva]))
        ->assertRedirect();

    expect(DB::table('anagrafica_immobile')->where('id', $rigaViva)->exists())->toBeFalse();
});

it('una riga agganciata a un passaggio registrato (subentri) non si cancella nemmeno se è ancora aperta', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    $bianchi = personaDelCondominio($this->condominio, 'Bianchi Anna');
    $rigaRossi = rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03', '2026-04-30');
    $rigaBianchi = rigaTitolarita($this->immobile, $bianchi, 'proprietario', '2026-05-01');

    DB::table('subentri')->insert([
        'condominio_id' => $this->condominio->id, 'immobile_id' => $this->immobile->id,
        'anagrafica_uscente_id' => $rossi->id, 'anagrafica_entrante_id' => $bianchi->id,
        'riga_uscente_id' => $rigaRossi, 'riga_entrante_id' => $rigaBianchi,
        'tipologia' => 'proprietario', 'tipo_passaggio' => 'vendita', 'decorrenza' => '2026-05-01',
        'utente_id' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->deleteJson(route('admin.gestionale.immobili.anagrafiche.destroy', [$this->condominio, $this->immobile, $rigaBianchi]))
        ->assertUnprocessable();

    expect(DB::table('anagrafica_immobile')->where('id', $rigaBianchi)->exists())->toBeTrue();
});

it('«Modifica» che cambia la persona su una riga con storia risponde 422; correggere quota o note sulla stessa persona passa', function () {
    $verdi = personaDelCondominio($this->condominio, 'Verdi Luca');
    $neri = personaDelCondominio($this->condominio, 'Neri Paolo');
    $rigaChiusa = rigaTitolarita($this->immobile, $verdi, 'inquilino', '2024-01-01', '2025-12-31');

    $corpo = [
        'anagrafica_id' => $neri->id, 'tipologia' => 'inquilino', 'quota' => 100,
        'data_inizio' => '2024-01-01', 'data_fine' => '2025-12-31', 'note' => 'cambio persona',
    ];

    $this->actingAs($this->user)
        ->putJson(route('admin.gestionale.immobili.anagrafiche.update', [$this->condominio, $this->immobile, $rigaChiusa]), $corpo)
        ->assertUnprocessable();

    expect(DB::table('anagrafica_immobile')->where('id', $rigaChiusa)->value('anagrafica_id'))->toBe($verdi->id);

    $this->actingAs($this->user)
        ->put(route('admin.gestionale.immobili.anagrafiche.update', [$this->condominio, $this->immobile, $rigaChiusa]), array_merge($corpo, [
            'anagrafica_id' => $verdi->id, 'note' => 'era il contratto del 2024',
        ]))
        ->assertRedirect();

    expect(DB::table('anagrafica_immobile')->where('id', $rigaChiusa)->value('note'))->toBe('era il contratto del 2024');
});

/*
|--------------------------------------------------------------------------
| Rotte per periodo: l'id della riga, non della persona
|--------------------------------------------------------------------------
*/

it('con due periodi della stessa persona, «Modifica» apre quello indicato e l\'aggiornamento tocca solo quello', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    // Vende nel 2022 e ricompra nel 2026: due righe, stessa persona, stesso ruolo.
    $primo = rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03', '2022-06-30', 100.0);
    $secondo = rigaTitolarita($this->immobile, $rossi, 'proprietario', '2026-05-01', null, 100.0);

    $props = $this->actingAs($this->user)
        ->get(route('admin.gestionale.immobili.anagrafiche.edit', [$this->condominio, $this->immobile, $secondo]))
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['anagrafica']['pivot']['id'])->toBe($secondo)
        ->and($props['anagrafica']['pivot']['data_inizio'])->toBe('2026-05-01');

    $this->actingAs($this->user)
        ->put(route('admin.gestionale.immobili.anagrafiche.update', [$this->condominio, $this->immobile, $secondo]), [
            'anagrafica_id' => $rossi->id, 'tipologia' => 'proprietario', 'quota' => 100,
            'data_inizio' => '2026-05-01', 'data_fine' => null, 'note' => 'ricomprato',
        ])
        ->assertRedirect();

    expect(DB::table('anagrafica_immobile')->where('id', $secondo)->value('note'))->toBe('ricomprato')
        ->and(DB::table('anagrafica_immobile')->where('id', $primo)->value('note'))->toBeNull();
});

it('una riga di un\'altra unità non si raggiunge dall\'indirizzo di questa: 404, non un aggiornamento fuori posto', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    $altra = Immobile::forceCreate([
        'condominio_id' => $this->condominio->id, 'nome' => 'Interno 4', 'descrizione' => 'Appartamento', 'interno' => '4',
    ]);
    $rigaAltrove = rigaTitolarita($altra, $rossi, 'proprietario', '2019-03-03');

    $this->actingAs($this->user)
        ->get(route('admin.gestionale.immobili.anagrafiche.edit', [$this->condominio, $this->immobile, $rigaAltrove]))
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| L'elenco: titolari attuali, storico a parte
|--------------------------------------------------------------------------
*/

it('l\'elenco dell\'unità porta i titolari attuali separati dallo storico, con il conteggio dei passaggi', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    $bianchi = personaDelCondominio($this->condominio, 'Bianchi Anna');
    $verdi = personaDelCondominio($this->condominio, 'Verdi Luca');
    rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03', '2026-04-30');
    $rigaBianchi = rigaTitolarita($this->immobile, $bianchi, 'proprietario', '2026-05-01');
    rigaTitolarita($this->immobile, $verdi, 'inquilino', '2024-01-01', '2025-12-31');

    $props = $this->actingAs($this->user)
        ->get(route('admin.gestionale.immobili.anagrafiche.index', [$this->condominio, $this->immobile]))
        ->assertOk()
        ->viewData('page')['props'];

    expect(collect($props['immobile']['anagrafiche'])->pluck('pivot.id')->all())->toBe([$rigaBianchi])
        ->and($props['storico']['passaggi'])->toBe(2)
        ->and(collect($props['storico']['righe'])->pluck('anagrafica.nome')->all())->toBe(['Bianchi Anna', 'Rossi Mario', 'Verdi Luca']);
});

/*
|--------------------------------------------------------------------------
| S5 — le guardie riscritte per il tempo (invarianti 11 e 12)
|--------------------------------------------------------------------------
*/

/** Il corpo di «Associa soggetto», come lo manda il modulo. */
function corpoAssocia(Condominio $c, Immobile $i, Anagrafica $a, string $tipologia, float $quota, string $dal, ?string $al = null): array
{
    return [
        'anagrafica_id' => $a->id, 'condominio_id' => $c->id, 'immobile_id' => $i->id,
        'tipologia' => $tipologia, 'quota' => $quota, 'data_inizio' => $dal, 'data_fine' => $al, 'note' => null,
    ];
}

it('inv. 11 — la somma delle quote è per giorno: venditore chiuso al 30/04 e acquirente al 100 % dal 01/05 passano; dal 30/04 (un giorno in comune a 200) no, e il messaggio nomina il giorno', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    $bianchi = personaDelCondominio($this->condominio, 'Bianchi Anna');
    rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03', '2026-04-30');
    $rotta = route('admin.gestionale.immobili.anagrafiche.store', [$this->condominio, $this->immobile]);

    // Sovrapposizione di un giorno: il 30 aprile Rossi (100) + Bianchi (100) = 200.
    $this->actingAs($this->user)
        ->postJson($rotta, corpoAssocia($this->condominio, $this->immobile, $bianchi, 'proprietario', 100, '2026-04-30'))
        ->assertUnprocessable()
        ->assertJsonPath('errors.quota.0', 'La somma delle quote per proprietario non può superare 100: il 30 aprile 2026 farebbe 200.');

    // Dal 1° maggio: in nessun giorno la somma supera 100 (prima di B2 questa query sommava 200 e rifiutava).
    $this->actingAs($this->user)
        ->postJson($rotta, corpoAssocia($this->condominio, $this->immobile, $bianchi, 'proprietario', 100, '2026-05-01'))
        ->assertRedirect();
    expect(DB::table('anagrafica_immobile')->where('immobile_id', $this->immobile->id)->count())->toBe(2);

    // E una riga FUTURA conta nel suo giorno: un terzo al 50 % dal 01/07 sfora il 1° luglio (100 + 50), anche se oggi non è ancora in corso.
    $terzo = personaDelCondominio($this->condominio, 'Verdi Luca');
    $this->actingAs($this->user)
        ->postJson($rotta, corpoAssocia($this->condominio, $this->immobile, $terzo, 'proprietario', 50, '2027-07-01'))
        ->assertUnprocessable()
        ->assertJsonPath('errors.quota.0', 'La somma delle quote per proprietario non può superare 100: il 1 luglio 2027 farebbe 150.');
});

it('inv. 12 — la stessa persona può avere due periodi non sovrapposti sulla stessa unità (vende e ricompra); due periodi sovrapposti con lo stesso ruolo no, con un ruolo diverso sì', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03', '2024-12-31');
    $rotta = route('admin.gestionale.immobili.anagrafiche.store', [$this->condominio, $this->immobile]);

    // Ricompra nel 2026: periodo disgiunto, passa (prima di B2: «già collegata a questo immobile»).
    $this->actingAs($this->user)
        ->postJson($rotta, corpoAssocia($this->condominio, $this->immobile, $rossi, 'proprietario', 100, '2026-05-01'))
        ->assertRedirect();

    // Un terzo periodo da proprietario che si sovrappone a quello in corso: no, e il messaggio dice quale.
    $this->actingAs($this->user)
        ->postJson($rotta, corpoAssocia($this->condominio, $this->immobile, $rossi, 'proprietario', 100, '2026-09-01'))
        ->assertUnprocessable()
        ->assertJsonPath('errors.anagrafica_id.0', 'Questa persona è già proprietario di questa unità dal 1 maggio 2026, in corso: i due periodi si sovrappongono. Chiudi quello esistente, o registra un passaggio.');

    // Lo stesso periodo con un altro ruolo (nudo proprietario che si aggiunge): la guardia 1 non lo vieta.
    $this->actingAs($this->user)
        ->postJson($rotta, corpoAssocia($this->condominio, $this->immobile, $rossi, 'usufruttuario', 100, '2026-09-01'))
        ->assertRedirect();
    expect(DB::table('anagrafica_immobile')->where('immobile_id', $this->immobile->id)->where('anagrafica_id', $rossi->id)->count())->toBe(3);
});

it('S8-12 — «oggi» è quello dell\'utente, non UTC: alle 22:30 UTC del 20/09 (00:30 del 21 a Roma) chi entra dal 21/09 è già fra i titolari attuali e chi esce non lo è più', function () {
    $rossi = personaDelCondominio($this->condominio, 'Rossi Mario');
    $bianchi = personaDelCondominio($this->condominio, 'Bianchi Anna');
    rigaTitolarita($this->immobile, $rossi, 'proprietario', '2019-03-03', '2026-09-20');
    rigaTitolarita($this->immobile, $bianchi, 'proprietario', '2026-09-21');
    \Carbon\Carbon::setTestNow('2026-09-20 22:30:00', 'UTC');
    \Carbon\CarbonImmutable::setTestNow('2026-09-20 22:30:00');
    try {
        $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.immobili.anagrafiche.index', [$this->condominio, $this->immobile]))->assertOk();
        $nomi = collect($pagina->viewData('page')['props']['immobile']['anagrafiche'] ?? $pagina->viewData('page')['props']['anagrafiche'])->pluck('nome')->all();
        expect($nomi)->toContain('Bianchi Anna')->not->toContain('Rossi Mario');
    } finally {
        \Carbon\Carbon::setTestNow();
        \Carbon\CarbonImmutable::setTestNow();
    }
});
