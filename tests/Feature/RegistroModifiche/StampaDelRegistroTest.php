<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission;
use App\Http\Controllers\RegistroModifiche\RegistroController;
use App\Models\Condominio;
use App\Models\User;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Services\RegistroModifiche\Gesto;
use App\Services\RegistroModifiche\RegistroModifiche;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role as RuoloSpatie;

/**
 * 1.12.0-beta.13, la stampa del registro con la finestra «Stampa il registro delle modifiche»: il periodo dal … al …, il conto delle righe prima di
 * stampare, il limite di una stampa sola.
 *
 * - **Il conto** (una rotta di sola lettura, del condominio e dello studio): quante righe uscirebbero con i filtri e il periodo, e il limite di una stampa
 *   sola. Niente PDF.
 * - **Il periodo dal … al …**: giorni di Roma, dall'inizio del primo alla fine del secondo (una riga delle 00:15 di Roma del 1° gennaio è del 31 dicembre
 *   per l'orologio del server, e sta nel periodo che comincia il 1° gennaio). Con il periodo il mese non conta; senza, la pagina usa il mese di oggi.
 * - **La stampa** con il periodo: le stesse righe del conto, il periodo in testa al PDF («Periodo: dal 01/01/2026 al 30/06/2026») e nel nome del file.
 * - «Al» prima di «Dal»: il conto risponde con l'errore sotto «Al», la stampa non parte.
 * - Oltre il limite: il conto lo dice (più righe del limite), la stampa non parte; un esercizio alla volta sta sotto il limite.
 * - **Gli stessi permessi della stampa**: 403 a chi non vede il registro (un ruolo con il solo pannello, il collaboratore).
 *
 * Che cosa non copre: la finestra a video (`FinestraDellaStampa.test.ts`); il PDF pagina per pagina (mPDF); le altre prove della stampa e delle pagine
 * (`PagineDelRegistroTest`).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    // Le persone e i condomini nascono nel 2024 (con le loro righe del registro): fuori da ogni periodo provato qui sotto.
    $this->travelTo(Carbon::parse('2024-01-10 10:00:00', 'Europe/Rome'));
    $this->andrea = User::factory()->create(['name' => 'Andrea Costa', 'email_verified_at' => now()]);
    $this->andrea->assignRole('amministratore');
    $this->c = Condominio::factory()->create(['nome' => 'Via Roma 12']);
    $this->altro = Condominio::factory()->create(['nome' => 'Corso Italia 3']);
    $this->actingAs($this->andrea);
    // Il gestionale di un condominio, aperto la prima volta, crea l'esercizio e i conti (righe del registro «del programma»): anche loro nel 2024.
    foreach ([$this->c, $this->altro] as $condominio) {
        $this->getJson(route('admin.gestionale.registro-modifiche.stampa.conto', $condominio))->assertOk();
    }
    $this->righeDel2024 = \Illuminate\Support\Facades\DB::table('registro_modifiche_condomini')->where('condominio_id', $this->c->id)->count();
    // Le righe, nell'ordine in cui sono successe, alle ore di Roma: due sono a cavallo della mezzanotte, dove il giorno di Roma e quello del server
    // non sono lo stesso.
    $scrivi = function (string $quando, string $area, string $tipo, int $id, string $nome, string $riepilogo, ?Condominio $dove = null) {
        $this->travelTo(Carbon::parse($quando, 'Europe/Rome'));
        RegistroModifiche::scrivi(Gesto::su($area, $tipo, $id, $nome)->azione('gesto')->riepilogo($riepilogo)->nelCondominio($dove ?? $this->c)->perLaRiga());
    };
    $scrivi('2025-12-31 23:30:00', 'comunicazioni', 'comunicazione', 1, 'Comunicazione «Auguri»', 'Pubblicata in bacheca');
    $scrivi('2026-01-01 00:15:00', 'assemblee', 'assemblea', 501, 'Assemblea ordinaria del 15/01/2026', 'Convocata');
    $scrivi('2026-06-30 23:30:00', 'comunicazioni', 'comunicazione', 2, 'Comunicazione «Pulizie»', 'Pubblicata in bacheca');
    $scrivi('2026-07-01 00:30:00', 'comunicazioni', 'comunicazione', 3, 'Comunicazione «Ascensore»', 'Pubblicata in bacheca');
    $scrivi('2026-11-20 23:56:00', 'assemblee', 'assemblea', 502, 'Assemblea ordinaria del 22/11/2026', 'Lettere della convocazione stampate: 2');
    $scrivi('2026-11-20 23:58:00', 'comunicazioni', 'comunicazione', 4, 'Comunicazione «Corso Italia»', 'Pubblicata in bacheca', $this->altro);
    $this->travelTo(Carbon::parse('2026-11-21 10:00:00', 'Europe/Rome'));
});

/** Il conto della stampa del condominio (o dello studio con `null`), come lo chiede la finestra. */
function sdrConto($test, ?Condominio $c, array $parametri, ?User $chi = null)
{
    $url = $c === null ? route('impostazioni.registro-modifiche.stampa.conto', $parametri)
        : route('admin.gestionale.registro-modifiche.stampa.conto', ['condominio' => $c->id] + $parametri);

    return $test->actingAs($chi ?? $test->andrea)->getJson($url);
}

/** La stampa dalla rotta vera, con la spia sui dati che il controller passa alla vista: [risposta, dati]. */
function sdrStampa($test, ?Condominio $c, array $parametri): array
{
    $spia = new class extends \App\Services\PDF\PdfService
    {
        public array $dati = [];

        public function generate(string $view, array $data = [], array $config = []): \Mpdf\Mpdf
        {
            $this->dati = $data;

            return parent::generate($view, $data, $config);
        }
    };
    app()->instance(\App\Services\PDF\PdfService::class, $spia);
    $url = $c === null ? route('impostazioni.registro-modifiche.stampa', $parametri)
        : route('admin.gestionale.registro-modifiche.stampa', ['condominio' => $c->id] + $parametri);
    $r = $test->actingAs($test->andrea)->get($url);

    return [$r, $spia->dati];
}

it('il conto: quante righe uscirebbero dal … al … (giorni di Roma, dall\'inizio del primo alla fine del secondo) e il limite di una stampa sola', function () {
    expect(sdrConto($this, $this->c, ['dal' => '2026-01-01', 'al' => '2026-06-30'])->assertOk()->json())
        ->toBe(['righe' => 2, 'limite' => RegistroController::righeStampabiliDelRegistroCon(ini_get('memory_limit'))])
        ->and(sdrConto($this, $this->c, ['dal' => '2026-07-01', 'al' => '2026-11-30'])->json('righe'))->toBe(2)
        ->and(sdrConto($this, $this->c, ['dal' => '2025-12-31', 'al' => '2025-12-31'])->json('righe'))->toBe(1)
        // I filtri della pagina valgono anche qui.
        ->and(sdrConto($this, $this->c, ['dal' => '2026-01-01', 'al' => '2026-12-31', 'area' => 'assemblee'])->json('righe'))->toBe(2)
        ->and(sdrConto($this, $this->c, ['dal' => '2026-01-01', 'al' => '2026-12-31', 'cerca' => 'Pulizie'])->json('righe'))->toBe(1)
        // Con il periodo il mese dell'indirizzo non conta; senza, vale il mese come nella pagina (il mese di oggi, o «tutti»).
        ->and(sdrConto($this, $this->c, ['mese' => '2026-11', 'dal' => '2026-01-01', 'al' => '2026-06-30'])->json('righe'))->toBe(2)
        ->and(sdrConto($this, $this->c, [])->json('righe'))->toBe(1)
        ->and(sdrConto($this, $this->c, ['mese' => 'tutti'])->json('righe'))->toBe(5 + $this->righeDel2024)
        // Lo studio: tutti i condomini, o quello scelto.
        ->and(sdrConto($this, null, ['dal' => '2026-11-01', 'al' => '2026-11-30'])->json('righe'))->toBe(2)
        ->and(sdrConto($this, null, ['dal' => '2026-11-01', 'al' => '2026-11-30', 'condominio' => $this->altro->id])->json('righe'))->toBe(1);
});

it('la stampa dal … al …: le righe del conto, il periodo in testa al PDF e nel nome del file', function () {
    $conto = sdrConto($this, $this->c, ['dal' => '2026-01-01', 'al' => '2026-06-30', 'area' => 'comunicazioni'])->json('righe');
    [$r, $dati] = sdrStampa($this, $this->c, ['dal' => '2026-01-01', 'al' => '2026-06-30', 'area' => 'comunicazioni']);

    $r->assertOk();
    expect(substr((string) $r->getContent(), 0, 4))->toBe('%PDF')
        ->and(collect($dati['righe'])->pluck('oggetto.nome')->all())->toBe(['Comunicazione «Pulizie»'])
        ->and(count($dati['righe']))->toBe($conto)
        ->and($dati['filtri'])->toBe(['Periodo' => 'dal 01/01/2026 al 30/06/2026', 'Su che cosa' => 'Comunicazioni'])
        ->and($r->headers->get('Content-Disposition'))->toBe('inline; filename="registro-modifiche-via-roma-12-dal-2026-01-01-al-2026-06-30.pdf"');
    // Il testo del PDF, dove c'è chi lo legge (`pdftotext`): il periodo e il filtro in testa, la riga.
    if (trim((string) shell_exec('command -v pdftotext')) !== '') {
        $file = tempnam(sys_get_temp_dir(), 'registro');
        file_put_contents($file, (string) $r->getContent());
        $testo = preg_replace('/[\s\x{a0}]+/u', ' ', (string) shell_exec('pdftotext ' . escapeshellarg($file) . ' - 2>/dev/null'));
        @unlink($file);
        expect($testo)->toContain('Periodo: dal 01/01/2026 al 30/06/2026')->toContain('Su che cosa: Comunicazioni')->toContain('Comunicazione «Pulizie»');
    }

    // Lo studio, con il condominio scelto: le righe di quel condominio nel periodo.
    [$studio, $datiStudio] = sdrStampa($this, null, ['dal' => '2026-11-01', 'al' => '2026-11-30', 'condominio' => $this->altro->id]);
    $studio->assertOk();
    expect(collect($datiStudio['righe'])->pluck('oggetto.nome')->all())->toBe(['Comunicazione «Corso Italia»'])
        ->and($datiStudio['filtri'])->toBe(['Periodo' => 'dal 01/11/2026 al 30/11/2026', 'Condominio' => 'Corso Italia 3']);
});

it('la stampa senza il periodo resta quella di oggi: il mese della pagina in testa', function () {
    [$r, $dati] = sdrStampa($this, $this->c, ['mese' => '2026-07']);

    $r->assertOk();
    expect(collect($dati['righe'])->pluck('oggetto.nome')->all())->toBe(['Comunicazione «Ascensore»'])
        ->and($dati['filtri'])->toBe(['Periodo' => 'Luglio 2026'])
        ->and($r->headers->get('Content-Disposition'))->toBe('inline; filename="registro-modifiche-via-roma-12-2026-07.pdf"');
});

it('«Al» prima di «Dal»: il conto risponde con l\'errore sotto «Al», e la stampa non parte', function () {
    sdrConto($this, $this->c, ['dal' => '2026-06-30', 'al' => '2026-01-01'])->assertStatus(422)
        ->assertJsonPath('errors.al.0', '«Al» viene prima di «Dal»: scegli un giorno uguale o successivo.');
    sdrConto($this, $this->c, ['dal' => '2026-02-30', 'al' => '2026-06-30'])->assertStatus(422)->assertJsonValidationErrors(['dal']);

    [$r] = sdrStampa($this, $this->c, ['dal' => '2026-06-30', 'al' => '2026-01-01']);
    $r->assertRedirect()->assertSessionHasErrors(['al']);
});

it('oltre il limite di una stampa sola il conto lo dice e la stampa non parte; un esercizio alla volta sta sotto il limite', function () {
    $limite = new ReflectionProperty(RegistroController::class, 'limiteMemoria');
    $limite->setValue(null, '1M');
    try {
        // Con 1M il limite è il minimo, 200 righe: 200 righe in più nel 2025, così «dall'inizio» le righe sono 206.
        $this->travelTo(Carbon::parse('2025-03-10 09:00:00', 'Europe/Rome'));
        foreach (range(1, 200) as $i) {
            RegistroModifiche::scrivi(Gesto::su('comunicazioni', 'comunicazione', 1000 + $i, 'Comunicazione n. ' . $i)->azione('gesto')->riepilogo('Pubblicata')
                ->nelCondominio($this->c)->perLaRiga());
        }
        $this->travelTo(Carbon::parse('2026-11-21 10:00:00', 'Europe/Rome'));

        $dallInizio = sdrConto($this, $this->c, ['dal' => '2025-03-01', 'al' => '2026-11-30'])->assertOk()->json();
        expect($dallInizio)->toBe(['righe' => 205, 'limite' => 200])
            ->and(sdrConto($this, $this->c, ['dal' => '2026-01-01', 'al' => '2026-12-31'])->json())->toBe(['righe' => 4, 'limite' => 200]);

        [$r] = sdrStampa($this, $this->c, ['dal' => '2025-03-01', 'al' => '2026-11-30']);
        $r->assertRedirect()->assertSessionHas('message', fn ($m) => str_contains((string) ($m['message'] ?? ''), 'troppe per una stampa sola'));
        [$esercizio] = sdrStampa($this, $this->c, ['dal' => '2026-01-01', 'al' => '2026-12-31']);
        $esercizio->assertOk();
    } finally {
        $limite->setValue(null, null);
    }
});

it('con 0 righe la stampa non parte e torna indietro con la frase: «Nessuna riga con questi filtri: non c\'è niente da stampare.»', function () {
    expect(sdrConto($this, $this->c, ['dal' => '2030-01-01', 'al' => '2030-01-31'])->json('righe'))->toBe(0);

    [$r] = sdrStampa($this, $this->c, ['dal' => '2030-01-01', 'al' => '2030-01-31']);
    $r->assertRedirect()->assertSessionHas('message', fn ($m) => ($m['message'] ?? null) === 'Nessuna riga con questi filtri: non c\'è niente da stampare.');
    expect($r->headers->get('Content-Type'))->not->toContain('pdf');
});

it('la pagina del condominio dà alla finestra gli esercizi di quel condominio soltanto, dal più recente, con nome, date e stato', function () {
    \App\Models\Esercizio::where('condominio_id', $this->c->id)->delete();
    \App\Models\Esercizio::factory()->create(['condominio_id' => $this->c->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    \App\Models\Esercizio::factory()->create(['condominio_id' => $this->c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    \App\Models\Esercizio::factory()->create(['condominio_id' => $this->altro->id, 'nome' => 'Esercizio dell\'altro', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);

    $r = $this->actingAs($this->andrea)->get(route('admin.gestionale.registro-modifiche.index', ['condominio' => $this->c->id]))->assertOk();
    $esercizi = $r->viewData('page')['props']['esercizi'];
    expect(array_column($esercizi, 'nome'))->toBe(['Esercizio 2026', 'Esercizio 2025'])
        ->and(array_keys($esercizi[0]))->toBe(['id', 'nome', 'dal', 'al', 'stato'])
        ->and($esercizi[0]['dal'])->toBe('2026-01-01')->and($esercizi[0]['al'])->toBe('2026-12-31')
        ->and(array_column($esercizi, 'stato'))->toBe(['aperto', 'chiuso']);
});

it('il conto con gli stessi permessi della stampa: 403 a chi non vede il registro', function () {
    RuoloSpatie::findOrCreate('Solo pannello', 'web')->syncPermissions([Permission::ACCESS_ADMIN_PANEL->value, Permission::VIEW_CONDOMINI->value]);
    $soloPannello = User::factory()->create(['email_verified_at' => now()]);
    $soloPannello->assignRole('Solo pannello');
    $marco = User::factory()->create(['email_verified_at' => now()]);
    $marco->assignRole('collaboratore');

    foreach ([$soloPannello, $marco] as $chi) {
        sdrConto($this, $this->c, ['dal' => '2026-01-01', 'al' => '2026-12-31'], $chi)->assertForbidden();
        sdrConto($this, null, ['dal' => '2026-01-01', 'al' => '2026-12-31'], $chi)->assertForbidden();
    }
    sdrConto($this, $this->c, ['dal' => '2026-01-01', 'al' => '2026-12-31'])->assertOk();
});
