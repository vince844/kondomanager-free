<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Decisione 539: la ricevuta del pagamento di un erede sul debito ereditato è intestata all'erede, di serie; l'amministratore la può cambiare nel campo
 * «Intestatario della ricevuta» della pagina dell'incasso («Eredi di …» o il defunto). La scrittura resta quella della decisione 529 (la riga sulla rata del
 * defunto con il legame all'erede, la coppia nel Wallet): cambia come la ricevuta la legge. Sul caso di Ettore Fabbri (`InteressiSulDebitoEreditatoScenario.php`):
 * la rata di aprile da € 300,00, il decesso il 17/11/2026, Elena e Marco al 50 %; Elena abita altrove (Via Garibaldi 8, Milano), Ettore in Via Verdi 1.
 *
 * - di serie: in alto a destra Elena con il suo indirizzo, «Ricevuto da: Elena» con il suo codice fiscale, «Per il debito ereditato da: Ettore Fabbri ·
 *   successione del 17/11/2026, quota 50 %» al posto di «Per conto di», e sotto la rata «la parte di Elena del debito ereditato da Ettore Fabbri»; la stessa
 *   persona per l'elenco delle ricevute e l'invio (`PersoneDegliIncassi`);
 * - «Eredi di Ettore Fabbri»: in alto loro, all'indirizzo del defunto; «Ricevuto da: Elena», «Per conto di: Eredi di Ettore Fabbri»;
 * - «il defunto»: come prima, «Ricevuto da: Elena — Per conto di: Ettore Fabbri»;
 * - Bianchi, erede e condòmino, versa la sua rata e il debito ereditato: la sua rata è una riga sua, non «la parte in più» di chi ha versato per un altro;
 * - una ricevuta già emessa non cambia: la copia riporta il contenuto dell'originale;
 * - il dettaglio dell'incasso dice a chi è intestata la ricevuta quando non è l'erede; l'elenco degli incassi dice chi ha pagato davvero, anche dopo lo
 *   storno, e il filtro per nome trova la riga con il nome dell'erede e con quello del defunto.
 *
 * Che cosa NON copre: il PDF impaginato (qui l'HTML della vista; il PDF di prova è nel rapporto); la catena (decisione 530), per la quale la scelta non c'è e
 * la ricevuta va all'erede; il versamento del defunto «per il debito degli eredi» con «Versato da» un erede, che si legge con la stessa regola del dettaglio
 * ma non ha la scelta nella pagina; il testo dell'email (il saluto è quello dell'anagrafica a cui va); MySQL.
 */

use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestionale\Subentro;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use App\Services\Gestionale\Ricevute\PersoneDegliIncassi;
use App\Services\Gestionale\Ricevute\RicevutaIncasso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;

require_once __DIR__ . '/InteressiSulDebitoEreditatoScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Il caso di Ettore con la successione registrata; Elena abita in un'altra città. */
function rdeCaso($test): array
{
    $s = ideCaso($test);
    $s['persone']['Elena']->forceFill(['indirizzo' => 'Via Garibaldi 8, 20100 Milano (MI)'])->save();
    $s['successione'] = ideRegistra($test, $s, ideSuccessione($s));

    return $s;
}

/** L'erede pagante, sul debito ereditato (centesimi) e sulle sue rate (`[id quota => centesimi]`), con la scelta dell'intestatario della ricevuta o senza. */
function rdeVersa($test, array $s, string $chi, int $ereditato, ?string $intestatario = null, array $righe = [], ?Subentro $successione = null)
{
    $test->travelTo('2026-11-25 11:00:00');
    $totale = $ereditato + array_sum($righe);

    return $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), array_filter([
        'pagante_id' => $s['persone'][$chi]->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-11-25', 'importo_totale' => number_format($totale / 100, 2, '.', ''),
        'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id,
        'dettaglio_pagamenti' => array_map(fn ($id, $c) => ['rata_id' => (int) $id, 'importo' => number_format($c / 100, 2, '.', '')], array_keys($righe), array_values($righe)),
        'debito_ereditato' => [['subentro_id' => ($successione ?? $s['successione'])->id, 'importo' => number_format($ereditato / 100, 2, '.', '')]],
        'intestatario_ricevuta' => $intestatario,
    ], fn ($v) => $v !== null));
}

function rdeUltimo(): ScritturaContabile
{
    return ScritturaContabile::where('tipo_movimento', 'incasso_rata')->latest('id')->firstOrFail();
}

/** L'HTML della ricevuta di un contenuto, per posta (con l'indirizzo in alto a destra), con gli spazi ridotti a uno. */
function rdeHtml(array $s, array $contenuto, array $voce = []): string
{
    $html = View::make('pdf.gestionale.ricevute_incasso', ['condominio' => $s['c'], 'ricevute' => [$voce + [
        'contenuto' => $contenuto, 'bollo' => 'non_dovuta', 'nota' => null, 'emessa_il' => '25/11/2026', 'indirizzo_per_posta' => true]]])->render();

    return (string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br>', '</td>', '</div>'], [' | ', ' | ', ' | '], $html)), ENT_QUOTES));
}

/** Il testo della testata (la prima tabella): a sinistra il condominio, a destra a chi è intestata. */
function rdeTestata(array $s, array $contenuto): string
{
    $html = View::make('pdf.gestionale.ricevute_incasso', ['condominio' => $s['c'], 'ricevute' => [[
        'contenuto' => $contenuto, 'bollo' => 'non_dovuta', 'nota' => null, 'emessa_il' => '25/11/2026', 'indirizzo_per_posta' => true]]])->render();
    preg_match('/<td style="padding: 0; vertical-align: top; text-align: right;">(.*?)<\/td>/s', $html, $m);

    return trim((string) preg_replace('/\s+/u', ' ', strip_tags(str_replace('</div>', ' | ', $m[1] ?? ''))));
}

it('decisione 539, di serie: la ricevuta del versamento di Elena sul debito ereditato è intestata a lei, con il debito da cui viene', function () {
    $s = rdeCaso($this);
    rdeVersa($this, $s, 'Elena', 15000)->assertSessionHasNoErrors();
    $incasso = rdeUltimo();
    $elena = $s['persone']['Elena'];

    $r = app(RicevutaIncasso::class)->per($incasso->fresh());
    // Con il codice di prima: la posizione Ettore Fabbri, «versato da» Elena, e nessuna riga del debito ereditato.
    expect($r['posizione'])->toBe(['id' => $elena->id, 'nome' => 'Elena', 'indirizzo' => 'Via Garibaldi 8, 20100 Milano (MI)', 'codice_fiscale' => $elena->codice_fiscale])
        ->and($r['versato_da'])->toBeNull()
        ->and($r['debito_ereditato'] ?? null)->toBe(['di' => 'Ettore Fabbri', 'successione_del' => '2026-11-17', 'quota' => '50'])
        ->and($r['quote'])->toHaveCount(1)
        ->and($r['quote'][0]['nota'] ?? null)->toBe('la parte di Elena del debito ereditato da Ettore Fabbri')
        // Le cifre non cambiano: € 150,00 imputati sulla rata di € 300,00 di Ettore, ne restano € 150,00 (la parte di Marco).
        ->and([$r['quote'][0]['importo'], $r['quote'][0]['prima'], $r['quote'][0]['imputato'], $r['quote'][0]['dopo']])->toBe([30000, 0, 15000, 15000])
        ->and($r['importo_versato'])->toBe(15000);

    // La stessa persona per l'elenco delle ricevute e per l'invio.
    $persone = app(PersoneDegliIncassi::class)->di([$incasso->id])[$incasso->id];
    expect([$persone['posizione'], $persone['versato_da'], $persone['piu_persone']])->toBe([$elena->id, null, []]);
    expect(collect(app(EmissioneRicevute::class)->destinatari($r))->pluck('anagrafica_id')->all())->toBe([$elena->id]);

    expect(rdeTestata($s, $r))->toBe('Gentile | Elena | Via Garibaldi 8, 20100 Milano (MI) |');
    $testo = rdeHtml($s, $r);
    expect($testo)->toContain('Ricevuto da | Elena | C.F. ' . $elena->codice_fiscale)
        ->toContain('Per il debito | ereditato da | Ettore Fabbri · successione del 17/11/2026, quota 50 %')
        ->toContain('la parte di Elena del debito ereditato da Ettore Fabbri')
        ->not->toContain('Per conto di')
        // Il resto com'è: il numero, l'emissione, il bollo.
        ->toContain('Numero ' . $incasso->numero_protocollo)->toContain('Emessa il 25/11/2026')->toContain('Imposta di bollo | Non dovuta');
});

it('decisione 539: con «Eredi di Ettore Fabbri» la ricevuta è intestata agli eredi, all\'indirizzo del defunto; Elena è chi ha versato', function () {
    $s = rdeCaso($this);
    rdeVersa($this, $s, 'Elena', 15000, 'eredi')->assertSessionHasNoErrors();
    $incasso = rdeUltimo();
    $ettore = $s['persone']['Neri'];

    // La scelta resta con l'incasso, nel registro del passaggio, con il versamento.
    $voce = collect($s['successione']->fresh()->registro['versamenti_degli_eredi'])->firstWhere('scrittura_id', $incasso->id);
    expect($voce['intestatario_ricevuta'] ?? null)->toBe('eredi');

    $r = app(RicevutaIncasso::class)->per($incasso->fresh());
    expect($r['posizione'])->toBe(['id' => $ettore->id, 'nome' => 'Eredi di Ettore Fabbri', 'indirizzo' => 'Via Verdi 1', 'codice_fiscale' => $ettore->codice_fiscale])
        ->and($r['versato_da'])->toBe(['id' => $s['persone']['Elena']->id, 'nome' => 'Elena'])
        ->and($r['debito_ereditato'] ?? null)->toBeNull()
        ->and($r['quote'][0]['nota'] ?? null)->toBeNull();

    expect(rdeTestata($s, $r))->toBe('Gentile | Eredi di Ettore Fabbri | Via Verdi 1 |');
    expect(rdeHtml($s, $r))->toContain('Ricevuto da | Elena | Per conto di | Eredi di Ettore Fabbri | C.F. ' . $ettore->codice_fiscale)
        ->not->toContain('Per il debito |');
});

it('decisione 539: con «il defunto» la ricevuta resta come prima, Ettore Fabbri con «Ricevuto da» Elena', function () {
    $s = rdeCaso($this);
    rdeVersa($this, $s, 'Elena', 15000, 'defunto')->assertSessionHasNoErrors();
    $incasso = rdeUltimo();

    $r = app(RicevutaIncasso::class)->per($incasso->fresh());
    expect($r['posizione']['nome'])->toBe('Ettore Fabbri')
        ->and($r['posizione']['indirizzo'])->toBe('Via Verdi 1')
        ->and($r['versato_da']['nome'] ?? null)->toBe('Elena')
        ->and($r['debito_ereditato'] ?? null)->toBeNull();
    expect(rdeTestata($s, $r))->toBe('Gentile | Ettore Fabbri | Via Verdi 1 |');
    expect(rdeHtml($s, $r))->toContain('Ricevuto da | Elena | Per conto di | Ettore Fabbri');
});

it('decisione 539: un intestatario che non è una delle tre scelte si rifiuta', function () {
    $s = rdeCaso($this);
    rdeVersa($this, $s, 'Elena', 15000, 'marco')->assertSessionHasErrors('intestatario_ricevuta');
    expect(ScritturaContabile::where('tipo_movimento', 'incasso_rata')->count())->toBe(0);
});

it('decisione 539: Bianchi, erede e condòmino, versa la sua rata e il debito ereditato: la sua rata è una riga sua, quella di Ettore porta la sua parte', function () {
    $s = ideCaso($this);
    $dati = ideSuccessione($s);
    $dati['eredi'] = [['anagrafica_id' => $s['persone']['Bianchi']->id, 'quota' => 50], ['anagrafica_id' => $s['persone']['Marco']->id, 'quota' => 50]];
    $dati['erede_di_riferimento'] = $s['persone']['Marco']->id;
    $s['successione'] = ideRegistra($this, $s, $dati);
    $quota = RataQuote::where('rata_id', lcdRata($s, 1))->where('anagrafica_id', $s['persone']['Bianchi']->id)->firstOrFail();
    $aperto = (int) $quota->importo - (int) $quota->importo_pagato;
    rdeVersa($this, $s, 'Bianchi', 15000, null, [$quota->id => $aperto])->assertSessionHasNoErrors();

    $r = app(RicevutaIncasso::class)->per(rdeUltimo()->fresh());
    // Con il codice di prima la rata di Bianchi era «la parte in più» di chi aveva versato per Ettore.
    expect($aperto)->toBeGreaterThan(0)
        ->and($r['posizione']['nome'])->toBe('Bianchi')
        ->and($r['versato_da'])->toBeNull()
        ->and($r['eccedenza'])->toBe(0)
        ->and(collect($r['quote'])->map(fn ($q) => [$q['quota_id'], $q['imputato'], $q['nota'] ?? null])->sortBy(0)->values()->all())
        ->toBe(collect([[$quota->id, $aperto, null], [RataQuote::where('rata_id', lcdRata($s, 1))->where('anagrafica_id', $s['persone']['Neri']->id)->value('id'), 15000,
            'la parte di Bianchi del debito ereditato da Ettore Fabbri']])->sortBy(0)->values()->all());
});

it('decisione 539: una ricevuta già emessa non cambia; la copia riporta il contenuto dell\'originale, anche se emesso con la regola di prima', function () {
    $s = rdeCaso($this);
    rdeVersa($this, $s, 'Elena', 10000)->assertSessionHasNoErrors();
    $incasso = rdeUltimo();
    $oggi = app(RicevutaIncasso::class)->per($incasso->fresh());

    // L'originale emesso con la regola di prima: intestato a Ettore, «versato da» Elena, senza la riga del debito ereditato.
    $ettore = $s['persone']['Neri'];
    $prima = array_merge($oggi, [
        'posizione' => ['id' => $ettore->id, 'nome' => 'Ettore Fabbri', 'indirizzo' => 'Via Verdi 1', 'codice_fiscale' => $ettore->codice_fiscale],
        'versato_da' => ['id' => $s['persone']['Elena']->id, 'nome' => 'Elena'],
        'quote' => array_map(fn (array $q) => array_diff_key($q, ['nota' => true]), $oggi['quote']),
    ]);
    unset($prima['debito_ereditato']);
    RegistroInvio::create(['condominio_id' => $s['c']->id, 'anagrafica_id' => $ettore->id, 'tipo' => RegistroInvio::TIPO_RICEVUTA, 'canale' => RegistroInvio::CANALE_STAMPA,
        'documentabile_type' => ScritturaContabile::class, 'documentabile_id' => $incasso->id, 'numero' => $incasso->numero_protocollo,
        'dati' => ['bollo' => 'non_dovuta', 'contenuto' => $prima], 'stato' => RegistroInvio::STATO_STAMPATA, 'emesso_il' => now()]);

    $emissione = app(EmissioneRicevute::class);
    $copia = $emissione->contenutoDaEmettere($incasso->fresh(), $emissione->originali([$incasso->id])[$incasso->id]);
    expect($copia['posizione']['nome'])->toBe('Ettore Fabbri')
        ->and($copia['versato_da']['nome'])->toBe('Elena')
        ->and(array_key_exists('debito_ereditato', $copia))->toBeFalse();
    expect(rdeHtml($s, $copia, ['copia_del' => '20/11/2026']))->toContain('COPIA')->toContain('Ricevuto da | Elena | Per conto di | Ettore Fabbri')
        ->not->toContain('Per il debito |');

    $stampa = fn (ScritturaContabile $i) => $this->actingAs($this->user)
        ->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s['c']->id]), ['incassi' => [$i->id], 'bollo' => 'non_dovuta'])->assertOk();
    $stampa($incasso);
    $ultima = RegistroInvio::where('documentabile_id', $incasso->id)->latest('id')->firstOrFail();
    expect($ultima->dati['copia_del'] ?? null)->not->toBeNull()
        ->and($ultima->dati['contenuto']['posizione']['nome'])->toBe('Ettore Fabbri');

    // Un originale emesso con la regola nuova, intestato a Elena: la copia lo riporta com'è, anche se dopo l'anagrafica cambia nome.
    rdeVersa($this, $s, 'Elena', 5000)->assertSessionHasNoErrors();
    $secondo = rdeUltimo();
    $stampa($secondo);
    $originale = RegistroInvio::where('documentabile_id', $secondo->id)->latest('id')->firstOrFail();
    expect($originale->dati['copia_del'] ?? null)->toBeNull()
        ->and($originale->dati['contenuto']['posizione']['nome'])->toBe('Elena')
        ->and($originale->dati['contenuto']['debito_ereditato']['di'] ?? null)->toBe('Ettore Fabbri');
    $s['persone']['Elena']->forceFill(['nome' => 'Elena Rossi'])->save();
    $stampa($secondo);
    $copiaNuova = RegistroInvio::where('documentabile_id', $secondo->id)->latest('id')->firstOrFail();
    expect($copiaNuova->dati['copia_del'] ?? null)->not->toBeNull()
        ->and($copiaNuova->dati['contenuto'])->toBe($originale->dati['contenuto']);
});

it('decisione 539: il dettaglio dell\'incasso dice a chi è intestata la ricevuta, solo quando non è l\'erede', function () {
    $s = rdeCaso($this);
    $dettaglio = fn () => $this->actingAs($this->user)->get(route('admin.gestionale.movimenti-rate.show', [$s['c'], rdeUltimo()->id]))->assertOk()->viewData('page')['props'];

    rdeVersa($this, $s, 'Elena', 5000)->assertSessionHasNoErrors();
    $props = $dettaglio();
    expect(array_key_exists('ricevuta_intestata_a', $props))->toBeTrue()->and($props['ricevuta_intestata_a'])->toBeNull();

    rdeVersa($this, $s, 'Elena', 5000, 'eredi')->assertSessionHasNoErrors();
    expect($dettaglio()['ricevuta_intestata_a'] ?? null)->toBe('Eredi di Ettore Fabbri');

    rdeVersa($this, $s, 'Elena', 5000, 'defunto')->assertSessionHasNoErrors();
    expect($dettaglio()['ricevuta_intestata_a'] ?? null)->toBe('Ettore Fabbri (il defunto)');
});

it('decisione 539 (5): l\'elenco degli incassi dice chi ha pagato davvero, anche dopo lo storno; il filtro trova la riga con il nome dell\'erede e del defunto', function () {
    $s = rdeCaso($this);
    rdeVersa($this, $s, 'Elena', 15000)->assertSessionHasNoErrors();
    $incasso = rdeUltimo();
    $riga = function (array $filtri = []) use ($s, $incasso) {
        $dati = $this->actingAs($this->user)->get(route('admin.gestionale.movimenti-rate.index', [$s['c']] + $filtri))->assertOk()->viewData('page')['props']['movimenti']['data'];

        return collect($dati)->firstWhere('id', $incasso->id);
    };

    $atteso = ['nome' => 'Elena', 'sotto' => 'erede, sul debito di Ettore Fabbri', 'versato_da' => null, 'anagrafica_id' => $s['persone']['Elena']->id];
    // Con il codice di prima la riga diceva la posizione, Ettore Fabbri, «Versato da» Elena, e non c'era `ha_pagato`.
    expect($riga()['ha_pagato'] ?? null)->toBe($atteso)
        // Controllo: la posizione del giornale non cambia.
        ->and($riga()['pagante']['lista_completa'])->toBe('Ettore Fabbri');
    expect($riga(['search' => 'Elena']))->not->toBeNull()
        ->and($riga(['search' => 'Ettore']))->not->toBeNull()
        ->and($riga(['search' => 'Verdi']))->toBeNull();

    $this->travelTo('2026-11-28 10:00:00');
    app(\App\Actions\Gestionale\Movimenti\StornoIncassoRateAction::class)->execute($incasso->fresh(), $s['c'], \App\Enums\NaturaStorno::VERSAMENTO_TORNATO);
    expect($riga()['ha_pagato'] ?? null)->toBe($atteso);
});

it('decisione 539 (5), controllo: un incasso senza versamento di un erede non ha `ha_pagato` nell\'elenco, e si legge come prima', function () {
    $s = rdeCaso($this);
    $this->travelTo('2026-11-25 11:00:00');
    $quota = RataQuote::where('rata_id', lcdRata($s, 1))->where('anagrafica_id', $s['persone']['Bianchi']->id)->firstOrFail();
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['Bianchi']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-11-25', 'importo_totale' => '10.00',
        'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id, 'dettaglio_pagamenti' => [['rata_id' => $quota->id, 'importo' => '10.00']],
    ])->assertSessionHasNoErrors();
    $dati = $this->actingAs($this->user)->get(route('admin.gestionale.movimenti-rate.index', [$s['c']]))->assertOk()->viewData('page')['props']['movimenti']['data'];

    expect(array_key_exists('ha_pagato', $dati[0]))->toBeTrue()->and($dati[0]['ha_pagato'])->toBeNull()
        ->and($dati[0]['pagante']['principale'])->toBe('Bianchi');
});
