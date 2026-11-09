<?php

/**
 * Le carte dello studio: più carte, ognuna a campi con il logo sì o no e il colore dei titoli e delle righe (nullo: i colori di oggi di ogni
 * stampa), e la carta per ognuno dei cinque gruppi di documenti, o la stessa per tutti.
 *
 * - La tabella nasce con la «Carta dello studio» (a campi, con il logo, colore nullo, predefinita); una carta sola è la predefinita.
 * - Un gruppo senza carta scelta, o con una carta che non c'è più, stampa con la predefinita, senza errori; senza la tabella, come oggi.
 * - La predefinita non si elimina (e quindi nemmeno l'ultima); una carta usata da un gruppo nemmeno, e il rifiuto dice quali gruppi.
 * - Il colore entra nei titoli e nei loro filetti, nella testata dello studio e in quella comune; il corpo, le intestazioni delle tabelle e le
 *   cifre restano come sono. Un colore che sul bianco ha un contrasto sotto 4,5 si rifiuta. Il modello F24 non cambia.
 * - «Prova la carta» fa un PDF con dati d'esempio: una lettera verticale e una pagina orizzontale con la testata della carta.
 * - Le rotte delle carte sono di chi gestisce le impostazioni; una carta che non c'è risponde 404.
 */

use App\Enums\Permission as Permesso;
use App\Models\Condominio;
use App\Models\CartaStampa;
use App\Models\Gestionale\DelegaF24;
use App\Models\User;
use App\Services\PDF\CartaDiStampa;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\ProvaDellaCarta;
use App\Settings\PrintSettings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';
require_once __DIR__.'/../Gestionale/RendicontoViaRomaScenario.php';
require_once __DIR__.'/../Gestionale/LettereScenario.php';
require_once __DIR__.'/../Gestionale/RicevutaIncassoScenario.php';
require_once __DIR__.'/Support/CartaDelloStudio.php';

beforeEach(function () {
    $this->user = be52Utente();
    Role::findByName('admin', 'web')->givePermissionTo(Permission::firstOrCreate(['name' => Permesso::MANAGE_GENERAL_SETTINGS->value, 'guard_name' => 'web']));
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
    Storage::fake('local');
    Storage::fake('public');
});

const CDCS_GRUPPI = ['lettere', 'ricevute_e_avvisi', 'rendiconto_e_assemblea', 'per_la_persona', 'registri_e_elenchi'];

/** Il colore delle prove: un bordeaux, scuro abbastanza sul bianco. */
const CDCS_COLORE = '#7a1f1f';

function cdcsPredefinita(): CartaStampa
{
    return CartaStampa::where('predefinita', true)->sole();
}

/** Una carta nuova, a campi, salvata com'è. */
function cdcsCarta(array $campi = []): CartaStampa
{
    return CartaStampa::create($campi + ['nome' => 'Carta blu', 'aspetto' => 'campi', 'mostra_logo' => true, 'colore' => null, 'predefinita' => false]);
}

/** La carta per gruppo nelle impostazioni, scritta com'è (gli id che mancano restano nulli). */
function cdcsScegli(array $perGruppo): void
{
    $s = app(PrintSettings::class);
    $s->carta_per_gruppo = $perGruppo + array_fill_keys(CDCS_GRUPPI, null);
    $s->save();
}

/**
 * Lo scenario di Via Roma al 15/01/2027, con il condominio che ha nome, indirizzo e codice fiscale noti. Il nome è corto e fisso: quello della
 * fabbrica è casuale, e da 31 caratteri va su due righe nella colonna del condominio, dove un controllo sul nome intero non lo troverebbe.
 */
function cdcsScenario($test): array
{
    $s = vrmScenario($test);
    vrmGiorno($test, '2027-01-15');
    $s['c']->update(['nome' => 'Condominio Via Roma 23', 'indirizzo' => 'Via Roma 23, 20100 Milano', 'codice_fiscale' => '90012345678']);
    $s['c'] = $s['c']->fresh();

    return $s;
}

function cdcsPdf($test, string $indirizzo): string
{
    $risposta = $test->actingAs($test->user)->get($indirizzo);
    $risposta->assertOk();

    return (string) ($risposta->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse ? $risposta->streamedContent() : $risposta->getContent());
}

/** Il colore del primo filetto lungo sotto il testo (il primo che comincia così, o quello che si chiede, dal primo: 0), entro 14 mm: il filetto del titolo. */
function cdcsFilettoSotto(string $pdf, int $pagina, string $testo, int $quale = 0): ?string
{
    $pezzi = array_values(array_filter(cdsPagine($pdf)[$pagina - 1] ?? [], fn (array $p) => str_starts_with($p[0], $testo)));
    $y = $pezzi[$quale][2] ?? null;
    foreach ($y === null ? [] : cdsFiletti($pdf, $pagina, 60.0) as [$yFiletto, , $colore]) {
        if ($yFiletto > $y && $yFiletto - $y <= 14.0) {
            return $colore;
        }
    }

    return null;
}

it('la tabella nasce con la «Carta dello studio»: a campi, con il logo, il colore nullo, predefinita; i cinque gruppi senza scelta', function () {
    $carte = CartaStampa::all();
    expect($carte)->toHaveCount(1)
        ->and($carte[0]->only(['nome', 'aspetto', 'mostra_logo', 'colore', 'predefinita']))->toBe(['nome' => 'Carta dello studio', 'aspetto' => 'campi', 'mostra_logo' => true, 'colore' => null, 'predefinita' => true])
        ->and(app(PrintSettings::class)->carta_per_gruppo)->toBe(array_fill_keys(CDCS_GRUPPI, null));
});

it('ogni gruppo stampa con la sua carta, il logo sì o no e il colore; un gruppo senza scelta stampa con la predefinita', function () {
    $s = cdcsScenario($this);
    cdsStudioPieno(true);
    $senzaLogo = cdcsCarta(['nome' => 'Senza logo', 'mostra_logo' => false, 'colore' => CDCS_COLORE]);
    cdcsScegli(['registri_e_elenchi' => $senzaLogo->id]);

    $scadute = cdcsPdf($this, route('admin.gestionale.rate-scadute.stampa', [$s['c']]));
    $distinta = cdcsPdf($this, route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]));

    expect(cdsHaImmagine($scadute, 1))->toBeFalse()
        ->and(cdsColoreDi($scadute, 1, 'Studio Bianchi'))->toBe(substr(CDCS_COLORE, 1))
        ->and(cdsHaImmagine($distinta, 1))->toBeTrue()
        ->and(cdsColoreDi($distinta, 1, 'Studio Bianchi'))->toBe('2c3e50');
    foreach (GruppoCarta::cases() as $gruppo) {
        $carta = app(CartaDiStampa::class)->perGruppo($gruppo);
        $atteso = $gruppo === GruppoCarta::REGISTRI_E_ELENCHI ? [null, CDCS_COLORE] : ($gruppo === GruppoCarta::NESSUNO ? [null, null] : ['logo', null]);
        expect([$carta->logo() === null ? null : 'logo', $carta->colore])->toBe($atteso, $gruppo->value);
    }
});

it('«La stessa per tutti»: la carta scelta per i cinque gruppi vale per ogni stampa, e il rifiuto di eliminarla li nomina tutti', function () {
    cdsStudioPieno(true);
    $unica = cdcsCarta(['nome' => 'Carta unica', 'colore' => CDCS_COLORE]);
    $this->actingAs($this->user)->put(route('impostazioni.stampe.carte.gruppi'), ['carta_per_gruppo' => array_fill_keys(CDCS_GRUPPI, $unica->id)])
        ->assertSessionHasNoErrors();

    foreach (array_filter(GruppoCarta::cases(), fn ($g) => $g !== GruppoCarta::NESSUNO) as $gruppo) {
        expect(app(CartaDiStampa::class)->perGruppo($gruppo)->colore)->toBe(CDCS_COLORE, $gruppo->value);
    }
    $this->actingAs($this->user)->delete(route('impostazioni.stampe.carte.destroy', $unica->id))
        ->assertSessionHas('message', fn ($m) => ($m['type'] ?? null) === 'error' && $m['message'] === 'La carta «Carta unica» si usa per: Lettere ai condòmini, Ricevute e avvisi di compensazione, Rendiconto e assemblea, Estratti conto e prospetti per la persona, Registri ed elenchi dello studio. Scegli un\'altra carta per questi documenti, poi eliminala.');
    expect(CartaStampa::find($unica->id))->not->toBeNull();
});

it('una carta scelta che non c\'è più: il gruppo stampa con la predefinita, senza errori; la pagina la mostra come «la predefinita»', function () {
    $s = cdcsScenario($this);
    cdsStudioPieno(true);
    cdcsScegli(['per_la_persona' => 9999, 'rendiconto_e_assemblea' => 9998]);

    $pdf = cdcsPdf($this, route('admin.gestionale.anagrafiche.estratto-conto.print', [$s['c'], $s['persone']['bice']]));
    expect(cdsHaImmagine($pdf, 1))->toBeTrue()->and(cdsTesto($pdf, 1))->toContain('Studio Bianchi Amministrazioni');
    $props = $this->actingAs($this->user)->get(route('impostazioni.stampe'))->assertOk()->viewData('page')['props'];
    expect($props['carta_per_gruppo'])->toBe(array_fill_keys(CDCS_GRUPPI, null));
});

it('senza la tabella delle carte, come in un aggiornamento a metà, le stampe escono come oggi', function () {
    $s = cdcsScenario($this);
    cdsStudioPieno(true);
    Schema::drop('carte_stampa');
    app()->forgetInstance(CartaDiStampa::class);

    $pdf = cdcsPdf($this, route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]));
    expect(cdsHaImmagine($pdf, 1))->toBeTrue()
        ->and(cdsColoreDi($pdf, 1, 'DISTINTA DELLE SPESE'))->toBe('1e3a5f');
});

it('la predefinita non si elimina, e quindi nemmeno l\'ultima carta; una carta che nessun gruppo usa si elimina', function () {
    $predefinita = cdcsPredefinita();
    $this->actingAs($this->user)->delete(route('impostazioni.stampe.carte.destroy', $predefinita->id))
        ->assertSessionHas('message', fn ($m) => ($m['type'] ?? null) === 'error' && $m['message'] === 'La carta «Carta dello studio» è la predefinita: non si elimina. Per eliminarla, segna prima come predefinita un\'altra carta.');
    expect(CartaStampa::count())->toBe(1);

    $altra = cdcsCarta(['nome' => 'Carta di prova']);
    $this->actingAs($this->user)->delete(route('impostazioni.stampe.carte.destroy', $altra->id))
        ->assertSessionHas('message', fn ($m) => ($m['type'] ?? null) === 'success');
    expect(CartaStampa::find($altra->id))->toBeNull()->and(CartaStampa::count())->toBe(1);
});

it('una carta usata da un gruppo non si elimina: il rifiuto dice quali gruppi', function () {
    $carta = cdcsCarta(['nome' => 'Carta verde']);
    cdcsScegli(['lettere' => $carta->id, 'registri_e_elenchi' => $carta->id]);

    $this->actingAs($this->user)->delete(route('impostazioni.stampe.carte.destroy', $carta->id))
        ->assertSessionHas('message', fn ($m) => ($m['message'] ?? null) === 'La carta «Carta verde» si usa per: Lettere ai condòmini, Registri ed elenchi dello studio. Scegli un\'altra carta per questi documenti, poi eliminala.');
    expect(CartaStampa::find($carta->id))->not->toBeNull();
});

it('il messaggio dopo «Salva la carta» di una carta cambiata: una carta che le stampe usano (la predefinita, o scelta per un gruppo) esce così; una che nessun documento usa lo dice, e dice dove si sceglie', function () {
    $dati = fn (CartaStampa $c) => ['nome' => $c->nome, 'mostra_logo' => true, 'colore' => null, 'predefinita' => (bool) $c->predefinita];
    $predefinita = cdcsPredefinita();
    $scelta = cdcsCarta(['nome' => 'Carta scelta']);
    $libera = cdcsCarta(['nome' => 'Studio Rossi 2026']);
    cdcsScegli(['lettere' => $scelta->id]);

    foreach ([[$predefinita, 'Carta «Carta dello studio» salvata: le stampe che la usano escono così.'], [$scelta, 'Carta «Carta scelta» salvata: le stampe che la usano escono così.'],
        [$libera, 'Carta «Studio Rossi 2026» salvata. Nessun documento la usa ancora: la scegli in «Quale carta per quale documento».']] as [$carta, $frase]) {
        $this->actingAs($this->user)->put(route('impostazioni.stampe.carte.update', $carta->id), $dati($carta))
            ->assertSessionHas('message', fn ($m) => ($m['type'] ?? null) === 'success' && ($m['message'] ?? null) === $frase);
    }
});

it('una carta sola è la predefinita: segnarne un\'altra toglie il segno alla prima; la predefinita non perde il segno da sola', function () {
    $prima = cdcsPredefinita();
    $this->actingAs($this->user)->post(route('impostazioni.stampe.carte.store'), ['nome' => 'Carta nuova', 'mostra_logo' => true, 'colore' => null, 'predefinita' => true])
        ->assertSessionHasNoErrors();
    $nuova = CartaStampa::where('nome', 'Carta nuova')->sole();
    expect(CartaStampa::where('predefinita', true)->pluck('id')->all())->toBe([$nuova->id])
        ->and($prima->fresh()->predefinita)->toBeFalse();

    $this->actingAs($this->user)->put(route('impostazioni.stampe.carte.update', $nuova->id), ['nome' => 'Carta nuova', 'mostra_logo' => true, 'colore' => null, 'predefinita' => false])
        ->assertSessionHasErrors(['predefinita' => 'C\'è sempre una carta predefinita: per cambiarla, segna come predefinita un\'altra carta.']);
    expect($nuova->fresh()->predefinita)->toBeTrue();
});

it('una carta si crea e si cambia: il nome, il logo sì o no, il colore in minuscolo; senza l\'aspetto, a campi; due carte non hanno lo stesso nome', function () {
    $this->actingAs($this->user)->post(route('impostazioni.stampe.carte.store'), ['nome' => '  Carta rossa  ', 'mostra_logo' => false, 'colore' => '#7A1F1F', 'predefinita' => false])
        ->assertSessionHasNoErrors();
    $carta = CartaStampa::where('nome', 'Carta rossa')->sole();
    expect($carta->only(['aspetto', 'mostra_logo', 'colore', 'predefinita']))->toBe(['aspetto' => 'campi', 'mostra_logo' => false, 'colore' => CDCS_COLORE, 'predefinita' => false]);

    $this->actingAs($this->user)->put(route('impostazioni.stampe.carte.update', $carta->id), ['nome' => 'Carta bordeaux', 'mostra_logo' => true, 'colore' => null, 'predefinita' => false])
        ->assertSessionHasNoErrors();
    expect($carta->fresh()->only(['nome', 'mostra_logo', 'colore']))->toBe(['nome' => 'Carta bordeaux', 'mostra_logo' => true, 'colore' => null]);

    $this->actingAs($this->user)->post(route('impostazioni.stampe.carte.store'), ['nome' => 'Carta dello studio', 'mostra_logo' => true, 'colore' => null, 'predefinita' => false])
        ->assertSessionHasErrors(['nome' => 'C\'è già una carta con questo nome.']);
});

it('il colore: #rrggbb, e con un contrasto sul bianco di almeno 4,5; il rifiuto dice perché', function (string $colore, ?string $frase) {
    $r = $this->actingAs($this->user)->post(route('impostazioni.stampe.carte.store'), ['nome' => 'Carta '.$colore, 'mostra_logo' => true, 'colore' => $colore, 'predefinita' => false]);
    $frase === null ? $r->assertSessionHasNoErrors() : $r->assertSessionHasErrors(['colore' => $frase]);
})->with([
    'un bordeaux' => ['#7a1f1f', null],
    'il blu di oggi' => ['#1e3a5f', null],
    'un grigio al limite (4,5)' => ['#767676', null],
    'un grigio appena più chiaro' => ['#777777', 'Il colore #777777 sul bianco della carta ha un contrasto di 4,47 a 1: i titoli si leggerebbero male. Scegli un colore più scuro (il contrasto deve essere almeno 4,5 a 1).'],
    'un giallo' => ['#f0c000', 'Il colore #f0c000 sul bianco della carta ha un contrasto di 1,71 a 1: i titoli si leggerebbero male. Scegli un colore più scuro (il contrasto deve essere almeno 4,5 a 1).'],
    'un nome di colore' => ['rosso', 'Il colore si scrive come #rrggbb, per esempio #1e3a5f.'],
    'tre cifre' => ['#abc', 'Il colore si scrive come #rrggbb, per esempio #1e3a5f.'],
    'uno stile nascosto' => ['#000000; background: url(x)', 'Il colore si scrive come #rrggbb, per esempio #1e3a5f.'],
]);

it('il colore nullo: ogni stampa ha i colori di oggi nei titoli, nei filetti e nella testata', function () {
    $s = cdcsScenario($this);
    cdsSenzaStudio();
    $senza = cdcsPdf($this, route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]));
    $registro = cdcsPdf($this, route('admin.gestionale.esercizi.registro-contabilita.print', [$s['c'], $s['e']]));
    cdsStudioPieno(true);
    $con = cdcsPdf($this, route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]));

    expect(cdsColoreDi($senza, 1, $s['c']->nome))->toBe('2c3e50')
        ->and(cdsFiletti($senza, 1, 150.0)[0][2])->toBe('2c3e50')
        ->and(cdsColoreDi($senza, 1, 'DISTINTA DELLE SPESE'))->toBe('1e3a5f')
        ->and(cdcsFilettoSotto($senza, 1, 'DISTINTA DELLE SPESE'))->toBe('1e3a5f')
        ->and(cdsColoreDi($registro, 1, 'Registro di contabilità'))->toBe('0e2238')
        ->and(cdsColoreDi($con, 1, 'Studio Bianchi'))->toBe('2c3e50')
        ->and(cdsFiletti($con, 1, 150.0)[0][2])->toBe('2c3e50');
});

it('un colore scelto entra nei titoli e nei loro filetti, nella testata dello studio e in quella comune; il corpo, le intestazioni delle tabelle e le cifre no', function () {
    $s = cdcsScenario($this);
    $predefinita = cdcsPredefinita();
    $predefinita->update(['colore' => CDCS_COLORE]);
    $c = substr(CDCS_COLORE, 1);
    $male = [];
    $controlla = function (string $nome, ?string $avuto, string $atteso) use (&$male) {
        if ($avuto !== $atteso) {
            $male[] = "{$nome}: {$avuto} invece di {$atteso}";
        }
    };

    cdsSenzaStudio();
    $senza = cdcsPdf($this, route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]));
    $controlla('la testata comune: il condominio', cdsColoreDi($senza, 1, $s['c']->nome), $c);
    $controlla('la testata comune: il filetto', cdsFiletti($senza, 1, 150.0)[0][2] ?? null, $c);
    $registroSenza = cdcsPdf($this, route('admin.gestionale.esercizi.registro-contabilita.print', [$s['c'], $s['e']]));
    $controlla('il registro senza lo studio: il condominio in testa', cdsColoreDi($registroSenza, 1, $s['c']->nome), $c);
    // Il filetto della tabella di testa è disegnato cella per cella: il primo pezzo è lungo quanto la colonna del condominio.
    $controlla('il registro senza lo studio: il filetto della testata', cdsFiletti($registroSenza, 1, 60.0)[0][2] ?? null, $c);

    cdsStudioPieno(true);
    $distinta = cdcsPdf($this, route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]));
    $controlla('la distinta: lo studio', cdsColoreDi($distinta, 1, 'Studio Bianchi'), $c);
    $controlla('la distinta: il condominio', cdsColoreDi($distinta, 1, $s['c']->nome), $c);
    $controlla('la distinta: il filetto della testata', cdsFiletti($distinta, 1, 150.0)[0][2] ?? null, $c);
    $controlla('la distinta: il titolo', cdsColoreDi($distinta, 1, 'DISTINTA DELLE SPESE'), $c);
    $controlla('la distinta: il filetto del titolo', cdcsFilettoSotto($distinta, 1, 'DISTINTA DELLE SPESE'), $c);
    $controlla('la distinta: il nome di un gruppo di voci', cdsColoreDi($distinta, 1, 'MANUTENZIONI'), '1e3a5f');
    $controlla('la distinta: il totale', cdsColoreDi($distinta, 1, 'TOTALE PREVENTIVO'), '333333');
    $controlla('la distinta: una voce', cdsColoreDi($distinta, 1, 'Pulizie'), '333333');

    $riparto = cdcsPdf($this, route('admin.gestionale.esercizi.piani-rate.print-riparto-tabelle', [$s['c'], $s['e'], $s['piano']]));
    $controlla('il riparto: il titolo', cdsColoreDi($riparto, 1, 'RIPARTO BILANCIO'), $c);
    $controlla('il riparto: il filetto del titolo', cdcsFilettoSotto($riparto, 1, 'RIPARTO BILANCIO'), $c);

    $registro = cdcsPdf($this, route('admin.gestionale.esercizi.registro-contabilita.print', [$s['c'], $s['e']]));
    $controlla('il registro: lo studio', cdsColoreDi($registro, 1, 'Studio Bianchi'), $c);
    $controlla('il registro: il titolo', cdsColoreDi($registro, 1, 'Registro di contabilità'), $c);
    $controlla('il registro: il filetto della testata', cdsFiletti($registro, 1, 150.0)[0][2] ?? null, $c);
    $controlla('il registro: l\'intestazione della tabella', cdsColoreDi($registro, 1, 'DATA'), '5a6b7b');

    $mastro = cdcsPdf($this, route('admin.gestionale.esercizi.libro-mastro.print', [$s['c'], $s['e']]));
    $controlla('il libro mastro: il titolo', cdsColoreDi($mastro, 1, 'Libro mastro'), $c);

    $fascicolo = cdcsPdf($this, route('admin.gestionale.esercizi.rendiconto.fascicolo', [$s['c'], $s['e']]));
    $controlla('il frontespizio: il titolo', cdsColoreDi($fascicolo, 1, 'Rendiconto dell'), $c);
    $controlla('il frontespizio: lo studio', cdsColoreDi($fascicolo, 1, 'Studio Bianchi'), $c);

    $f24Con = cdcsPdf($this, route('admin.gestionale.f24.modello', [$s['c'], DelegaF24::where('condominio_id', $s['c']->id)->firstOrFail()]));
    $predefinita->update(['colore' => null]);
    app()->forgetInstance(CartaDiStampa::class);
    $f24Senza = cdcsPdf($this, route('admin.gestionale.f24.modello', [$s['c'], DelegaF24::where('condominio_id', $s['c']->id)->firstOrFail()]));
    if (cdsFlussi($f24Con) != cdsFlussi($f24Senza)) {
        $male[] = 'il modello F24 cambia con il colore';
    }

    expect($male)->toBe([]);
});

it('un colore scelto nelle lettere e nelle ricevute: il titolo, il suo filetto, lo studio; le intestazioni delle tabelle restano come sono', function () {
    cdcsPredefinita()->update(['colore' => CDCS_COLORE]);
    cdsStudioPieno(true);
    $c = substr(CDCS_COLORE, 1);
    $l = lttScenario($this);
    $lettere = (string) $this->actingAs($this->user)->post(lttRotta($l, 'pdf-anteprima'), lttScelte($l))->assertOk()->getContent();

    $r = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($r, $r->anna, [[$r->anna1, 150.00]], '2026-02-01', 150.00);
    $ricevuta = (string) $this->actingAs(amministratoreRicevute())->post(route('admin.gestionale.ricevute.pdf-anteprima', ['condominio' => $r->condominio->id]), ['incassi' => [$incasso->id]])->assertOk()->getContent();

    expect([cdsColoreDi($lettere, 1, 'Sollecito'), cdcsFilettoSotto($lettere, 1, 'Sollecito'), cdsColoreDi($lettere, 1, 'Studio Bianchi'), cdsColoreDi($lettere, 1, 'Unità')])
        ->toBe([$c, $c, $c, '333333'])
        // Con lo studio la ricevuta scrive «Amministratore del Condominio …» come la lettera, nel colore del testo: lo studio ha il colore della carta.
        ->and([cdsColoreDi($ricevuta, 1, 'RICEVUTA DI PAGAMENTO'), cdcsFilettoSotto($ricevuta, 1, 'RICEVUTA DI PAGAMENTO'), cdsColoreDi($ricevuta, 1, 'Studio Bianchi'),
            cdsColoreDi($ricevuta, 1, 'Amministratore del')])
        ->toBe([$c, $c, $c, '333333']);
});

it('«Prova la carta» fa un PDF con dati d\'esempio: una lettera verticale e una pagina orizzontale con la testata della carta, il colore e il logo; mai i dati di un condominio', function () {
    cdsStudioPieno(true);
    Condominio::factory()->create(['nome' => 'Condominio Vero di Via Garibaldi', 'codice_fiscale' => '90011122233']);
    $carta = cdcsCarta(['nome' => 'Carta rossa', 'colore' => CDCS_COLORE]);

    $risposta = $this->actingAs($this->user)->get(route('impostazioni.stampe.carte.prova', $carta->id));
    $risposta->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $pdf = (string) $risposta->getContent();
    preg_match_all('#/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]#', $pdf, $misure, PREG_SET_ORDER);
    $versi = array_map(fn ($m) => (float) $m[1] < (float) $m[2] ? 'verticale' : 'orizzontale', $misure);
    $tutto = cdsTesto($pdf, 1).' '.cdsTesto($pdf, 2);

    expect(mb_strlen(ProvaDellaCarta::CONDOMINIO))->toBe(60)
        ->and(cdsNumeroPagine($pdf))->toBe(2)
        ->and(array_values(array_unique($versi)))->toBe(['verticale', 'orizzontale'])
        ->and(cdsTesto($pdf, 1))->toContain(ProvaDellaCarta::DESTINATARIO)->toContain('Studio Bianchi Amministrazioni')
        // La lettera con i margini delle lettere: l'etichetta in cima al foglio, come nell'anteprima delle lettere.
        ->and(cdsDoveObbligatorio($pdf, 1, 'PROVA — non è una lettera mandata')[1])->toBeLessThan(25.0)
        ->and(str_replace("\u{200B}", '', $tutto))->toContain(ProvaDellaCarta::CONDOMINIO)
        ->and($tutto)->not->toContain('Condominio Vero')->not->toContain('90011122233')
        ->and(cdsHaImmagine($pdf, 1))->toBeTrue()->and(cdsHaImmagine($pdf, 2))->toBeTrue()
        ->and(cdsColoreDi($pdf, 2, 'Studio Bianchi'))->toBe(substr(CDCS_COLORE, 1))
        ->and(cdsFiletti($pdf, 2, 150.0)[0][2] ?? null)->toBe(substr(CDCS_COLORE, 1));

    $senzaLogo = cdcsCarta(['nome' => 'Senza logo', 'mostra_logo' => false]);
    $pdf = (string) $this->actingAs($this->user)->get(route('impostazioni.stampe.carte.prova', $senzaLogo->id))->assertOk()->getContent();
    expect(cdsHaImmagine($pdf, 1))->toBeFalse()->and(cdsHaImmagine($pdf, 2))->toBeFalse()
        ->and(cdsColoreDi($pdf, 2, 'Studio Bianchi'))->toBe('2c3e50');
});

it('«Prova la carta» scrive in testa alla lettera il luogo della lettera vera: il comune dello studio, se c\'è; senza, quello del condominio d\'esempio', function (?string $comune, string $luogo) {
    cdsStudioPieno(false, ['studio_comune' => $comune]);
    $carta = cdcsCarta(['nome' => 'Carta del luogo']);
    $pdf = (string) $this->actingAs($this->user)->get(route('impostazioni.stampe.carte.prova', $carta->id))->assertOk()->getContent();

    expect(cdsTesto($pdf, 1))->toContain($luogo.', 01/05/2026');
    if ($luogo !== 'Roma') {
        expect(cdsTesto($pdf, 1))->not->toContain('Roma, 01/05/2026');
    }
})->with([
    'lo studio a Milano' => ['Milano', 'Milano'],
    'lo studio senza il comune' => [null, 'Roma'],
]);

it('le rotte delle carte sono di chi gestisce le impostazioni, e senza il permesso niente si scrive (né le carte, né la scelta per gruppo, né il file della carta intestata); una carta che non c\'è risponde 404', function () {
    $carta = cdcsCarta();
    $sceltaDiPrima = app(PrintSettings::class)->refresh()->carta_per_gruppo;
    $altro = User::factory()->create();
    $altro->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $dati = ['nome' => 'Altra', 'mostra_logo' => true, 'colore' => null, 'predefinita' => false];
    // Una carta intestata che si potrebbe salvare: un'immagine A4 larga 1.240 pixel, con i margini.
    $percorso = tempnam(sys_get_temp_dir(), 'cdcs');
    file_put_contents($percorso, cdsPng(1240, 1754));
    $conLaCarta = $dati + ['aspetto' => 'carta', 'margine_alto' => 45, 'margine_basso' => 25, 'margine_sinistro' => 20, 'margine_destro' => 18,
        'carta' => new \Illuminate\Http\UploadedFile($percorso, 'carta.png', 'image/png', null, true)];

    $this->actingAs($altro)->post(route('impostazioni.stampe.carte.store'), $conLaCarta)->assertForbidden();
    $this->actingAs($altro)->put(route('impostazioni.stampe.carte.update', $carta->id), $conLaCarta)->assertForbidden();
    $this->actingAs($altro)->delete(route('impostazioni.stampe.carte.destroy', $carta->id))->assertForbidden();
    $this->actingAs($altro)->put(route('impostazioni.stampe.carte.gruppi'), ['carta_per_gruppo' => array_fill_keys(CDCS_GRUPPI, $carta->id)])->assertForbidden();
    $this->actingAs($altro)->get(route('impostazioni.stampe.carte.prova', $carta->id))->assertForbidden();
    expect($carta->fresh()->only(['nome', 'aspetto', 'carta_path']))->toBe(['nome' => 'Carta blu', 'aspetto' => 'campi', 'carta_path' => null])
        ->and(CartaStampa::count())->toBe(2)
        ->and(app(PrintSettings::class)->refresh()->carta_per_gruppo)->toBe($sceltaDiPrima)
        ->and(Storage::disk('public')->allFiles())->toBe([]);

    $this->actingAs($this->user)->put(route('impostazioni.stampe.carte.update', 9999), $dati)->assertNotFound();
    $this->actingAs($this->user)->delete(route('impostazioni.stampe.carte.destroy', 'nove'))->assertNotFound();
    $this->actingAs($this->user)->get(route('impostazioni.stampe.carte.prova', 9999))->assertNotFound();
});

it('la scelta delle carte per i documenti: una carta che non c\'è si rifiuta; un gruppo che non c\'è anche', function () {
    $this->actingAs($this->user)->put(route('impostazioni.stampe.carte.gruppi'), ['carta_per_gruppo' => ['lettere' => 9999] + array_fill_keys(CDCS_GRUPPI, null)])
        ->assertSessionHasErrors(['carta_per_gruppo.lettere' => 'La carta scelta per «Lettere ai condòmini» non c\'è più: scegline un\'altra.']);
    $this->actingAs($this->user)->put(route('impostazioni.stampe.carte.gruppi'), ['carta_per_gruppo' => array_fill_keys(CDCS_GRUPPI, null) + ['nessuno' => null]])
        ->assertSessionHasErrors('carta_per_gruppo');
    expect(app(PrintSettings::class)->refresh()->carta_per_gruppo)->toBe(array_fill_keys(CDCS_GRUPPI, null));
});

it('la pagina riceve le carte, chi le usa, la scelta per gruppo e i gruppi con i loro esempi; la scelta rimandata com\'è non cambia niente', function () {
    $blu = cdcsCarta(['nome' => 'Carta «blu» & <b>', 'colore' => '#1e3a5f', 'mostra_logo' => false]);
    cdcsScegli(['lettere' => $blu->id]);
    $prima = app(PrintSettings::class)->refresh()->toArray();

    $p = $this->actingAs($this->user)->get(route('impostazioni.stampe'))->assertOk()->viewData('page')['props'];
    expect(collect($p['carte'])->firstWhere('id', $blu->id))->toBe(['id' => $blu->id, 'nome' => 'Carta «blu» & <b>', 'aspetto' => 'campi', 'mostra_logo' => false,
        'colore' => '#1e3a5f', 'predefinita' => false, 'usata_da' => ['Lettere ai condòmini'], 'carta_tipo' => null, 'carta_pagine' => null, 'margini' => null,
        'carta_illeggibile' => null])
        ->and($p['carta_per_gruppo'])->toBe(['lettere' => $blu->id] + array_fill_keys(CDCS_GRUPPI, null))
        ->and(array_column($p['gruppi_carta'], 'valore'))->toBe(CDCS_GRUPPI)
        ->and($p['gruppi_carta'][0])->toBe(['valore' => 'lettere', 'nome' => 'Lettere ai condòmini', 'esempi' => 'promemoria, sollecito, diffida, messa in mora']);

    $this->actingAs($this->user)->put(route('impostazioni.stampe.carte.gruppi'), ['carta_per_gruppo' => $p['carta_per_gruppo']])->assertSessionHasNoErrors();
    expect(app(PrintSettings::class)->refresh()->toArray())->toBe($prima);
});

it('la pagina crea la «Carta dello studio» se la tabella è vuota', function () {
    CartaStampa::query()->delete();
    $p = $this->actingAs($this->user)->get(route('impostazioni.stampe'))->assertOk()->viewData('page')['props'];
    expect($p['carte'])->toHaveCount(1)->and($p['carte'][0]['nome'])->toBe('Carta dello studio')->and($p['carte'][0]['predefinita'])->toBeTrue();
});

it('le carte si leggono una volta per richiesta; una carta cambiata dalla pagina vale per la stampa dopo', function () {
    $s = cdcsScenario($this);
    \Illuminate\Support\Facades\DB::enableQueryLog();
    foreach (GruppoCarta::cases() as $gruppo) {
        app(CartaDiStampa::class)->perGruppo($gruppo);
    }
    $letture = collect(\Illuminate\Support\Facades\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'carte_stampa'))->count();
    \Illuminate\Support\Facades\DB::disableQueryLog();
    expect($letture)->toBe(1);

    $indirizzo = route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]);
    expect(cdsColoreDi(cdcsPdf($this, $indirizzo), 1, 'DISTINTA DELLE SPESE'))->toBe('1e3a5f');
    $predefinita = cdcsPredefinita();
    $this->actingAs($this->user)->put(route('impostazioni.stampe.carte.update', $predefinita->id), ['nome' => $predefinita->nome, 'mostra_logo' => true, 'colore' => CDCS_COLORE, 'predefinita' => true])
        ->assertSessionHasNoErrors();
    expect(cdsColoreDi(cdcsPdf($this, $indirizzo), 1, 'DISTINTA DELLE SPESE'))->toBe(substr(CDCS_COLORE, 1));
});

it('con lo studio, un nome del condominio di 32 caratteri va su due righe nella colonna del condominio, e tutte e due hanno il colore della carta', function () {
    $s = cdcsScenario($this);
    $s['c']->update(['nome' => 'De luca, Ruggiero e Vitale Group']);
    cdcsPredefinita()->update(['colore' => CDCS_COLORE]);
    cdsStudioPieno(true);

    $pdf = cdcsPdf($this, route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]));
    $prima = cdsDove($pdf, 1, 'De luca');
    $seconda = cdsDove($pdf, 1, 'Group');

    expect(mb_strlen('De luca, Ruggiero e Vitale Group'))->toBe(32)
        ->and($prima)->not->toBeNull()->and($seconda)->not->toBeNull()
        ->and($seconda[1])->toBeGreaterThan($prima[1] + 2.0)
        ->and(cdsColoreDi($pdf, 1, 'De luca'))->toBe(substr(CDCS_COLORE, 1))
        ->and(cdsColoreDi($pdf, 1, 'Group'))->toBe(substr(CDCS_COLORE, 1));
});

/** Le stampe in Inter con il sottotitolo sotto il titolo: [nome, indirizzo, pagina, l'inizio del sottotitolo]. */
function cdcsSottotitoli(array $s): array
{
    $c = $s['c'];
    $e = $s['e'];

    return [
        ['il frontespizio', route('admin.gestionale.esercizi.rendiconto.fascicolo', [$c, $e]), 1, $e->nome.', dal'],
        ['il registro', route('admin.gestionale.esercizi.registro-contabilita.print', [$c, $e]), 1, 'Movimenti in entrata e in uscita'],
        ['il mastrino', route('admin.gestionale.esercizi.conti.movimenti.print', [$c, $e, $s['cassa']->conto_contabile_id]), 1, 'Mastrino del conto'],
        ['il libro mastro', route('admin.gestionale.esercizi.libro-mastro.print', [$c, $e]), 1, 'I mastrini di tutti i conti'],
        ['la situazione', route('admin.gestionale.esercizi.stato-patrimoniale.print', [$c, $e]).'?parte=situazione', 1, 'Situazione al'],
        ['il riepilogo', route('admin.gestionale.esercizi.rendiconto.riepilogo.stampa', [$c, $e]), 1, 'Per competenza'],
    ];
}

it('il sottotitolo delle stampe in Inter prende il colore della carta; con il colore nullo resta il blu di sempre', function () {
    $s = cdcsScenario($this);
    cdsSenzaStudio();
    $male = [];
    foreach ([null => '2e5c86', CDCS_COLORE => substr(CDCS_COLORE, 1)] as $colore => $atteso) {
        cdcsPredefinita()->update(['colore' => $colore === '' ? null : $colore]);
        app()->forgetInstance(CartaDiStampa::class);
        foreach (cdcsSottotitoli($s) as [$nome, $indirizzo, $pagina, $testo]) {
            $avuto = cdsColoreDi(cdcsPdf($this, $indirizzo), $pagina, $testo);
            if ($avuto !== $atteso) {
                $male[] = "{$nome}, colore ".($colore ?: 'nullo').": {$avuto} invece di {$atteso}";
            }
        }
    }
    expect($male)->toBe([]);
});

/** Il colore della data accanto al titolo del § 5 della situazione («dal … al …», la norma del titolo), su qualunque pagina. */
function cdcsColoreDellaNorma(string $pdf): ?string
{
    foreach (cdsFlussi($pdf) as $pagina) {
        foreach ($pagina['pezzi'] as $pezzo) {
            if (preg_match('#^dal \d{2}/\d{2}/\d{4} al \d{2}/\d{2}/\d{4}#', $pezzo[0]) === 1) {
                return $pezzo[3];
            }
        }
    }

    return null;
}

it('la data accanto al titolo del § 5 della situazione prende il colore della carta; con il colore nullo resta il blu di sempre', function () {
    $s = cdcsScenario($this);
    cdsSenzaStudio();
    $avuti = [];
    foreach ([null => '2e5c86', CDCS_COLORE => substr(CDCS_COLORE, 1)] as $colore => $atteso) {
        cdcsPredefinita()->update(['colore' => $colore === '' ? null : $colore]);
        app()->forgetInstance(CartaDiStampa::class);
        $avuti[$atteso] = cdcsColoreDellaNorma(cdcsPdf($this, route('admin.gestionale.esercizi.stato-patrimoniale.print', [$s['c'], $s['e']]).'?parte=cassa'));
    }

    expect($avuti)->toBe(['2e5c86' => '2e5c86', substr(CDCS_COLORE, 1) => substr(CDCS_COLORE, 1)]);
});

/**
 * Un server di prova su 127.0.0.1, in un processo a parte, che scrive in un file l'indirizzo di ogni richiesta che riceve e risponde 404: [il
 * processo, la porta, il file delle richieste].
 */
function cdcsServerDiProva(): array
{
    $cartella = sys_get_temp_dir().'/cdcs-server-'.bin2hex(random_bytes(4));
    mkdir($cartella);
    file_put_contents($cartella.'/router.php', '<?php file_put_contents(__DIR__."/richieste.log", $_SERVER["REQUEST_URI"]."\n", FILE_APPEND); http_response_code(404);');
    $libera = stream_socket_server('tcp://127.0.0.1:0');
    $porta = (int) substr((string) strrchr((string) stream_socket_get_name($libera, false), ':'), 1);
    fclose($libera);
    // Un processo solo: con più processi (la variabile delle impostazioni) quelli figli resterebbero vivi dopo la fine della prova.
    $processo = new \Symfony\Component\Process\Process([PHP_BINARY, '-S', '127.0.0.1:'.$porta, $cartella.'/router.php'], $cartella, ['PHP_CLI_SERVER_WORKERS' => false]);
    $processo->start();
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $porta) === false; $i++) {
        usleep(50_000);
    }

    return [$processo, $porta, $cartella.'/richieste.log'];
}

it('un colore scritto nel database senza passare dalla pagina, che non è #rrggbb, non arriva alla stampa: i colori di sempre, una riga nel registro, nessuna richiesta all\'indirizzo scritto nel colore', function () {
    $s = cdcsScenario($this);
    [$server, $porta, $richieste] = cdcsServerDiProva();
    \Illuminate\Support\Facades\DB::table('carte_stampa')->where('predefinita', true)
        ->update(['colore' => "red; background: url(http://127.0.0.1:{$porta}/colore.png)"]);
    app()->forgetInstance(CartaDiStampa::class);
    \Illuminate\Support\Facades\Log::spy();
    $indirizzo = route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]);

    try {
        cdsSenzaStudio();
        $senza = cdcsPdf($this, $indirizzo);
        cdsStudioPieno(true);
        $con = cdcsPdf($this, $indirizzo);
    } finally {
        $server->stop(1);
    }

    expect(is_file($richieste) ? trim((string) file_get_contents($richieste)) : '')->toBe('')
        ->and(cdsColoreDi($senza, 1, 'DISTINTA DELLE SPESE'))->toBe('1e3a5f')
        ->and(cdsColoreDi($con, 1, 'DISTINTA DELLE SPESE'))->toBe('1e3a5f')
        ->and(cdsColoreDi($con, 1, 'Studio Bianchi'))->toBe('2c3e50');
    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $frase, array $contesto = []) => str_contains($frase, 'colore') && str_contains((string) ($contesto['colore'] ?? ''), 'url('))->atLeast()->once();
});

it('una carta scelta che non c\'è più vale la predefinita, anche quando la predefinita non è la carta di sempre: il colore della predefinita', function () {
    $s = cdcsScenario($this);
    cdcsPredefinita()->update(['colore' => CDCS_COLORE]);
    cdcsScegli(['rendiconto_e_assemblea' => 9998]);

    $pdf = cdcsPdf($this, route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]));
    expect(cdsColoreDi($pdf, 1, 'DISTINTA DELLE SPESE'))->toBe(substr(CDCS_COLORE, 1))
        ->and(cdcsFilettoSotto($pdf, 1, 'DISTINTA DELLE SPESE'))->toBe(substr(CDCS_COLORE, 1));
});

/**
 * Le stampe con i titoli che prendono il colore, senza lo studio: [nome, indirizzo, [[pagina, l'inizio del titolo, il filetto sotto sì o no, quale
 * dei testi che cominciano così (dal primo, 0)], …]]. Il nome del condominio (`.ente` delle stampe in Inter) è un titolo anche lui.
 */
function cdcsTitoli(array $s): array
{
    $c = $s['c'];
    $e = $s['e'];
    $r = fn (string $nome, array $parametri, array $query = []) => route('admin.gestionale.'.$nome, $parametri).($query ? '?'.http_build_query($query) : '');

    return [
        ['lo scadenziario', $r('esercizi.piani-rate.print-scadenziario', [$c, $e, $s['piano']], ['modalita' => 'entrambi']),
            [[1, 'SCADENZIARIO RATE', true], [1, 'Prospetto per Condòmino', false]]],
        ['il riparto per tabella', $r('esercizi.piani-rate.print-riparto-tabelle', [$c, $e, $s['piano']]), [[1, 'RIPARTO BILANCIO PREVENTIVO PER TABELLA', true]]],
        ['il riparto per capitolo', $r('esercizi.piani-rate.print-riparto-capitoli', [$c, $e, $s['piano']]), [[1, 'RIPARTO BILANCIO PREVENTIVO PER CAPITOLO', true]]],
        ['la distinta', $r('esercizi.piani-conti.print-distinta', [$c, $e, $s['pc']]), [[1, 'DISTINTA DELLE SPESE', true]]],
        ['la ripartizione delle spese', $r('esercizi.piani-conti.print-riparto', [$c, $e, $s['pc']]), [[1, 'PROSPETTO DI RIPARTIZIONE SPESE', true]]],
        ['l\'estratto conto', $r('anagrafiche.estratto-conto.print', [$c, $s['persone']['bice']]), [[1, 'ESTRATTO CONTO', true]]],
        ['il prospetto degli oneri', $r('immobili.prospetto-oneri', [$c, $s['unita']['A'], 'esercizio' => $e->id]), [[1, 'PROSPETTO DEGLI ONERI ACCESSORI', true]]],
        // Il titolo delle rate scadute è anche nella riga del titolo della testata (grigia): quello del corpo è il secondo.
        ['le rate scadute', $r('rate-scadute.stampa', [$c]), [[1, 'Rate scadute', true, 1]]],
        ['la distinta del pagamento', $r('pagamenti-fornitori.distinta', [$c, $s['pag_ft1']]), [[1, 'Distinta di Pagamento Fornitore', false]]],
        ['il libro giornale', $r('esercizi.scritture.print', [$c, $e]), [[1, 'LIBRO GIORNALE', true]]],
        ['le posizioni', $r('esercizi.rendiconto.condomini.posizioni.stampa', [$c, $e]), [[1, 'Posizioni dei condòmini', true]]],
        ['i crediti', $r('esercizi.rendiconto.condomini.crediti.stampa', [$c, $e]), [[1, 'Crediti per anzianità', true]]],
        ['il registro', $r('esercizi.registro-contabilita.print', [$c, $e]), [[1, 'Registro di contabilità', false], [1, 'Condominio Via Roma 23', false]]],
        ['il mastrino', $r('esercizi.conti.movimenti.print', [$c, $e, $s['cassa']->conto_contabile_id]), [[1, 'Condominio Via Roma 23', false]]],
        ['il libro mastro', $r('esercizi.libro-mastro.print', [$c, $e]), [[1, 'Libro mastro', false], [1, 'Condominio Via Roma 23', false]]],
        ['la situazione', $r('esercizi.stato-patrimoniale.print', [$c, $e], ['parte' => 'situazione']), [[1, 'Situazione patrimoniale', false], [1, 'Condominio Via Roma 23', false]]],
        ['il riepilogo', $r('esercizi.rendiconto.riepilogo.stampa', [$c, $e]), [[1, 'Riepilogo finanziario', false], [1, 'Condominio Via Roma 23', false]]],
        ['il frontespizio', $r('esercizi.rendiconto.fascicolo', [$c, $e]), [[1, 'Rendiconto dell', false], [1, 'I controlli', true]]],
    ];
}

it('un colore scelto entra nel titolo di ogni stampa e nel filetto sotto il titolo, anche senza lo studio', function () {
    $s = cdcsScenario($this);
    cdcsPredefinita()->update(['colore' => CDCS_COLORE]);
    cdsSenzaStudio();
    $c = substr(CDCS_COLORE, 1);
    $male = [];
    foreach (cdcsTitoli($s) as [$nome, $indirizzo, $titoli]) {
        $pdf = cdcsPdf($this, $indirizzo);
        foreach ($titoli as $titolo) {
            [$pagina, $testo, $conFiletto] = $titolo;
            $quale = $titolo[3] ?? 0;
            $pezzi = array_values(array_filter(cdsFlussi($pdf)[$pagina - 1]['pezzi'] ?? [], fn (array $p) => str_starts_with($p[0], $testo)));
            if (($avuto = $pezzi[$quale][3] ?? null) !== $c) {
                $male[] = "{$nome}, «{$testo}»: ".($avuto ?? 'non trovato')." invece di {$c}";
            }
            if ($conFiletto && ($filetto = cdcsFilettoSotto($pdf, $pagina, $testo, $quale)) !== $c) {
                $male[] = "{$nome}, il filetto sotto «{$testo}»: ".($filetto ?? 'non trovato')." invece di {$c}";
            }
        }
    }
    expect($male)->toBe([]);
});

it('un colore scelto nell\'avviso di compensazione: il titolo e il suo filetto', function () {
    cdcsPredefinita()->update(['colore' => CDCS_COLORE]);
    $r = scenarioRicevutaIncasso();
    $this->travelTo('2026-03-02 10:00:00');
    incassaPerRicevuta($r, $r->anna, [[$r->anna1, 150.00]], '2026-02-01', 170.00);
    $incasso = incassaPerRicevuta($r, $r->anna, [[$r->anna1->fresh(), -20.00], [$r->anna2, 20.00]], '2026-03-01', 0.00);
    $avviso = (string) $this->actingAs(amministratoreRicevute())->post(route('admin.gestionale.ricevute.avvisi-compensazione.stampa', ['condominio' => $r->condominio->id]),
        ['avvisi' => [$incasso->id.':'.$r->anna->id], 'bollo' => 'non_dovuta'])->assertOk()->getContent();

    expect([cdsColoreDi($avviso, 1, 'Avviso di compensazione'), cdcsFilettoSotto($avviso, 1, 'Avviso di compensazione')])
        ->toBe([substr(CDCS_COLORE, 1), substr(CDCS_COLORE, 1)]);
});

it('una carta segnata come predefinita: la lettura delle altre predefinite blocca le righe fino alla fine del salvataggio (due richieste insieme non lasciano due predefinite)', function () {
    $nuova = cdcsCarta(['nome' => 'Carta nuova']);
    $connessione = \Illuminate\Support\Facades\DB::connection();
    $grammatica = $connessione->getQueryGrammar();
    // La grammatica di MySQL, dove il blocco si scrive: SQLite non lo scrive.
    $connessione->setQueryGrammar(new \Illuminate\Database\Query\Grammars\MySqlGrammar($connessione));
    try {
        $scritte = $connessione->pretend(fn () => app(\App\Services\PDF\CarteDelloStudio::class)
            ->salva($nuova, ['nome' => 'Carta nuova', 'mostra_logo' => true, 'colore' => null, 'predefinita' => true]));
    } finally {
        $connessione->setQueryGrammar($grammatica);
    }
    $lettura = collect($scritte)->first(fn (array $q) => str_starts_with($q['query'], 'select') && str_contains($q['query'], '`predefinita`'));

    expect($lettura['query'] ?? '')->toEndWith('for update');
});

it('il nome della carta è unico senza badare alle maiuscole e agli spazi; gli spazi in mezzo si salvano come uno', function () {
    foreach (['CARTA DELLO STUDIO', 'carta  dello   studio', "Carta\u{00A0}dello studio"] as $nome) {
        $this->actingAs($this->user)->post(route('impostazioni.stampe.carte.store'), ['nome' => $nome, 'mostra_logo' => true, 'colore' => null, 'predefinita' => false])
            ->assertSessionHasErrors(['nome' => 'C\'è già una carta con questo nome.']);
    }
    $this->actingAs($this->user)->post(route('impostazioni.stampe.carte.store'), ['nome' => '  Carta    rossa ', 'mostra_logo' => true, 'colore' => null, 'predefinita' => false])
        ->assertSessionHasNoErrors();
    $rossa = CartaStampa::where('nome', 'Carta rossa')->sole();
    // La stessa carta può cambiare le maiuscole del suo nome.
    $this->actingAs($this->user)->put(route('impostazioni.stampe.carte.update', $rossa->id), ['nome' => 'carta ROSSA', 'mostra_logo' => true, 'colore' => null, 'predefinita' => false])
        ->assertSessionHasNoErrors();

    expect(CartaStampa::count())->toBe(2)->and($rossa->fresh()->nome)->toBe('carta ROSSA');
});
