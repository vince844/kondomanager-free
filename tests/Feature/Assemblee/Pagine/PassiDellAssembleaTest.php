<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission as Permesso;
use App\Http\Controllers\Assemblee\PerLePagine;
use App\Models\Assemblee\Assemblea;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

require_once __DIR__ . '/../Convocazione/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679; i passi guidati della pagina dell'assemblea): il server dice i sei passi, nell'ordine «Date e ordine del giorno»,
 * «Documenti», «Convocazione», «In sala», «Verbale e trasmissione», «Delibere eseguite» (le sue regole le prova `PagineDelSeguitoTest`), con lo stato, la riga sotto il nome, «che cosa manca» per il passo di adesso e la frase
 * del piede. Una prova per regola:
 *
 * - le date sono fatte con le date valide e almeno un punto; la tabella del valore che non copre tutte le unità e il luogo che manca sono voci che informano e
 *   non bloccano; senza punti il passo è quello di adesso e dice che cosa manca, ogni voce da fare con il suo collegamento dentro la pagina; in preparazione
 *   le voci del passo restano anche a passo fatto;
 * - i documenti non bloccano: fatti quando lo sono le date, con la riga «l'avviso con la delega · nessun allegato»;
 * - la convocazione è fatta quando ogni convocato ha il suo avviso spedito: una lettera stampata e non ancora spedita non basta, e chi non ha un mezzo valido
 *   tiene il passo aperto e lo dice; la riga dice quanti da spedire ed entro quando, poi quanti raggiunti;
 * - i passi che vengono dopo dicono il perché; quelli di «Prepara l'assemblea» si aprono quando il primo passo è fatto;
 * - la sala si apre dalla prima spedizione (con le deleghe ricevute; «Apri la sala» dal giorno della prima convocazione), il verbale dopo la sala; un'assemblea annullata ha la sala e il verbale chiusi; una riunione
 *   registrata a mano non ha passi;
 * - «che cosa manca» della convocazione con e senza la casella PEC dello studio, senza il luogo, con il preventivo da fermare;
 * - la pagina non manda più le card della testata; il parametro `scheda` apre i passi di oggi; «Prepara l'assemblea» ha la strada con il primo passo aperto,
 *   e dopo il salvataggio la pagina apre il passo 1.
 *
 * Che cosa non copre: come la strada si disegna (vitest di `StradaDellAssemblea`).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** I passi per chiave. @return array<string, array<string, mixed>> */
function pdaPassi(Assemblea $a): array
{
    return collect(PerLePagine::passi($a->refresh()))->keyBy('chiave')->all();
}

/** L'ordinaria di Via Roma 12 con la tabella «Proprietà», che copre le sette unità. */
function pdaConLaTabella(Beta51Scenario $s, ?array $punti = null): Assemblea
{
    return ConvocazioneDiViaRoma12::ordinaria($s, ['tabella_id' => $s->tabelle['Proprietà']->id], $punti);
}

it('i sei passi, nell\'ordine, con il nome e il numero', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $this->actingAs($s->admin);
    $passi = PerLePagine::passi(pdaConLaTabella($s));

    expect(array_column($passi, 'chiave'))->toBe(['date', 'documenti', 'convocazione', 'sala', 'verbale', 'delibere'])
        ->and(array_column($passi, 'nome'))->toBe(['Date e ordine del giorno', 'Documenti', 'Convocazione', 'In sala', 'Verbale e trasmissione', 'Delibere eseguite'])
        ->and(array_column($passi, 'numero'))->toBe([1, 2, 3, 4, 5, 6]);
});

it('«Date e ordine del giorno» è fatto con le date valide e almeno un punto; in preparazione le sue voci restano, tutte a posto', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $this->actingAs($s->admin);
    $p = pdaPassi(pdaConLaTabella($s));

    expect($p['date'])->toMatchArray(['stato' => 'fatto', 'riga' => '14/12 e 15/12 · 6 punti', 'scopo' => null,
        'piede' => 'Il passo è fatto quando le date valgono e c\'è almeno un punto; la tabella che non copre tutte le unità avvisa e non blocca.'])
        ->and($p['date']['manca'])->toBe([
            ['chiave' => 'date', 'fatto' => true, 'informa' => false, 'titolo' => 'Le date e il luogo', 'dettaglio' => '14/12 e 15/12', 'collegamento' => null],
            ['chiave' => 'ordine', 'fatto' => true, 'informa' => false, 'titolo' => 'L\'ordine del giorno', 'dettaglio' => '6 punti', 'collegamento' => null],
            ['chiave' => 'tabella', 'fatto' => true, 'informa' => false, 'titolo' => 'La tabella del valore', 'dettaglio' => '«Proprietà» · 7 unità su 7',
                'collegamento' => null],
        ]);
});

it('la tabella del valore che non copre tutte le unità avvisa e non blocca: il passo 1 è fatto e la convocazione si apre con un clic', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this)->tabella('Scala A', ['3' => 500, '5' => 500]);
    $this->actingAs($s->admin);

    $senza = pdaPassi(ConvocazioneDiViaRoma12::ordinaria($s));
    expect($senza['date']['stato'])->toBe('fatto')
        ->and($senza['date']['manca'][2])->toBe(['chiave' => 'tabella', 'fatto' => false, 'informa' => true, 'titolo' => 'La tabella del valore',
            'dettaglio' => 'da scegliere qui sotto', 'collegamento' => ['testo' => 'Sceglila', 'url' => '#tabella-del-valore', 'passo' => null]])
        ->and($senza['documenti']['stato'])->toBe('fatto')->and($senza['convocazione']['stato'])->toBe('adesso');

    $parziale = pdaPassi(ConvocazioneDiViaRoma12::ordinaria($s, ['tabella_id' => $s->tabelle['Scala A']->id]));
    expect($parziale['date']['stato'])->toBe('fatto')
        ->and($parziale['date']['manca'][2])->toMatchArray(['fatto' => false, 'informa' => true,
            'collegamento' => ['testo' => 'Controllala', 'url' => '#tabella-del-valore', 'passo' => null]])
        ->and($parziale['date']['manca'][2]['dettaglio'])->toStartWith('«Scala A» · 2 unità su 7 · ')
        ->and($parziale['convocazione']['stato'])->toBe('adesso');
});

it('senza punti «Date e ordine del giorno» è il passo di adesso; ogni voce da fare ha il suo collegamento dentro la pagina', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $this->actingAs($s->admin);

    $vuota = pdaPassi(pdaConLaTabella($s, []));
    expect($vuota['date'])->toMatchArray(['stato' => 'adesso', 'riga' => '14/12 e 15/12 · nessun punto', 'scopo' => 'per andare avanti'])
        ->and($vuota['date']['manca'][1])->toBe(['chiave' => 'ordine', 'fatto' => false, 'informa' => false, 'titolo' => 'L\'ordine del giorno',
            'dettaglio' => 'nessun punto: aggiungilo qui sotto', 'collegamento' => ['testo' => 'Aggiungi', 'url' => '#ordine-del-giorno', 'passo' => null]])
        ->and($vuota['documenti']['stato'])->toBe('dopo')->and($vuota['convocazione']['stato'])->toBe('dopo');
});

it('senza il luogo il passo 1 è fatto, e la voce delle date informa: l\'avviso lo deve dire prima di spedire', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = pdaConLaTabella($s);
    $a->forceFill(['luogo' => null])->saveQuietly();
    $this->actingAs($s->admin);
    $p = pdaPassi($a);

    expect($p['date']['stato'])->toBe('fatto')
        ->and($p['date']['manca'][0])->toBe(['chiave' => 'date', 'fatto' => true, 'informa' => true, 'titolo' => 'Le date e il luogo',
            'dettaglio' => '14/12 e 15/12 · manca il luogo: l\'avviso lo deve dire prima di spedire',
            'collegamento' => ['testo' => 'Scrivilo', 'url' => '#date-dell-assemblea', 'passo' => null]]);
});

it('i passi che vengono dopo dicono il perché: si aprono quando è fatto il passo di adesso', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $this->actingAs($s->admin);
    $p = pdaPassi(pdaConLaTabella($s, []));

    expect($p['documenti'])->toMatchArray(['stato' => 'dopo', 'perche' => 'si apre quando «Date e ordine del giorno» è fatto'])
        ->and($p['convocazione'])->toMatchArray(['stato' => 'dopo', 'perche' => 'si apre quando «Date e ordine del giorno» è fatto']);
});

it('«Documenti», guardati con «Avanti: Convocazione», è fatto con l\'avviso e la delega sempre; senza le date fatte viene dopo', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $this->actingAs($s->admin);

    $varie = pdaPassi(pdaConLaTabella($s, [['testo' => 'Varie ed eventuali', 'materia' => 'varie']]));
    expect($varie['documenti'])->toMatchArray(['stato' => 'fatto', 'riga' => 'l\'avviso con la delega · nessun allegato', 'manca' => [],
        'piede' => 'Guarda l\'avviso con la delega e gli allegati, poi premi «Avanti: Convocazione»: il passo diventa fatto.']);

    $sei = pdaPassi(pdaConLaTabella($s));
    expect($sei['documenti']['riga'])->toBe('l\'avviso con la delega · nessun allegato · il rendiconto e il preventivo da fermare');

    expect(pdaPassi(pdaConLaTabella($s, []))['documenti']['stato'])->toBe('dopo');
});

it('«Convocazione»: adesso, con quanti da spedire ed entro quando; la lettera stampata non è spedita; poi quanti raggiunti', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = pdaConLaTabella($s);
    $this->actingAs($s->admin);

    expect(pdaPassi($a)['convocazione'])->toMatchArray(['stato' => 'adesso', 'riga' => '9 da spedire · entro il 03/12 · 1 senza un mezzo valido', 'scopo' => 'prima di spedire',
        'piede' => 'Il passo è fatto quando tutti i convocati hanno il loro avviso spedito.']);

    // Le lettere stampate e non ancora spedite non contano: il passo resta quello di adesso, e la stampa non fa «convocata».
    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    $dopo = pdaPassi($a);
    expect($a->refresh()->stato->value)->toBe('in_preparazione')
        ->and($dopo['date']['stato'])->toBe('fatto')
        ->and($dopo['convocazione'])->toMatchArray(['stato' => 'adesso', 'riga' => '0 su 9 raggiunte · 9 da spedire · 1 senza un mezzo valido']);

    $invii = collect(['marco', 'carla', 'gallo'])->map(fn ($chi) => ConvocazioneDiViaRoma12::invio($a, $s, $chi)->id)->all();
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => $invii, 'canale' => 'raccomandata',
        'data' => '2026-11-26'])->assertSessionHasNoErrors();
    expect(pdaPassi($a)['convocazione']['riga'])->toBe('0 su 9 raggiunte · 6 da spedire · 1 senza un mezzo valido');
});

it('«Convocazione» è fatto quando ogni convocato ha il suo avviso spedito: la PEC partita, la raccomandata spedita, la consegna a mano registrata', function () {
    ConvocazioneDiViaRoma12::conLaCasella();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $s->persone['bianchi']->forceFill(['indirizzo' => 'Via Roma 12, 20121 Milano'])->saveQuietly();
    $a = ConvocazioneDiViaRoma12::ordinaria($s, ['tabella_id' => $s->tabelle['Proprietà']->id], [
        ['testo' => 'Approvazione del rendiconto 2025 e dello stato di riparto', 'materia' => 'rendiconto'],
        ['testo' => 'Varie ed eventuali', 'materia' => 'varie'],
    ]);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]))->assertRedirect();
    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    $invii = collect(['marco', 'carla', 'gallo', 'bianchi'])->map(fn ($chi) => ConvocazioneDiViaRoma12::invio($a, $s, $chi)->id)->all();
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => $invii, 'canale' => 'raccomandata',
        'data' => '2026-11-26'])->assertSessionHasNoErrors();
    expect(pdaPassi($a)['convocazione'])->toMatchArray(['stato' => 'adesso', 'riga' => '0 su 7 raggiunte · 1 da spedire']);

    $anna = ConvocazioneDiViaRoma12::invio($a, $s, 'anna');
    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $anna]), ['esito' => 'consegnata', 'giorno' => '2026-11-26'])
        ->assertSessionHasNoErrors();
    $p = pdaPassi($a);
    expect($p['convocazione'])->toMatchArray(['stato' => 'fatto', 'riga' => '1 su 7 raggiunta', 'manca' => []])
        ->and(array_map(fn ($x) => $x['stato'], $p))->toBe(['date' => 'fatto', 'documenti' => 'fatto', 'convocazione' => 'fatto', 'sala' => 'adesso', 'verbale' => 'chiuso',
            'delibere' => 'chiuso']);
});

it('«In sala» si apre dalla prima spedizione (in preparazione è chiuso); «Verbale e trasmissione» resta chiuso, dopo la sala', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $this->actingAs($s->admin);
    $p = pdaPassi(pdaConLaTabella($s));

    expect($p['sala'])->toMatchArray(['stato' => 'chiuso', 'riga' => 'si apre dalla prima spedizione', 'perche' => 'si apre dalla prima spedizione', 'manca' => []])
        ->and($p['verbale'])->toMatchArray(['stato' => 'chiuso', 'riga' => 'dopo la sala', 'perche' => 'dopo la sala']);
});

it('un\'assemblea annullata ha la sala e il verbale chiusi e nessun passo di adesso; una riunione registrata a mano non ha passi', function () {
    ConvocazioneDiViaRoma12::oggi('2026-12-02 10:00');
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = pdaConLaTabella($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    $this->post(route('admin.gestionale.assemblee.riconvoca', [$s->c, $a]), ['motivo' => 'Le raccomandate non arrivano in tempo',
        'sedute' => [['convocazione' => 'prima', 'fissata_il' => '2026-12-21 08:00'], ['convocazione' => 'seconda', 'fissata_il' => '2026-12-22 18:30']]])
        ->assertRedirect();
    $p = pdaPassi($a);

    expect(array_map(fn ($x) => $x['stato'], $p))->toBe(['date' => 'fatto', 'documenti' => 'fatto', 'convocazione' => 'fatto', 'sala' => 'chiuso', 'verbale' => 'chiuso',
        'delibere' => 'chiuso'])
        ->and($p['convocazione']['piede'])->toBe('L\'assemblea è annullata: gli invii e le prove restano.')
        ->and($p['sala']['perche'])->toBe('l\'assemblea è annullata')->and($p['verbale']['perche'])->toBe('l\'assemblea è annullata');

    $d = ViaRoma12::delibera($s, '2026-10-22', 'Rifacimento della facciata', 'approvata', '41');
    expect(PerLePagine::passi($d->assemblea))->toBe([]);
});

it('«che cosa manca» della convocazione: senza la casella PEC dello studio «Imposta» porta alla pagina delle caselle; con la casella è fatta', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = pdaConLaTabella($s);
    $s->admin->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(['name' => Permesso::MANAGE_GENERAL_SETTINGS->value, 'guard_name' => 'web']));
    $this->actingAs($s->admin);

    expect(pdaPassi($a)['convocazione']['manca'])->toBe([
        ['chiave' => 'avviso', 'fatto' => true, 'informa' => true, 'titolo' => 'Date, luogo e ordine del giorno',
            'dettaglio' => 'a posto · il rendiconto e il preventivo da fermare prima di spedire',
            'collegamento' => ['testo' => 'Apri «Documenti»', 'url' => null, 'passo' => 'documenti']],
        ['chiave' => 'casella', 'fatto' => false, 'informa' => false, 'titolo' => 'La casella PEC dello studio', 'dettaglio' => 'da impostare: le 3 PEC non partono',
            'collegamento' => ['testo' => 'Imposta', 'url' => route('impostazioni.mail', ['apri' => 'pec']), 'passo' => null]],
        ['chiave' => 'convocati', 'fatto' => false, 'informa' => false, 'titolo' => 'I convocati',
            'dettaglio' => '1 senza un mezzo valido: Bianchi Anna (ha solo l\'email) · 1 PEC non comunicata dalla persona',
            'collegamento' => ['testo' => 'Vedi chi', 'url' => '#convocati', 'passo' => null]],
    ]);

    ConvocazioneDiViaRoma12::conLaCasella();
    expect(pdaPassi($a)['convocazione']['manca'][1])->toBe(['chiave' => 'casella', 'fatto' => true, 'informa' => false, 'titolo' => 'La casella PEC dello studio',
        'dettaglio' => 'impostata', 'collegamento' => null]);
});

it('«che cosa manca» della convocazione senza il luogo: la frase di ciò che manca all\'avviso, e il collegamento al passo delle date', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = pdaConLaTabella($s);
    $a->forceFill(['luogo' => null])->saveQuietly();
    $this->actingAs($s->admin);

    expect(pdaPassi($a)['convocazione']['manca'][0])->toBe(['chiave' => 'avviso', 'fatto' => false, 'informa' => false, 'titolo' => 'Date, luogo e ordine del giorno',
        'dettaglio' => 'L\'avviso deve dire dove si tiene l\'assemblea (art. 66 c. 3). Manca il luogo della prima convocazione e della seconda convocazione: '
            . 'scrivilo nel passo «Date e ordine del giorno».',
        'collegamento' => ['testo' => 'Scrivilo', 'url' => null, 'passo' => 'date']]);
});

it('una persona senza un mezzo valido tiene aperto il passo «Convocazione», e «Che cosa manca» dice chi e perché', function () {
    ConvocazioneDiViaRoma12::conLaCasella();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::soloOrdinaria($s);
    $a->forceFill(['tabella_id' => $s->tabelle['Proprietà']->id])->saveQuietly();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]))->assertRedirect();
    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => collect(['marco', 'carla', 'gallo'])
        ->map(fn ($chi) => ConvocazioneDiViaRoma12::invio($a, $s, $chi)->id)->all(), 'canale' => 'raccomandata', 'data' => '2026-11-26'])->assertSessionHasNoErrors();
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => [ConvocazioneDiViaRoma12::invio($a, $s, 'anna')->id],
        'canale' => 'a_mano', 'data' => '2026-11-26'])->assertSessionHasNoErrors();
    $p = pdaPassi($a);

    expect($p['convocazione'])->toMatchArray(['stato' => 'adesso', 'riga' => '1 su 7 raggiunta · 1 da spedire · 1 senza un mezzo valido'])
        ->and($p['convocazione']['manca'][2])->toBe(['chiave' => 'convocati', 'fatto' => false, 'informa' => false, 'titolo' => 'I convocati',
            'dettaglio' => '1 senza un mezzo valido: Bianchi Anna (ha solo l\'email)', 'collegamento' => ['testo' => 'Vedi chi', 'url' => '#convocati', 'passo' => null]]);
});

it('«Prepara l\'assemblea»: i passi chiusi si aprono quando il primo passo è fatto; dopo il salvataggio, con il passo 1 fatto, la pagina apre «Documenti»', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $this->actingAs($s->admin);
    $passi = PerLePagine::passiDellaPreparazione();
    expect($passi[1])->toMatchArray(['stato' => 'chiuso', 'riga' => 'dopo «Prepara l\'assemblea»',
        'perche' => 'si apre dopo «Prepara l\'assemblea», quando il primo passo è fatto: le date valgono e c\'è almeno un punto']);

    $r = $this->post(route('admin.gestionale.assemblee.store', $s->c), ['tipo' => 'ordinaria', 'ambito' => 'condominio', 'luogo' => 'Sala condominiale, Via Roma 12',
        'sedute' => [['convocazione' => 'prima', 'numero' => 1, 'giorno' => '2026-12-14', 'ora' => '08:00'],
            ['convocazione' => 'seconda', 'numero' => 1, 'giorno' => '2026-12-15', 'ora' => '18:30']], 'proposte' => ['varie']])->assertSessionHasNoErrors();
    $a = Assemblea::query()->where('condominio_id', $s->c->id)->firstOrFail();
    $r->assertRedirect(route('admin.gestionale.assemblee.show', ['condominio' => $s->c->id, 'assemblea' => $a->id, 'scheda' => 'documenti']));
});

it('la pagina manda i passi e non le card della testata; il parametro «scheda» apre i passi di oggi; «Prepara l\'assemblea» ha la strada', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = pdaConLaTabella($s);
    $this->actingAs($s->admin);
    $pagina = fn (array $piu = []) => $this->get(route('admin.gestionale.assemblee.show', [$s->c, $a] + $piu))->assertOk();

    $pagina()->assertInertia(fn (AssertableInertia $p) => $p->component('gestionale/assemblee/Assemblea')->missing('testata')->has('passi', 6)
        ->where('passi.2.chiave', 'convocazione')->where('passi.2.stato', 'adesso')->where('scheda', null));
    $pagina(['scheda' => 'fascicolo'])->assertInertia(fn (AssertableInertia $p) => $p->where('scheda', 'documenti'));
    $pagina(['scheda' => 'convocazione'])->assertInertia(fn (AssertableInertia $p) => $p->where('scheda', 'convocazione'));
    $pagina(['scheda' => 'diario'])->assertInertia(fn (AssertableInertia $p) => $p->where('scheda', 'diario'));
    $pagina(['scheda' => 'date'])->assertInertia(fn (AssertableInertia $p) => $p->where('scheda', 'date'));
    $pagina(['punto' => $a->punti()->first()->id])->assertInertia(fn (AssertableInertia $p) => $p->where('scheda', 'date'));

    $this->get(route('admin.gestionale.assemblee.create', $s->c))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('gestionale/assemblee/Prepara')->has('passi', 6)
            ->where('passi.0.chiave', 'date')->where('passi.0.stato', 'adesso')
            ->where('passi', fn ($passi) => collect($passi)->slice(1)->every(fn ($x) => $x['stato'] === 'chiuso'
                && $x['perche'] === 'si apre dopo «Prepara l\'assemblea», quando il primo passo è fatto: le date valgono e c\'è almeno un punto'
                && $x['riga'] === 'dopo «Prepara l\'assemblea»')));
});

it('«che cosa manca» della convocazione si calcola solo quando la convocazione è il passo di adesso', function () {
    $letture = new class { public int $n = 0; };
    app()->instance(\App\Services\Posta\CasellaPec::class, new class($letture) extends \App\Services\Posta\CasellaPec {
        public function __construct(private readonly object $letture) {}

        public function configurazione(): ?\App\Services\Posta\ConfigurazionePec
        {
            $this->letture->n++;

            return null;
        }
    });
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $this->actingAs($s->admin);

    expect(pdaPassi(pdaConLaTabella($s, []))['convocazione']['stato'])->toBe('dopo')->and($letture->n)->toBe(0);
    expect(pdaPassi(pdaConLaTabella($s))['convocazione']['stato'])->toBe('adesso')->and($letture->n)->toBeGreaterThan(0);
});

it('appena preparata dalla rotta, «Documenti» è il passo di adesso e la convocazione viene dopo: niente spunta da sola', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $this->actingAs($s->admin);
    $this->post(route('admin.gestionale.assemblee.store', $s->c), [
        'tipo' => 'ordinaria', 'ambito' => 'condominio', 'luogo' => 'Sala condominiale', 'tabella_id' => $s->tabelle['Proprietà']->id, 'proposte' => ['varie'],
        'sedute' => [['convocazione' => 'prima', 'numero' => 1, 'giorno' => '2026-12-14', 'ora' => '08:00'], ['convocazione' => 'seconda', 'numero' => 1, 'giorno' => '2026-12-15', 'ora' => '18:30']],
    ])->assertSessionHasNoErrors();
    $p = pdaPassi(Assemblea::query()->where('condominio_id', $s->c->id)->latest('id')->firstOrFail());

    expect(array_map(fn ($x) => $x['stato'], $p))->toBe(['date' => 'fatto', 'documenti' => 'adesso', 'convocazione' => 'dopo', 'sala' => 'chiuso', 'verbale' => 'chiuso',
        'delibere' => 'chiuso'])
        ->and($p['documenti']['piede'])->toBe('Guarda l\'avviso con la delega e gli allegati, poi premi «Avanti: Convocazione»: il passo diventa fatto.')
        ->and($p['convocazione']['perche'])->toBe('si apre quando «Documenti» è fatto');
});

it('«Avanti: Convocazione» dalla rotta segna i documenti visti una volta, apre la convocazione, e «Documenti» prende la spunta', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $this->actingAs($s->admin);
    $a = pdaConLaTabella($s);
    $a->forceFill(['documenti_visti_il' => null])->saveQuietly();

    $this->post(route('admin.gestionale.assemblee.documenti-visti', [$s->c, $a]))
        ->assertRedirect(route('admin.gestionale.assemblee.show', ['condominio' => $s->c->id, 'assemblea' => $a->id, 'scheda' => 'convocazione']));
    $visti = $a->fresh()->documenti_visti_il;
    expect($visti)->not->toBeNull();
    $p = pdaPassi($a);
    expect($p['documenti']['stato'])->toBe('fatto')->and($p['convocazione']['stato'])->toBe('adesso');

    // Una seconda volta non cambia il giorno.
    $this->travel(2)->days();
    $this->post(route('admin.gestionale.assemblee.documenti-visti', [$s->c, $a]))->assertRedirect();
    expect($a->fresh()->documenti_visti_il->equalTo($visti))->toBeTrue();
});

it('un\'assemblea già convocata senza il giorno dei documenti visti ha «Documenti» fatto: le assemblee di prima non tornano indietro', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $this->actingAs($s->admin);
    $a = pdaConLaTabella($s);
    $a->forceFill(['documenti_visti_il' => null, 'stato' => 'convocata'])->saveQuietly();

    expect(pdaPassi($a)['documenti']['stato'])->toBe('fatto');
});

it('i documenti visti di un\'assemblea di un altro condominio sono un 404, e chi non gestisce le assemblee non li segna', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = pdaConLaTabella($s);
    $a->forceFill(['documenti_visti_il' => null])->saveQuietly();
    $altro = \App\Models\Condominio::factory()->create();

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.documenti-visti', [$altro, $a]))->assertNotFound();
    expect($a->fresh()->documenti_visti_il)->toBeNull();
});

