<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\MemoDellAssemblea;
use App\Models\RegistroModifica;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/../Motore/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, §6.3 e §8 del progetto della tappa 2): ogni gesto delle pagine delle assemblee **dalla rotta**, con la sua riga del registro
 * delle modifiche e le sue parole. Preparare, cambiare una data, aggiungere, riscrivere, riordinare e togliere un punto, tenerla fuori dal programma,
 * eliminarla; la delibera a mano (anche straordinaria), la data corretta, lo spostamento e l'eliminazione; il verbale caricato e tolto, poi la riunione
 * eliminata; il memo e «Non serve più»; la tabella di proprietà; la raccolta delle date.
 *
 * Che cosa non copre: le pagine a video; le righe dei collegamenti delle dodici pagine (hanno i loro test); i gesti della convocazione.
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->withoutVite();
});

/** Le righe dell'area delle assemblee scritte dopo `$prima` (le pagine del gestionale creano da sole i conti di serie: quelle non contano). */
function aaRighe(int $prima, ?string $tipo = null)
{
    return RegistroModifica::query()->where('id', '>', $prima)->when($tipo !== null, fn ($q) => $q->where('oggetto_tipo', $tipo))
        ->whereIn('oggetto_tipo', ['assemblea', 'seduta', 'punto', 'delibera', 'memo_assemblea', 'condominio', 'documento'])->orderBy('id')->get();
}

function aaSedute(string $prima = '2026-12-14', string $seconda = '2026-12-15'): array
{
    return [['convocazione' => 'prima', 'numero' => 1, 'giorno' => $prima, 'ora' => '08:00'], ['convocazione' => 'seconda', 'numero' => 1, 'giorno' => $seconda, 'ora' => '18:30']];
}

it('«Prepara l\'assemblea» dalla rotta: una riga sola con i punti spuntati e le date; poi la seconda convocazione cambiata è la riga della seduta', function () {
    $s = ViaRoma12::nuovo($this);
    $prima = ViaRoma12::ultimaRiga();

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.store', $s->c), [
        'tipo' => 'ordinaria', 'ambito' => 'condominio', 'luogo' => 'Sala condominiale, Via Roma 12', 'sedute' => aaSedute(), 'proposte' => ['varie'],
    ])->assertSessionHasNoErrors()->assertRedirect();
    $a = Assemblea::query()->where('condominio_id', $s->c->id)->firstOrFail();
    $righe = aaRighe($prima, 'assemblea');
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->riepilogo)->toBe('Assemblea ordinaria del 15/12/2026 preparata: 1 punto, prima convocazione 14/12/2026 alle 08:00, seconda 15/12/2026 alle 18:30')
        ->and($a->punti()->pluck('testo')->all())->toBe(['Varie ed eventuali']);

    $prima = ViaRoma12::ultimaRiga();
    $this->actingAs($s->admin)->patch(route('admin.gestionale.assemblee.sedute', [$s->c, $a]), ['sedute' => aaSedute('2026-12-14', '2026-12-16')])
        ->assertSessionHasNoErrors();
    $righe = aaRighe($prima, 'seduta');
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->campi[0])->toMatchArray(['etichetta' => 'Il giorno e l\'ora', 'prima' => '15/12/2026 alle 18:30', 'dopo' => '16/12/2026 alle 18:30']);

    $this->actingAs($s->admin)->patch(route('admin.gestionale.assemblee.sedute', [$s->c, $a]), ['sedute' => aaSedute('2026-12-14', '2026-12-14')])
        ->assertSessionHasErrors(['sedute' => 'La seconda convocazione non può tenersi nello stesso giorno della prima: scegli un giorno dopo.']);
});

it('i punti dalla rotta: aggiunto (della sola scala B, con la sua tabella), riscritto, riordinato, tolto; «Varie» resta in fondo', function () {
    $s = ViaRoma12::nuovo($this)->tabella('Ascensore B', ['B1' => 500, 'B2' => 500]);
    $s->unitaSeManca('A1');
    $scala = \App\Models\Scala::query()->create(['condominio_id' => $s->c->id, 'name' => 'B']);
    foreach (['B1', 'B2'] as $u) {
        $s->unita[$u]->forceFill(['scala_id' => $scala->id])->saveQuietly();
    }
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Rendiconto 2025', 'materia' => 'rendiconto'], ['testo' => 'Varie ed eventuali', 'materia' => 'varie']]);

    $prima = ViaRoma12::ultimaRiga();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.store', [$s->c, $a]), [
        'testo' => 'Sostituzione delle funi dell\'ascensore della scala B: impresa e spesa di € 8.200,00', 'materia' => 'lavori', 'maggioranza' => 'meta_del_valore',
        'ambito' => 'scala', 'scala_id' => $scala->id, 'tabella_id' => $s->tabelle['Ascensore B']->id, 'importo' => '8.200,00',
    ])->assertSessionHasNoErrors();
    $punto = $a->punti()->where('materia', 'lavori')->firstOrFail();
    // La riga del punto nuovo; «Varie ed eventuali», che scende di uno per restare in fondo, ha la sua («Numero: 2 → 3»).
    $righe = aaRighe($prima, 'punto');
    expect($righe->where('azione', 'creata'))->toHaveCount(1)
        ->and($righe->where('azione', 'modificata')->flatMap(fn ($r) => $r->campi)->pluck('etichetta')->all())->toBe(['Numero'])
        ->and($punto->numero)->toBe(2)->and($punto->scala_id)->toBe($scala->id)->and($punto->importo)->toBe(820000)
        ->and($a->punti()->pluck('materia')->map->value->all())->toBe(['rendiconto', 'lavori', 'varie']);

    $prima = ViaRoma12::ultimaRiga();
    $this->actingAs($s->admin)->patch(route('admin.gestionale.assemblee.punti.update', [$s->c, $a, $punto]), ['testo' => 'Funi dell\'ascensore della scala B'])
        ->assertSessionHasNoErrors();
    expect(aaRighe($prima, 'punto'))->toHaveCount(1)->and(aaRighe($prima, 'punto')[0]->azione)->toBe('modificata');

    $prima = ViaRoma12::ultimaRiga();
    $ids = $a->punti()->pluck('id')->all();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.riordina', [$s->c, $a]), ['ordine' => [$ids[1], $ids[0], $ids[2]]])
        ->assertSessionHasNoErrors();
    expect(aaRighe($prima)->pluck('riepilogo')->all())->toBe(['Ordine del giorno riordinato: il punto 2 diventa il 1; il punto 1 diventa il 2']);

    $prima = ViaRoma12::ultimaRiga();
    $this->actingAs($s->admin)->delete(route('admin.gestionale.assemblee.punti.destroy', [$s->c, $a, $punto]))->assertSessionHasNoErrors();
    $righe = aaRighe($prima, 'punto');
    expect($righe)->toHaveCount(1)->and($righe[0]->azione)->toBe('eliminata')->and($a->punti()->pluck('numero')->all())->toBe([1, 2]);
});

it('«Tenuta su carta» dalla rotta, poi «Registra la delibera» sul punto non trattato; eliminare in preparazione è una riga', function () {
    $s = ViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Rendiconto 2025', 'materia' => 'rendiconto'], ['testo' => 'Il portone', 'materia' => 'lavori']]);
    [$p1, $p2] = $a->punti()->get()->all();
    $prima = ViaRoma12::ultimaRiga();

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.tenuta-fuori', [$s->c, $a]), [
        'giorno' => '2026-11-15', 'esiti' => [$p1->id => ['esito' => 'approvata', 'testo' => null], $p2->id => ['esito' => null]],
    ])->assertSessionHasNoErrors()->assertSessionHas('message.message', 'Assemblea tenuta su carta: 1 delibera registrata. Le altre le registri dopo, sul loro punto.');
    $righe = aaRighe($prima, 'assemblea');
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->riepilogo)->toBe('Assemblea ordinaria del 15/12/2026 tenuta su carta: 1 delibera registrata a mano, 1 punto non trattato')
        ->and($a->fresh()->stato->value)->toBe('registrata_a_mano');
    // A video le parole di «Tenuta su carta»: lo stato e il sottotitolo della pagina.
    $pagina = \App\Http\Controllers\Assemblee\PerLePagine::assemblea($a->fresh());
    expect($pagina['stato_parola'])->toBe('Tenuta su carta')
        ->and(\App\Http\Controllers\Assemblee\PerLePagine::sottotitolo($a->fresh()))->toBe('Tenuta su carta il 15/11/2026');

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.delibera', [$s->c, $a, $p2]), ['esito' => 'respinta'])->assertSessionHasNoErrors();
    expect($p2->delibere()->first()->esito->value)->toBe('respinta')->and($p2->delibere()->first()->deliberata_il->format('Y-m-d'))->toBe('2026-11-15');

    $b = ViaRoma12::ordinaria($s);
    $prima = ViaRoma12::ultimaRiga();
    $this->actingAs($s->admin)->delete(route('admin.gestionale.assemblee.destroy', [$s->c, $b]))->assertRedirect(route('admin.gestionale.assemblee.index', $s->c));
    expect(aaRighe($prima, 'assemblea')->pluck('riepilogo')->all())->toBe(['Assemblea ordinaria del 15/12/2026 eliminata, in preparazione: 0 punti']);
});

it('la delibera a mano dalla rotta, straordinaria; la data corretta; lo spostamento; l\'eliminazione: una riga ciascuno', function () {
    $s = ViaRoma12::nuovo($this);
    $prima = ViaRoma12::ultimaRiga();

    $r = $this->actingAs($s->admin)->postJson(route('admin.gestionale.delibere.a-mano', $s->c), [
        'giorno' => '2024-03-12', 'tipo' => 'straordinaria', 'testo' => 'Sostituzione dell\'ascensore', 'esito' => 'approvata', 'numero_verbale' => '3/2024',
    ])->assertCreated();
    $d = Delibera::query()->findOrFail($r->json('delibera.id'));
    expect(aaRighe($prima)->pluck('riepilogo')->all())->toBe(['Delibera registrata a mano: riunione del 12/03/2024, «Sostituzione dell\'ascensore», approvata, verbale n. 3/2024'])
        ->and($d->assemblea->tipo->value)->toBe('straordinaria')
        ->and($r->json('assemblea.verbale_da_caricare'))->toBeTrue()
        ->and($r->json('delibera.si_sceglie'))->toBeTrue();

    $prima = ViaRoma12::ultimaRiga();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.correggi-data', [$s->c, $d->assemblea]), ['giorno' => '2024-03-11'])->assertSessionHasNoErrors();
    expect(aaRighe($prima, 'assemblea')[0]->campi[0])->toMatchArray(['prima' => '12/03/2024', 'dopo' => '11/03/2024'])
        ->and($d->fresh()->deliberata_il->format('Y-m-d'))->toBe('2024-03-11');

    $prima = ViaRoma12::ultimaRiga();
    $this->actingAs($s->admin)->post(route('admin.gestionale.delibere.sposta', [$s->c, $d]), ['giorno' => '2024-03-10', 'numero_verbale' => '2/2024'])
        ->assertSessionHasNoErrors();
    expect(aaRighe($prima)->pluck('riepilogo')->implode(' | '))->toContain('Delibera spostata nella riunione del 10/03/2024, verbale n. 2/2024');

    $prima = ViaRoma12::ultimaRiga();
    $this->actingAs($s->admin)->delete(route('admin.gestionale.delibere.destroy', [$s->c, $d->fresh()]))->assertSessionHasNoErrors();
    expect(aaRighe($prima)->pluck('riepilogo')->implode(' | '))->toContain('Delibera registrata a mano eliminata: riunione del 10/03/2024')
        ->and(Delibera::query()->whereKey($d->id)->exists())->toBeFalse();
});

it('il verbale sottoscritto caricato e tolto dalla rotta; poi la riunione senza delibere e senza verbale si elimina', function () {
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    $s = ViaRoma12::nuovo($this);
    $d = ViaRoma12::delibera($s, '2026-10-22', 'Rifacimento della facciata', 'approvata', '41');
    $riunione = $d->assemblea;

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.store', [$s->c, $riunione]), [
        'verbale' => UploadedFile::fake()->create('verbale_41.pdf', 120, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    expect($riunione->fresh()->verbale_documento_id)->not->toBeNull();

    $this->actingAs($s->admin)->delete(route('admin.gestionale.delibere.destroy', [$s->c, $d]))->assertSessionHasNoErrors();
    $this->actingAs($s->admin)->delete(route('admin.gestionale.assemblee.destroy', [$s->c, $riunione]))
        ->assertSessionHasErrors(['riunione' => 'La riunione ha il verbale sottoscritto: non si elimina.']);

    $prima = ViaRoma12::ultimaRiga();
    $this->actingAs($s->admin)->delete(route('admin.gestionale.assemblee.verbale.destroy', [$s->c, $riunione]))->assertSessionHasNoErrors();
    $campi = aaRighe($prima, 'assemblea')->flatMap(fn ($r) => $r->campi ?? [])->pluck('etichetta')->all();
    expect($riunione->fresh()->verbale_documento_id)->toBeNull()->and($campi)->toContain('Il verbale sottoscritto');

    $this->actingAs($s->admin)->delete(route('admin.gestionale.assemblee.destroy', [$s->c, $riunione]))->assertSessionHasNoErrors();
    expect(Assemblea::query()->whereKey($riunione->id)->exists())->toBeFalse();
});

it('il memo dalla finestra: «Creata …, ricordamelo dal …, a te»; «Non serve più» con il motivo; la tabella di proprietà; la raccolta', function () {
    $s = ViaRoma12::nuovo($this)->tabella('Proprietà', ['A' => 600, 'B' => 400]);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $giorno = now()->setTimezone('Europe/Rome')->addDays(6)->format('Y-m-d');
    $prima = ViaRoma12::ultimaRiga();

    $r = $this->actingAs($s->admin)->postJson(route('admin.gestionale.memo.store', $s->c), [
        'testo' => 'Ratifica della spesa urgente di € 1.800,00', 'origine_tipo' => 'fattura_passiva', 'origine_id' => $fattura->id,
        'ricordami' => true, 'ricordami_dal' => $giorno, 'a_chi' => 'me',
    ])->assertCreated();
    $righe = aaRighe($prima, 'memo_assemblea');
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->riepilogo)->toBe('Creata: «Ratifica della spesa urgente di € 1.800,00», ricordamelo dal ' . \Illuminate\Support\Carbon::parse($giorno)->format('d/m/Y') . ', a te')
        ->and($r->json('frase'))->toContain('nell\'Action inbox e nell\'agenda, a te.');

    $memo = MemoDellAssemblea::query()->findOrFail($r->json('memo.id'));
    $prima = ViaRoma12::ultimaRiga();
    $this->actingAs($s->admin)->post(route('admin.gestionale.memo.non-serve', [$s->c, $memo]), ['motivo' => 'Ne abbiamo parlato a voce'])->assertSessionHasNoErrors();
    expect(aaRighe($prima, 'memo_assemblea')[0]->campi[0])->toMatchArray(['prima' => 'In attesa', 'dopo' => 'Non serve più']);

    $prima = ViaRoma12::ultimaRiga();
    $this->actingAs($s->admin)->post(route('admin.gestionale.tabella-di-proprieta', $s->c), ['perimetro_tipo' => 'condominio', 'tabella_id' => $s->tabelle['Proprietà']->id])
        ->assertSessionHasNoErrors();
    expect(aaRighe($prima, 'condominio')[0]->campi[0])->toMatchArray(['etichetta' => 'Tabella di proprietà generale', 'dopo' => 'Proprietà']);

    $s->gestione('Facciata 2026', 'straordinaria');
    $piano = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $s->gestioni['Facciata 2026']['g']->id, 'condominio_id' => $s->c->id,
        'esercizio_id' => $s->e->id, 'nome' => 'Facciata 2026 — rate', 'stato' => 'bozza', 'tipo' => 'straordinario', 'numero_rate' => 6, 'giorno_scadenza' => 5,
        'data_prima_scadenza' => '2027-01-05', 'metodo_distribuzione' => 'prima_rata', 'data_delibera_assemblea' => '2026-10-22', 'numero_verbale' => '41']);
    $prima = ViaRoma12::ultimaRiga();
    $this->actingAs($s->admin)->post(route('admin.gestionale.delibere.raccolta.store', $s->c), ['spuntate' => [['oggetto_tipo' => 'piano_rate', 'oggetto_id' => $piano->id]]])
        ->assertRedirect(route('admin.gestionale.assemblee.index', $s->c));
    expect(aaRighe($prima, 'condominio')->pluck('riepilogo')->implode(' | '))->toContain('Date scritte a mano raccolte in delibere: 1 riunione');
});

it('l\'ultima delibera di una riunione a mano senza verbale si elimina con la riunione dalla rotta, e si torna all\'archivio', function () {
    $s = ViaRoma12::nuovo($this, 'Corso Italia 3');
    $d = ViaRoma12::delibera($s, '2024-03-12', 'Rifacimento della facciata di Via Roma', 'approvata', '41');
    $riunione = $d->assemblea;

    $this->actingAs($s->admin)->delete(route('admin.gestionale.delibere.destroy', [$s->c, $d]), ['con_la_riunione' => true])
        ->assertRedirect(route('admin.gestionale.assemblee.index', ['condominio' => $s->c->id]))->assertSessionHasNoErrors();
    expect(Assemblea::query()->whereKey($riunione->id)->exists())->toBeFalse();
});

it('la raccolta dalla rotta con «Per chi»: la riunione della sola scala B; una scala di un altro condominio è 404', function () {
    $s = ViaRoma12::nuovo($this);
    $altro = ViaRoma12::nuovo($this, 'Corso Italia 3');
    $scala = \App\Models\Scala::query()->create(['condominio_id' => $s->c->id, 'name' => 'B']);
    $scalaAltrove = \App\Models\Scala::query()->create(['condominio_id' => $altro->c->id, 'name' => 'A']);
    $s->gestione('Ascensore', 'straordinaria');
    $piano = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $s->gestioni['Ascensore']['g']->id, 'condominio_id' => $s->c->id,
        'esercizio_id' => $s->e->id, 'nome' => 'Ascensore scala B — rate', 'stato' => 'bozza', 'tipo' => 'straordinario', 'numero_rate' => 6, 'giorno_scadenza' => 5,
        'data_prima_scadenza' => '2027-01-05', 'metodo_distribuzione' => 'prima_rata', 'data_delibera_assemblea' => '2025-05-20', 'numero_verbale' => 'n.9']);
    $pagina = $this->actingAs($s->admin)->get(route('admin.gestionale.delibere.raccolta', $s->c))->assertOk()->viewData('page')['props'];
    $chiave = $pagina['anteprima'][0]['chiave'];
    expect($pagina['scale'])->toBe([['id' => $scala->id, 'nome' => 'B']]);

    $this->actingAs($s->admin)->post(route('admin.gestionale.delibere.raccolta.store', $s->c), ['spuntate' => [['oggetto_tipo' => 'piano_rate', 'oggetto_id' => $piano->id]],
        'perimetri' => [['chiave' => $chiave, 'ambito' => 'scala', 'scala_id' => $scalaAltrove->id]]])->assertNotFound();
    $this->actingAs($s->admin)->post(route('admin.gestionale.delibere.raccolta.store', $s->c), ['spuntate' => [['oggetto_tipo' => 'piano_rate', 'oggetto_id' => $piano->id]],
        'perimetri' => [['chiave' => $chiave, 'ambito' => 'scala', 'scala_id' => $scala->id]]])->assertRedirect(route('admin.gestionale.assemblee.index', $s->c));
    expect(Assemblea::query()->where('origine', 'a_mano')->where('condominio_id', $s->c->id)->value('scala_id'))->toBe($scala->id);
});

it('«Apri» di una riga sulle date (la seduta, il luogo) apre il passo «Date e ordine del giorno»', function () {
    $s = ViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Varie ed eventuali', 'materia' => 'varie']]);
    $prima = ViaRoma12::ultimaRiga();
    $this->actingAs($s->admin)->patch(route('admin.gestionale.assemblee.sedute', [$s->c, $a]), ['sedute' => aaSedute('2026-12-14', '2026-12-16')])
        ->assertSessionHasNoErrors();
    $this->patch(route('admin.gestionale.assemblee.update', [$s->c, $a]), ['luogo' => 'Bar Centrale, Via Roma 10'])->assertSessionHasNoErrors();

    $seduta = aaRighe($prima, 'seduta')->first();
    $luogo = aaRighe($prima, 'assemblea')->first(fn ($r) => collect((array) $r->campi)->contains(fn ($c) => ($c['chiave'] ?? null) === 'luogo'));
    $date = route('admin.gestionale.assemblee.show', [$s->c->id, $a->id, 'scheda' => 'date']);
    expect(\App\Services\RegistroModifiche\RegistroPerLaPagina::apri($seduta))->toBe($date)
        ->and($luogo)->not->toBeNull()
        ->and(\App\Services\RegistroModifiche\RegistroPerLaPagina::apri($luogo))->toBe($date);
});

it('«Prepara l\'assemblea» con «Segna come tabella di proprietà generale»: la tabella scelta diventa quella del condominio, con la sua riga; mai sopra una già segnata, mai per una scala', function () {
    $s = ViaRoma12::nuovo($this)->tabella('Proprietà', ['A' => 600, 'B' => 400])->tabella('Scale', ['A' => 500, 'B' => 500]);
    $prima = ViaRoma12::ultimaRiga();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.store', $s->c), [
        'tipo' => 'ordinaria', 'ambito' => 'condominio', 'sedute' => aaSedute(), 'proposte' => ['varie'],
        'tabella_id' => $s->tabelle['Proprietà']->id, 'segna_di_proprieta' => true,
    ])->assertSessionHasNoErrors()->assertRedirect();
    expect((int) $s->c->fresh()->tabella_proprieta_id)->toBe((int) $s->tabelle['Proprietà']->id)
        ->and(aaRighe($prima, 'condominio')[0]->campi[0])->toMatchArray(['etichetta' => 'Tabella di proprietà generale', 'prima' => null, 'dopo' => 'Proprietà']);

    // Già segnata: un'altra assemblea con un'altra tabella non la cambia.
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.store', $s->c), [
        'tipo' => 'ordinaria', 'ambito' => 'condominio', 'sedute' => aaSedute('2027-01-14', '2027-01-15'), 'proposte' => ['varie'],
        'tabella_id' => $s->tabelle['Scale']->id, 'segna_di_proprieta' => true,
    ])->assertSessionHasNoErrors();
    expect((int) $s->c->fresh()->tabella_proprieta_id)->toBe((int) $s->tabelle['Proprietà']->id);

    // Senza la spunta niente si segna.
    $s->c->forceFill(['tabella_proprieta_id' => null])->save();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.store', $s->c), [
        'tipo' => 'ordinaria', 'ambito' => 'condominio', 'sedute' => aaSedute('2027-02-14', '2027-02-15'), 'proposte' => ['varie'],
        'tabella_id' => $s->tabelle['Proprietà']->id,
    ])->assertSessionHasNoErrors();
    expect($s->c->fresh()->tabella_proprieta_id)->toBeNull();

    // Per una scala la casella non vale: la tabella del condominio resta da segnare.
    $scala = \App\Models\Scala::query()->create(['condominio_id' => $s->c->id, 'name' => 'B']);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.store', $s->c), [
        'tipo' => 'ordinaria', 'ambito' => 'scala', 'scala_id' => $scala->id, 'sedute' => aaSedute('2027-03-14', '2027-03-15'), 'proposte' => ['varie'],
        'tabella_id' => $s->tabelle['Scale']->id, 'segna_di_proprieta' => true,
    ])->assertSessionHasNoErrors();
    expect($s->c->fresh()->tabella_proprieta_id)->toBeNull();
});
