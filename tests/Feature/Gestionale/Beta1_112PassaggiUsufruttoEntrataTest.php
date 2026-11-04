<?php

/**
 * Coda 455, punto 6a (decisione 175) — l'anteprima del passaggio d'usufrutto non cita da sola l'art. 1004 c.c. quando il netto del conguaglio è un'entrata.
 *
 * Il blocco «Rate già emesse» dell'anteprima dice come si divide la quota ordinaria delle rate già emesse: in proporzione ai giorni, con l'articolo che lo
 * vuole. L'art. 1004 c.c. riguarda le spese; un'entrata che è un frutto civile (un affitto, un canone) va all'usufruttuario per l'art. 984 c.c. Quando
 * nel conguaglio le entrate superano le spese, il netto per chi entra è un credito (`totale_entrante` negativo): la frase diceva ancora «all'usufruttuario,
 * art. 1004 c.c.». Il blocco «ordinaria» del pannello distingue già le entrate (`AnteprimaPassaggio`, le frasi dell'ordinaria); qui mancava. Le tre frasi
 * della stessa forma: la costituzione con la legge, l'estinzione con l'accrescimento e l'estinzione dell'usufrutto nato con la legge.
 *
 * Lo scenario è quello di `ScenariPassaggi` (`ruScenario`): Ugo proprietario al 100 %, «Spese generali» € 1.200,00 sul «Proprietario», dodici rate; in più
 * «Affitto antenna», un'entrata di € 2.400,00 sul «Proprietario» (`b52UsVoce`): ogni rata netta è 10000 − 20000 = − 10000, e le quattro rate emesse da gennaio
 * ad aprile sono − 40000. Il netto per chi entra è un credito per costruzione: − 40000 per i suoi giorni su 365. Con l'entrata di € 240,00 la rata netta è
 * 10000 − 2000 = 8000, e il netto per chi entra è un debito.
 *
 * La coppia del conguaglio è per gestione, e la frase dice «sulla gestione di ciascun piano»: con le spese su una gestione e l'affitto su un'altra, la coppia
 * della seconda è un credito anche se il netto di tutte e due è un debito, e la frase dice i due articoli.
 *
 * Che cosa NON copre: MySQL; un conguaglio con le sole entrate (la frase nomina i due articoli, che dicono la regola anche dove una delle due parti manca); la
 * riserva d'usufrutto, dove l'ordinaria non si conguaglia.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Beta52UsufruttoScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** La parentesi della quota ordinaria quando il netto per chi entra è un credito: i due articoli, ognuno con ciò che riguarda. */
const PUE_DUE_ARTICOLI = 'le spese per l\'art. 1004 c.c., le entrate, se sono frutti civili, per l\'art. 984 c.c.';

/**
 * Lo scenario con l'entrata di `$entrata` centesimi sul «Proprietario» accanto alle spese (nessuna con zero), il piano generato e le rate fino ad aprile a giornale.
 * Con `$usufrutto` l'unità è in usufrutto a Ugo e Dora al 50 % ciascuno, con Elsa nuda proprietaria, e le due voci stanno sull'«Usufruttuario».
 */
function pueScenario(int $entrata, bool $usufrutto = false): array
{
    $s = ruScenario('prima_rata', 0, genera: false);
    $soggetto = $usufrutto ? 'usufruttuario' : 'proprietario';
    if ($entrata !== 0) {
        b52UsVoce($s, 'Affitto antenna', 'entrata', $entrata, [$soggetto => 100]);
    }
    if ($usufrutto) {
        DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', b52UsAssociazioneSpese($s))->update(['soggetto' => 'usufruttuario']);
        DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->delete();
        $s['dora'] = Anagrafica::forceCreate(['nome' => 'Usufruttuaria Dora', 'email' => 'pue-dora-' . $s['unita']->id . '@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'PUEDORA' . str_pad((string) $s['unita']->id, 9, '0', STR_PAD_LEFT)]);
        $s['dora']->condomini()->syncWithoutDetaching([$s['c']->id]);
        foreach ([[$s['v'], 'usufruttuario', 50], [$s['dora'], 'usufruttuario', 50], [$s['a'], 'nuda_proprietario', 100]] as [$chi, $ruolo, $quota]) {
            DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $chi->id, 'immobile_id' => $s['unita']->id, 'tipologia' => $ruolo, 'quota' => $quota, 'attivo' => true,
                'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Letto: generazione del preventivo nella prova', esercizio: $s['e']);
    ruEmetti($s);

    return $s;
}

/** Una seconda gestione ordinaria, «Antenna 2026», con la sola entrata data sul «Proprietario» e il suo piano, generato e con le rate fino ad aprile a giornale. */
function pueSecondaGestione(array $s, int $entrata): void
{
    $g = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Antenna 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($s['e'], $g->id);
    $pc = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'PC antenna']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Affitto antenna', 'tipo' => 'entrata', 'natura_spesa' => 'ordinaria', 'importo' => $entrata]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => b52UsTabella($s), 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $piano = PianoRate::create(['gestione_id' => $g->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Preventivo antenna 2026', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true]);
    app(GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Letto: generazione del piano dell\'antenna nella prova', esercizio: $s['e']);
    ruEmetti(['g' => $g, 'piano' => $piano] + $s);
}

/** Il blocco «Rate già emesse» dell'anteprima, dalla rotta che la pagina chiama, e il netto per chi entra. @return array{0: string, 1: int} */
function pueRate($test, array $s, array $dati): array
{
    $a = ruAnteprima($test, $s, $dati);

    return [implode("\n", $a['rate']['frasi']), (int) ($a['rate']['conguaglio']['totale_entrante'] ?? 0)];
}

/** L'estinzione dell'usufrutto della riga data, il giorno dato. */
function pueEstinzione(array $s, int $riga, string $il, array $extra = []): array
{
    return ['tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => $riga, 'decorrenza' => $il, 'quota' => 100, 'tipologia' => 'proprietario',
        'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Estinzione dell\'usufrutto, letta'] + $extra;
}

/** La riga aperta d'usufrutto di una persona sull'unità. */
function pueRigaUsufrutto(array $s, Anagrafica $chi): int
{
    return (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $chi->id)->where('immobile_id', $s['unita']->id)->where('tipologia', 'usufruttuario')->whereNull('data_fine')->value('id');
}

it('Coda 455 (6a) — costituzione dell\'usufrutto con la legge, il netto per l\'usufruttuario è un credito (l\'affitto supera le spese): la quota ordinaria si divide con i due articoli, non con il solo art. 1004 c.c.', function () {
    $s = pueScenario(240000);
    [$frasi, $netto] = pueRate($this, $s, b52UsCostituzione($s));

    expect($netto)->toBeLessThan(0)
        ->and($frasi)->toContain('Il conguaglio fra Venditore Ugo, che resta come nudo proprietario, e Acquirente Elsa è proposto come due righe di saldo che sommano a zero, sulla gestione di ciascun piano: la quota ordinaria divisa in proporzione ai giorni (dal 1 maggio 2026 all\'usufruttuario: ' . PUE_DUE_ARTICOLI . '), la quota straordinaria resta al nudo proprietario (art. 1005 c.c.).')
        ->and($frasi)->not->toContain('all\'usufruttuario, art. 1004 c.c.');
});

it('Coda 455 (6a), controprova — costituzione con la legge e il netto per l\'usufruttuario un debito (le spese superano l\'affitto): la frase di sempre, con l\'art. 1004 c.c.', function () {
    $s = pueScenario(24000);
    [$frasi, $netto] = pueRate($this, $s, b52UsCostituzione($s));

    expect($netto)->toBeGreaterThan(0)
        ->and($frasi)->toContain('la quota ordinaria divisa in proporzione ai giorni (dal 1 maggio 2026 all\'usufruttuario, art. 1004 c.c.), la quota straordinaria resta al nudo proprietario (art. 1005 c.c.).')
        ->and($frasi)->not->toContain('art. 984 c.c.');
});

it('Coda 455 (6a) — estinzione dell\'usufrutto nato con la legge, il netto per chi torna proprietario pieno è un credito: la frase dice anche l\'art. 984 c.c. per le entrate; con un debito, la frase di sempre', function (int $entrata, bool $credito, string $articoli) {
    $s = pueScenario($entrata);
    ruRegistra($this, $s, ruRiserva($s));
    [$frasi, $netto] = pueRate($this, $s, pueEstinzione($s, pueRigaUsufrutto($s, $s['v']), '2026-09-01'));

    expect($netto < 0)->toBe($credito)
        ->and($frasi)->toContain('la quota ordinaria divisa in proporzione ai giorni, la quota straordinaria resta al nudo proprietario (' . $articoli . ').');
})->with([
    'l\'affitto supera le spese' => [240000, true, 'art. 63 disp. att. c.c.; artt. 1004-1005 c.c.; per le entrate, se sono frutti civili, art. 984 c.c.'],
    'le spese superano l\'affitto' => [24000, false, 'art. 63 disp. att. c.c.; artt. 1004-1005 c.c.'],
]);

it('Coda 455 (6a) — estinzione con l\'accrescimento all\'altra usufruttuaria, il netto per lei è un credito: la frase dice i due articoli; con un debito, la frase di sempre', function (int $entrata, bool $credito, string $articoli) {
    $s = pueScenario($entrata, usufrutto: true);
    [$frasi, $netto] = pueRate($this, $s, pueEstinzione($s, pueRigaUsufrutto($s, $s['v']), '2026-05-01', ['accrescimento' => true]));

    expect($netto < 0)->toBe($credito)
        ->and($frasi)->toContain('Il conguaglio fra Venditore Ugo e Usufruttuaria Dora, a cui l\'usufrutto si accresce, è proposto come righe di saldo che sommano a zero, sulla gestione di ciascun piano: la quota ordinaria divisa in proporzione ai giorni (' . $articoli . '), la quota straordinaria resta al nudo proprietario (art. 1005 c.c.).');
})->with([
    'l\'affitto supera le spese' => [240000, true, PUE_DUE_ARTICOLI],
    'le spese superano l\'affitto' => [24000, false, 'art. 1004 c.c.'],
]);

it('Coda 455 (6a) — le spese su una gestione e l\'affitto su un\'altra: il netto di tutte e due per l\'usufruttuario è un debito, ma la coppia della gestione dell\'affitto è un credito, e la frase dice i due articoli', function () {
    // «Ordinaria 2026»: le sole spese, € 1.200,00; «Antenna 2026»: il solo affitto, € 240,00. La coppia dell'antenna è un credito per Elsa, quella delle spese un
    // debito cinque volte più grande.
    $s = pueScenario(0);
    pueSecondaGestione($s, 24000);
    $a = ruAnteprima($this, $s, b52UsCostituzione($s));
    $coppie = collect($a['rate']['conguaglio']['coppie'])->mapWithKeys(fn ($c) => [$c['gestione'] => (int) $c['importo']])->all();

    expect((int) $a['rate']['conguaglio']['totale_entrante'])->toBeGreaterThan(0)
        ->and($coppie['Antenna 2026'])->toBeLessThan(0)
        ->and($coppie['Ordinaria 2026'])->toBeGreaterThan(0)
        ->and(implode("\n", $a['rate']['frasi']))->toContain('la quota ordinaria divisa in proporzione ai giorni (dal 1 maggio 2026 all\'usufruttuario: ' . PUE_DUE_ARTICOLI . '), la quota straordinaria resta al nudo proprietario (art. 1005 c.c.).');
});
