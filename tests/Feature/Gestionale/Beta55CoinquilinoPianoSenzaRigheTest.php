<?php

/**
 * 1.11.0-beta.55, Coda 245 — la regola del piano su un piano senza il riparto registrato (generato dalla 1.10, prima della 1.11.0-beta.29:
 * tutti i preventivi di chi aggiorna dalla 1.10.x).
 *
 * Senza le righe del riparto l'anello è uno per piano: le quote pure degli inquilini sull'unità (la 1.10 divideva la parte dell'inquilino
 * per quota, senza giorni), ridivise con quota × giorni sulla competenza del piano. Il conguaglio si ferma, e lo dice con la strada del
 * Wallet legato al passaggio, quando la parte dell'inquilino non si separa: un inquilino che ha anche un altro ruolo sull'unità, o quote senza
 * la composizione della rata (la 1.7.x).
 *
 * I CONTI A MANO: il caso base, le quote pure Ines 18000 e Dora 18000 (36000): dopo l'uscita del 1/7, 36000 × 9050/27300 = 11934,07 → Ines
 * 11934, Dora 24066: la coppia ∓ 6066, la stessa del piano con il riparto (`Beta55RegolaDelPianoTest`, C1).
 *
 * Cosa NON copre: la competenza del piano senza riparto diversa da quella dei suoi capitoli (la 1.10 non li aveva); MySQL.
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Beta55CoinquilinoScenari.php';

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

it('Coda 245 (piano senza riparto) — il ramo base: le quote pure di Ines e Dora, ridivise per quota e per giorni: ∓ € 60,66, e con «Scrivi» le due righe', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();

    $a = fclAnteprima($this, $s, fclModulo($s));

    expect(rdpCoppia($a))->toBe(['Dora' => 6066, 'Ines' => -6066])
        ->and($a['rate']['scelta_del_conguaglio'])->toBeTrue()
        ->and($a['rate']['conguaglio']['per_gestione'][0]['piano']['voci'][0])->toMatchArray(['anello' => 36000,
            'prima' => [(int) $s['persone']['Ines']->id => 18000, (int) $s['persone']['Dora']->id => 18000]]);
    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    expect(fclSaldi($s))->toBe(['Dora' => 6066, 'Ines' => -6066]);
});

it('Coda 245 (piano senza riparto) — si ferma quando un inquilino ha anche un altro ruolo sull\'unità: Dora inquilina e comproprietaria', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['titolari' => [['Ugo', 'proprietario', 50], ['Dora', 'proprietario', 50]]]);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();

    $a = fclAnteprima($this, $s, fclModulo($s));

    expect(rdpCoppia($a))->toBe([])
        ->and($a['rate']['scelta_del_conguaglio'])->toBeFalse()
        ->and(fclFrasi($a))->toContain('Piano «Preventivo 2026»: il piano non ha il dettaglio del riparto, e su Interno 1 un inquilino ha anche un altro ruolo: la parte dell\'inquilino non si separa dalle sue altre quote — se serve un conguaglio, si scrive dal Wallet con saldi legati a questo passaggio, sulla stessa gestione. Nessun conguaglio proposto su quelle quote.');
});

it('Coda 245 (piano senza riparto) — si ferma con le quote senza la composizione della rata (la 1.7.x)', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id'))->where('anagrafica_id', $s['persone']['Dora']->id)
        ->update(['regole_calcolo' => null]);

    $a = fclAnteprima($this, $s, fclModulo($s));

    expect(rdpCoppia($a))->toBe([])
        ->and(fclFrasi($a))->toContain('le quote degli inquilini su Interno 1 sono state generate da una versione che non salvava la composizione della rata');
});

it('Coda 245 (piano senza riparto) — l\'inizio di Luca dopo la fine con la regola del piano dice le cifre della regola del piano, e chi rimborsa chi con il prospetto', function (string $scelta, string $testa) {
    // Cambiato con la decisione 115.1: il prospetto legge anche il piano della 1.10 con la regola del piano, e l'inizio dice la frase della regola del piano
    // con le persone e le cifre. Prima: «… e Luca la rimborsa a chi l'ha pagata, fra le parti» (con «Scrivi») e «chi l'ha pagata e chi la rimborsa lo regolano
    // le parti» (con «Non scriverlo»), senza cifre. Le cifre (C5a): Luca 50 × 122 = 6100, Ines 9050, Dora 18250 (somma 33400): 6574,85, 9754,49, 19670,66 →
    // Luca 6575, Ines 9754, Dora 19671 (i due centesimi a Luca, ,85, e a Dora, ,66); Luca + 6575, Dora 19671 − 24066 = − 4395, Ines 9754 − 11934 = − 2180, con
    // l'una e l'altra scelta della fine (la differenza del piano del passaggio).
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => $scelta]);
    fclPersona($s, 'Luca');

    $a = fds50Anteprima($this, $s, ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone']['Luca']->id, 'decorrenza' => '2026-09-01',
        'quota' => 50, 'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []]);

    expect(fclFrasi($a))->toContain($testa . ' Con Luca dal 1 settembre 2026 al 50 % la regola del piano darebbe Luca € 65,75, Dora € 196,71 e Ines € 97,54: il programma non scrive niente, e Luca rimborsa con il prospetto degli oneri accessori € 43,95 a Dora e € 21,80 a Ines.')
        ->and(fclFrasi($a))->not->toContain('lo decidono le parti')
        ->and(fclFrasi($a))->not->toContain('lo regolano le parti')
        ->and($a['rate']['giorni_del_conduttore'][0]['parti'])->toBe([['anagrafica_id' => (int) $s['persone']['Luca']->id, 'nome' => 'Luca', 'cifra' => 6575],
            ['anagrafica_id' => (int) $s['persone']['Dora']->id, 'nome' => 'Dora', 'cifra' => -4395], ['anagrafica_id' => (int) $s['persone']['Ines']->id, 'nome' => 'Ines', 'cifra' => -2180]]);
})->with([
    'scritto' => ['scrivi', 'La fine locazione di Ines del 1 luglio 2026 ha diviso la parte dell\'inquilino con la regola del piano (Ines € 119,34 e Dora € 240,66).'],
    'non scritto' => ['non_scrivere', 'La fine locazione di Ines del 1 luglio 2026 non ha scritto il conguaglio della regola del piano (Ines € 119,34 e Dora € 240,66): la differenza di quella fine resta fra le parti, con il prospetto degli oneri accessori.'],
]);

it('Coda 245 (piano senza riparto) — le quote di chi esce della 1.7.x con un saldo pregresso assorbito: il conguaglio si ferma come prima, e la regola del piano non propone niente', function () {
    // Il conguaglio non separa la spesa dell'anno dal pregresso (`ConguaglioPassaggio::senzaComposizione`): le quote di Ines non sono risolte, e
    // alla regola del piano non arriva nessun piano. Nessuna coppia, nessuna scelta, la frase delle non risolte.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    fclRiporto($s, 'Ines', 5000);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id'))->where('anagrafica_id', $s['persone']['Ines']->id)
        ->update(['regole_calcolo' => null]);

    $a = fclAnteprima($this, $s, fclModulo($s));

    expect(rdpCoppia($a))->toBe([])
        ->and($a['rate']['scelta_del_conguaglio'])->toBeFalse()
        ->and(fclFrasi($a))->toContain('generate da una versione che non salvava quanta parte di ogni rata è saldo pregresso');
});
