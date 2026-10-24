<?php

/**
 * 1.11.0-beta.55, Coda 245 — il prospetto degli oneri accessori sull'anello di una fine con la regola del piano (la strada della regola del piano).
 *
 * Dove la locazione è continuata con un coinquilino e la fine ha la regola del piano nel registro (`conguaglio.piano`), il prospetto non legge più la
 * parte dell'inquilino per giorni dopo l'uscita: per ogni voce dell'anello dà a ogni conduttore la sua parte con la regola del piano, con la titolarità
 * di oggi, e accanto ciò che ha nelle sue rate. Ciò che i passaggi hanno scritto in saldi è «con il conguaglio della fine locazione del …»; ciò che hanno
 * lasciato fra le parti lo dice il passaggio che l'ha lasciato (decisione 111.3, la differenza del piano del passaggio): con «Non scriverlo» chi esce
 * e chi riceveva i suoi giorni, nel resto di una fine le persone del resto, nell'inizio il conduttore e chi pagherebbe di meno. I giorni senza
 * conduttore sono la parte di chi sta al posto dell'inquilino. Le fini delle beta con la fermata del coinquilino restano lette com'erano (strada C).
 *
 * Il condominio è quello di `ScenariFineLocazione`: «Spese generali» € 1.200,00 al 30 % all'inquilino, l'anello dell'Interno 1 è 36000; Ines e Dora
 * inquiline al 50 %, congelate 18000 e 18000; Ugo proprietario al 100 %; le rate da gennaio a giugno a giornale. Ogni passaggio passa dalle rotte vere.
 *
 * I CONTI A MANO (centesimi; anno 2026, 365 giorni; resti maggiori, a pari resto chi esce):
 * - C1, Ines esce il 1/7 (181 giorni): pesi Ines 50 × 181 = 9050, Dora 50 × 365 = 18250; 36000 × 9050/27300 = 11934,07 → Ines 11934, Dora 24066.
 *   «Scrivi»: Ines nelle sue rate 18000 e con il conguaglio − 6066, Dora 18000 e + 6066: nessuna differenza. «Non scriverlo»: niente in saldi; Ines ha
 *   pagato 6066 più della sua parte, Dora 6066 meno: Dora rimborsa a Ines 6066.
 * - B6 (Dora con la riga chiusa al 30/9 già alla generazione: congelato Ines 50 × 365 = 18250 e Dora 50 × 273 = 13650 → 36000 × 18250/31900 =
 *   20595,61 → 20596 e 15404): dopo i conduttori coprono 273 giorni, Ines 9050/22700 × 273, Dora 13650/22700 × 273, e Ugo i 92 giorni dal 1/10:
 *   10734,85, 16191,18, 9073,97 → Ines 10735, Dora 16191, Ugo 9074. «Non scriverlo»: Ines ha pagato 20596 − 10735 = 9861 più della sua parte; Dora le
 *   rimborsa 16191 − 15404 = 787 e Ugo, senza conduttore, 9074.
 * - C4, dopo C1 «Scrivi» Dora esce il 1/10 (273 giorni) con «Scrivi»: Ines 10735, Dora 16191, Ugo 9074 (i 92 giorni dal 1/10); la coppia di Dora
 *   − 7875 → Ugo; il resto Ines − 1199, Ugo + 1199 (decisione 111.3). Ines: 18000 − 6066 = 11934 pagati, 1199 più della sua parte: glieli restituisce
 *   Ugo. Dora 18000 + 6066 − 7875 = 16191: niente. Senza conduttore, Ugo: 7875 con il conguaglio, e 1199 da restituire a Ines.
 * - C4-ns, dopo C1 «Non scriverlo»: la stessa fine di Dora; Ines ha pagato 18000, 7265 più della sua parte: 6066 da Dora (la fine di Ines) e 1199 da
 *   Ugo (il resto della fine di Dora). Dora 18000 − 7875 = 10125, 6066 meno della sua parte.
 * - C5a, dopo C1 «Scrivi» Luca entra il 1/9 al 50 % (122 giorni): Luca 6100, Ines 9050, Dora 18250 (somma 33400): 6574,85, 9754,49, 19670,66 →
 *   Luca 6575, Ines 9754, Dora 19671. Luca non ha quote: rimborsa 4395 a Dora (19671 − 24066) e 2180 a Ines (9754 − 11934).
 * - La fine di Dora con «Non scriverlo» dopo C1 «Scrivi»: la coppia non scritta (Dora − 7875, Ugo + 7875) e il resto (Ines − 1199, Ugo + 1199): Ugo
 *   restituisce 7875 a Dora e 1199 a Ines.
 * - Il cambio Dora → Luca il 1/10 dopo C1 «Scrivi»: Dora 50 × 273 = 13650, Luca 50 × 92 = 4600, Ines 9050 (somma 27300): Ines 11934, Dora 18000, Luca
 *   6066 (6065,93); con «Non scriverlo» Luca rimborsa a Dora 6066.
 * - C5a-Luca, poi Luca esce il 1/12 (91 giorni): Luca 4550 → 5142,86, Ines 10229,20, Dora 20627,94 → Luca 5143, Ines 10229, Dora 20628; il resto della
 *   sua fine Luca − 1432, Dora + 957, Ines + 475. Luca rimborsa in tutto 5143: a Dora 4395 − 957 = 3438, a Ines 2180 − 475 = 1705.
 *
 * Cosa NON copre: la Coda 352 nella strada dei giorni (`Beta55FermataChiHaPagatoTest`); la pagina Vue; MySQL.
 */

use App\Models\Gestionale\Subentro;
use App\Models\User;
use App\Services\Riparto\ProspettoOneriAccessori;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Beta55CoinquilinoScenari.php';
require_once __DIR__.'/Beta53SaldoLegatoScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class]);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Lo scenario della regola del piano (`rdpScenario`) con le persone che i passaggi dopo useranno (Luca, Elsa). */
function cprScenario($test, array $inquilini = [['Ines', 50], ['Dora', 50]], array $opz = []): array
{
    $s = rdpScenario($test, $inquilini, $opz);
    foreach (['Luca', 'Elsa'] as $n) {
        fclPersona($s, $n);
    }

    return $s;
}

/** La fine della locazione di `$chi` dalla rotta vera (con la quota della sua riga), con la scelta data; con `$entra`, il cambio d'inquilino in un passo. */
function cprFine($test, array $s, string $chi, string $il, ?string $scelta, ?string $entra = null, array $pertinenze = []): Subentro
{
    $quota = (float) DB::table('anagrafica_immobile')->where('id', fclRiga($s, $chi))->value('quota');

    return fclRegistra($test, $s, ['quota' => $quota] + fclModulo($s, $entra !== null ? $s['persone'][$entra] : null, $il, $pertinenze, $chi) + ($scelta !== null ? ['conguaglio' => $scelta] : []))->fresh();
}

/** L'inizio della locazione di `$chi` dalla rotta vera, alla quota data. */
function cprInizio($test, array $s, string $chi, string $il, float|int $quota = 50): Subentro
{
    return fclRegistra($test, $s, ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone'][$chi]->id, 'decorrenza' => $il, 'quota' => $quota,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []])->fresh();
}

/** Il prospetto dell'Interno 1 (o dell'unità data) nell'esercizio 2026, dal servizio. */
function cprProspetto(array $s, ?\App\Models\Immobile $unita = null): array
{
    return app(ProspettoOneriAccessori::class)->calcola(($unita ?? $s['unita'])->fresh(), $s['e']);
}

/** La sezione di un conduttore in breve: [totale, nelle sue rate, con il conguaglio, da rimborsare]; null se il prospetto non la ha. */
function cprSezione(array $p, string $nome): ?array
{
    $c = collect($p['conduttori'])->firstWhere('nome', $nome);

    return $c === null ? null : [(int) $c['totale'], (int) $c['nelle_sue_rate'], (int) $c['con_il_conguaglio'], (int) $c['da_rimborsare']];
}

/**
 * Chi rimborsa chi nella sezione di un conduttore (o, con `null`, nei giorni senza conduttore): «a Ines» = la persona della sezione rimborsa a Ines, «da
 * Luca» = Luca le restituisce. Per controparte, la cifra.
 */
function cprRimborsi(array $p, ?string $nome): array
{
    $sezione = $nome === null ? $p['senza_conduttore'] : collect($p['conduttori'])->firstWhere('nome', $nome);
    $out = [];
    foreach ($sezione['regola_del_piano']['rimborsi'] ?? [] as $r) {
        $chiave = $r['verso'] . ' ' . $r['nome'];
        $out[$chiave] = ($out[$chiave] ?? 0) + (int) $r['importo'];
    }
    ksort($out);

    return $out;
}

/** I conguagli scritti nella sezione di un conduttore (o, con `null`, nei giorni senza conduttore): giorno del passaggio → cifra con il segno. */
function cprConguagli(array $p, ?string $nome): array
{
    $sezione = $nome === null ? $p['senza_conduttore'] : collect($p['conduttori'])->firstWhere('nome', $nome);

    return collect($sezione['regola_del_piano']['conguagli'] ?? [])->mapWithKeys(fn ($c) => [$c['del'] => (int) $c['importo']])->all();
}

/** Le voci della regola del piano di un conduttore: [voce, importo, giorni, nelle sue rate]. */
function cprVoci(array $p, string $nome): array
{
    return collect(collect($p['conduttori'])->firstWhere('nome', $nome)['voci'] ?? [])->where('modo', 'piano')
        ->map(fn ($v) => [$v['conto'], (int) $v['importo'], $v['giorni'] === null ? null : (int) $v['giorni'], (int) $v['nelle_rate']])->values()->all();
}

/** I giorni senza conduttore in breve: [totale, con il conguaglio, da rimborsare]. */
function cprSenza(array $p): array
{
    $s = $p['senza_conduttore'];

    return [(int) $s['totale'], (int) collect($s['regola_del_piano']['conguagli'] ?? [])->sum('importo'), (int) ($s['regola_del_piano']['da_rimborsare'] ?? 0)];
}

/** Tutte le frasi del prospetto in un testo solo. */
function cprFrasi(array $p): string
{
    return implode(' | ', $p['intestazione']);
}

// --- La strada della regola del piano: la fine di Ines --------------------------------------------------------------------------------------------

it('Coda 245 (prospetto) — C1 con «Scrivi»: Ines € 119,34 e Dora € 240,66 con la regola del piano; nelle loro rate € 180,00 ciascuna, e il conguaglio della fine le porta alla loro parte', function () {
    $s = cprScenario($this);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Ines'))->toBe([11934, 18000, -6066, 0])
        ->and(cprSezione($p, 'Dora'))->toBe([24066, 18000, 6066, 0])
        ->and(cprVoci($p, 'Ines'))->toBe([['Spese generali', 11934, 181, 18000]])
        ->and(cprVoci($p, 'Dora'))->toBe([['Spese generali', 24066, 365, 18000]])
        ->and(cprConguagli($p, 'Ines'))->toBe(['2026-07-01' => -6066])
        ->and(cprConguagli($p, 'Dora'))->toBe(['2026-07-01' => 6066])
        ->and(cprRimborsi($p, 'Ines'))->toBe([])
        ->and(cprRimborsi($p, 'Dora'))->toBe([])
        ->and($p['senza_conduttore']['totale'])->toBe(0)
        ->and($p['totale_inquilino'])->toBe(36000)
        ->and(cprFrasi($p))->toContain('Alla fine della locazione del 01/07/2026 la locazione è continuata con Dora, e il conguaglio è stato scritto con la regola del piano: la parte dell\'inquilino si divide per quota e per giorni fra chi è stato conduttore nell\'anno, e la coppia ha portato ognuno alla sua parte («con il conguaglio della fine locazione del 01/07/2026»).')
        ->and(cprFrasi($p))->toContain('Le spese si dividono con la stessa regola del riparto: per giorni di conduzione e, dove più conduttori sono stati insieme nell\'anno, per quota e per giorni sull\'intera voce; non per consumi: dove c\'è la contabilizzazione del calore il risultato è un altro.')
        ->and(cprFrasi($p))->not->toContain('lo decidono le parti')
        ->and(collect($p['conduttori'])->flatMap(fn ($c) => $c['voci'])->contains(fn ($v) => ($v['strada'] ?? null) === 'C'))->toBeFalse();
});

it('Coda 245 (prospetto) — C1 con «Non scriverlo»: niente in saldi; Ines ha pagato € 60,66 più della sua parte e Dora glieli rimborsa, come dice il pannello della fine', function () {
    $s = cprScenario($this);
    $a = fclAnteprima($this, $s, ['quota' => 50] + fclModulo($s) + ['conguaglio' => 'non_scrivere']);
    // La frase del pannello della fine dice chi rimborsa chi con il prospetto: la stessa cifra.
    expect(fclFrasi($a))->toContain('il prospetto degli oneri accessori dice che Dora rimborsa a Ines € 60,66');
    cprFine($this, $s, 'Ines', '2026-07-01', 'non_scrivere');
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Ines'))->toBe([11934, 18000, 0, -6066])
        ->and(cprSezione($p, 'Dora'))->toBe([24066, 18000, 0, 6066])
        ->and(cprRimborsi($p, 'Ines'))->toBe(['da Dora' => 6066])
        ->and(cprRimborsi($p, 'Dora'))->toBe(['a Ines' => 6066])
        ->and(collect($p['conduttori'])->firstWhere('nome', 'Dora')['regola_del_piano']['rimborsi'][0]['perche'])->toBe('la fine della locazione del 01/07/2026 non ha scritto il conguaglio')
        ->and(cprFrasi($p))->toContain('Alla fine della locazione del 01/07/2026 la locazione è continuata con Dora, e il conguaglio non è stato scritto: con la regola del piano la parte dell\'inquilino si divide per quota e per giorni fra chi è stato conduttore nell\'anno; chi ha pagato più della sua parte ha la differenza «da restituire», chi meno «da rimborsare», fra le parti.')
        ->and(cprFrasi($p))->not->toContain('lo decidono le parti');
});

it('Coda 245 (prospetto) — B6 con «Non scriverlo»: Ines ha pagato € 98,61 più della sua parte; Dora le rimborsa € 7,87 e Ugo, per i giorni senza conduttore dal 1/10, € 90,74', function () {
    $s = cprScenario($this, opz: ['fini' => ['Dora' => '2026-09-30']]);
    expect(fclNetti($s))->toBe(['Dora' => 15404, 'Ines' => 20596, 'Ugo' => 84000]);
    cprFine($this, $s, 'Ines', '2026-07-01', 'non_scrivere');
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Ines'))->toBe([10735, 20596, 0, -9861])
        ->and(cprSezione($p, 'Dora'))->toBe([16191, 15404, 0, 787])
        ->and(cprVoci($p, 'Dora'))->toBe([['Spese generali', 16191, 273, 15404]])
        ->and(cprRimborsi($p, 'Ines'))->toBe(['da Dora' => 787, 'da Ugo' => 9074])
        ->and(cprSenza($p))->toBe([9074, 0, 9074])
        ->and(cprRimborsi($p, null))->toBe(['a Ines' => 9074])
        ->and(collect($p['senza_conduttore']['voci'])->map(fn ($v) => [$v['modo'], (int) $v['importo'], (int) $v['giorni']])->all())->toBe([['piano', 9074, 92]])
        ->and($p['totale_inquilino'])->toBe(36000);
});

it('Coda 245 (prospetto) — B6 con «Scrivi»: i giorni senza conduttore dal 1/10 sono la parte di Ugo, € 90,74, pagata con il conguaglio della fine di Ines', function () {
    $s = cprScenario($this, opz: ['fini' => ['Dora' => '2026-09-30']]);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Ines'))->toBe([10735, 20596, -9861, 0])
        ->and(cprSezione($p, 'Dora'))->toBe([16191, 15404, 787, 0])
        ->and(cprSenza($p))->toBe([9074, 9074, 0])
        ->and(cprConguagli($p, null))->toBe(['2026-07-01' => 9074])
        ->and(cprRimborsi($p, null))->toBe([]);
});

// --- I passaggi dopo -------------------------------------------------------------------------------------------------------------------------------

it('Coda 245 (prospetto) — C4: dopo la fine di Ines con «Scrivi» Dora esce il 1/10 con «Scrivi»; Ines è € 11,99 sopra il piano e glieli restituisce Ugo, che con il conguaglio ha pagato € 78,75 dei suoi € 90,74', function () {
    $s = cprScenario($this);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    cprFine($this, $s, 'Dora', '2026-10-01', 'scrivi');
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Ines'))->toBe([10735, 18000, -6066, -1199])
        ->and(cprSezione($p, 'Dora'))->toBe([16191, 18000, -1809, 0])
        ->and(cprConguagli($p, 'Dora'))->toBe(['2026-07-01' => 6066, '2026-10-01' => -7875])
        ->and(cprRimborsi($p, 'Ines'))->toBe(['da Ugo' => 1199])
        ->and(cprSenza($p))->toBe([9074, 7875, 1199])
        ->and(cprRimborsi($p, null))->toBe(['a Ines' => 1199])
        ->and(collect($p['senza_conduttore']['regola_del_piano']['rimborsi'])->first()['perche'])->toBe('la parte che la fine della locazione del 01/10/2026 ha lasciato fuori dal conguaglio')
        ->and($p['totale_inquilino'])->toBe(36000);
});

it('Coda 245 (prospetto) — C4-ns: dopo la fine di Ines con «Non scriverlo» Ines è € 72,65 sopra il piano: € 60,66 da Dora (la sua fine) e € 11,99 da Ugo (il resto della fine di Dora)', function () {
    $s = cprScenario($this);
    cprFine($this, $s, 'Ines', '2026-07-01', 'non_scrivere');
    cprFine($this, $s, 'Dora', '2026-10-01', 'scrivi');
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Ines'))->toBe([10735, 18000, 0, -7265])
        ->and(cprRimborsi($p, 'Ines'))->toBe(['da Dora' => 6066, 'da Ugo' => 1199])
        ->and(cprSezione($p, 'Dora'))->toBe([16191, 18000, -7875, 6066])
        ->and(cprRimborsi($p, 'Dora'))->toBe(['a Ines' => 6066])
        ->and(cprSenza($p))->toBe([9074, 7875, 1199]);
});

it('Coda 245 (prospetto) — la fine di Dora con «Non scriverlo» dopo la fine di Ines con «Scrivi»: il prospetto dice chi rimborsa chi, come promette il pannello: Ugo restituisce € 78,75 a Dora e € 11,99 a Ines', function () {
    $s = cprScenario($this);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    $a = fclAnteprima($this, $s, ['quota' => 50] + fclModulo($s, null, '2026-10-01', [], 'Dora') + ['conguaglio' => 'non_scrivere']);
    expect($a['rate']['frasi'][0])->toContain('Se non scrivi il conguaglio, nei saldi non si scrive niente, e il prospetto degli oneri accessori dice chi rimborsa chi.');
    cprFine($this, $s, 'Dora', '2026-10-01', 'non_scrivere');
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Ines'))->toBe([10735, 18000, -6066, -1199])
        ->and(cprRimborsi($p, 'Ines'))->toBe(['da Ugo' => 1199])
        ->and(cprSezione($p, 'Dora'))->toBe([16191, 18000, 6066, -7875])
        ->and(cprRimborsi($p, 'Dora'))->toBe(['da Ugo' => 7875])
        ->and(cprSenza($p))->toBe([9074, 0, 9074])
        ->and(cprRimborsi($p, null))->toBe(['a Dora' => 7875, 'a Ines' => 1199]);
});

it('Coda 245 (prospetto) — C5a: Luca entra il 1/9 al 50 % dopo la fine di Ines con «Scrivi»; non ha quote, e rimborsa con il prospetto € 43,95 a Dora e € 21,80 a Ines, come dice il pannello dell\'inizio', function () {
    $s = cprScenario($this);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    $dati = ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone']['Luca']->id, 'decorrenza' => '2026-09-01', 'quota' => 50,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []];
    expect(fclFrasi(fclAnteprima($this, $s, $dati)))->toContain('Luca rimborsa con il prospetto degli oneri accessori € 43,95 a Dora e € 21,80 a Ines');
    cprInizio($this, $s, 'Luca', '2026-09-01');
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Luca'))->toBe([6575, 0, 0, 6575])
        ->and(cprVoci($p, 'Luca'))->toBe([['Spese generali', 6575, 122, 0]])
        ->and(cprRimborsi($p, 'Luca'))->toBe(['a Dora' => 4395, 'a Ines' => 2180])
        ->and(cprSezione($p, 'Ines'))->toBe([9754, 18000, -6066, -2180])
        ->and(cprRimborsi($p, 'Ines'))->toBe(['da Luca' => 2180])
        ->and(cprSezione($p, 'Dora'))->toBe([19671, 18000, 6066, -4395])
        ->and(cprRimborsi($p, 'Dora'))->toBe(['da Luca' => 4395])
        ->and(collect($p['conduttori'])->firstWhere('nome', 'Luca')['regola_del_piano']['rimborsi'][0]['perche'])->toBe('con l\'inizio della locazione di Luca del 01/09/2026')
        ->and($p['totale_inquilino'])->toBe(36000);
});

it('Coda 245 (prospetto) — il cambio Dora → Luca il 1/10 con «Non scriverlo» dopo la fine di Ines: Luca rimborsa a Dora € 60,66 con il prospetto, come dice il pannello del cambio', function () {
    $s = cprScenario($this);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    $a = fclAnteprima($this, $s, ['quota' => 50] + fclModulo($s, $s['persone']['Luca'], '2026-10-01', [], 'Dora') + ['conguaglio' => 'non_scrivere']);
    expect(fclFrasi($a))->toContain('Luca rimborsa a Dora € 60,66 con il prospetto degli oneri accessori');
    cprFine($this, $s, 'Dora', '2026-10-01', 'non_scrivere', 'Luca');
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Ines'))->toBe([11934, 18000, -6066, 0])
        ->and(cprSezione($p, 'Dora'))->toBe([18000, 18000, 6066, -6066])
        ->and(cprRimborsi($p, 'Dora'))->toBe(['da Luca' => 6066])
        ->and(cprSezione($p, 'Luca'))->toBe([6066, 0, 0, 6066])
        ->and(cprRimborsi($p, 'Luca'))->toBe(['a Dora' => 6066])
        ->and(collect($p['conduttori'])->firstWhere('nome', 'Luca')['regola_del_piano']['rimborsi'][0]['perche'])->toBe('il cambio d\'inquilino del 01/10/2026 non ha scritto il conguaglio');
});

it('Coda 245 (prospetto) — il cambio Dora → Luca il 1/10 con «Scrivi» dopo la fine di Ines: la coppia del cambio una volta sola, con il conguaglio del cambio d\'inquilino', function () {
    // Il cambio sull'anello (C5d): Dora 50 × 273 = 13650, Luca 50 × 92 = 4600, Ines 9050 (somma 27300, come prima): Ines 11934, Dora 18000, Luca 6066 (6065,93); la coppia
    // Dora − 6066 → Luca. Dora 18000 + 6066 − 6066 = 18000, Luca 6066 con il conguaglio: nessuno da rimborsare. Il cambio è un passaggio dopo la fine, con la sua voce: il
    // prospetto non lo conta anche fra i cambi di prima della fine.
    $s = cprScenario($this);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    cprFine($this, $s, 'Dora', '2026-10-01', 'scrivi', 'Luca');
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Ines'))->toBe([11934, 18000, -6066, 0])
        ->and(cprSezione($p, 'Dora'))->toBe([18000, 18000, 0, 0])
        ->and(cprSezione($p, 'Luca'))->toBe([6066, 0, 6066, 0])
        ->and(collect(collect($p['conduttori'])->firstWhere('nome', 'Luca')['regola_del_piano']['conguagli'])->map(fn ($c) => [$c['tipo'], $c['del'], (int) $c['importo']])->all())
            ->toBe([['cambio', '2026-10-01', 6066]]);
});

it('Coda 245 (prospetto) — C5a-Luca: la fine di Luca, che non ha quote, è un\'informazione; nel prospetto Luca rimborsa in tutto € 51,43: € 34,38 a Dora e € 17,05 a Ines', function () {
    $s = cprScenario($this);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    cprInizio($this, $s, 'Luca', '2026-09-01');
    $a = fclAnteprima($this, $s, ['quota' => 50] + fclModulo($s, null, '2026-12-01', [], 'Luca'));
    expect(fclFrasi($a))->toContain('Con la regola del piano Luca pagherebbe € 14,32 in meno, Dora € 9,57 in più, e Ines € 4,75 in più. La differenza la regolano le parti con il prospetto degli oneri accessori.');
    cprFine($this, $s, 'Luca', '2026-12-01', null);
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Luca'))->toBe([5143, 0, 0, 5143])
        ->and(cprVoci($p, 'Luca'))->toBe([['Spese generali', 5143, 91, 0]])
        ->and(cprRimborsi($p, 'Luca'))->toBe(['a Dora' => 3438, 'a Ines' => 1705])
        ->and(cprSezione($p, 'Ines'))->toBe([10229, 18000, -6066, -1705])
        ->and(cprSezione($p, 'Dora'))->toBe([20628, 18000, 6066, -3438])
        // Luca non ha quote del piano: la frase del rimborso lo dice (prima nessun test la controllava).
        ->and(collect(collect($p['conduttori'])->firstWhere('nome', 'Luca')['regola_del_piano']['rimborsi'])->pluck('perche')->all())
            ->each->toContain('con la fine della locazione di Luca del 01/12/2026, che non aveva quote del piano');
});

it('Coda 245 (prospetto) — dopo l\'inizio di Luca, la fine di Dora con Luca che resta: Dora le quote le aveva, e il prospetto non dice che non le aveva; Luca rimborsa a Dora € 54,45 e a Ines € 21,80, Ines a Dora € 15,58', function () {
    // C5a: Ines 9754, Dora 19671, Luca 6575 (Luca + 6575, Dora − 4395, Ines − 2180). La fine di Dora il 1/10 con Luca che resta: pesi 9050, 50 × 273 = 13650, 6100
    // (somma 28800, tutto l'anno coperto) → 11312,5, 17062,5 e 7625: a pari resto il centesimo a chi esce, Dora 17063, Ines 11312, Luca 7625; Dora − 2608, Ines + 1558,
    // Luca + 1050. Chi riceve i giorni di Dora è Luca, che non ha quote del piano: nessuna riga, e il resto si dice. Per coppia: Luca a Dora 4395 + 1050 = 5445, Luca a
    // Ines 2180, Ines a Dora 1558. Dora: 18000 + 6066 − 5445 − 1558 = 17063; Ines 18000 − 6066 − 2180 + 1558 = 11312; Luca 5445 + 2180 = 7625. Era: «con la fine
    // della locazione di Dora del 01/10/2026, che non aveva quote del piano», di Dora che ne ha € 180,00 nelle sue rate.
    $s = cprScenario($this);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    cprInizio($this, $s, 'Luca', '2026-09-01');
    cprFine($this, $s, 'Dora', '2026-10-01', null);
    $p = cprProspetto($s);
    $perche = fn (string $nome) => collect(collect($p['conduttori'])->firstWhere('nome', $nome)['regola_del_piano']['rimborsi'])->mapWithKeys(fn ($r) => [$r['verso'] . ' ' . $r['nome'] => $r['perche']])->all();

    expect(cprSezione($p, 'Dora'))->toBe([17063, 18000, 6066, -7003])
        ->and(cprSezione($p, 'Ines'))->toBe([11312, 18000, -6066, -622])
        ->and(cprSezione($p, 'Luca'))->toBe([7625, 0, 0, 7625])
        ->and(cprVoci($p, 'Dora'))->toBe([['Spese generali', 17063, 273, 18000]])
        ->and(cprVoci($p, 'Luca'))->toBe([['Spese generali', 7625, 122, 0]])
        ->and(cprRimborsi($p, 'Dora'))->toBe(['da Ines' => 1558, 'da Luca' => 5445])
        ->and(cprRimborsi($p, 'Luca'))->toBe(['a Dora' => 5445, 'a Ines' => 2180])
        ->and(cprRimborsi($p, 'Ines'))->toBe(['a Dora' => 1558, 'da Luca' => 2180])
        ->and($perche('Dora'))->toBe(['da Luca' => 'con l\'inizio della locazione di Luca del 01/09/2026; con la fine della locazione di Dora del 01/10/2026, che nei saldi non ha scritto niente',
            'da Ines' => 'con la fine della locazione di Dora del 01/10/2026, che nei saldi non ha scritto niente']);
    expect(fclStampa($s))->not->toContain('che non aveva quote del piano')
        ->and(fclStampa($s))->toContain('da restituire a Dora da Ines: con la fine della locazione di Dora del 01/10/2026, che nei saldi non ha scritto niente € 15,58');
});

// --- La stampa --------------------------------------------------------------------------------------------------------------------------------------

it('Coda 245 (prospetto) — la stampa di C1: l\'etichetta «con il conguaglio della fine locazione del 01/07/2026», i totali della regola del piano, la frase di testa, nessun «lo decidono le parti» e nessun «€ -»', function () {
    $s = cprScenario($this);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    $stampa = fclStampa($s);

    expect($stampa)->toContain('con il conguaglio della fine locazione del 01/07/2026')
        ->and($stampa)->toContain('Totale a carico di Ines € 119,34')
        ->and($stampa)->toContain('Totale a carico di Dora € 240,66')
        ->and($stampa)->toContain('per quota e per giorni sull\'intera voce')
        ->and($stampa)->toContain('con la regola del piano')
        ->and($stampa)->not->toContain('lo decidono le parti')
        ->and($stampa)->not->toContain('€ -');

    $s = cprScenario($this);
    cprFine($this, $s, 'Ines', '2026-07-01', 'non_scrivere');
    $stampa = fclStampa($s);
    expect($stampa)->toContain('da restituire a Ines da Dora: la fine della locazione del 01/07/2026 non ha scritto il conguaglio € 60,66')
        ->and($stampa)->toContain('da rimborsare a Ines: la fine della locazione del 01/07/2026 non ha scritto il conguaglio € 60,66')
        ->and($stampa)->not->toContain('lo decidono le parti')
        ->and($stampa)->not->toContain('€ -');
});

// --- Il box -----------------------------------------------------------------------------------------------------------------------------------------

it('Coda 245 (prospetto) — C7: il coinquilino sull\'Interno 1 e il box di Rita con Ines sola; una scelta sola, e il prospetto dell\'Interno 1 ha la regola del piano, quello del box la strada dei giorni', function () {
    $s = cprScenario($this, opz: ['box' => [['Rita', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi', pertinenze: [$s['box']]);
    // L'Interno 1 (900 millesimi): l'anello è 32400, congelato 16200 e 16200; 32400 × 9050/27300 = 10740,66 → Ines 10741, Dora 21659.
    $p = cprProspetto($s);
    expect(cprSezione($p, 'Ines'))->toBe([10741, 16200, -5459, 0])
        ->and(cprSezione($p, 'Dora'))->toBe([21659, 16200, 5459, 0]);
    // Il box (100 millesimi): Ines 3600; i 184 giorni dopo l'uscita, 3600 × 184/365 = 1814,79 → 1815, li ha pagati Rita con il conguaglio.
    $b = cprProspetto($s, $s['box']);
    expect(collect($b['conduttori'])->firstWhere('nome', 'Ines')['regola_del_piano'] ?? null)->toBeNull()
        ->and(collect($b['senza_conduttore']['voci'])->map(fn ($v) => [$v['pagato_da'], (int) $v['importo'], (int) $v['giorni']])->all())->toBe([['Rita', 1815, 184]]);
});

// --- I piani senza riparto registrato ---------------------------------------------------------------------------------------------------------------

/**
 * Lo scenario con il piano generato dalla 1.10 (senza le righe del riparto): l'anello è la somma delle quote pure degli inquilini sulle rate, 18000 + 18000,
 * e la regola del piano dà le cifre del piano con il riparto (una voce sola). Con le persone dei passaggi dopo.
 */
function cprSenzaRiparto($test, array $inquilini = [['Ines', 50], ['Dora', 50]], array $opz = []): array
{
    $s = cprScenario($test, $inquilini, $opz);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();

    return $s;
}

/** Il modulo dell'inizio della locazione di `$chi` il giorno dato, alla quota data. */
function cprModuloInizio(array $s, string $chi, string $il, float|int $quota = 50): array
{
    return ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone'][$chi]->id, 'decorrenza' => $il, 'quota' => $quota,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []];
}

it('Coda 245 (prospetto) — un piano generato dalla 1.10, senza riparto registrato, con «Non scriverlo»: il prospetto lo legge con la regola del piano, Ines € 119,34 e Dora € 240,66, e Dora rimborsa a Ines € 60,66, come dice il pannello della fine', function () {
    // Cambiato con la decisione 115.1: prima il prospetto lasciava fuori il piano («il prospetto non ricostruisce le voci»), la testa diceva «Nessun piano rate
    // approvato in questo esercizio» e la stampa «Nessuna voce a carico dell'inquilino», e i € 60,66 di «Non scriverlo» non li diceva più nessuna pagina.
    // Ora l'anello è quello delle quote pure (18000 + 18000), le cifre sono quelle di C1: 36000 × 9050/27300 = 11934,07 → Ines 11934, Dora 24066.
    $s = cprSenzaRiparto($this);
    $a = fclAnteprima($this, $s, ['quota' => 50] + fclModulo($s) + ['conguaglio' => 'non_scrivere']);
    expect($a['rate']['frasi'][0])->toContain('Se non lo scrivi, nei saldi non si scrive niente: le rate restano come sono, e il prospetto degli oneri accessori dice che Dora rimborsa a Ines € 60,66.')
        ->and(fclFrasi($a))->not->toContain('lascia fuori il piano');
    cprFine($this, $s, 'Ines', '2026-07-01', 'non_scrivere');
    $p = cprProspetto($s);

    expect(array_column($p['piani'], 'nome'))->toBe(['Preventivo 2026'])
        ->and($p['piani_esclusi'])->toBe([])
        ->and(cprSezione($p, 'Ines'))->toBe([11934, 18000, 0, -6066])
        ->and(cprSezione($p, 'Dora'))->toBe([24066, 18000, 0, 6066])
        ->and(cprVoci($p, 'Ines'))->toBe([['Voci a carico dell\'inquilino', 11934, 181, 18000]])
        ->and(cprVoci($p, 'Dora'))->toBe([['Voci a carico dell\'inquilino', 24066, 365, 18000]])
        ->and(collect(collect($p['conduttori'])->firstWhere('nome', 'Ines')['voci'])->firstWhere('modo', 'piano')['nota'])
            ->toBe('il piano non ha il riparto registrato: le voci a carico dell\'inquilino si leggono insieme, dalle quote delle rate')
        ->and(cprRimborsi($p, 'Ines'))->toBe(['da Dora' => 6066])
        ->and(cprRimborsi($p, 'Dora'))->toBe(['a Ines' => 6066])
        ->and($p['totale_inquilino'])->toBe(36000)
        ->and(cprFrasi($p))->toContain('Il piano «Preventivo 2026» è stato generato da una versione precedente e non ha il riparto registrato: la parte dell\'inquilino è quella delle quote nelle rate, tutta insieme, e si divide con la regola del piano.')
        ->and(cprFrasi($p))->not->toContain('gli importi sono ricostruiti dai dati di oggi')
        ->and(cprFrasi($p))->not->toContain('Nessun piano rate approvato in questo esercizio');
    $stampa = fclStampa($s);
    expect($stampa)->toContain('Totale a carico di Dora € 240,66')
        ->and($stampa)->toContain('da rimborsare a Ines: la fine della locazione del 01/07/2026 non ha scritto il conguaglio € 60,66')
        ->and($stampa)->not->toContain('Nessuna voce a carico dell\'inquilino su questa unità');
});

it('Coda 245 (prospetto) — il piano generato dalla 1.10 con «Scrivi»: il conguaglio della fine porta Ines e Dora alla loro parte, € 119,34 e € 240,66', function () {
    $s = cprSenzaRiparto($this);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Ines'))->toBe([11934, 18000, -6066, 0])
        ->and(cprSezione($p, 'Dora'))->toBe([24066, 18000, 6066, 0])
        ->and(cprConguagli($p, 'Ines'))->toBe(['2026-07-01' => -6066])
        ->and(cprRimborsi($p, 'Dora'))->toBe([]);
});

it('Coda 245 (prospetto) — il piano generato dalla 1.10, C4: la fine di Dora rimanda al prospetto, e il prospetto dice che Ugo restituisce a Ines € 11,99; l\'avviso di «Annulla il conguaglio» della fine di Ines rimanda al prospetto', function () {
    // C4: Ines 10735, Dora 16191, Ugo 9074; la coppia di Dora − 7875 → Ugo, il resto Ines − 1199, Ugo + 1199. Prima le frasi dicevano «chi rimborsa chi lo
    // regolano le parti: il prospetto degli oneri accessori lascia fuori il piano» e «le cifre del piano di oggi le regolano le parti».
    $s = cprSenzaRiparto($this);
    $fi = cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    $a = fclAnteprima($this, $s, ['quota' => 50] + fclModulo($s, null, '2026-10-01', [], 'Dora'));
    expect($a['rate']['frasi'][0])->toContain('Se non scrivi il conguaglio, nei saldi non si scrive niente, e il prospetto degli oneri accessori dice chi rimborsa chi.')
        ->and(fclFrasi($a))->not->toContain('lascia fuori il piano');
    cprFine($this, $s, 'Dora', '2026-10-01', 'scrivi');
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Ines'))->toBe([10735, 18000, -6066, -1199])
        ->and(cprRimborsi($p, 'Ines'))->toBe(['da Ugo' => 1199])
        ->and(cprSezione($p, 'Dora'))->toBe([16191, 18000, -1809, 0])
        ->and(cprSenza($p))->toBe([9074, 7875, 1199])
        ->and(cprRimborsi($p, null))->toBe(['a Ines' => 1199])
        // L'avviso apre con il complemento di tempo («Dopo questo conguaglio, la fine …»), non fra il verbo e il suo oggetto: le cifre sono quelle di prima (coppia di Dora 7875, resto 1199).
        ->and(fds50Storico($s, $fi->fresh())['conguaglio']['avvisi'])->toBe(['Dopo questo conguaglio, la fine della locazione di Dora del 1 ottobre 2026 ha scritto la sua coppia con la regola del piano (€ 78,75 a credito di Dora e € 78,75 a debito di Ugo), e la regola del piano ha lasciato fuori dal conguaglio € 11,99 a credito di Ines e € 11,99 a debito di Ugo. Annullato questo conguaglio, la coppia di Dora e la parte lasciata fuori dal conguaglio restano come sono, e il prospetto degli oneri accessori dice le cifre del piano di oggi.']);
});

it('Coda 245 (prospetto) — il piano generato dalla 1.10, C4-ns: la differenza della fine di Ines resta fra le parti con il prospetto; Ines ha € 60,66 da Dora e € 11,99 da Ugo', function () {
    $s = cprSenzaRiparto($this);
    cprFine($this, $s, 'Ines', '2026-07-01', 'non_scrivere');
    $a = fclAnteprima($this, $s, ['quota' => 50] + fclModulo($s, null, '2026-10-01', [], 'Dora'));
    expect($a['rate']['frasi'][0])->toContain('La fine locazione di Ines del 1 luglio 2026 non ha scritto il conguaglio: con la regola del piano sulla gestione Ordinaria 2026 Ines € 119,34 e Dora € 240,66, e la differenza di quella fine, € 60,66 a credito di Ines e € 60,66 a debito di Dora, resta fra le parti, con il prospetto degli oneri accessori.');
    cprFine($this, $s, 'Dora', '2026-10-01', 'scrivi');
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Ines'))->toBe([10735, 18000, 0, -7265])
        ->and(cprRimborsi($p, 'Ines'))->toBe(['da Dora' => 6066, 'da Ugo' => 1199])
        ->and(cprSezione($p, 'Dora'))->toBe([16191, 18000, -7875, 6066]);
});

it('Coda 245 (prospetto) — il piano generato dalla 1.10, C5a: l\'inizio di Luca dice le cifre, € 65,75, € 43,95 a Dora e € 21,80 a Ines, e il prospetto le dice; poi la fine di Luca, che non ha quote, rimanda al prospetto', function () {
    // C5a: Luca 6100, Ines 9050, Dora 18250 (somma 33400): 6574,85, 9754,49, 19670,66 → Luca 6575, Ines 9754, Dora 19671; Luca rimborsa 4395 a Dora e 2180 a Ines.
    // Prima il pannello diceva «… e Luca la rimborsa a chi l'ha pagata, fra le parti», senza persone né cifre, e «dopo il passaggio il prospetto degli oneri
    // accessori lo lascia fuori». C5a-Luca: Luca 5143, Ines 10229, Dora 20628; Luca rimborsa a Dora 4395 − 957 = 3438, a Ines 2180 − 475 = 1705.
    $s = cprSenzaRiparto($this);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    $a = fclAnteprima($this, $s, cprModuloInizio($s, 'Luca', '2026-09-01'));
    expect(fclFrasi($a))->toContain('La fine locazione di Ines del 1 luglio 2026 ha diviso la parte dell\'inquilino con la regola del piano (Ines € 119,34 e Dora € 240,66). Con Luca dal 1 settembre 2026 al 50 % la regola del piano darebbe Luca € 65,75, Dora € 196,71 e Ines € 97,54: il programma non scrive niente, e Luca rimborsa con il prospetto degli oneri accessori € 43,95 a Dora e € 21,80 a Ines.')
        ->and(fclFrasi($a))->not->toContain('lo lascia fuori')
        ->and(fclFrasi($a))->not->toContain('la rimborsa a chi l\'ha pagata')
        ->and(implode(' ', $a['cancello']['informazioni']))->toContain('con la regola del piano, con Luca dal 1 settembre 2026, Luca € 65,75, Dora € 196,71 e Ines € 97,54, e Luca rimborsa con il prospetto degli oneri accessori € 43,95 a Dora e € 21,80 a Ines')
        ->and(implode(' ', $a['cancello']['informazioni']))->not->toContain('lo lascia fuori');
    cprInizio($this, $s, 'Luca', '2026-09-01');
    $p = cprProspetto($s);
    expect(cprSezione($p, 'Luca'))->toBe([6575, 0, 0, 6575])
        ->and(cprRimborsi($p, 'Luca'))->toBe(['a Dora' => 4395, 'a Ines' => 2180])
        ->and(cprSezione($p, 'Ines'))->toBe([9754, 18000, -6066, -2180])
        ->and(cprSezione($p, 'Dora'))->toBe([19671, 18000, 6066, -4395]);

    $b = fclAnteprima($this, $s, ['quota' => 50] + fclModulo($s, null, '2026-12-01', [], 'Luca'));
    expect(fclFrasi($b))->toContain('Con la regola del piano Luca pagherebbe € 14,32 in meno, Dora € 9,57 in più, e Ines € 4,75 in più. La differenza la regolano le parti con il prospetto degli oneri accessori.');
    $fl = cprFine($this, $s, 'Luca', '2026-12-01', null);
    expect(implode(' ', fds50Storico($s, $fl)['fermate']))->toEndWith('La differenza la regolano le parti con il prospetto degli oneri accessori.')
        ->and(cprSezione(cprProspetto($s), 'Luca'))->toBe([5143, 0, 0, 5143])
        ->and(cprRimborsi(cprProspetto($s), 'Luca'))->toBe(['a Dora' => 3438, 'a Ines' => 1705]);
});

it('Coda 245 (prospetto) — il piano generato dalla 1.10, il cambio Dora → Luca con «Non scriverlo» dopo la fine di Ines: Luca rimborsa a Dora € 60,66 con il prospetto, e il pannello non dice che il prospetto lascia fuori il piano', function () {
    // Dora 50 × 273 = 13650, Luca 50 × 92 = 4600, Ines 9050 (somma 27300): Ines 11934, Dora 18000, Luca 6066 (6065,93). Prima: «Luca rimborsa a Dora € 60,66
    // fra le parti», e `prospetto_esclude` con il piano (la voce «Non scriverlo» diceva che il prospetto lo lascia fuori), mentre il prospetto lo legge.
    $s = cprSenzaRiparto($this);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    $a = fclAnteprima($this, $s, ['quota' => 50] + fclModulo($s, $s['persone']['Luca'], '2026-10-01', [], 'Dora') + ['conguaglio' => 'non_scrivere']);
    expect(fclFrasi($a))->toContain('Luca rimborsa a Dora € 60,66 con il prospetto degli oneri accessori')
        ->and($a['rate']['prospetto_esclude'])->toBe([]);
    cprFine($this, $s, 'Dora', '2026-10-01', 'non_scrivere', 'Luca');
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Ines'))->toBe([11934, 18000, -6066, 0])
        ->and(cprSezione($p, 'Dora'))->toBe([18000, 18000, 6066, -6066])
        ->and(cprSezione($p, 'Luca'))->toBe([6066, 0, 0, 6066])
        ->and(cprRimborsi($p, 'Luca'))->toBe(['a Dora' => 6066]);
});

it('Coda 245 (prospetto) — il piano generato dalla 1.10, il cambio Dora → Luca con il box spuntato e «Non scriverlo»: sull\'Interno 1 Luca rimborsa a Dora € 54,59 con il prospetto, e solo per il box il prospetto lascia fuori il piano, € 9,07 fra le parti', function () {
    // L'Interno 1 900/1000 (anello 120000 × 0,9 × 30 % = 32400, Ines e Dora 16200), il Box 12 100/1000 (3600) di Rita con Dora sola inquilina. La fine di Ines il 1/7 con «Scrivi»: 9050 /
    // 18250 su 27300 → 10740,66 / 21659,34 → Ines 10741 (il centesimo a ,66), Dora 21659. Il cambio Dora → Luca il 1/10 con il box: sull'Interno 1 la regola del piano (Ines 9050, Dora
    // 13650, Luca 4600 su 27300) 10741 / 16200 / 5459, Dora − 5459 → Luca; sul box i giorni, 3600 × 92/365 = 907,40 → 907. Il prospetto dell'Interno 1 legge il piano con la regola del
    // piano (Luca `[5459, 0, 0, 5459]`, «a Dora 5459»); quello del box lo lascia fuori (i titolari sono cambiati dopo la generazione). Era: la frase del server diceva per tutto il passaggio
    // «dopo questo passaggio il prospetto degli oneri accessori lo lascia fuori», falsa per l'Interno 1.
    // Cambiato con la frase dei giorni del cambio con il box (sotto): la frase non è più una sola per tutto il passaggio, e dice per l'Interno 1 «Luca rimborsa a Dora € 54,59 con il prospetto»
    // e solo per il Box 12 che il prospetto lascia fuori il piano e il rimborso (€ 9,07) si regola fra le parti. Le cifre e `prospetto_esclude_dove` sono quelle di prima.
    $s = cprSenzaRiparto($this, [['Ines', 50], ['Dora', 50]], ['box' => [['Rita', 'proprietario', 100], ['Dora', 'inquilino', 100]]]);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    $modulo = ['quota' => 50] + fclModulo($s, $s['persone']['Luca'], '2026-10-01', [$s['box']], 'Dora');
    $a = fclAnteprima($this, $s, $modulo);
    $perLInterno = \Illuminate\Support\Str::before(fclFrasi($a), 'Per «Box 12»');
    expect($perLInterno)->toContain('Luca rimborsa a Dora € 54,59 con il prospetto degli oneri accessori')
        ->and($perLInterno)->not->toContain('lascia fuori')
        ->and(fclFrasi($a))->toContain('Per «Box 12» le righe del conguaglio, se lo scrivi, entrano nei saldi e sommano a zero, con la quota divisa in proporzione ai giorni: € 9,07 dei giorni dopo l\'uscita passano a Luca. '
            . 'Se non lo scrivi, nei saldi non si scrive niente: i giorni dopo l\'uscita restano nelle rate di Dora, e il rimborso di Luca a Dora si regola fra le parti: il piano «Preventivo 2026» è stato generato da una versione precedente, '
            . 'senza riparto registrato, e dopo questo passaggio il prospetto degli oneri accessori lo lascia fuori.')
        ->and($a['rate']['prospetto_esclude'])->toBe(['Preventivo 2026'])
        ->and($a['rate']['prospetto_esclude_dove'])->toBe([['piano' => 'Preventivo 2026', 'unita' => 'Box 12']]);
    fclRegistra($this, $s, $modulo + ['conguaglio' => 'non_scrivere']);

    $p = cprProspetto($s);
    expect(cprSezione($p, 'Dora'))->toBe([16200, 16200, 5459, -5459])
        ->and(cprSezione($p, 'Luca'))->toBe([5459, 0, 0, 5459])
        ->and(cprRimborsi($p, 'Luca'))->toBe(['a Dora' => 5459])
        ->and(collect(cprProspetto($s, $s['box'])['piani_esclusi'])->pluck('nome')->all())->toBe(['Preventivo 2026']);
});

it('Coda 245 (prospetto) — il piano generato dalla 1.10, il cambio Dora → Luca con il box spuntato su un piano con il riparto e senza il box: la frase resta quella del prospetto (i controlli)', function () {
    // Scritto dopo il codice (il rosso è la mutazione che conta le unità come i piani). Senza il box tutte le coppie sono della regola del piano: «Luca rimborsa a Dora € 54,59 con il prospetto»
    // (32400 su 27300 come sopra); con il riparto registrato il prospetto legge anche il box, e nessun piano resta fuori.
    $s = cprSenzaRiparto($this, [['Ines', 50], ['Dora', 50]], ['box' => [['Rita', 'proprietario', 100], ['Dora', 'inquilino', 100]]]);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    $a = fclAnteprima($this, $s, ['quota' => 50] + fclModulo($s, $s['persone']['Luca'], '2026-10-01', [], 'Dora'));
    expect(fclFrasi($a))->toContain('Luca rimborsa a Dora € 54,59 con il prospetto degli oneri accessori')
        ->and(fclFrasi($a))->not->toContain('lascia fuori')
        ->and($a['rate']['prospetto_esclude_dove'])->toBe([]);

    $s = cprScenario($this, [['Ines', 50], ['Dora', 50]], ['box' => [['Rita', 'proprietario', 100], ['Dora', 'inquilino', 100]]]);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    $b = fclAnteprima($this, $s, ['quota' => 50] + fclModulo($s, $s['persone']['Luca'], '2026-10-01', [$s['box']], 'Dora'));
    expect(fclFrasi($b))->toContain('Luca glieli rimborsa con il prospetto degli oneri accessori.')
        ->and(fclFrasi($b))->not->toContain('lascia fuori')
        ->and($b['rate']['prospetto_esclude'])->toBe([]);
});

it('Coda 245 (prospetto) — il cambio Dora → Luca con il box spuntato: la frase dei giorni dice le sole unità dei giorni (il box, € 9,07), e l\'Interno 1 ha la frase della regola del piano (€ 54,59)', function (bool $senzaRiparto, string $rimborsoDelBox) {
    // Le cifre dei test di sopra: l'Interno 1 con la regola del piano 5459 (Dora − 5459 → Luca), il Box 12 per giorni 3600 × 92/365 = 907,40 → 907; in tutto 6366. Era: «la quota è divisa
    // in proporzione ai giorni, e € 63,66 dei giorni dopo l'uscita passano a Luca», cioè la somma delle due con i € 54,59 della regola del piano detti giorni.
    $s = $senzaRiparto ? cprSenzaRiparto($this, [['Ines', 50], ['Dora', 50]], ['box' => [['Rita', 'proprietario', 100], ['Dora', 'inquilino', 100]]])
        : cprScenario($this, [['Ines', 50], ['Dora', 50]], ['box' => [['Rita', 'proprietario', 100], ['Dora', 'inquilino', 100]]]);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    $a = fclAnteprima($this, $s, ['quota' => 50] + fclModulo($s, $s['persone']['Luca'], '2026-10-01', [$s['box']], 'Dora'));

    expect(rdpCoppia($a, 'Dora', (int) $s['unita']->id))->toBe(['Dora' => -5459, 'Luca' => 5459])
        ->and(rdpCoppia($a, 'Dora', (int) $s['box']->id))->toBe(['Dora' => -907, 'Luca' => 907])
        ->and($a['rate']['frasi'][0])->toBe('Le rate già emesse non si toccano. Nell\'unità «Interno 1» se scrivi il conguaglio, le sue righe fra Dora e Luca, che entra come inquilino, entrano nei saldi e sommano a zero: con la regola del piano la parte di Ines non cambia, '
            . 'e € 54,59 della parte di Dora passano a Luca. Se non lo scrivi, nei saldi non si scrive niente: le rate restano come sono, e Luca rimborsa a Dora € 54,59 con il prospetto degli oneri accessori. '
            . 'Per «Box 12» le righe del conguaglio, se lo scrivi, entrano nei saldi e sommano a zero, con la quota divisa in proporzione ai giorni: € 9,07 dei giorni dopo l\'uscita passano a Luca. '
            . 'Se non lo scrivi, nei saldi non si scrive niente: i giorni dopo l\'uscita restano nelle rate di Dora, ' . $rimborsoDelBox)
        ->and(fclFrasi($a))->not->toContain('€ 63,66');
})->with([
    'il piano della 1.10, che il prospetto lascia fuori per il box' => [true, 'e il rimborso di Luca a Dora si regola fra le parti: il piano «Preventivo 2026» è stato generato da una versione precedente, senza riparto registrato, e dopo questo passaggio il prospetto degli oneri accessori lo lascia fuori.'],
    'il piano con il riparto registrato, che il prospetto legge anche per il box' => [false, 'e Luca glieli rimborsa con il prospetto degli oneri accessori.'],
]);

it('Coda 245 (prospetto) — il cambio Dora → Luca con il box spuntato e un\'entrata dopo il cambio: un credito dei giorni del box, € 20,93, passa a Luca, e Dora restituisce la differenza', function () {
    // Come sopra, con l'«Affitto antenna» (entrata € 1.000,00, 30 % all'inquilino = 30000, competenza 1/10–31/12, tutta dopo il cambio): 27000 sull'Interno 1 e 3000 sul box. Box: la spesa di Luca
    // 3600 × 92/365 = 907,40 → 907, meno 3000 = − 2093 (un credito). Interno 1: spese come sopra (Dora 21659 → 16200, Luca + 5459), antenna Dora − 27000 → 0 e Luca − 27000: Luca 5459 − 27000 =
    // − 21541. In tutto − 23634. Era: «e un credito di € 236,34 dei giorni dopo l'uscita passa a Luca», la somma dei due con i € 215,41 della regola del piano detti giorni.
    $s = cprScenario($this, [['Ines', 50], ['Dora', 50]], ['box' => [['Rita', 'proprietario', 100], ['Dora', 'inquilino', 100]], 'capitoli' => [['Affitto antenna', 'entrata', 100000, '2026-10-01', '2026-12-31']]]);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    $a = fclAnteprima($this, $s, ['quota' => 50] + fclModulo($s, $s['persone']['Luca'], '2026-10-01', [$s['box']], 'Dora'));

    expect(rdpCoppia($a, 'Dora', (int) $s['unita']->id))->toBe(['Dora' => 21541, 'Luca' => -21541])
        ->and(rdpCoppia($a, 'Dora', (int) $s['box']->id))->toBe(['Dora' => 2093, 'Luca' => -2093])
        ->and($a['rate']['frasi'][0])->toContain('Per «Box 12» le righe del conguaglio, se lo scrivi, entrano nei saldi e sommano a zero, con la quota divisa in proporzione ai giorni: un credito di € 20,93 dei giorni dopo l\'uscita passa a Luca. '
            . 'Se non lo scrivi, nei saldi non si scrive niente: i giorni dopo l\'uscita restano nelle rate di Dora, e Dora restituisce a Luca la differenza con il prospetto degli oneri accessori.')
        ->and($a['rate']['frasi'][0])->toStartWith('Le rate già emesse non si toccano. Nell\'unità «Interno 1» se scrivi il conguaglio')
        ->and($a['rate']['frasi'][0])->toContain('un credito di € 215,41 della parte di Dora passa a Luca')
        ->and(fclFrasi($a))->not->toContain('€ 236,34');
});

it('Coda 245 (prospetto) — il coinquilino senza quote su un piano senza riparto registrato: la differenza la regolano le parti con il prospetto, e il prospetto dice che Luca rimborsa a Dora € 10,83', function () {
    // Ines 50, Dora 30; Luca entra il 1/12 al 20 % su un piano che non si ricalcola più, e non ha quote. Congelato 36000 × 50/80 = 22500 e 13500. La fine di
    // Ines il 1/7: pesi 9050, 30 × 365 = 10950, 20 × 31 = 620 (somma 20620): 15800,19, 19117,36, 1082,44 → Ines 15800, Dora 19117, Luca 1083 (il centesimo a lui,
    // ,44). La coppia Ines − 6700 → Dora; il resto Dora − 1083, Luca + 1083. Cambiato con la decisione 115.1: prima «la differenza la regolano le parti.», senza il
    // prospetto, che lasciava fuori il piano.
    $s = cprSenzaRiparto($this, [['Ines', 50], ['Dora', 30]]);
    cprInizio($this, $s, 'Luca', '2026-12-01', 20);
    $a = fclAnteprima($this, $s, ['quota' => 50] + fclModulo($s));
    expect(fclFrasi($a))->toContain('Luca non ha quote del piano, e nei saldi non ha righe: con la regola del piano Dora pagherebbe € 10,83 in meno e Luca pagherebbe € 10,83 in più; la differenza la regolano le parti con il prospetto degli oneri accessori.');
    $fine = cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    expect(implode(' ', fds50Storico($s, $fine)['fermate']))->toEndWith('La differenza la regolano le parti con il prospetto degli oneri accessori.');
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Ines'))->toBe([15800, 22500, -6700, 0])
        ->and(cprSezione($p, 'Dora'))->toBe([19117, 13500, 6700, -1083])
        ->and(cprRimborsi($p, 'Dora'))->toBe(['da Luca' => 1083])
        ->and(cprSezione($p, 'Luca'))->toBe([1083, 0, 0, 1083])
        ->and(cprRimborsi($p, 'Luca'))->toBe(['a Dora' => 1083]);
});

it('Coda 245 (prospetto) — il piano generato dalla 1.10 dopo che Dora ha comprato da Zeno: la regola del piano non si ricalcola più, e il piano resta fuori dal prospetto dicendo perché e le cifre della fine di Ines; l\'inizio di Luca lo dice', function () {
    // Ugo e Zeno proprietari al 50 %, Ines e Dora inquiline al 50 %. La fine di Ines il 1/7 con «Scrivi» (Ines 11934, Dora 24066: ∓ 6066), poi Zeno vende a Dora
    // la sua metà il 1/9: Dora è inquilina e comproprietaria, e sul piano senza riparto la parte dell'inquilino non si separa più dalle sue altre quote (le quote
    // pure di Dora sono anche di proprietaria). Il prospetto non la può dire con la regola del piano: lascia fuori il piano, con il perché e le cifre della fine
    // di Ines, che apre l'anello (anche dopo l'inizio di Elsa il 1/8 al 25 %, che sull'anello viene dopo). Il pannello dell'inizio di Luca il 1/10 dice lo stesso.
    $s = cprSenzaRiparto($this, opz: ['titolari' => [['Ugo', 'proprietario', 50], ['Zeno', 'proprietario', 50]]]);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    expect(cprSezione(cprProspetto($s), 'Dora'))->toBe([24066, 18000, 6066, 0]);
    cprInizio($this, $s, 'Elsa', '2026-08-01', 25);
    fds50Registra($this, $s, ['tipo' => 'vendita', 'tipologia' => 'proprietario', 'riga_uscente_id' => fds50Riga($s, 'Zeno', 'proprietario'),
        'anagrafica_entrante_id' => $s['persone']['Dora']->id, 'decorrenza' => '2026-09-01', 'quota' => 50, 'copia_autentica' => false,
        'estremi_titolo' => 'rogito notaio Verdi, rep. 4321', 'pertinenze' => []]);
    $p = cprProspetto($s);

    expect($p['piani'])->toBe([])
        ->and($p['piani_esclusi'])->toBe([['nome' => 'Preventivo 2026', 'motivo' => 'generato da una versione precedente senza riparto registrato, e su Interno 1 un inquilino ha anche un altro ruolo: la parte dell\'inquilino non si separa dalle sue altre quote, e la regola del piano non si ricalcola; con la fine della locazione del 01/07/2026 dava Ines € 119,34 e Dora € 240,66']])
        ->and($p['conduttori'])->toBe([])
        // Coda 359: la testa e la stampa dicono che il piano approvato resta fuori, non che non c'è.
        ->and(cprFrasi($p))->not->toContain('Nessun piano rate approvato in questo esercizio')
        ->and($p['intestazione'])->toContain('Il prospetto è vuoto: il piano rate approvato «Preventivo 2026» di questo esercizio non si può leggere qui, e il perché è qui sotto.')
        ->and(fclStampa($s))->toContain('Le voci a carico dell\'inquilino del piano «Preventivo 2026» non sono riportate: il piano è rimasto fuori, con il suo motivo qui sopra.');

    $a = fclAnteprima($this, $s, cprModuloInizio($s, 'Luca', '2026-10-01', 25));
    expect(fclFrasi($a))->toContain('Il piano «Preventivo 2026» è stato generato da una versione precedente senza riparto registrato, e su Interno 1 un inquilino ha anche un altro ruolo: la parte dell\'inquilino non si separa dalle sue altre quote, e la regola del piano non si ricalcola; con la fine della locazione del 1 luglio 2026 dava Ines € 119,34 e Dora € 240,66. Il prospetto degli oneri accessori lo lascia fuori, e la parte di Luca dal 1 ottobre 2026 la regolano le parti.')
        ->and(fclFrasi($a))->not->toContain('la rimborsa a chi l\'ha pagata')
        // Scritto dopo il codice: la stessa cosa nelle informazioni del cancello, in breve.
        ->and(implode(' ', $a['cancello']['informazioni']))->toContain('il piano «Preventivo 2026» non si ricalcola più: le sue quote restano a chi le ha, e la regola del piano non si ricalcola (su Interno 1 un inquilino ha anche un altro ruolo: la parte dell\'inquilino non si separa dalle sue altre quote), il prospetto degli oneri accessori lo lascia fuori, e la parte di Luca dal 1 ottobre 2026 la regolano le parti')
        ->and($a['rate']['giorni_del_conduttore'])->toBe([]);
});

it('Coda 245 (prospetto) — la vendita di Zeno a Dora, che è inquilina, ferma la regola del piano sul piano della 1.10: il cancello della vendita dice che il prospetto lo lascia fuori, con le cifre della fine di Ines e, se la fine non ha scritto il conguaglio, i € 60,66 fra le parti', function (string $scelta, array $dora, string $finale) {
    // Ugo e Zeno proprietari al 50 %, Ines e Dora inquiline al 50 %, il piano della 1.10 (18000 e 18000): la fine di Ines il 1/7: 36000 × 9050/27300 = 11934,07 → Ines 11934, Dora 24066, ∓ 6066. Dora compra
    // da Zeno il 1/9 ed è inquilina e comproprietaria: sul piano senza riparto la parte dell'inquilino non si separa più dalle sue altre quote, e il prospetto lo lascia fuori. Prima il pannello della vendita e il suo
    // cancello dicevano solo le quote (e il prospetto, dopo, spiegava il perché): a chi registra la vendita il prospetto spariva senza che nessuno glielo avesse detto.
    $s = cprSenzaRiparto($this, opz: ['titolari' => [['Ugo', 'proprietario', 50], ['Zeno', 'proprietario', 50]]]);
    cprFine($this, $s, 'Ines', '2026-07-01', $scelta);
    expect(cprSezione(cprProspetto($s), 'Dora'))->toBe($dora);
    $vendita = ['tipo' => 'vendita', 'tipologia' => 'proprietario', 'riga_uscente_id' => fds50Riga($s, 'Zeno', 'proprietario'), 'anagrafica_entrante_id' => $s['persone']['Dora']->id,
        'decorrenza' => '2026-09-01', 'quota' => 50, 'copia_autentica' => false, 'estremi_titolo' => 'rogito notaio Verdi, rep. 4321', 'pertinenze' => []];

    $a = fds50Anteprima($this, $s, $vendita);
    expect($a['cancello']['avvisi'])->toBe(['Dopo questo passaggio il prospetto degli oneri accessori lascia fuori il piano «Preventivo 2026»: è stato generato da una versione precedente senza riparto registrato, '
        . 'e su Interno 1 Dora avrà anche un altro ruolo, quindi la parte dell\'inquilino non si separa dalle sue altre quote e la regola del piano non si ricalcola. '
        . 'Con la fine della locazione del 1 luglio 2026 la regola del piano dava Ines € 119,34 e Dora € 240,66.' . $finale]);
    fds50Registra($this, $s, $vendita);
    expect(cprProspetto($s)['piani'])->toBe([])
        ->and(collect(cprProspetto($s)['piani_esclusi'])->pluck('nome')->all())->toBe(['Preventivo 2026']);
})->with([
    'la fine non scritta' => ['non_scrivere', [24066, 18000, 0, 6066], ' La fine non ha scritto il conguaglio: le parti regolano fra loro € 60,66 a credito di Ines e € 60,66 a debito di Dora, sulla gestione Ordinaria 2026.'],
    'la fine con la coppia scritta' => ['scrivi', [24066, 18000, 6066, 0], ''],
]);

it('Coda 245 (prospetto) — la vendita non dice che il prospetto lascia fuori il piano quando non è vero: chi compra non è inquilino, il piano ha il riparto, nessuna fine ha aperto l\'anello, o il piano è già fermo', function () {
    // I controlli del test di sopra (scritto dopo il codice: il rosso sono le mutazioni delle quattro guardie). Il cancello della vendita non ha nessun avviso in nessuno dei quattro casi.
    $vendita = fn (array $s, string $chi, string $compra = 'Dora') => ['tipo' => 'vendita', 'tipologia' => 'proprietario', 'riga_uscente_id' => fds50Riga($s, $chi, 'proprietario'),
        'anagrafica_entrante_id' => $s['persone'][$compra]->id, 'decorrenza' => '2026-09-01', 'quota' => 50, 'copia_autentica' => false,
        'estremi_titolo' => 'rogito notaio Verdi, rep. 4321', 'pertinenze' => []];
    $titolari = ['titolari' => [['Ugo', 'proprietario', 50], ['Zeno', 'proprietario', 50]]];

    // Chi compra non è inquilino (Elsa): il prospetto legge ancora il piano.
    $s = cprSenzaRiparto($this, opz: $titolari);
    cprFine($this, $s, 'Ines', '2026-07-01', 'non_scrivere');
    expect(fds50Anteprima($this, $s, $vendita($s, 'Zeno', 'Elsa'))['cancello']['avvisi'])->toBe([]);

    // Il piano ha il riparto registrato: il prospetto lo legge dal riparto, qualunque ruolo abbia chi compra.
    $s = cprScenario($this, opz: $titolari);
    cprFine($this, $s, 'Ines', '2026-07-01', 'non_scrivere');
    expect(fds50Anteprima($this, $s, $vendita($s, 'Zeno'))['cancello']['avvisi'])->toBe([]);

    // Nessuna fine con la regola del piano ha aperto l'anello: il piano è quello di sempre.
    $s = cprSenzaRiparto($this, opz: $titolari);
    expect(fds50Anteprima($this, $s, $vendita($s, 'Zeno'))['cancello']['avvisi'])->toBe([]);

    // Il piano è già fermo (Dora ha comprato da Zeno): una seconda vendita non cambia niente, e non lo ripete.
    $s = cprSenzaRiparto($this, opz: $titolari);
    cprFine($this, $s, 'Ines', '2026-07-01', 'non_scrivere');
    fds50Registra($this, $s, $vendita($s, 'Zeno'));
    expect(fds50Anteprima($this, $s, $vendita($s, 'Ugo', 'Dora'))['cancello']['avvisi'])->toBe([]);
});

it('Coda 245 (prospetto) — due piani generati dalla 1.10 e la vendita a Dora: tutti e due restano fuori, con le cifre della fine di Ines, e la testa, la stampa e l\'inizio di Luca li dicono insieme', function () {
    // Scritto dopo il codice (il rosso sono le mutazioni delle frasi al plurale). Il «Preventivo 2026» (anello 18000 + 18000) e l'«Integrativa pulizie 2026»
    // (€ 400,00 al 30 %: 12000, Ines 6000 e Dora 6000), tutti e due senza riparto registrato. La fine di Ines il 1/7 con «Scrivi» li prende tutti e due: 36000 ×
    // 9050/27300 → 11934 / 24066, 12000 × 9050/27300 = 3978,02 → 3978 / 8022; in tutto Ines 15912, Dora 32088. Poi Zeno vende a Dora la sua metà.
    $s = cprSenzaRiparto($this, opz: ['titolari' => [['Ugo', 'proprietario', 50], ['Zeno', 'proprietario', 50]]]);
    $pc = \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $spese = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    $s['piano']->capitoli()->syncWithoutDetaching([$spese->id => ['importo' => 120000]]);
    $tabella = \App\Models\Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $pc]), [
        'nome' => 'Pulizie scale', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'importo' => '400,00', 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $tabella->id, 'percentuale_proprietario' => 70, 'percentuale_inquilino' => 30, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Integrativa pulizie 2026', 'metodo_distribuzione' => 'prima_rata',
        'numero_rate' => 2, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-02-05', 'genera_subito' => true,
        'accetta_destinatari' => true, 'nota_destinatari' => 'Letto: generazione del piano integrativo',
    ])->assertSessionHasNoErrors();
    $integrativa = \App\Models\Gestionale\PianoRate::where('gestione_id', $s['g']->id)->where('nome', 'Integrativa pulizie 2026')->firstOrFail();
    $this->actingAs($this->user)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $integrativa]), ['approvato' => true, 'data_delibera_assemblea' => '2026-01-15'])
        ->assertSessionHasNoErrors();
    fds50Emetti($this, $s, '2026-06-30', $integrativa);
    DB::table('righe_riparto')->where('piano_rate_id', $integrativa->id)->delete();
    $fine = cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    expect(fclSaldi($s))->toBe(['Dora' => 8088, 'Ines' => -8088])
        ->and(collect($fine->registro['conguaglio']['piano'][0]['voci'])->pluck('piano_rate_id')->sort()->values()->all())->toBe([(int) $s['piano']->id, (int) $integrativa->id]);
    $vendita = ['tipo' => 'vendita', 'tipologia' => 'proprietario', 'riga_uscente_id' => fds50Riga($s, 'Zeno', 'proprietario'),
        'anagrafica_entrante_id' => $s['persone']['Dora']->id, 'decorrenza' => '2026-09-01', 'quota' => 50, 'copia_autentica' => false,
        'estremi_titolo' => 'rogito notaio Verdi, rep. 4321', 'pertinenze' => []];
    // Il cancello della vendita lo dice prima, per tutti e due i piani insieme (una frase, i nomi al plurale).
    expect(fds50Anteprima($this, $s, $vendita)['cancello']['avvisi'])->toBe(['Dopo questo passaggio il prospetto degli oneri accessori lascia fuori i piani «Preventivo 2026» e «Integrativa pulizie 2026»: '
        . 'sono stati generati da una versione precedente senza riparto registrato, e su Interno 1 Dora avrà anche un altro ruolo, quindi la parte dell\'inquilino non si separa dalle sue altre quote e la regola del piano non si ricalcola. '
        . 'Con la fine della locazione del 1 luglio 2026 la regola del piano dava Ines € 159,12 e Dora € 320,88.']);
    fds50Registra($this, $s, $vendita);
    $p = cprProspetto($s);

    $motivo = 'generato da una versione precedente senza riparto registrato, e su Interno 1 un inquilino ha anche un altro ruolo: la parte dell\'inquilino non si separa dalle sue altre quote, e la regola del piano non si ricalcola; con la fine della locazione del 01/07/2026 dava Ines € 159,12 e Dora € 320,88';
    expect($p['piani_esclusi'])->toBe([['nome' => 'Preventivo 2026', 'motivo' => $motivo], ['nome' => 'Integrativa pulizie 2026', 'motivo' => $motivo]])
        ->and($p['intestazione'])->toContain('Il prospetto è vuoto: i piani rate approvati «Preventivo 2026», «Integrativa pulizie 2026» di questo esercizio non si possono leggere qui, e il perché è qui sotto.')
        ->and(fclStampa($s))->toContain('Le voci a carico dell\'inquilino dei piani «Preventivo 2026», «Integrativa pulizie 2026» non sono riportate: i piani sono rimasti fuori, con il loro motivo qui sopra.');
    $a = fclAnteprima($this, $s, cprModuloInizio($s, 'Luca', '2026-10-01'));
    expect(fclFrasi($a))->toContain('I piani «Preventivo 2026» e «Integrativa pulizie 2026» non si ricalcolano più: anche le rate ancora da emettere restano a chi le ha. I piani «Preventivo 2026» e «Integrativa pulizie 2026» sono stati generati da una versione precedente senza riparto registrato, e su Interno 1 un inquilino ha anche un altro ruolo: la parte dell\'inquilino non si separa dalle sue altre quote, e la regola del piano non si ricalcola; con la fine della locazione del 1 luglio 2026 dava Ines € 159,12 e Dora € 320,88. Il prospetto degli oneri accessori li lascia fuori, e la parte di Luca dal 1 ottobre 2026 la regolano le parti.')
        ->and(implode(' ', $a['cancello']['informazioni']))->toContain('i piani «Preventivo 2026» e «Integrativa pulizie 2026» non si ricalcolano più: le loro quote restano a chi le ha, e la regola del piano non si ricalcola (su Interno 1 un inquilino ha anche un altro ruolo: la parte dell\'inquilino non si separa dalle sue altre quote), il prospetto degli oneri accessori li lascia fuori, e la parte di Luca dal 1 ottobre 2026 la regolano le parti');
});

// --- Gli altri casi della strada della regola del piano -----------------------------------------------------------------------------------------------

it('Coda 245 (prospetto) — «Annulla il conguaglio» della fine di Ines: le parti hanno regolato fra loro la differenza, € 60,66, e il prospetto la dice regolata, senza nessuno da rimborsare', function () {
    $s = cprScenario($this);
    $fine = cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    sl53AnnullaConguaglio($this, $s, $fine)->assertStatus(302);
    expect(fclSaldi($s))->toBe([]);
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Ines'))->toBe([11934, 18000, 0, 0])
        ->and(collect($p['conduttori'])->firstWhere('nome', 'Ines')['regolato_fra_le_parti'])->toBe(-6066)
        ->and(collect($p['conduttori'])->firstWhere('nome', 'Dora')['regolato_fra_le_parti'])->toBe(6066)
        ->and(cprRimborsi($p, 'Ines'))->toBe([])
        ->and(cprFrasi($p))->toContain('Alla fine della locazione del 01/07/2026 la locazione è continuata con Dora, e il conguaglio scritto con la regola del piano è stato annullato: le parti hanno regolato fra loro la differenza di quella fine («regolata fra le parti»), e la parte dell\'inquilino si divide per quota e per giorni fra chi è stato conduttore nell\'anno.');
    expect(fclStampa($s))->toContain('regolata fra le parti alla fine della locazione del 01/07/2026, a suo credito € 60,66');
});

it('Coda 245 (prospetto) — i giorni che non copre nessuno, neanche chi sta al posto dell\'inquilino: la loro parte, € 30,58, è nelle rate di Ines, e le si restituisce fra le parti', function () {
    // B6 con la riga di Ugo chiusa a mano al 30/11: dal 1/12 nessuno. Ines 10735, Dora 16191, Ugo 6016 (i 61 giorni dal 1/10 al 30/11: 36000 × 61/365 =
    // 6016,44), e i 31 giorni di dicembre 36000 × 31/365 = 3057,53 → 3058 (i centesimi ai resti più grandi, Ines ,85 e i giorni di nessuno ,53). La coppia
    // scritta: Ines − 6803, Dora + 787, Ugo + 6016. Ines: 10735 − 20596 + 6803 = − 3058, i giorni di dicembre.
    $s = cprScenario($this, opz: ['fini' => ['Dora' => '2026-09-30']]);
    DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['persone']['Ugo']->id)->update(['data_fine' => '2026-11-30']);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    $p = cprProspetto($s);

    expect(fclSaldi($s))->toBe(['Dora' => 787, 'Ines' => -6803, 'Ugo' => 6016])
        ->and(cprSezione($p, 'Ines'))->toBe([10735, 20596, -6803, -3058])
        ->and(cprRimborsi($p, 'Ines'))->toBe(['da ' => 3058])
        ->and(cprSezione($p, 'Dora'))->toBe([16191, 15404, 787, 0])
        ->and(collect($p['senza_conduttore']['voci'])->map(fn ($v) => [$v['modo'], $v['pagato_da'], (int) $v['importo'], (int) $v['giorni']])->all())->toBe([['da_restituire', 'Ines', 3058, 31], ['piano', null, 6016, 61]])
        ->and(cprSenza($p))->toBe([9074, 6016, 0])
        ->and($p['totale_inquilino'])->toBe(36000);
    expect(fclStampa($s))->toContain('da restituire a Ines fra le parti: i giorni che non copre nessuno, neanche chi sta al posto dell\'inquilino € 30,58');
});

it('Coda 245 (prospetto) — C4 con i saldi del Wallet legati alla fine di Dora per il resto: il prospetto dice ancora chi rimborsa chi, e dice i fatti di quei saldi', function () {
    $s = cprScenario($this);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    $dora = cprFine($this, $s, 'Dora', '2026-10-01', 'scrivi');
    expect(sl53Errori(sl53Posta($this, $s, 'Ines', -1199, (int) $dora->id)))->toBe([])
        ->and(sl53Errori(sl53Posta($this, $s, 'Ugo', 1199, (int) $dora->id)))->toBe([]);
    $p = cprProspetto($s);

    // Le righe legate non entrano nei conti del piano (decisione 100.2): la parte resta, e la frase dice i fatti.
    expect(cprRimborsi($p, 'Ines'))->toBe(['da Ugo' => 1199])
        ->and(cprFrasi($p))->toContain('Ci sono saldi del Wallet legati alla fine della locazione di Dora del 1 ottobre 2026: € 11,99 a credito di Ines e € 11,99 a debito di Ugo; se sono la parte che qui è «da restituire» e «da rimborsare», quella parte è già regolata.');
});

it('Coda 245 (prospetto) — una voce che nessun passaggio cambia resta com\'è: le «Pulizie del primo semestre» € 10,03, che il piano ha dato con il centesimo a Ines, non si ridividono', function () {
    // Ines, Dora e Pia al 30 %. Le pulizie (1/1–30/6, al 30 %: 300,9) le ha divise il motore con Ugo, il centesimo alla prima riga: Ines 101, Dora 100, Pia
    // 100. Dora esce il 1/7: le pulizie sono tutte prima, nessuno cambia, e restano così (ridividerle darebbe il centesimo a chi esce, Dora). Le spese
    // generali: Ines 30 × 365 = 10950, Dora 30 × 181 = 5430, Pia 10950 (somma 27330): 14423,70, 7152,59, 14423,70 → Ines 14424, Dora 7152, Pia 14424.
    $s = cprScenario($this, [['Ines', 30], ['Dora', 30], ['Pia', 30]], ['capitoli' => [['Pulizie del primo semestre', 'spesa', 1003, '2026-01-01', '2026-06-30']]]);
    cprFine($this, $s, 'Dora', '2026-07-01', 'scrivi');
    $p = cprProspetto($s);

    expect(fclSaldi($s))->toBe(['Dora' => -4848, 'Ines' => 2424, 'Pia' => 2424])
        ->and(cprVoci($p, 'Dora'))->toBe([['Spese generali', 7152, 181, 12000], ['Pulizie del primo semestre', 100, 181, 100]])
        ->and(cprSezione($p, 'Dora'))->toBe([7252, 12100, -4848, 0])
        ->and(cprSezione($p, 'Ines'))->toBe([14525, 12101, 2424, 0])
        ->and(cprSezione($p, 'Pia'))->toBe([14524, 12100, 2424, 0]);
});

it('Coda 245 (prospetto) — il già versato della persona: € 120,00 versati da Ines restano suoi, accanto alla sua parte con la regola del piano', function () {
    // Il versato di Ines non entra nell'anello (36000, congelato 18000 e 18000): la coppia è ∓ 6066. Ines ha nelle sue rate 18000 − 12000 = 6000; la sua
    // parte è 11934, meno i 12000 che ha versato: − 66, a suo favore. Con la coppia scritta (− 6066) non c'è niente da rimborsare.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    $conto = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->whereIn('piano_conto_id', \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->pluck('id'))->firstOrFail();
    DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $conto->id, 'immobile_id' => $s['unita']->id,
        'anagrafica_id' => $s['persone']['Ines']->id, 'importo_cents' => 12000, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    $p = cprProspetto($s);

    expect(fclNetti($s))->toBe(['Dora' => 24066, 'Ines' => -66, 'Ugo' => 84000])
        ->and(cprSezione($p, 'Ines'))->toBe([-66, 6000, -6066, 0])
        ->and(collect(collect($p['conduttori'])->firstWhere('nome', 'Ines')['voci'])->map(fn ($v) => [$v['conto'], $v['modo'], (int) $v['importo']])->all())
            ->toBe([['Spese generali', 'piano', 11934], ['Spese generali — già versato', 'rate', -12000]])
        ->and(cprSezione($p, 'Dora'))->toBe([24066, 18000, 6066, 0]);
});

it('Coda 245 (prospetto) — C6.5a-ns: Ines con «Non scriverlo», poi Dora esce lo stesso giorno con «Scrivi»: Ines ha pagato € 90,74 più della sua parte, € 60,66 da Dora e € 30,08 da Ugo', function () {
    // Dopo tutte e due: Ines 8926, Dora 8926 (36000 × 90,5/365 = 8926,03), Ugo i 184 giorni dal 1/7, 18148. La fine di Dora sull'anello: prima Ines 11934 e
    // Dora 24066, dopo 8926, 8926 e 18148: Dora − 15140 → Ugo, fuori Ines − 3008 e Ugo + 3008.
    $s = cprScenario($this);
    cprFine($this, $s, 'Ines', '2026-07-01', 'non_scrivere');
    cprFine($this, $s, 'Dora', '2026-07-01', 'scrivi');
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Ines'))->toBe([8926, 18000, 0, -9074])
        ->and(cprRimborsi($p, 'Ines'))->toBe(['da Dora' => 6066, 'da Ugo' => 3008])
        ->and(cprSezione($p, 'Dora'))->toBe([8926, 18000, -15140, 6066])
        ->and(cprSenza($p))->toBe([18148, 15140, 3008]);
});

it('Coda 245 (prospetto) — B6-Luca: Luca entra il 1/11 dopo la fine di Ines con Dora già chiusa al 30/9; la regola del piano gli dà € 39,02, e con Ines e Dora li rimborsa a Ugo, che li ha pagati con il conguaglio', function () {
    // B6 «Scrivi»: Ines − 9861, Dora + 787, Ugo + 9074. Con Luca dal 1/11 al 50 %: Luca 3902, Ines 11578, Dora 17463, Ugo 3057 (il conto nel docblock di
    // `Beta55CoinquilinoDopoLaCoppiaTest`); l'inizio: Luca + 3902, Ines + 843, Dora + 1272, Ugo − 6017. Ugo ha pagato 9074 con il conguaglio e la sua parte è
    // 3057: gli rimborsano 6017 Luca, Ines e Dora.
    $s = cprScenario($this, opz: ['fini' => ['Dora' => '2026-09-30']]);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    cprInizio($this, $s, 'Luca', '2026-11-01');
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Luca'))->toBe([3902, 0, 0, 3902])
        ->and(cprRimborsi($p, 'Luca'))->toBe(['a Ugo' => 3902])
        ->and(cprSezione($p, 'Ines'))->toBe([11578, 20596, -9861, 843])
        ->and(cprRimborsi($p, 'Ines'))->toBe(['a Ugo' => 843])
        ->and(cprSezione($p, 'Dora'))->toBe([17463, 15404, 787, 1272])
        ->and(cprSenza($p))->toBe([3057, 9074, -6017])
        ->and(cprRimborsi($p, null))->toBe(['da Dora' => 1272, 'da Ines' => 843, 'da Luca' => 3902]);
});

it('Coda 245 (prospetto) — B6-vendita-Luca: dopo la vendita di Ugo a Elsa sul ripiego, i giorni di Elsa li ha pagati Ugo con il conguaglio: Luca, Ines e Dora rimborsano Ugo, o Elsa se le parti hanno regolato la fermata della vendita', function () {
    // L'inizio di Luca il 1/12: Luca + 1916, Ines + 455, Dora + 686, Elsa − 3057 (il pannello). Elsa non ha pagato niente: la sua riga le è arrivata con la
    // vendita, che si è fermata sulla parte del ripiego (€ 60,16 a carico di Ugo, poi € 29,59). I giorni di dicembre li ha pagati Ugo con il conguaglio
    // della fine di Ines (Coda 352): Luca rimborsa a Ugo € 19,16, o a Elsa se le parti hanno regolato fra loro la fermata. Senza conduttore: Ugo 3058 ed
    // Elsa 2959, 6017; con il conguaglio Ugo ha pagato 9074, e gli si rimborsano 3057.
    $s = cprScenario($this, opz: ['fini' => ['Dora' => '2026-09-30']]);
    cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-11-01'));
    cprInizio($this, $s, 'Luca', '2026-12-01');
    $p = cprProspetto($s);
    $alternativa = 'Elsa, se le parti hanno regolato fra loro la parte del conguaglio che la vendita del 01/11/2026 non ha spostato';

    expect(cprSezione($p, 'Luca'))->toBe([1916, 0, 0, 1916])
        ->and(cprRimborsi($p, 'Luca'))->toBe(['a Ugo' => 1916])
        ->and(collect($p['conduttori'])->firstWhere('nome', 'Luca')['regola_del_piano']['rimborsi'][0]['oppure'])->toBe($alternativa)
        ->and(cprSezione($p, 'Ines'))->toBe([11190, 20596, -9861, 455])
        ->and(cprRimborsi($p, 'Ines'))->toBe(['a Ugo' => 455])
        ->and(cprSezione($p, 'Dora'))->toBe([16877, 15404, 787, 686])
        ->and(cprRimborsi($p, 'Dora'))->toBe(['a Ugo' => 686])
        ->and(cprSenza($p))->toBe([6017, 9074, -3057]);
    expect(fclStampa($s))->toContain('da rimborsare a Ugo (o a ' . $alternativa . '): con l\'inizio della locazione di Luca del 01/12/2026 € 19,16');
});

it('Coda 245 (prospetto) — «Annulla il conguaglio» della fine di Ines dopo la fine di Dora: il prospetto dice le cifre del piano di oggi, come promette l\'avviso; la coppia di Dora resta, e Ugo restituisce a Ines € 11,99', function () {
    // Dopo C4 l'annullamento del conguaglio della fine di Ines toglie le sue righe (Ines − 6066, Dora + 6066): le parti l'hanno regolata fra loro. Ines: 10735 − 18000
    // − (− 6066 regolati) = − 1199, il resto della fine di Dora; Dora 16191 − 18000 − (− 7875) − 6066 = 0.
    $s = cprScenario($this);
    $ines = cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    cprFine($this, $s, 'Dora', '2026-10-01', 'scrivi');
    sl53AnnullaConguaglio($this, $s, $ines)->assertStatus(302);
    $p = cprProspetto($s);

    expect(fclSaldi($s))->toBe(['Dora' => -7875, 'Ugo' => 7875])
        ->and(cprSezione($p, 'Ines'))->toBe([10735, 18000, 0, -1199])
        ->and(collect($p['conduttori'])->firstWhere('nome', 'Ines')['regolato_fra_le_parti'])->toBe(-6066)
        ->and(cprRimborsi($p, 'Ines'))->toBe(['da Ugo' => 1199])
        ->and(cprSezione($p, 'Dora'))->toBe([16191, 18000, -7875, 0])
        ->and(cprSenza($p))->toBe([9074, 7875, 1199]);
});

it('Coda 245 (prospetto) — a pari resto la parte di oggi è quella che la fine ha scritto: Dora esce il 31/7, e il centesimo è suo, come nella coppia', function () {
    // Dora esce il 31/7 (la sua riga chiusa il 30/7, 211 giorni): pesi Ines 50 × 365 = 18250, Dora 50 × 211 = 10550 (somma 28800): 36000 × 10550/28800 = 13187,5 e 22812,5, un pari resto esatto:
    // il centesimo va a chi esce, Dora 13188 e Ines 22812 (la coppia Dora − 4812, Ines + 4812). Nel prospetto la parte di oggi è la stessa, al centesimo: niente da
    // rimborsare.
    $s = cprScenario($this);
    cprFine($this, $s, 'Dora', '2026-07-31', 'scrivi');
    $p = cprProspetto($s);

    expect(fclSaldi($s))->toBe(['Dora' => -4812, 'Ines' => 4812])
        ->and(cprSezione($p, 'Dora'))->toBe([13188, 18000, -4812, 0])
        ->and(cprSezione($p, 'Ines'))->toBe([22812, 18000, 4812, 0]);
});

// --- Un piano della stessa gestione generato dopo la fine -----------------------------------------------------------------------------------------

it('Coda 245 (prospetto) — un piano integrativo della stessa gestione generato dopo la fine di Ines: la fine di Dora lo prende, e il prospetto legge l\'anello una volta, con la coppia di Dora contata una volta sola; Dora è al piano, e Ugo restituisce a Ines € 15,99', function () {
    // Il piano integrativo («Pulizie scale» € 400,00 al 30 %: l'anello è 12000) è generato con la riga di Ines già chiusa al 30/6: 12000 × 9050/27300 = 3978,02 →
    // Ines 3978, Dora 8022, ed è solo nella voce della fine di Dora. La fine di Dora il 1/10: sul preventivo Ines 10735, Dora 16191, Ugo 9074 (prima 11934 / 24066:
    // Ines − 1199, Dora − 7875, Ugo + 9074); sul piano integrativo 12000 × 273/365 × 9050/22700 = 3578,28, Dora 5397,07, Ugo 12000 × 92/365 = 3024,66 → Ines 3578,
    // Dora 5397, Ugo 3025 (il centesimo a Ugo, ,66; prima 3978 / 8022: Ines − 400, Dora − 2625, Ugo + 3025). La coppia Dora − 10500 → Ugo; il resto Ines − 1599,
    // Ugo + 1599. Dora: parte 16191 + 5397 = 21588, nelle sue rate 18000 + 8022 = 26022, conguagli + 6066 − 10500 = − 4434: niente da rimborsare. Ines: 10735 +
    // 3578 = 14313, nelle rate 18000 + 3978 = 21978, conguaglio − 6066: € 15,99 da Ugo, una volta. Senza conduttore, Ugo: 9074 + 3025 = 12099, 10500 con il
    // conguaglio, 1599 da restituire a Ines. Era: la coppia di Dora contata due volte (Dora «da rimborsare a chi le ha pagate € 105,00», Ugo − € 89,01).
    $s = rdpConIntegrativa($this);
    expect(collect($s['fine_ines']->registro['conguaglio']['piano'][0]['voci'])->pluck('piano_rate_id')->all())->toBe([(int) $s['piano']->id]);
    $dora = cprFine($this, $s, 'Dora', '2026-10-01', 'scrivi');
    expect(fds50Coppia($s, $dora))->toBe(['Dora' => -10500, 'Ugo' => 10500]);
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Dora'))->toBe([21588, 26022, -4434, 0])
        ->and(cprVoci($p, 'Dora'))->toBe([['Spese generali', 16191, 273, 18000], ['Pulizie scale', 5397, 273, 8022]])
        ->and(cprRimborsi($p, 'Dora'))->toBe([])
        ->and(cprSezione($p, 'Ines'))->toBe([14313, 21978, -6066, -1599])
        ->and(cprVoci($p, 'Ines'))->toBe([['Spese generali', 10735, 181, 18000], ['Pulizie scale', 3578, 181, 3978]])
        ->and(cprRimborsi($p, 'Ines'))->toBe(['da Ugo' => 1599])
        ->and(cprSenza($p))->toBe([12099, 10500, 1599])
        ->and(cprRimborsi($p, null))->toBe(['a Ines' => 1599])
        ->and($p['totale_inquilino'])->toBe(48000)
        ->and(substr_count(cprFrasi($p), 'Alla fine della locazione del 01/07/2026 la locazione è continuata con Dora'))->toBe(1);
    expect(fclStampa($s))->not->toContain('da rimborsare a chi le ha pagate')
        ->and(fclStampa($s))->toContain('Totale a carico di Dora € 215,88');

    // Il controllo: lo stesso piano integrativo generato prima della fine di Ines, che allora lo prende (Ines 6000 e Dora 6000 nelle rate, la coppia della fine di
    // Ines − 8088): le stesse parti, e il prospetto le dice uguali.
    $s = rdpConIntegrativa($this, false);
    cprFine($this, $s, 'Dora', '2026-10-01', 'scrivi');
    $p = cprProspetto($s);
    expect(cprSezione($p, 'Dora'))->toBe([21588, 24000, -2412, 0])
        ->and(cprSezione($p, 'Ines'))->toBe([14313, 24000, -8088, -1599])
        ->and(cprRimborsi($p, 'Ines'))->toBe(['da Ugo' => 1599])
        ->and(cprSenza($p))->toBe([12099, 10500, 1599]);
});

it('Coda 245 (prospetto) — il preventivo generato dopo la fine di Ines, che ha preso solo il piano integrativo: il prospetto legge l\'anello con tutti e due i piani, anche se il primo non è nella voce della fine', function () {
    // Il piano integrativo (12000) generato prima della fine di Ines: congelato 6000 / 6000; la fine di Ines lo prende da solo: 12000 × 9050/27300 = 3978,02 → Ines
    // − 2022, Dora + 2022. Il preventivo (36000) generato dopo, con la riga di Ines chiusa: 11934 / 24066. La fine di Dora prende i due piani: sul preventivo Ines − 1199,
    // Dora − 7875, Ugo + 9074; sull'integrativo (prima 3978 / 8022) Ines − 400, Dora − 2625, Ugo + 3025; la coppia Dora − 10500 → Ugo, il resto Ines − 1599 / Ugo + 1599.
    // Dora: 16191 + 5397 = 21588, nelle rate 24066 + 6000 = 30066, conguagli + 2022 − 10500 = − 8478: al piano. Ines: 10735 + 3578 = 14313, nelle rate 11934 + 6000 =
    // 17934, conguaglio − 2022: € 15,99 da Ugo. Il preventivo viene per primo nel prospetto, e non è nella voce della fine di Ines.
    $s = rdpConIntegrativa($this, false, true);
    expect(collect($s['fine_ines']->registro['conguaglio']['piano'][0]['voci'])->pluck('piano_rate_id')->all())->toBe([(int) $s['integrativa']->id]);
    cprFine($this, $s, 'Dora', '2026-10-01', 'scrivi');
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Dora'))->toBe([21588, 30066, -8478, 0])
        ->and(cprSezione($p, 'Ines'))->toBe([14313, 17934, -2022, -1599])
        ->and(cprRimborsi($p, 'Ines'))->toBe(['da Ugo' => 1599])
        ->and(cprSenza($p))->toBe([12099, 10500, 1599]);
});

it('Coda 245 (prospetto) — il piano integrativo generato dopo la fine di Ines, e la fine di Dora con «Non scriverlo»: Ugo restituisce € 105,00 a Dora e € 15,99 a Ines, una volta', function () {
    // Le stesse parti del caso con «Scrivi»; niente in saldi alla fine di Dora: Dora 21588 − 26022 − 6066 = − 10500, Ines − 1599; senza conduttore Ugo 12099, tutto da
    // rimborsare: 10500 a Dora e 1599 a Ines. Era: ogni rimborso detto due volte (€ 210,00 a Dora e € 31,98 a Ines sulla carta).
    $s = rdpConIntegrativa($this);
    cprFine($this, $s, 'Dora', '2026-10-01', 'non_scrivere');
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Dora'))->toBe([21588, 26022, 6066, -10500])
        ->and(cprRimborsi($p, 'Dora'))->toBe(['da Ugo' => 10500])
        ->and(cprSezione($p, 'Ines'))->toBe([14313, 21978, -6066, -1599])
        ->and(cprRimborsi($p, 'Ines'))->toBe(['da Ugo' => 1599])
        ->and(cprSenza($p))->toBe([12099, 0, 12099])
        ->and(cprRimborsi($p, null))->toBe(['a Dora' => 10500, 'a Ines' => 1599]);
});

it('Coda 245 (prospetto) — la fine di Ines con il «Riscaldamento 2026» su un\'altra gestione: la regola del piano vale sulle due gestioni, e il prospetto legge anche il riscaldamento con la regola del piano e con il suo conguaglio', function () {
    // Il «Riscaldamento 2026» (Combustibile € 400,00, tutto all'inquilino: l'anello è 40000, congelato 20000 e 20000), generato prima della fine di Ines e con le rate da
    // gennaio a giugno a giornale. La fine di Ines il 1/7: 40000 × 9050/27300 = 13260,07 → Ines 13260, Dora 26740: la coppia − 6740 / + 6740, accanto a quella del
    // preventivo (∓ 6066). Ines: 11934 + 13260 = 25194, nelle sue rate 38000, conguagli − 12806: niente da rimborsare; Dora 24066 + 26740 = 50806, 38000, + 12806.
    // Era: il riscaldamento letto come una fine delle beta con la fermata del coinquilino («chi li paga lo decidono le parti»), senza il suo conguaglio di € 67,40.
    $s = cprScenario($this);
    $r = b51aRiscaldamento($s, 40000, null, null);
    b51aEmettiPiano($this, $s, $r, '2026-06-30');
    $fine = cprFine($this, $s, 'Ines', '2026-07-01', 'scrivi');
    expect(collect($fine->registro['conguaglio']['piano'])->map(fn ($g) => [(int) $g['gestione_id'], array_values(array_map('intval', $g['parti']))])->all())
        ->toBe([[(int) $s['g']->id, [-6066, 6066]], [(int) $r->gestione_id, [-6740, 6740]]]);
    $p = cprProspetto($s);

    expect(cprSezione($p, 'Ines'))->toBe([25194, 38000, -12806, 0])
        ->and(cprVoci($p, 'Ines'))->toBe([['Spese generali', 11934, 181, 18000], ['Combustibile', 13260, 181, 20000]])
        ->and(cprSezione($p, 'Dora'))->toBe([50806, 38000, 12806, 0])
        ->and(cprVoci($p, 'Dora'))->toBe([['Spese generali', 24066, 365, 18000], ['Combustibile', 26740, 365, 20000]])
        ->and(cprFrasi($p))->not->toContain('lo decidono le parti')
        ->and(substr_count(cprFrasi($p), 'Alla fine della locazione del 01/07/2026 la locazione è continuata con Dora'))->toBe(1)
        ->and(collect($p['conduttori'])->flatMap(fn ($c) => $c['voci'])->contains(fn ($v) => ($v['strada'] ?? null) === 'C'))->toBeFalse();
});
