<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission as Permesso;
use App\Services\Assemblee\MemoriaDelCondominio;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;

require_once __DIR__ . '/../Motore/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, §3 P1, P2, P3, P5, P6, P7, P9, P12 del progetto della tappa 2): le pagine delle assemblee con le loro prop. L'archivio vuoto e
 * pieno con le tre card e la riga della raccolta; «Prepara l'assemblea» con le proposte e il memo arrivato dal compito; la pagina dell'assemblea con
 * l'intestazione, l'ordine del giorno con i badge della sola scala B, i passi della strada al posto delle tre card, le proposte differite e il «Diario»
 * chiesto quando si apre; la riunione registrata a mano con «Verbale da caricare»; la raccolta con la data sospetta; la riga «Entra nella riunione del …» della
 * delibera a mano, e il file del verbale che senza il permesso è 403; la card della dashboard differita; l'interruttore della tabella di proprietà.
 *
 * Che cosa non copre: come la pagina dispone le prop a video (i test vitest dei componenti), il telefono e il tema scuro (la prova a video).
 */

beforeEach(fn () => $this->withoutVite());

it('l\'archivio vuoto, poi pieno: le tre card, le righe per anno, la riga della raccolta; i filtri tornano con le sole righe', function () {
    $s = ViaRoma12::nuovo($this);
    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.index', $s->c))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('gestionale/assemblee/Archivio')->has('assemblee.data', 0)
            ->where('card.prossima.valore', 'Nessuna')->where('raccolta', null)->where('puo.gestire', true));

    ViaRoma12::ordinaria($s);
    ViaRoma12::delibera($s, '2024-03-12', 'Sostituzione dell\'ascensore', 'approvata', '3/2024');
    $s->gestione('Facciata 2026', 'straordinaria');
    \App\Models\Gestionale\PianoRate::create(['gestione_id' => $s->gestioni['Facciata 2026']['g']->id, 'condominio_id' => $s->c->id,
        'esercizio_id' => $s->e->id, 'nome' => 'Facciata 2026 — rate', 'stato' => 'bozza', 'tipo' => 'straordinario', 'numero_rate' => 6, 'giorno_scadenza' => 5,
        'data_prima_scadenza' => '2027-01-05', 'metodo_distribuzione' => 'prima_rata', 'data_delibera_assemblea' => '2026-10-22', 'numero_verbale' => '41']);

    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.index', $s->c))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('assemblee.data', 2)
            ->where('assemblee.data.0.nome', 'Ordinaria del 15/12/2026')->where('assemblee.data.0.stato_parola', 'In preparazione')
            ->where('assemblee.data.1.nome', 'Riunione del 12/03/2024')->where('assemblee.data.1.verbale_da_caricare', true)
            ->where('card.prossima.valore', 'Ordinaria del 15/12/2026')->where('anni', ['2026', '2024'])
            ->where('raccolta.frase', 'In Via Roma 12 c\'è 1 data scritta a mano: raccoglile'));

    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.index', [$s->c, 'anno' => '2024']))
        ->assertInertia(fn (AssertableInertia $p) => $p->has('assemblee.data', 1)->where('filtri.anno', '2024'));
});

it('«Prepara l\'assemblea» con il memo del compito; la pagina: l\'intestazione, il punto della sola scala B, i passi, le proposte, il «Diario»', function () {
    $s = ViaRoma12::nuovo($this)->tabella('Ascensore B', ['B1' => 1000]);
    $scala = \App\Models\Scala::query()->create(['condominio_id' => $s->c->id, 'name' => 'B']);
    $s->unita['B1']->forceFill(['scala_id' => $scala->id])->saveQuietly();
    $memo = MemoriaDelCondominio::salva($s->c, ['testo' => 'Il portone'], $s->admin);

    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.create', [$s->c, 'memo' => $memo->id]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('gestionale/assemblee/Prepara')->where('memo.id', $memo->id)
            ->where('proposte.1.chiave', 'da_te')->where('proposte.1.punti.0.testo', 'Il portone')->where('proposte.1.punti.0.spuntato', true));

    $a = ViaRoma12::ordinaria($s, [['testo' => 'Funi dell\'ascensore della scala B', 'materia' => 'lavori', 'ambito' => 'scala', 'scala_id' => $scala->id,
        'tabella_id' => $s->tabelle['Ascensore B']->id], ['testo' => 'Varie ed eventuali', 'materia' => 'varie']]);
    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.show', [$s->c, $a]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('gestionale/assemblee/Assemblea')
            ->where('assemblea.titolo', 'Assemblea ordinaria di Via Roma 12')
            ->where('assemblea.sottotitolo', 'Prima convocazione 14/12/2026 alle 08:00 · seconda 15/12/2026 alle 18:30 · sala condominiale, Via Roma 12')
            ->where('ordine.0.perimetro_parola', 'Solo la scala B')->where('ordine.0.tabella_nome', 'Ascensore B')
            ->where('ordine.0.tabella_controllo', '1 unità su 1 · 1.000,00 millesimi')->where('ordine.0.maggioranza_parola', 'Metà del valore')
            ->missing('testata')->where('passi.0.nome', 'Date e ordine del giorno')->where('passi.0.riga', '14/12 e 15/12 · 2 punti')
            ->where('passi.2.nome', 'Convocazione')
            ->where('assemblea.apri_la_sala.attiva', false)->missing('diario')->missing('proposte')
            ->loadDeferredProps('proposte', fn (AssertableInertia $r) => $r->has('proposte'))
            ->reloadOnly('diario', fn (AssertableInertia $r) => $r->where('diario.righe.0.chi', fn ($chi) => is_string($chi))));
});

it('la riunione registrata a mano: «Verbale da caricare»; la riga «Entra nella riunione del …»; il file senza il permesso è 403', function () {
    $s = ViaRoma12::nuovo($this);
    $d = ViaRoma12::delibera($s, '2026-10-22', 'Rifacimento della facciata', 'approvata', '41');

    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.show', [$s->c, $d->assemblea]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('assemblea.a_mano', true)->where('assemblea.verbale_da_caricare', true)
            ->where('assemblea.sottotitolo', 'Registrata a mano · ordinaria · verbale n. 41')->where('ordine.0.delibere.0.usata', null));

    $this->actingAs($s->admin)->getJson(route('admin.gestionale.delibere.a-mano.destinazione', [$s->c, 'giorno' => '2026-10-22', 'numero_verbale' => ' 41 ']))
        ->assertOk()->assertJsonPath('frase', 'Entra nella riunione del 22/10/2026, verbale n. 41')->assertJsonPath('riunione.id', $d->assemblea_id);
    $this->actingAs($s->admin)->getJson(route('admin.gestionale.delibere.a-mano.destinazione', [$s->c, 'giorno' => '2026-10-22', 'numero_verbale' => '42']))
        ->assertOk()->assertJsonPath('frase', 'Nasce una riunione nuova del 22/10/2026');

    $marco = ViaRoma12::utente([Permesso::VIEW_ASSEMBLEE, Permesso::MANAGE_ASSEMBLEE]);
    $marco->givePermissionTo('Accesso pannello amministratore');
    \Illuminate\Support\Facades\Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    $this->actingAs($marco)->post(route('admin.gestionale.delibere.a-mano', $s->c), ['giorno' => '2026-10-20', 'testo' => 'Il cancello', 'esito' => 'approvata',
        'verbale' => UploadedFile::fake()->create('verbale.pdf', 50, 'application/pdf')], ['Accept' => 'application/json'])->assertForbidden();
    $this->actingAs($marco)->postJson(route('admin.gestionale.delibere.a-mano', $s->c), ['giorno' => '2026-10-20', 'testo' => 'Il cancello', 'esito' => 'approvata'])
        ->assertCreated()->assertJsonPath('assemblea.verbale_da_caricare', true);
});

it('la raccolta: l\'anteprima con la data sospetta, senza il modello; la card della dashboard differita; l\'interruttore della tabella', function () {
    $s = ViaRoma12::nuovo($this)->tabella('Proprietà', ['A' => 1000]);
    $s->gestione('Facciata 2024', 'straordinaria');
    \App\Models\Gestionale\PianoRate::create(['gestione_id' => $s->gestioni['Facciata 2024']['g']->id, 'condominio_id' => $s->c->id,
        'esercizio_id' => $s->e->id, 'nome' => 'Facciata 2024 — rate', 'stato' => 'approvato', 'tipo' => 'straordinario', 'numero_rate' => 6, 'giorno_scadenza' => 5,
        'data_prima_scadenza' => '2027-01-05', 'metodo_distribuzione' => 'prima_rata', 'data_delibera_assemblea' => '2024-03-12', 'approvato_il' => '2024-03-12 10:00:00']);

    $this->actingAs($s->admin)->get(route('admin.gestionale.delibere.raccolta', $s->c))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('gestionale/assemblee/Raccolta')->has('anteprima', 1)
            ->where('anteprima.0.schede.0.sospetta', 'La data è il giorno in cui il piano è stato approvato nel programma: controllala sul verbale')
            ->where('anteprima.0.schede.0.spuntata', false)->missing('anteprima.0.schede.0.modello'));

    MemoriaDelCondominio::salva($s->c, ['testo' => 'Il portone'], $s->admin);
    $this->actingAs($s->admin)->get(route('admin.gestionale.index', $s->c))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->missing('prossimaAssemblea')
            ->loadDeferredProps('assemblee', fn (AssertableInertia $r) => $r->where('prossimaAssemblea.totale', fn ($n) => $n >= 1)));

    // La scheda della tabella si apre con «Modifica condomini» (Coda 714).
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => Permesso::EDIT_CONDOMINI->value, 'guard_name' => 'web']);
    $s->admin->givePermissionTo(Permesso::EDIT_CONDOMINI->value);
    $this->actingAs($s->admin)->get(route('admin.gestionale.tabelle.edit', [$s->c, $s->tabelle['Proprietà']]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('proprieta', ['questa' => false, 'attuale' => null, 'si_sceglie' => true]));
});
