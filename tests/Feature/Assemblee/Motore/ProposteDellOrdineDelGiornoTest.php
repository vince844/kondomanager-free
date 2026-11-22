<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Gestionale\NotaSintetica;
use App\Services\Assemblee\MemoriaDelCondominio;
use App\Services\Assemblee\ProposteDellOrdineDelGiorno;
use App\Services\Assemblee\StatiDellAssemblea;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, §5 e §13 rilievo 9 del progetto della tappa 2): l'ordine del giorno proposto in gruppi; lo sforo, i lavori senza delibera, i
 * lavori d'urgenza non riferiti, la riga scritta nella nota sintetica, «Varie ed eventuali»; il memo sulla stessa origine di un sospeso è un punto solo; un
 * sospeso con la sua chiave in un'assemblea in preparazione non si propone più, e torna quando l'assemblea si annulla.
 *
 * Che cosa non copre: il rendiconto da approvare (la lettura dei sei mesi ha i suoi test, `SeiMesiPerAgireTest`), i fatti della nota calcolati (chiedono il
 * rendiconto intero: la lettura va in errore senza fermare la proposta, e lo prova la riga scritta), il deficit (compiti scritti dal programma).
 */

$chiavi = fn (array $gruppi, string $gruppo) => array_column(collect($gruppi)->firstWhere('chiave', $gruppo)['punti'], 'chiave_fonte');

it('lo sforo, il piano della straordinaria senza delibera e quello d\'urgenza, «Varie ed eventuali», ognuno nel suo gruppo', function () use ($chiavi) {
    $s = ViaRoma12::nuovo($this)->tabella('Proprietà', ['A' => 1000])->gestione('Facciata 2026', 'straordinaria')
        ->voce('Facciata 2026', 'Facciata', 1450000, ['Proprietà' => 100]);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $senza = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $s->gestioni['Facciata 2026']['g']->id, 'condominio_id' => $s->c->id,
        'esercizio_id' => $s->e->id, 'nome' => 'Facciata 2026 — rate', 'stato' => 'bozza', 'tipo' => 'straordinario', 'numero_rate' => 6, 'giorno_scadenza' => 5,
        'data_prima_scadenza' => '2027-01-05', 'metodo_distribuzione' => 'prima_rata']);
    $urgenza = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $s->gestioni['Facciata 2026']['g']->id, 'condominio_id' => $s->c->id,
        'esercizio_id' => $s->e->id, 'nome' => 'Tetto urgente', 'stato' => 'approvato', 'tipo' => 'straordinario', 'numero_rate' => 3, 'giorno_scadenza' => 5,
        'data_prima_scadenza' => '2027-01-05', 'metodo_distribuzione' => 'prima_rata', 'tipo_autorizzazione' => 'urgenza', 'motivazione_autorizzazione' => 'Infiltrazioni']);

    $gruppi = ProposteDellOrdineDelGiorno::per($s->c, null);
    $sforo = collect($gruppi)->firstWhere('chiave', 'dal_programma')['punti'][0];

    expect(array_column($gruppi, 'titolo'))->toBe(['Di serie', 'Da te', 'Dal programma', 'Dalla nota sintetica'])
        ->and($chiavi($gruppi, 'di_serie'))->toContain('varie')
        ->and($chiavi($gruppi, 'dal_programma'))->toBe(['fattura:' . $fattura->id, 'piano_rate:' . $senza->id, 'piano_rate:' . $urgenza->id])
        // Uno sforo non è per forza urgente: il punto dice «oltre il preventivo» (il «per …» è la descrizione della prima riga, non il motivo).
        ->and($sforo['testo'])->toBe('Ratifica della spesa oltre il preventivo di € 1.800,00 per riparazione della colonna dell\'acqua (fattura n. 45 di Idraulica Bianchi)')
        ->and($sforo['testo_pubblico'])->toBe('Ratifica della spesa oltre il preventivo di € 1.800,00 per riparazione della colonna dell\'acqua')
        ->and($sforo['spuntato'])->toBeTrue()
        ->and(collect($gruppi)->firstWhere('chiave', 'dal_programma')['punti'][1]['maggioranza'])->toBe('meta_del_valore')
        ->and(collect($gruppi)->firstWhere('chiave', 'dal_programma')['punti'][2]['testo'])->toBe('Comunicazione dei lavori urgenti «Tetto urgente» e del loro riparto')
        ->and(collect($gruppi)->firstWhere('chiave', 'dal_programma')['punti'][2]['maggioranza'])->toBe('senza_voto');
});

it('un memo sulla fattura in sforo e lo sforo sono un punto solo, con il testo del memo, fra «Da te»', function () use ($chiavi) {
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);
    MemoriaDelCondominio::salva($s->c, ['testo' => 'Ratifica della colonna dell\'acqua', 'nota' => 'Chiedere il preventivo bis',
        'origine_tipo' => 'fattura_passiva', 'origine_id' => $fattura->id], $s->admin);

    $gruppi = ProposteDellOrdineDelGiorno::per($s->c, null);
    $memo = collect($gruppi)->firstWhere('chiave', 'da_te')['punti'];
    expect($chiavi($gruppi, 'dal_programma'))->toBe([])
        ->and($memo)->toHaveCount(1)
        ->and($memo[0]['chiave_fonte'])->toBe('fattura:' . $fattura->id)
        ->and($memo[0]['testo'])->toBe('Ratifica della colonna dell\'acqua')
        ->and($memo[0]['frase'])->toContain('Fattura n. 45 · Idraulica Bianchi · € 1.800,00')
        ->and($memo[0]['frase'])->toContain('la tua nota: Chiedere il preventivo bis')
        ->and($memo[0]['materia'])->toBe('ratifica');
});

it('un sospeso in un\'assemblea in preparazione non si propone più; annullata, torna fra le proposte', function () use ($chiavi) {
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $proposte = collect(ProposteDellOrdineDelGiorno::per($s->c, null))->firstWhere('chiave', 'dal_programma')['punti'];
    $a = ViaRoma12::ordinaria($s, $proposte);

    expect($chiavi(ProposteDellOrdineDelGiorno::per($s->c, null), 'dal_programma'))->toBe([])
        ->and($chiavi(ProposteDellOrdineDelGiorno::per($s->c, $a), 'di_serie'))->toBe(['varie']);

    $a->update(['stato' => 'annullata']);
    expect($chiavi(ProposteDellOrdineDelGiorno::per($s->c, null), 'dal_programma'))->toBe(['fattura:' . $fattura->id]);
});

it('la riga scritta in «Da decidere in assemblea» della nota sintetica, senza maggioranza proposta', function () use ($chiavi) {
    $s = ViaRoma12::nuovo($this);
    NotaSintetica::query()->create(['condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id, 'contenuto' => ['da_decidere' => ['Riparto delle spese del portone fra le due palazzine']]]);

    $nota = collect(ProposteDellOrdineDelGiorno::per($s->c, null))->firstWhere('chiave', 'dalla_nota')['punti'];
    expect($nota)->toHaveCount(1)
        ->and($nota[0]['chiave_fonte'])->toBe('nota:' . $s->e->id . ':riga:' . sha1('Riparto delle spese del portone fra le due palazzine'))
        ->and($nota[0]['maggioranza'])->toBeNull()
        ->and($nota[0]['fonte'])->toBe('nota_sintetica');
});

it('la card della dashboard: i punti senza «Varie», oltre gli otto «e altre N»', function () {
    $s = ViaRoma12::nuovo($this);
    foreach (range(1, 10) as $n) {
        MemoriaDelCondominio::salva($s->c, ['testo' => 'Punto ' . $n], $s->admin);
    }
    $card = MemoriaDelCondominio::perLaDashboard($s->c);
    expect($card['totale'])->toBe(10)->and($card['punti'])->toHaveCount(8)->and($card['altre'])->toBe(2)
        ->and($card['punti'][0]['origine'])->toBe('Da te')->and($card['in_preparazione'])->toBeNull();
});

it('il compito di una segnalazione si propone come la segnalazione: il suo titolo non va nella frase per la bacheca', function () {
    $s = ViaRoma12::nuovo($this);
    $segnalazione = \App\Models\Segnalazione::factory()->create(['condominio_id' => $s->c->id, 'subject' => 'Infiltrazione dal bagno dell\'Int. 6 di Mario Bianchi',
        'created_at' => '2026-11-18 10:00:00']);
    $compito = \App\Models\Evento::create(['title' => 'Infiltrazione dal bagno dell\'Int. 6 di Mario Bianchi', 'start_time' => now(), 'end_time' => now()->addHour(),
        'visibility' => 'hidden', 'is_approved' => true, 'eventable_type' => $segnalazione->getMorphClass(), 'eventable_id' => $segnalazione->id,
        'meta' => ['type' => 'segnalazione_guasto', 'requires_action' => true, 'context' => ['segnalazione_id' => $segnalazione->id]]]);
    $compito->condomini()->attach($s->c->id);

    $p = \App\Services\Assemblee\PropostaDelPunto::per($s->c, 'compito', $compito->id);
    expect($p['testo_pubblico'])->not->toContain('Mario Bianchi')->toBe('Intervento richiesto con una segnalazione')
        ->and($p['testo'])->toBe('Intervento richiesto con la segnalazione del 18/11/2026');
});

it('un altro compito dell\'agenda: il titolo resta nel testo per lo studio, la frase per la bacheca è generica', function () {
    $s = ViaRoma12::nuovo($this);
    $compito = \App\Models\Evento::create(['title' => 'Chiamare il signor Rossi per la perdita', 'start_time' => now(), 'end_time' => now()->addHour(),
        'visibility' => 'hidden', 'is_approved' => true, 'meta' => ['type' => 'promemoria', 'requires_action' => true]]);
    $compito->condomini()->attach($s->c->id);

    $p = \App\Services\Assemblee\PropostaDelPunto::per($s->c, 'compito', $compito->id);
    expect($p['testo'])->toBe('Chiamare il signor Rossi per la perdita')->and($p['testo_pubblico'])->toBe('Un punto dall\'agenda dello studio');
});

it('per un\'assemblea della sola scala i sospesi di tutto il condominio si propongono non spuntati; i memo della scala e «Varie» sì', function () {
    $s = ViaRoma12::nuovo($this);
    $scala = \App\Models\Scala::query()->create(['condominio_id' => $s->c->id, 'name' => 'B']);
    ViaRoma12::fatturaInSforo($s);
    $memo = MemoriaDelCondominio::salva($s->c, ['testo' => 'L\'ascensore della scala B', 'ambito' => 'scala', 'scala_id' => $scala->id], $s->admin);
    $a = ViaRoma12::ordinaria($s, [], ['ambito' => 'scala', 'scala_id' => $scala->id]);

    $gruppi = collect(ProposteDellOrdineDelGiorno::per($s->c, $a));
    $spuntato = fn (string $gruppo) => array_column($gruppi->firstWhere('chiave', $gruppo)['punti'], 'spuntato', 'chiave_fonte');
    expect(array_values(array_unique($spuntato('dal_programma'))))->toBe([false])
        ->and($spuntato('da_te')['memo:' . $memo->id])->toBeTrue()
        ->and($spuntato('di_serie')['varie'])->toBeTrue();

    // Per tutto il condominio, spuntati come prima.
    $tutto = collect(ProposteDellOrdineDelGiorno::per($s->c, null))->firstWhere('chiave', 'dal_programma')['punti'];
    expect(array_values(array_unique(array_column($tutto, 'spuntato'))))->toBe([true]);
});

it('un sospeso con una delibera approvata viva non si propone più, anche nell\'assemblea chiusa; respinto o rinviato torna', function () use ($chiavi) {
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $proposte = collect(ProposteDellOrdineDelGiorno::per($s->c, null))->firstWhere('chiave', 'dal_programma')['punti'];
    $a = ViaRoma12::ordinaria($s, $proposte);
    $punto = $a->punti()->where('chiave_fonte', 'fattura:' . $fattura->id)->firstOrFail();
    $a->update(['stato' => 'chiusa']);
    $delibera = \App\Models\Assemblee\Delibera::create(['condominio_id' => $s->c->id, 'assemblea_id' => $a->id, 'punto_id' => $punto->id, 'testo' => 'Ratificata',
        'esito' => 'respinta', 'deliberata_il' => '2026-12-15']);
    $dal = fn () => $chiavi(ProposteDellOrdineDelGiorno::per($s->c, null), 'dal_programma');

    // Respinta: la chiave non è occupata, il sospeso torna.
    expect($dal())->toBe(['fattura:' . $fattura->id]);
    // Rinviata: torna, con «rinviato dal».
    $delibera->update(['esito' => 'rinviata']);
    expect($dal())->toBe(['fattura:' . $fattura->id])
        ->and(collect(ProposteDellOrdineDelGiorno::per($s->c, null))->firstWhere('chiave', 'dal_programma')['punti'][0]['rinviato_dal'])->toBe('2026-12-15');
    // Approvata (e approvata con modifiche): non si propone più, a qualunque stato dell'assemblea.
    $delibera->update(['esito' => 'approvata']);
    expect($dal())->toBe([]);
    $delibera->update(['esito' => 'approvata_con_modifiche']);
    expect($dal())->toBe([]);
    // Revocata o annullata: la delibera non è viva, il sospeso torna.
    $delibera->update(['revocata_il' => '2026-12-20']);
    expect($dal())->toBe(['fattura:' . $fattura->id]);
    $delibera->update(['revocata_il' => null, 'annullata_il' => '2026-12-21']);
    expect($dal())->toBe(['fattura:' . $fattura->id]);
});

it('eUnSospeso: la fattura con una delibera approvata viva non è più un sospeso', function () {
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $a = ViaRoma12::ordinaria($s, collect(ProposteDellOrdineDelGiorno::per($s->c, null))->firstWhere('chiave', 'dal_programma')['punti']);
    $a->update(['stato' => 'chiusa']);
    expect(ProposteDellOrdineDelGiorno::eUnSospeso($fattura))->toBeTrue();
    \App\Models\Assemblee\Delibera::create(['condominio_id' => $s->c->id, 'assemblea_id' => $a->id,
        'punto_id' => $a->punti()->where('chiave_fonte', 'fattura:' . $fattura->id)->value('id'), 'testo' => 'Ratificata', 'esito' => 'approvata', 'deliberata_il' => '2026-12-15']);
    expect(ProposteDellOrdineDelGiorno::eUnSospeso($fattura))->toBeFalse();
});
