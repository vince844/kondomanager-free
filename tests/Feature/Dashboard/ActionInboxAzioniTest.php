<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'Action inbox, le righe dell'elenco: la prossima azione del tipo (una mappa sola, `TipiDeiCompiti`, che copre ogni tipo dei compiti: il pulsante con la
 * sua pagina, o «Segna come fatto» che apre la risposta «Fatto»), il titolo senza il nome del condominio ripetuto (il titolo salvato non cambia), la riga sotto con la rata, la
 * cifra o la fattura. I permessi restano quelli di oggi: la pagina con «Visualizza scadenze in agenda», le risposte con «Modifica scadenza in agenda». I due
 * compiti rari portano dove si risolvono: l'eccedenza del già versato alla pagina «Già versato» della voce, il deficit ereditato alla creazione di un piano
 * rate straordinario nell'esercizio aperto di oggi (lo scoperto è la parte che nessuna rata chiede), qualunque collegamento abbia salvato il compito.
 */

use App\Enums\EventoTipo;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\User;
use App\Services\Gestionale\Inbox\TipiDeiCompiti;
use Spatie\Permission\Models\Role as RuoloSpatie;

require_once __DIR__.'/InboxCompitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

/** Le righe dell'elenco, pagina dopo pagina, per titolo salvato. */
function aiaRighe($test, array $filtri = [], ?User $chi = null): \Illuminate\Support\Collection
{
    $righe = collect();
    for ($p = 1; $p <= 10; $p++) {
        $dati = $test->actingAs($chi ?? $test->admin)->get(route('admin.inbox', $filtri + ['page' => $p]))->assertOk()->viewData('page')['props']['tasks']['data'];
        $righe = $righe->concat($dati);
        if (count($dati) < 15) {
            break;
        }
    }

    return $righe->keyBy('title');
}

/** I tipi scritti in `meta.type` dai punti del programma che creano i compiti (i file che scrivono `requires_action`), letti dai sorgenti. */
function aiaTipiNeiSorgenti(): array
{
    $tipi = [];
    $file = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
    foreach ($file as $f) {
        if (! $f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }
        $testo = (string) file_get_contents($f->getPathname());
        if (! str_contains($testo, 'requires_action')) {
            continue;
        }
        preg_match_all("/'type'\\s*=>\\s*'([a-z_]+)'/", $testo, $m);
        $tipi = [...$tipi, ...$m[1]];
    }

    return array_values(array_unique($tipi));
}

it('la mappa delle prossime azioni copre ogni tipo: ogni caso di EventoTipo e ogni tipo scritto in meta.type ha il suo pulsante o «Segna come fatto»', function () {
    $tipi = array_values(array_unique([...array_map(fn (EventoTipo $t) => $t->value, EventoTipo::cases()), ...aiaTipiNeiSorgenti(), ...ICS_SI_SPOSTA, ...ICS_A_PARTE]));
    // I sorgenti si leggono davvero: ci sono i tipi dei listener delle fatture e dell'F24.
    expect(aiaTipiNeiSorgenti())->toContain('pagamento_fornitore', 'versamento_ritenuta', 'convocazione_urgenza', 'ratifica_sforo', 'pianifica_ripianamento_deficit');

    $senza = array_values(array_filter($tipi, fn ($t) => ! array_key_exists($t, TipiDeiCompiti::TIPI)));
    expect($senza)->toBe([]);
    foreach (TipiDeiCompiti::TIPI as $tipo => $voce) {
        expect($voce['nome'], $tipo)->toBeString()->not->toBe('')
            ->and($voce['azione'], $tipo)->toBeString()->not->toBe('')
            ->and(is_bool($voce['pagina']), $tipo)->toBeTrue()
            // Senza una pagina sua il pulsante è «Segna come fatto»; con la pagina, mai «Segna come fatto» né un rimando generico.
            ->and($voce['pagina'] ? $voce['azione'] !== TipiDeiCompiti::FATTO : $voce['azione'] === TipiDeiCompiti::FATTO, $tipo)->toBeTrue()
            ->and(preg_match('/^(Vedi|Risolvi|Apri il dettaglio)/u', $voce['azione']), $tipo)->toBe(0);
    }
    // Ogni tipo con la sua prossima azione, scritta qui a mano (decisioni 419, 423, 426, 428): l'atteso non viene dalla mappa.
    expect(collect(TipiDeiCompiti::TIPI)->map(fn ($v) => $v['azione'])->all())->toEqual([
        'verifica_pagamento' => 'Registra l\'incasso', 'controllo_incassi' => 'Registra gli incassi', 'emissione_rata' => 'Emetti la rata',
        'emissione_rata_sopravvenienza' => 'Crea il piano rate', 'pagamento_fornitore' => 'Paga la fattura', 'scoperto_documentato' => 'Assegna le quote',
        'gia_versato_acconto_dichiarato' => 'Registra la fattura', 'pianifica_ripianamento_deficit' => 'Prepara il piano di ripianamento',
        'segnalazione_guasto' => 'Apri la segnalazione', 'commento' => 'Rispondi al commento', 'allegato' => 'Approva gli allegati',
        'versamento_ritenuta' => 'Prepara l\'F24', 'f24' => 'Prepara l\'F24', 'scadenza' => 'Segna come fatto',
        'eccedenza_gia_versato_rilevata' => 'Controlla il già versato', 'convocazione_urgenza' => 'Segna come fatto', 'ratifica_sforo' => 'Segna come fatto',
        'agenda' => 'Segna come fatto', 'promemoria' => 'Segna come fatto', 'scadenza_rata_condomino' => 'Segna come fatto',
    ]);
});

it('ogni riga ha la prossima azione del suo tipo: il pulsante porta alla sua pagina; «Segna come fatto» non porta altrove, ed è anche il pulsante di un tipo con la pagina ma senza indirizzo', function () {
    // L'esercizio aperto di oggi, dove il deficit ereditato prepara il suo piano rate.
    $esercizio = \App\Models\Esercizio::factory()->create(['condominio_id' => $this->condominio->id, 'stato' => 'aperto', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    foreach ([...ICS_SI_SPOSTA, ...ICS_A_PARTE] as $tipo) {
        icsCompito($this, $tipo);
    }
    $senzaIndirizzo = icsCompito($this, 'emissione_rata', [], 'Emettere senza indirizzo');
    Evento::whereKey($senzaIndirizzo->id)->update(['meta' => json_encode(array_merge($senzaIndirizzo->meta, ['action_url' => null]))]);
    $righe = aiaRighe($this);

    foreach ([...ICS_SI_SPOSTA, ...ICS_A_PARTE] as $tipo) {
        $riga = $righe['Compito ' . $tipo];
        $voce = TipiDeiCompiti::TIPI[$tipo];
        // Con la pagina: il collegamento salvato, o la pagina del condominio che il tipo dice (le ritenute: «Ritenute e F24»; il deficit: il piano rate
        // straordinario nell'esercizio aperto).
        $atteso = ! $voce['pagina'] ? null : (isset($voce['rotta'])
            ? route($voce['rotta'], ['condominio' => $this->condominio->id] + (($voce['nell_esercizio'] ?? false) ? ['esercizio' => $esercizio->id] : []) + ($voce['parametri'] ?? []), false)
            : '/da/risolvere');
        expect($riga['azione']['testo'], $tipo)->toBe($voce['azione'])
            ->and($riga['azione']['url'], $tipo)->toBe($atteso);
    }
    expect($righe['Emettere senza indirizzo']['azione'])->toBe(['testo' => 'Segna come fatto', 'url' => null]);

    // La stessa mappa nell'Inbox operativa della dashboard del condominio: ogni compito ha la stessa prossima azione, mai «Risolvi».
    $delCondominio = collect();
    for ($p = 1; $p <= 10; $p++) {
        $dati = $this->actingAs($this->admin)->get(route('admin.gestionale.index', [$this->condominio, 'page' => $p]))->assertOk()->viewData('page')['props']['inboxTasks']['data'];
        $delCondominio = $delCondominio->concat($dati);
        if (count($dati) < 15) {
            break;
        }
    }
    $delCondominio = $delCondominio->keyBy('title');
    expect($delCondominio->keys()->sort()->values()->all())->toBe($righe->keys()->sort()->values()->all());
    foreach ($righe as $titolo => $riga) {
        expect($delCondominio[$titolo]['azione'], $titolo)->toBe($riga['azione']);
    }
});

it('il commento di una segnalazione, salvato senza indirizzo, porta alla segnalazione', function () {
    $segnalazione = \App\Models\Segnalazione::factory()->create(['condominio_id' => $this->condominio->id, 'subject' => 'Ascensore fermo', 'stato' => 'aperta']);
    $commento = \App\Models\Commento::factory()->create(['commentable_id' => $segnalazione->id, 'commentable_type' => $segnalazione->getMorphClass(),
        'user_id' => $this->admin->id, 'corpo' => 'Quando viene il tecnico?', 'stato' => 'pubblicato', 'condominio_id' => $this->condominio->id]);
    app(\App\Services\CommentoEventoService::class)->sincronizza($commento);
    // La segnalazione può avere anche il suo compito (se è aperta): qui conta la riga del commento.
    $riga = aiaRighe($this)->firstWhere('type', 'commento');

    expect($riga['type'])->toBe('commento')
        ->and($riga['azione'])->toBe(['testo' => 'Rispondi al commento', 'url' => route('admin.segnalazioni.show', $segnalazione, false)]);
});

it('il titolo senza il nome del condominio ripetuto; il titolo salvato non cambia', function () {
    $casi = [
        'Emettere rata 1 - Condominio Aurora' => 'Emettere rata 1',
        'Verifica incassi - Rata 1 (Condominio Aurora)' => 'Verifica incassi - Rata 1',
        'Pagare Ditta pulizia de Filippo (Condominio Aurora)' => 'Pagare Ditta pulizia de Filippo',
        'F24 Ritenuta — Studio Bianchi (Condominio Aurora)' => 'F24 Ritenuta — Studio Bianchi',
        'Convocare Assemblea - Sforo Urgente (Condominio Aurora)' => 'Convocare Assemblea - Sforo Urgente',
        'Quote non assegnate — Quote straordinarie' => 'Quote non assegnate — Quote straordinarie',
        'Condominio Aurora' => 'Condominio Aurora',
    ];
    foreach (array_keys($casi) as $titolo) {
        icsCompito($this, 'emissione_rata', [], $titolo);
    }
    $righe = aiaRighe($this);

    foreach ($casi as $salvato => $aVideo) {
        expect($righe[$salvato]['titolo'], $salvato)->toBe($aVideo);
    }
    expect(Evento::where('title', 'Emettere rata 1 - Condominio Aurora')->exists())->toBeTrue();
    // Anche con il nome che il compito ha salvato, se il condominio ha cambiato nome dopo.
    $vecchio = icsCompito($this, 'pagamento_fornitore', ['condominio_nome' => 'Residenza Aurora'], 'Pagare Idraulica Rossi (Residenza Aurora)');
    expect(aiaRighe($this)['Pagare Idraulica Rossi (Residenza Aurora)']['titolo'])->toBe('Pagare Idraulica Rossi')
        ->and($vecchio->fresh()->title)->toBe('Pagare Idraulica Rossi (Residenza Aurora)');
});

it('la riga sotto dice la rata e la cifra, la fattura, il bonifico, il piano; senza dati la prima riga della descrizione', function () {
    icsCompito($this, 'emissione_rata', ['numero_rata' => 1, 'totale_rata' => 10001], 'Rata uno');
    icsCompito($this, 'controllo_incassi', ['numero_rata' => 12, 'totale_rata' => 9999], 'Incassi dodici');
    icsCompito($this, 'pagamento_fornitore', ['numero_documento' => 'F-12', 'importo' => 45000], 'Fattura');
    icsCompito($this, 'versamento_ritenuta', ['importo' => 2000], 'Ritenuta');
    icsCompito($this, 'versamento_ritenuta', ['importo' => 4000, 'fornitore' => 'Studio Bianchi'], 'Ritenuta del fornitore');
    icsCompito($this, 'scoperto_documentato', ['context' => ['piano_rate_nome' => 'Quote straordinarie', 'importo_cents' => 30000]], 'Quote');
    icsCompito($this, 'scadenza', [], 'Generico');
    Evento::where('title', 'Generico')->update(['description' => "Il contratto scade il 30 giugno.\nSeconda riga."]);
    [$bonifico] = icsBonifico($this);
    $righe = aiaRighe($this);

    expect($righe['Rata uno']['sotto'])->toBe('Rata 1 · € 100,01')
        ->and($righe['Incassi dodici']['sotto'])->toBe('Rata 12 · € 99,99')
        ->and($righe['Fattura']['sotto'])->toBe('Fattura n. F-12 · € 450,00')
        ->and($righe['Ritenuta']['sotto'])->toBe('Ritenuta d\'acconto · € 20,00')
        ->and($righe['Ritenuta del fornitore']['sotto'])->toBe('Ritenuta di Studio Bianchi · € 40,00')
        ->and($righe['Quote']['sotto'])->toBe('Quote straordinarie · € 300,00')
        ->and($righe['Generico']['sotto'])->toBe('Il contratto scade il 30 giugno.')
        ->and($righe[$bonifico->title]['sotto'])->toBe('Mario Rossi · € 123,45');
});

it('i permessi di oggi: senza «Visualizza scadenze in agenda» la pagina non si apre e non dice niente; senza «Modifica scadenza in agenda» si legge e non si risponde', function () {
    icsCompito($this, 'emissione_rata', [], 'Emettere rata 7');
    RuoloSpatie::findOrCreate('Solo pannello', 'web')->syncPermissions([Permission::ACCESS_ADMIN_PANEL->value]);
    RuoloSpatie::findOrCreate('Solo agenda', 'web')->syncPermissions([Permission::ACCESS_ADMIN_PANEL->value, Permission::VIEW_EVENTS->value]);
    $pannello = User::factory()->create(['email_verified_at' => now()]);
    $pannello->assignRole('Solo pannello');
    $agenda = User::factory()->create(['email_verified_at' => now()]);
    $agenda->assignRole('Solo agenda');

    $vietata = $this->actingAs($pannello->fresh())->get(route('admin.inbox'));
    $vietata->assertForbidden();
    expect($vietata->getContent())->not->toContain('Emettere rata 7')->not->toContain('scaduti');

    $righe = aiaRighe($this, [], $agenda->fresh());
    expect($righe['Emettere rata 7']['azione']['testo'])->toBe('Emetti la rata');
    $compito = Evento::where('title', 'Emettere rata 7')->firstOrFail();
    $this->actingAs($agenda->fresh())->from(route('admin.inbox'))->post(route('admin.inbox.risposta', $compito), ['tipo' => 'fatto'])->assertForbidden();
    expect($compito->fresh()->is_completed)->toBeFalse();
});

it('la colonna dei sei mesi: la pagina porta i condomini da calcolare, in ordine di nome, e nessuna cifra; con un condominio scelto, solo quello', function () {
    $altro = Condominio::factory()->create(['nome' => 'Corso Italia 3']);

    $tutti = $this->actingAs($this->admin)->get(route('admin.inbox'))->viewData('page')['props'];
    expect($tutti['seiMesiCondomini'])->toBe([['id' => $this->condominio->id, 'nome' => 'Condominio Aurora'], ['id' => $altro->id, 'nome' => 'Corso Italia 3']])
        ->and(json_encode($tutti['seiMesiCondomini'], JSON_UNESCAPED_UNICODE))->not->toContain('€');

    $uno = $this->actingAs($this->admin)->get(route('admin.inbox', ['condominio_id' => $altro->id]))->viewData('page')['props'];
    expect($uno['seiMesiCondomini'])->toBe([['id' => $altro->id, 'nome' => 'Corso Italia 3']]);
});

it('la Dashboard social non porta la lista dei sei mesi', function () {
    $props = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->viewData('page')['props'];

    expect($props)->not->toHaveKey('seiMesiCondomini');
});

it('le ritenute da versare sono un tipo solo, anche scritte da due punti del programma: «Ritenute da versare», la prossima azione apre «Ritenute e F24»', function () {
    icsCompito($this, 'versamento_ritenuta', ['importo' => 2000, 'fornitore' => 'Studio Bianchi'], 'F24 Ritenuta — Studio Bianchi');
    icsCompito($this, 'f24', [], 'Versare la ritenuta d\'acconto del mese');
    icsCompito($this, 'emissione_rata', [], 'Emettere rata 2');
    $p = $this->actingAs($this->admin)->get(route('admin.inbox'))->viewData('page')['props'];

    expect(collect($p['grafici']['tipi'])->firstWhere('tipo', 'versamento_ritenuta'))->toMatchArray(['etichetta' => 'Ritenute da versare', 'valore' => 2])
        ->and(collect($p['grafici']['tipi'])->pluck('tipo')->all())->not->toContain('f24');
    $f24 = route('admin.gestionale.f24.index', ['condominio' => $this->condominio->id], false);
    foreach (['versamento_ritenuta', 'f24'] as $tipo) {
        $righe = aiaRighe($this, ['tipo' => $tipo]);
        expect($righe->keys()->sort()->values()->all(), $tipo)->toBe(['F24 Ritenuta — Studio Bianchi', 'Versare la ritenuta d\'acconto del mese'])
            ->and($righe->pluck('azione')->unique()->values()->all(), $tipo)->toBe([['testo' => 'Prepara l\'F24', 'url' => $f24]]);
    }
});

it('l\'eccedenza del già versato porta al già versato della sua voce («Controlla il già versato»); con più voci, o senza il dettaglio, all\'elenco del già versato', function () {
    $eccedenze = fn (array $conti) => ['context' => ['piano_rate_id' => 9, 'piano_rate_nome' => 'Preventivo 2026', 'totale_cents' => 5000,
        'eccedenze' => array_map(fn ($conto) => ['immobile_id' => 1, 'anagrafica_id' => 2, 'conto_id' => $conto, 'versato' => 8000, 'dovuto' => 5000, 'eccedenza' => 3000], $conti)]];
    icsCompito($this, 'eccedenza_gia_versato_rilevata', $eccedenze([41, 41]), 'Eccedenza su una voce');
    icsCompito($this, 'eccedenza_gia_versato_rilevata', $eccedenze([41, 42]), 'Eccedenza su due voci');
    icsCompito($this, 'eccedenza_gia_versato_rilevata', [], 'Eccedenza senza dettaglio');
    $righe = aiaRighe($this);

    $voce = route('admin.gestionale.contributi.edit', ['condominio' => $this->condominio->id, 'conto' => 41], false);
    $elenco = route('admin.gestionale.contributi.index', ['condominio' => $this->condominio->id], false);
    expect($righe['Eccedenza su una voce']['azione'])->toBe(['testo' => 'Controlla il già versato', 'url' => $voce])
        ->and($righe['Eccedenza su due voci']['azione'])->toBe(['testo' => 'Controlla il già versato', 'url' => $elenco])
        ->and($righe['Eccedenza senza dettaglio']['azione'])->toBe(['testo' => 'Controlla il già versato', 'url' => $elenco])
        // Il collegamento salvato nel compito (la pagina del piano, che l'eccedenza non la mostra) non conta più.
        ->and($righe->pluck('azione.url')->all())->not->toContain('/da/risolvere');
});

it('il deficit ereditato prepara il piano di ripianamento: la creazione di un piano rate straordinario nell\'esercizio aperto di oggi, qualunque collegamento abbia salvato il compito; senza un esercizio aperto «Segna come fatto»', function () {
    $deficit = icsCompito($this, 'pianifica_ripianamento_deficit', ['importo_buco' => 50000], 'Deficit Cassa Ereditato: Idraulica Rossi');
    // Il collegamento salvato dai compiti di prima: le rate scadute (dalla 1.12.0-beta.4) o l'elenco delle fatture.
    Evento::whereKey($deficit->id)->update(['meta' => json_encode(array_merge($deficit->meta, [
        'action_url' => route('admin.gestionale.rate-scadute.index', ['condominio' => $this->condominio->id], false)]))]);
    expect(aiaRighe($this)['Deficit Cassa Ereditato: Idraulica Rossi']['azione'])->toBe(['testo' => 'Segna come fatto', 'url' => null]);

    // Un esercizio chiuso e quello aperto di oggi: il piano si prepara in quello aperto.
    \App\Models\Esercizio::factory()->create(['condominio_id' => $this->condominio->id, 'stato' => 'chiuso', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31']);
    $aperto = \App\Models\Esercizio::factory()->create(['condominio_id' => $this->condominio->id, 'stato' => 'aperto', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    $riga = aiaRighe($this)['Deficit Cassa Ereditato: Idraulica Rossi'];

    expect($riga['azione'])->toBe(['testo' => 'Prepara il piano di ripianamento', 'url' => route('admin.gestionale.esercizi.piani-rate.create',
            ['condominio' => $this->condominio->id, 'esercizio' => $aperto->id, 'tipo' => 'straordinario'], false)])
        ->and($riga['azione']['url'])->toContain('tipo=straordinario')
        ->and($riga['azione']['url'])->not->toContain('rate-scadute')
        ->and($riga['sotto'])->toBe('Scoperto di € 500,00');
});
