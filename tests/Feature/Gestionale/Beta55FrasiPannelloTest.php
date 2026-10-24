<?php

/**
 * 1.11.0-beta.55, Coda 245 — le frasi del pannello e dell'avviso dei passaggi sull'anello della regola del piano: dove una parola, una data o una persona
 * sbagliavano senza toccare una cifra. Ogni test parte dalle rotte della pagina (anteprima, registrazione, annullamento) e legge il testo che l'amministratore
 * legge.
 *
 * I CONTI A MANO (centesimi; anno 2026, 365 giorni; «Spese generali» € 1.200,00 al 30 % all'inquilino: l'anello dell'Interno 1 è 36000; Ugo proprietario):
 * - Ines 50 e Dora 50, la riga di Dora chiusa al 30/9 prima della generazione (273 giorni): congelato 50 × 365 = 18250 e 50 × 273 = 13650 (somma 31900),
 *   36000 × 18250/31900 = 20595,61 → 20596 e 15404. La fine di Ines il 1/7 con «Scrivi»: Ines 181 giorni, Dora 273, i 92 giorni dal 1/10 a Ugo. Ugo vende a Elsa
 *   il 1/11, Luca entra il 15/11 al 50 %, e la sua locazione finisce il 1/12.
 * - Prima della fine di Luca (47 giorni, dal 15/11): i giorni scoperti sono 31 di Ugo (1/10-31/10) e 14 di Elsa (1/11-14/11), 45 in tutto; i pesi di
 *   Ines 9050, Dora 13650 e Luca 50 × 47 = 2350 (somma 25050) si normalizzano sui 320 giorni coperti, e l'anello si divide in 365: Ines 11402,51, Dora 17198,26,
 *   Luca 2960,87, Ugo 3057,53, Elsa 1380,82 → 11402 / 17198 / 2961 / 3058 / 1381.
 * - Dopo la fine di Luca (16 giorni, dal 15/11 al 30/11): 76 giorni scoperti, 31 di Ugo e 45 di Elsa (14 + i 31 dal 1/12); i pesi 9050, 13650 e 800 (somma
 *   23500) sui 289 coperti: Ines 10977,11, Dora 16556,64, Luca 970,35, Ugo 3057,53, Elsa 4438,36 → 10977 / 16557 / 970 / 3058 / 4438 (i due centesimi a Dora
 *   e a Ugo). I giorni che la fine di Luca restituisce a chi paga al posto dell'inquilino sono quelli dal 1/12: Elsa passa da 1381 a 4438 (+ 3057), Ugo resta
 *   a 3058.
 * - Ines 40, Dora 30, Pia 30, la riga di Pia chiusa al 30/6 e quella di Dora al 30/9 prima della generazione; Ines è aperta tutto l'anno: congelato
 *   40 × 365 = 14600, 30 × 273 = 8190 e 30 × 181 = 5430 (somma 28220), 36000 × 14600/28220 = 18625,09, × 8190/28220 = 10447,91 e × 5430/28220 = 6927,00 → Ines 18625,
 *   Dora 10448 (il centesimo che avanza va al resto più grande, ,91), Pia 6927. La fine di Ines il 1/7 con
 *   «Scrivi»: Ines 181 giorni, Dora 273, Pia 181 e i 92 giorni dal 1/10 a Ugo; 7240, 8190 e 5430 (somma 20860) sui 273 coperti, più i 92 di Ugo: Ines 9345, Dora 10572,
 *   Pia 7009, Ugo 9074. Le differenze: Ines − 9280, Dora + 124, Pia + 82, Ugo + 9074. Pia è già uscita e non riceve niente; i giorni di Ines vanno a Dora e a Ugo, in
 *   proporzione alle loro differenze (124 e 9074 su 9198): Dora 125,1 e Ugo 9154,9 → 125 e 9155. Il resto: Pia + 82, Dora 124 − 125 = − 1, Ugo 9074 − 9155 = − 81.
 *   Ugo ha € 0,81 di credito che non sono nel conguaglio: li deve la sola Pia (+ 82); Dora ha anche lei un credito (€ 0,01), e non li deve.
 * - Ines 50 e Dora 50, il piano congelato 18000 e 18000. La fine di Ines il 1/7 con «Scrivi»: Ines 181 giorni (9050), Dora 365 (18250), somma 27300:
 *   36000 × 9050/27300 = 11934,07 → Ines 11934, Dora 24066, coppia ∓ 6066. La fine di Dora il 1/10: Ines 9050, Dora 13650 (273 giorni), somma 22700, sui 273
 *   coperti più i 92 di Ugo: Ines 10734,85, Dora 16191,18, Ugo 9073,97 → 10735 / 16191 / 9074. Differenze Ines − 1199, Dora − 7875, Ugo + 9074: la coppia di
 *   Dora − 7875 → Ugo, il resto Ines − 1199 / Ugo + 1199 (a Dora 24066 − 7875 = 16191).
 * - Il «Preventivo 2026» di Ines 50 e Dora 50, la riga di Dora chiusa al 30/9 prima della generazione (B6) e quella di Ugo al 30/11 dopo: dal 1/12 nessuno. La fine
 *   di Ines il 1/7: Ines 10735 + 3058 (i 31 giorni di dicembre, 36000 × 31/365 = 3057,53 → 3058) = 13793 contro 20596, Dora 16191 contro 15404, Ugo 6016 (61 giorni):
 *   coppia Ines − 6803, Dora + 787, Ugo + 6016. I 3058 dei giorni di nessuno restano nelle rate di Ines: la coppia non porta ognuno alla sua parte.
 * - Ines 40, Dora 30, Pia 30 sull'Interno 1 (900/1000, l'anello 120000 × 0,9 × 30 % = 32400), la riga di Pia chiusa al 30/6 prima della generazione, e il Box 12 di Rita con
 *   Ines sola inquilina. Congelato: 40 × 365 = 14600, 30 × 365 = 10950 e 30 × 181 = 5430 (somma 30980): 15269,21 / 11451,90 / 5678,89 → 15269 / 11452 / 5679. Dopo la
 *   fine di Ines: 7240, 10950, 5430 (somma 23620): 9931,24 / 15020,32 / 7448,43 → 9931 / 15020 / 7449. Differenze Ines − 5338, Dora + 3568, Pia + 1770: la coppia
 *   Ines − 5338 → Dora, il resto Dora − 1770 / Pia + 1770 (Pia è già uscita e non riceve niente). Sul box 3600 × 184/365 = 1814,79 → Ines − 1815 → Rita.
 * - Lo storico di «Non scriverlo»: C1 (Ines e Dora al 50 %, anello 36000, Ines esce il 1/7): 36000 × 9050/27300 = 11934,07 → Ines 11934, Dora 24066, Ines − 6066 e Dora
 *   + 6066, € 60,66 che le parti si regolano fra loro. Senza coinquilino (Ines sola, Ugo proprietario): 36000 × 184/365 = 18147,95 → Ines − 18148, Ugo + 18148.
 * - La rivendita con due voci (Bruno 50 % proprietario, Ugo nudo e Zeno usufruttuario al 50 %; la coppia di Ines 18148, Bruno 9074 e Zeno 9074 con l'estinzione): Bruno compra la nuda
 *   da Ugo il 1/10 e l'usufrutto di Zeno si estingue lo stesso giorno; Bruno rivende tutto a Carlo il 1/12, 31 giorni di 184: 18148 × 3100/18400 = 3057,54 → 3058, metà dalla riga
 *   di Bruno e metà dalla quota che l'estinzione gli ha lasciato nella riga di Zeno, 1529 e 1529: una voce a carico di Bruno, con il Wallet, e una a carico di Zeno (o di Bruno, se la
 *   fermata dell'estinzione è regolata), senza.
 */

use App\Models\Gestionale\Subentro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Beta55CoinquilinoScenari.php';
require_once __DIR__.'/Beta53SaldoLegatoScenari.php';
require_once __DIR__.'/FermataDopoScriviHelpers.php';
require_once __DIR__.'/Beta55FermataScenari.php';

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

/** Il modulo della fine della locazione di `$chi` il giorno dato, con la quota della sua riga. */
function fppModuloFine(array $s, string $chi, string $il): array
{
    $quota = (float) DB::table('anagrafica_immobile')->where('id', fclRiga($s, $chi))->value('quota');

    return ['quota' => $quota] + fclModulo($s, null, $il, [], $chi);
}

it('Coda 245 (dopo la coppia) — la fine di Luca il 1/12 restituisce i giorni dal 1 dicembre, non dal primo giorno senza conduttore di tutta la voce, e li nomina a chi li prende', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['fini' => ['Dora' => '2026-09-30']]);
    foreach (['Luca', 'Elsa'] as $n) {
        fclPersona($s, $n);
    }
    fclRegistra($this, $s, fppModuloFine($s, 'Ines', '2026-07-01') + ['conguaglio' => 'scrivi']);
    fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-11-01'));
    fds50Registra($this, $s, array_replace(fds50ModuloInizio($s, 'Luca', '2026-11-15'), ['quota' => 50]));

    $a = fclAnteprima($this, $s, fppModuloFine($s, 'Luca', '2026-12-01'));
    $frasi = fclFrasi($a);

    // Era: «i giorni dal 1 ottobre 2026 vanno a chi paga al posto dell'inquilino, Ugo e Elsa» (il primo giorno scoperto della voce, e Ugo, la cui parte non cambia).
    expect($frasi)->toContain('Prima di questo passaggio, con la regola del piano, sulla gestione Ordinaria 2026 Ines € 114,02, Dora € 171,98, Luca € 29,61, Ugo € 30,58 e Elsa € 13,81. '
            . 'Con la fine della locazione di Luca i giorni dal 1 dicembre 2026 vanno a chi paga al posto dell\'inquilino, Elsa, e la regola del piano ridivide la voce: '
            . 'Ines € 109,77, Dora € 165,57, Luca € 9,70, Ugo € 30,58 e Elsa € 44,38.')
        ->and($frasi)->not->toContain('i giorni dal 1 ottobre 2026 vanno a chi paga al posto dell\'inquilino, Ugo e Elsa');
});

it('Coda 245 (fermata) — la vendita di Ugo dopo la prima fine con chi era già uscito: i € 0,81 di credito rimasti fuori dal conguaglio li deve la sola Pia, non Dora, che ha anche lei un credito', function () {
    $s = rdpScenario($this, [['Ines', 40], ['Dora', 30], ['Pia', 30]], ['fini' => ['Pia' => '2026-06-30', 'Dora' => '2026-09-30']]);
    fclPersona($s, 'Elsa');
    expect(fclNetti($s))->toBe(['Dora' => 10448, 'Ines' => 18625, 'Pia' => 6927, 'Ugo' => 84000]);
    $fine = fclRegistra($this, $s, fppModuloFine($s, 'Ines', '2026-07-01') + ['conguaglio' => 'scrivi'])->fresh();
    expect(fds50Coppia($s, $fine))->toBe(['Dora' => 125, 'Ines' => -9280, 'Ugo' => 9155])
        ->and(collect($fine->registro['resto_del_piano'][0]['parti'])->pluck('cifra', 'nome')->all())->toBe(['Pia' => 82, 'Dora' => -1, 'Ugo' => -81]);

    $v = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-11-01'));
    // Era: «… € 0,81 di credito sono rimasti fuori dal conguaglio, da regolare con Dora e Pia.»
    expect(implode(' ', fds50Con($v, 'sono rimasti fuori dal conguaglio')))->toContain('con la regola del piano la sua parte è € 90,74, e € 0,81 di credito sono rimasti fuori dal conguaglio, da regolare con Pia.')
        ->and(implode(' ', fds50Con($v, 'sono rimasti fuori dal conguaglio')))->not->toContain('da regolare con Dora');
});

it('Coda 245 (dopo la coppia) — l\'avviso di «Annulla il conguaglio» della fine di Ines dice «Dopo questo conguaglio, la fine di Dora …» con la coppia scritta e con la parte divisa senza scrivere il conguaglio', function (string $scelta, string $verbo) {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
    $inesFine = fclRegistra($this, $s, fppModuloFine($s, 'Ines', '2026-07-01') + ['conguaglio' => 'scrivi'])->fresh();
    fclRegistra($this, $s, fppModuloFine($s, 'Dora', '2026-10-01') + ['conguaglio' => $scelta]);

    $avvisi = fds50Storico($s, $inesFine)['conguaglio']['avvisi'];
    // Era: «La fine della locazione di Dora del 1 ottobre 2026 ha scritto dopo questo conguaglio la sua coppia …» (il complemento di tempo fra il verbo e il suo oggetto).
    expect($avvisi)->toHaveCount(1)
        ->and($avvisi[0])->toStartWith('Dopo questo conguaglio, la fine della locazione di Dora del 1 ottobre 2026 ' . $verbo . ' (€ 78,75 a credito di Dora e € 78,75 a debito di Ugo)')
        ->and($avvisi[0])->not->toContain('dopo questo conguaglio la');
})->with([
    'la coppia scritta' => ['scrivi', 'ha scritto la sua coppia con la regola del piano'],
    'senza scrivere il conguaglio' => ['non_scrivere', 'ha diviso la sua parte con la regola del piano, senza scrivere il conguaglio'],
]);

it('Coda 245 (fine con la regola del piano) — i giorni che non copre nessuno restano a chi esce: la coppia non porta ognuno alla sua parte, e la regola dice piano_coppia', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['fini' => ['Dora' => '2026-09-30']]);
    DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['persone']['Ugo']->id)->update(['data_fine' => '2026-11-30']);

    $a = fclAnteprima($this, $s, fclModulo($s));

    // Era: `piano`, e la voce «Scrivi» diceva «le righe in saldi portano ognuno alla sua parte» con € 30,58 che restano nelle rate di Ines.
    expect(rdpCoppia($a))->toBe(['Dora' => 787, 'Ines' => -6803, 'Ugo' => 6016])
        ->and($a['rate']['conguaglio']['per_gestione'][0]['piano']['nessuno'])->toBe(3058)
        ->and($a['rate']['conguaglio']['regola'])->toBe('piano_coppia');
});

it('Coda 245 (fine con la regola del piano) — l\'unità con il coinquilino e un resto, e il box senza: la regola dice misto_coppia, perché una parte dell\'Interno 1 resta fuori', function () {
    $s = rdpScenario($this, [['Ines', 40], ['Dora', 30], ['Pia', 30]], ['fini' => ['Pia' => '2026-06-30'], 'box' => [['Rita', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);

    $a = fclAnteprima($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]));

    // Era: `misto`, e la voce «Scrivi» diceva «le righe in saldi portano ognuno alla sua parte» con Pia € 17,70 sotto il piano e Dora sopra.
    expect(rdpCoppia($a, 'Ines', (int) $s['unita']->id))->toBe(['Dora' => 5338, 'Ines' => -5338])
        ->and(rdpCoppia($a, 'Ines', (int) $s['box']->id))->toBe(['Ines' => -1815, 'Rita' => 1815])
        ->and(collect($a['rate']['resto_del_piano'])->map(fn (array $r) => collect($r['parti'])->pluck('cifra', 'nome')->all())->all())->toBe([['Pia' => 1770, 'Dora' => -1770]])
        ->and($a['rate']['conguaglio']['regola'])->toBe('misto_coppia');
});

it('Coda 245 (fine con la regola del piano) — senza niente che resti fuori la regola resta piano, e con l\'unità e il box misto', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['box' => [['Rita', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);

    expect(fclAnteprima($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]))['rate']['conguaglio']['regola'])->toBe('misto')
        ->and(fclAnteprima($this, $s, fclModulo($s))['rate']['conguaglio']['regola'])->toBe('piano');
});

it('Coda 245 (storico) — la fine con «Non scriverlo» dice nello storico le cifre che le parti si regolano fra loro: con un coinquilino e senza', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
    $fine = fclRegistra($this, $s, fppModuloFine($s, 'Ines', '2026-07-01') + ['conguaglio' => 'non_scrivere'])->fresh();

    $c = fds50Storico($s, $fine)['conguaglio'];
    // Era: «Conguaglio non scritto: nessuna riga in saldi, la posizione resta com'era», senza le cifre che il registro ha in `regolato_fuori`.
    expect($c['stato'])->toBe('non_scritto')
        ->and($c['fra_le_parti'])->toBe('Le parti regolano fra loro € 60,66 a credito di Ines e € 60,66 a debito di Dora, sulla gestione Ordinaria 2026.');
});

it('Coda 245 (storico) — la fine di Dora con «Non scriverlo» dopo la fine di Ines con «Scrivi»: lo storico dice anche la parte di Ines che la coppia di Dora non porta, € 11,99, e che il prospetto dice chi rimborsa chi', function () {
    // Il caso C4 sopra: Ugo deve a Dora € 78,75 (la coppia) e a Ines € 11,99 (il resto: 10735 contro 11934 di prima), in tutto i € 90,74 dei giorni dal 1/10 (9074). Era: lo storico
    // diceva solo «€ 78,75 a credito di Dora e a debito di Ugo», e dei € 11,99 non diceva niente, mentre il prospetto li dice (Ines «da rimborsare» − 1199).
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
    fclRegistra($this, $s, fppModuloFine($s, 'Ines', '2026-07-01') + ['conguaglio' => 'scrivi']);
    $fineDora = fclRegistra($this, $s, fppModuloFine($s, 'Dora', '2026-10-01') + ['conguaglio' => 'non_scrivere'])->fresh();

    expect($fineDora->registro['regolato_fuori'][0]['persone'])->toBe([['anagrafica_id' => $s['persone']['Dora']->id, 'nome' => 'Dora', 'importo' => -7875], ['anagrafica_id' => $s['persone']['Ugo']->id, 'nome' => 'Ugo', 'importo' => 7875]])
        ->and(fds50Storico($s, $fineDora)['conguaglio']['fra_le_parti'])->toBe('Le parti regolano fra loro € 78,75 a credito di Dora e € 78,75 a debito di Ugo, sulla gestione Ordinaria 2026. '
            . 'Sulla gestione Ordinaria 2026 la regola del piano lascia fuori dalla coppia di Dora anche € 11,99 a credito di Ines e € 11,99 a debito di Ugo: li regolano le parti, e il prospetto degli oneri accessori dice chi rimborsa chi.');
    $p = app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);
    $ines = collect($p['conduttori'])->firstWhere('nome', 'Ines');
    expect((int) $ines['da_rimborsare'])->toBe(-1199)
        ->and((int) $p['senza_conduttore']['regola_del_piano']['da_rimborsare'])->toBe(9074);
});

it('Coda 245 (storico) — il resto fra le parti dal registro: i gruppi della stessa gestione si sommano, ogni gestione ha la sua frase, e senza un resto non c\'è nessuna frase', function () {
    // La forma del registro è quella che la rotta scrive (`conguaglio.piano[]`, vedi sopra): qui due unità sulla gestione 7 (Ines − 1199 e − 301, Ugo + 1199 e + 301: in tutto − 1500 e + 1500;
    // Pia + 30 e − 30: in tutto zero, e non si nomina) e una seconda gestione (Ines − 50, Ugo + 50), che nessuna rotta del test costruisce con un resto.
    $gruppo = fn (int $gestione, array $resto) => ['gestione_id' => $gestione, 'resto' => $resto, 'gia_usciti' => [2], 'uscente_id' => 3, 'nomi' => [1 => 'Ugo', 2 => 'Ines', 3 => 'Dora', 4 => 'Pia']];
    $gestioni = [7 => 'Ordinaria 2026', 9 => 'Straordinaria 2026'];

    expect(\App\Services\Subentro\RegolaDelPiano::fraseDelRestoFraLeParti([$gruppo(7, [2 => -1199, 1 => 1199, 4 => 30]), $gruppo(7, [2 => -301, 1 => 301, 4 => -30]), $gruppo(9, [2 => -50, 1 => 50])], 'Dora', $gestioni))
        ->toBe('Sulla gestione Ordinaria 2026 la regola del piano lascia fuori dalla coppia di Dora anche € 15,00 a credito di Ines e € 15,00 a debito di Ugo; '
            . 'sulla gestione Straordinaria 2026 la regola del piano lascia fuori dalla coppia di Dora anche € 0,50 a credito di Ines e € 0,50 a debito di Ugo: li regolano le parti, e il prospetto degli oneri accessori dice chi rimborsa chi.')
        ->and(\App\Services\Subentro\RegolaDelPiano::fraseDelRestoFraLeParti([$gruppo(7, [])], 'Dora', $gestioni))->toBeNull()
        ->and(\App\Services\Subentro\RegolaDelPiano::fraseDelRestoFraLeParti([], 'Dora', $gestioni))->toBeNull();
});

it('Coda 245 (storico) — senza un coinquilino «Non scriverlo» lascia fra le parti i giorni dopo l\'uscita: lo storico dice € 181,48 a credito di Ines e a debito di Ugo; con «Scrivi» non dice niente', function () {
    $s = fds50Scenario();
    fds50Genera($s);
    fds50Emetti($this, $s, '2026-06-30');
    $fine = fds50Fine($this, $s, 'non_scrivere');

    expect(fds50Storico($s, $fine)['conguaglio']['fra_le_parti'])->toBe('Le parti regolano fra loro € 181,48 a credito di Ines e € 181,48 a debito di Ugo, sulla gestione Ordinaria 2026.');
});

it('Coda 245 (storico) — con «Scrivi» le cifre sono nei saldi: lo storico non dice niente fra le parti', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
    $fine = fclRegistra($this, $s, fppModuloFine($s, 'Ines', '2026-07-01') + ['conguaglio' => 'scrivi'])->fresh();

    expect(fds50Storico($s, $fine)['conguaglio'])->toHaveKey('fra_le_parti')
        ->and(fds50Storico($s, $fine)['conguaglio']['fra_le_parti'])->toBeNull();
});

it('Coda 354 (avviso di «Annulla il conguaglio») — la rivendita con due voci: i fatti dei saldi del Wallet legati alla vendita si dicono una volta, sotto la voce che ha il Wallet, non anche sotto quella di Zeno', function () {
    $s = fer55Base($this, [['Bruno', 'proprietario', 50], ['Ugo', 'nuda_proprietario', 50], ['Zeno', 'usufruttuario', 50]]);
    fer55Passo($this, $s, ['tipo' => 'vendita', 'tipologia' => 'nuda_proprietario', 'riga_uscente_id' => fds50Riga($s, 'Ugo', 'nuda_proprietario'),
        'anagrafica_entrante_id' => $s['persone']['Bruno']->id, 'decorrenza' => '2026-10-01', 'quota' => 50, 'copia_autentica' => false,
        'estremi_titolo' => 'rogito notaio Verdi, rep. 4321', 'pertinenze' => []]);
    fer55Passo($this, $s, ['quota' => 50] + fds50ModuloEstinzione($s, 'Zeno', '2026-10-01'));
    $rivendita = fer55Passo($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 100));
    expect(fer55Registro($rivendita))->toBe([
        ['persone' => ['Bruno'], 'cifra' => 1529, 'riceve' => ['Carlo' => 1529], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata'],
        ['persone' => ['Zeno'], 'cifra' => 1529, 'riceve' => ['Carlo' => 1529], 'uscente' => 'Bruno', 'dopo_la_fermata' => ['passaggio' => 'l\'estinzione dell\'usufrutto del 1 ottobre 2026', 'cifra' => 4537],
            'wallet' => false, 'strada' => null],
    ]);
    // La strada del Wallet della prima voce, seguita dalla pagina dei saldi: Bruno a credito, Carlo a debito, legati alla rivendita.
    expect(sl53Errori(sl53Posta($this, $s, 'Bruno', -1529, (int) $rivendita->id)))->toBe([])
        ->and(sl53Errori(sl53Posta($this, $s, 'Carlo', 1529, (int) $rivendita->id)))->toBe([]);

    $avvisi = fds50Storico($s, $s['fine'])['conguaglio']['avvisi'];
    $fatti = 'I saldi del Wallet legati alla vendita del 1 dicembre 2026 sommano oggi € 15,29 a credito di Bruno e € 15,29 a debito di Carlo; la parte della sua fermata è € 0,00. '
        . 'L\'annullamento del conguaglio non li cambia: correggi dal Wallet i saldi che si modificano.';
    // Era: gli stessi fatti sotto tutte e due le voci, anche sotto quella di Zeno, che il Wallet non ce l'ha.
    expect($avvisi)->toHaveCount(3)
        ->and($avvisi[1])->toBe('La vendita del 1 dicembre 2026 si è fermata su questo conguaglio: € 15,29 dei giorni dal 1 dicembre 2026 sono rimasti a carico di Bruno sulla gestione Ordinaria 2026, da regolare a mano con Carlo. '
            . 'Annullato il conguaglio, quella fermata non vale più. ' . $fatti)
        ->and($avvisi[2])->toBe('La vendita del 1 dicembre 2026 si è fermata su questo conguaglio: € 15,29 dei giorni dal 1 dicembre 2026 sono rimasti a carico di Zeno, o di Bruno se la fermata dell\'estinzione dell\'usufrutto '
            . 'del 1 ottobre 2026 era già stata regolata, sulla gestione Ordinaria 2026, da regolare a mano con Carlo. Annullato il conguaglio, quella fermata non vale più: se le parti l\'hanno regolata fra loro, quell\'accordo va rivisto.')
        ->and(substr_count(implode(' ', $avvisi), 'I saldi del Wallet legati alla vendita del 1 dicembre 2026 sommano'))->toBe(1);
});

it('Coda 245 (storico) — la successione con «Non scriverlo» non dichiara un accordo fra le parti (decisione 72): lo storico non dice nessuna cifra fra le parti', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 100]]);
    $successione = fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 100, ['Bruno' => 100], Subentro::ARRETRATO_AL_DEFUNTO, Subentro::NON_SCRIVERE_IL_CONGUAGLIO));

    expect(fds50Storico($s, $successione)['conguaglio'])->toMatchArray(['stato' => 'non_scritto', 'fra_le_parti' => null]);
});

it('Coda 245 (storico) — il conguaglio scritto e poi annullato ha le cifre in `regolato_fuori`, ma non è un «Non scriverlo»: lo storico non dice niente fra le parti', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
    $fine = fclRegistra($this, $s, fppModuloFine($s, 'Ines', '2026-07-01') + ['conguaglio' => 'scrivi'])->fresh();
    expect(sl53AnnullaConguaglio($this, $s, $fine)->status())->toBe(302)
        ->and($fine->fresh()->registro['regolato_fuori'][0]['importo'])->toBe(6066);

    expect(fds50Storico($s, $fine->fresh())['conguaglio'])->toMatchArray(['stato' => 'annullato', 'fra_le_parti' => null]);
});
