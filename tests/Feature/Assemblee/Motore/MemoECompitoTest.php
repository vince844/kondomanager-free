<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission as Permesso;
use App\Models\Evento;
use App\Services\Assemblee\MemoriaDelCondominio;
use App\Services\Assemblee\OrdineDelGiorno;
use App\Services\Gestionale\Inbox\ElencoDellInbox;
use App\Services\Gestionale\Inbox\TipiDeiCompiti;
use App\Services\Gestionale\RispostePromemoria;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, R10 del progetto della tappa 2; decisioni 499 e 501): il memo e il suo compito sono una cosa sola. Senza data niente compito; con
 * la data il compito nasce quel giorno alle 09:00 come si legge, con il memo come `eventable` e `meta.assegnato_a` per «Le mie»; «Non serve più» e
 * «Ricordamelo» dal menu del compito passano dal memo; il punto che entra nell'ordine del giorno chiude il compito con la frase del programma, senza riga del
 * registro; il punto tolto rimette il memo «in attesa» e il compito torna. Salvare il memo è la riga «Creata: …».
 *
 * Che cosa non copre: la pagina dell'Action inbox e il menu del compito (sono della pagina); «Le mie» con le segnalazioni (lo provano i test dell'Inbox).
 */

beforeEach(fn () => ContestoDelRegistro::azzera());

it('senza data il memo non è un compito; con la data è un compito solo, quel giorno alle 09:00, con la riga «Creata»', function () {
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $senza = MemoriaDelCondominio::salva($s->c, ['testo' => 'Il portone'], $s->admin);
    expect($senza->evento_id)->toBeNull();

    $prima = ViaRoma12::ultimaRiga();
    $memo = MemoriaDelCondominio::salva($s->c, ['testo' => 'Ratifica della spesa urgente di € 1.800,00', 'origine_tipo' => 'fattura_passiva',
        'origine_id' => $fattura->id, 'ricordami_dal' => '2026-11-25', 'a_chi' => 'me'], $s->admin);
    $compito = Evento::query()->find($memo->evento_id);
    $righe = ViaRoma12::righe($prima, 'memo_assemblea');

    expect($compito)->not->toBeNull()
        ->and($compito->start_time->format('Y-m-d H:i'))->toBe('2026-11-25 09:00')
        ->and($compito->meta['type'])->toBe('memo_assemblea')
        ->and($compito->meta['assegnato_a'])->toBe($s->admin->id)
        ->and($compito->eventable_id)->toBe($memo->id)
        ->and($compito->meta['riga'])->toStartWith('Dal 25/11 · Via Roma 12')
        ->and($compito->meta['riga'])->toContain('Fattura n. 45 · Idraulica Bianchi · € 1.800,00')
        ->and(Evento::query()->where('meta->type', 'memo_assemblea')->count())->toBe(1)
        ->and($righe)->toHaveCount(1)
        ->and($righe[0]->riepilogo)->toBe('Creata: «Ratifica della spesa urgente di € 1.800,00», ricordamelo dal 25/11/2026, a te')
        ->and($righe[0]->scheda_tipo)->toBe('fattura_passiva')
        ->and($memo->origine_nome)->toBe('Fattura n. 45 · Idraulica Bianchi · € 1.800,00')
        ->and(TipiDeiCompiti::nome('memo_assemblea'))->toBe('Da portare in assemblea')
        ->and(TipiDeiCompiti::sottoDaiDati('memo_assemblea', $compito, null))->toBe($compito->meta['riga']);
});

it('«Le mie» trova il compito del memo per sé e per il collaboratore, non quello di tutto lo studio', function () {
    $s = ViaRoma12::nuovo($this);
    $marco = ViaRoma12::utente([Permesso::VIEW_ASSEMBLEE]);
    MemoriaDelCondominio::salva($s->c, ['testo' => 'A me', 'ricordami_dal' => '2026-11-20', 'a_chi' => 'me'], $s->admin);
    MemoriaDelCondominio::salva($s->c, ['testo' => 'A Marco', 'ricordami_dal' => '2026-11-20', 'a_chi' => 'collaboratore', 'assegnato_a' => $marco->id], $s->admin);
    MemoriaDelCondominio::salva($s->c, ['testo' => 'A tutti', 'ricordami_dal' => '2026-11-20', 'a_chi' => 'studio'], $s->admin);

    $elenco = new ElencoDellInbox(\App\Services\Gestionale\Inbox\FasceDeiCompiti::oggi());
    expect($elenco->leMie(ElencoDellInbox::filtri([], $s->admin)))->toBe(1)
        ->and($elenco->leMie(ElencoDellInbox::filtri([], $marco)))->toBe(1);
});

it('«Non serve più» e «Ricordamelo» dal menu del compito passano dal memo', function () {
    $s = ViaRoma12::nuovo($this);
    $uno = MemoriaDelCondominio::salva($s->c, ['testo' => 'Il portone', 'ricordami_dal' => '2026-11-20'], $s->admin);
    $due = MemoriaDelCondominio::salva($s->c, ['testo' => 'Il cancello', 'ricordami_dal' => '2026-11-20'], $s->admin);

    MemoriaDelCondominio::rispostaDelCompito(Evento::query()->find($uno->evento_id), RispostePromemoria::NON_SERVE, ['motivo' => 'Già deciso'], $s->admin);
    MemoriaDelCondominio::rispostaDelCompito(Evento::query()->find($due->evento_id), RispostePromemoria::RICORDAMELO, ['giorno' => '2026-12-02'], $s->admin);

    expect($uno->fresh()->stato->value)->toBe('non_serve')->and($uno->fresh()->chiuso_motivo)->toBe('Già deciso')
        ->and((bool) Evento::query()->find($uno->evento_id)->is_completed)->toBeTrue()
        ->and($due->fresh()->ricordami_dal->format('Y-m-d'))->toBe('2026-12-02')
        ->and(Evento::query()->find($due->evento_id)->start_time->format('Y-m-d H:i'))->toBe('2026-12-02 09:00');
});

it('il punto nell\'ordine del giorno chiude il compito con la frase del programma, senza riga; tolto il punto il compito torna', function () {
    $s = ViaRoma12::nuovo($this);
    $memo = MemoriaDelCondominio::salva($s->c, ['testo' => 'Il portone', 'ricordami_dal' => '2027-01-10'], $s->admin);
    $a = ViaRoma12::ordinaria($s);
    $compito = Evento::query()->find($memo->evento_id);
    $prima = ViaRoma12::ultimaRiga();

    $punto = MemoriaDelCondominio::nellOrdine($memo, $a, $s->admin);

    $risposta = \App\Models\Gestionale\RispostaPromemoria::query()->where('chiave', RispostePromemoria::chiaveDelCompito($compito->id))->first();
    expect((bool) $compito->fresh()->is_completed)->toBeTrue()
        ->and($risposta?->nota)->toBe('Chiuso: il punto è nell\'ordine del giorno del 15/12/2026.')
        ->and(ViaRoma12::righe($prima)->where('oggetto_tipo', 'compito')->count())->toBe(0)
        ->and($memo->fresh()->stato->value)->toBe('nel_punto');

    OrdineDelGiorno::togli($punto, $s->admin);
    $tornato = $memo->fresh();
    expect($tornato->stato->value)->toBe('in_attesa')
        ->and($tornato->evento_id)->not->toBe($compito->id)
        ->and((bool) Evento::query()->find($tornato->evento_id)->is_completed)->toBeFalse();
});

it('la prossima azione del compito: «Prepara l\'assemblea» senza un\'assemblea in preparazione, «Mettilo nell\'ordine del giorno del 15/12» con', function () {
    // Le rotte arrivano con le pagine: qui due rotte finte con i loro nomi, per provare i due rami.
    \Illuminate\Support\Facades\Route::get('admin/gestionale/{condominio}/assemblee/create', fn () => '')->name('admin.gestionale.assemblee.create');
    \Illuminate\Support\Facades\Route::get('admin/gestionale/{condominio}/assemblee/{assemblea}', fn () => '')->name('admin.gestionale.assemblee.show');
    app('router')->getRoutes()->refreshNameLookups();
    $s = ViaRoma12::nuovo($this);
    $memo = MemoriaDelCondominio::salva($s->c, ['testo' => 'Il portone', 'ricordami_dal' => '2026-11-20'], $s->admin);
    $compito = Evento::query()->find($memo->evento_id);

    expect(TipiDeiCompiti::azione('memo_assemblea', $compito))->toBe(['testo' => 'Prepara l\'assemblea',
        'url' => '/admin/gestionale/' . $s->c->id . '/assemblee/create?memo=' . $memo->id]);
    $a = ViaRoma12::ordinaria($s);
    expect(TipiDeiCompiti::azione('memo_assemblea', $compito)['testo'])->toBe('Mettilo nell\'ordine del giorno del 15/12');
});

it('«Non serve più» vale solo per un memo in attesa: il memo nel punto non si chiude da qui, e togliendo il punto non torna in vita un memo chiuso', function () {
    $s = ViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s);
    $memo = MemoriaDelCondominio::salva($s->c, ['testo' => 'Sostituzione del portone', 'ricordami_dal' => '2026-12-01'], $s->admin);
    $punto = MemoriaDelCondominio::nellOrdine($memo, $a, $s->admin);

    expect(fn () => MemoriaDelCondominio::nonServe($memo->fresh(), 'Già deciso dal consiglio', $s->admin))
        ->toThrow(\Illuminate\Validation\ValidationException::class, 'Questo memo è già un punto dell\'ordine del giorno: per toglierlo, togli il punto.');
    expect($memo->fresh()->stato->value)->toBe('nel_punto');

    // Un memo chiuso da un'altra strada (la delibera della ratifica) resta chiuso quando il punto si toglie.
    $memo->fresh()->update(['stato' => 'deliberato']);
    OrdineDelGiorno::togli($punto->fresh(), $s->admin);
    $aperti = Evento::query()->where('meta->type', 'memo_assemblea')->where('is_completed', false)->count();
    expect($memo->fresh()->stato->value)->toBe('deliberato')->and($aperti)->toBe(0);
});

it('un memo chiuso con «Non serve più» non si cambia, e non rifà il compito', function () {
    $s = ViaRoma12::nuovo($this);
    $memo = MemoriaDelCondominio::salva($s->c, ['testo' => 'Sostituzione del portone'], $s->admin);
    MemoriaDelCondominio::nonServe($memo, 'Fatto dal consiglio', $s->admin);

    expect(fn () => MemoriaDelCondominio::aggiorna($memo->fresh(), ['ricordami_dal' => '2026-12-01'], $s->admin))
        ->toThrow(\Illuminate\Validation\ValidationException::class, 'Questo memo non è più in attesa: non si cambia.');
    $aperti = Evento::query()->where('meta->type', 'memo_assemblea')->where('is_completed', false)->count();
    expect($memo->fresh()->stato->value)->toBe('non_serve')->and($aperti)->toBe(0);
});

it('due memo sulla stessa scheda sono un punto solo: entrano tutti e due, e il compito del primo si chiude', function () {
    $s = ViaRoma12::nuovo($this);
    $gestione = \App\Models\Gestione::query()->create(['condominio_id' => $s->c->id, 'nome' => 'Facciata 2026', 'tipo' => 'straordinaria',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    $primo = MemoriaDelCondominio::salva($s->c, ['testo' => 'Lavori sulla facciata', 'origine_tipo' => 'gestione', 'origine_id' => $gestione->id,
        'ricordami_dal' => '2026-12-01'], $s->admin);
    $secondo = MemoriaDelCondominio::salva($s->c, ['testo' => 'Lavori sulla facciata, con il preventivo', 'origine_tipo' => 'gestione', 'origine_id' => $gestione->id], $s->admin);
    $daTe = collect(\App\Services\Assemblee\ProposteDellOrdineDelGiorno::per($s->c, null))->firstWhere('chiave', 'da_te')['punti'];

    $a = ViaRoma12::ordinaria($s, $daTe);

    $chiavi = $a->punti()->pluck('chiave_fonte')->all();
    expect(count($daTe))->toBe(1)
        ->and($primo->fresh()->stato->value)->toBe('nel_punto')->and($secondo->fresh()->stato->value)->toBe('nel_punto')
        ->and($primo->fresh()->punto_id)->toBe($secondo->fresh()->punto_id)
        ->and((bool) Evento::query()->find($primo->fresh()->evento_id)->is_completed)->toBeTrue()
        ->and(array_count_values($chiavi)['gestione:' . $gestione->id])->toBe(1);

    // Un terzo memo sulla stessa gestione, salvato dopo: entra da sé nel punto che c'è, senza compito; «Mettilo nell'ordine del giorno» non lo raddoppia.
    $terzo = MemoriaDelCondominio::salva($s->c, ['testo' => 'Facciata: l\'impresa', 'origine_tipo' => 'gestione', 'origine_id' => $gestione->id,
        'ricordami_dal' => '2026-12-01'], $s->admin);
    $punto = $a->punti()->where('chiave_fonte', 'gestione:' . $gestione->id)->first();
    expect($terzo->stato->value)->toBe('nel_punto')->and($terzo->punto_id)->toBe($punto->id)->and($terzo->evento_id)->toBeNull()
        ->and(fn () => MemoriaDelCondominio::nellOrdine($terzo->fresh(), $a->fresh(), $s->admin))->toThrow(\Illuminate\Validation\ValidationException::class)
        ->and(array_count_values($a->punti()->pluck('chiave_fonte')->all())['gestione:' . $gestione->id])->toBe(1);

    // Tenuta fuori con la delibera sul punto: si chiudono tutti e tre.
    \App\Services\Assemblee\StatiDellAssemblea::tenutaFuoriDalProgramma($a->fresh(), $s->admin, [$punto->id => ['esito' => 'approvata']], '2026-11-18');
    expect([$primo->fresh()->stato->value, $secondo->fresh()->stato->value, $terzo->fresh()->stato->value])->toBe(['deliberato', 'deliberato', 'deliberato']);
});

it('lo sforo è già un punto dell\'ordinaria: il memo nato dopo sulla stessa fattura entra in quel punto, e la finestra lo dice', function () {
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $programma = collect(\App\Services\Assemblee\ProposteDellOrdineDelGiorno::per($s->c, null))->firstWhere('chiave', 'dal_programma')['punti'];
    $a = ViaRoma12::ordinaria($s, $programma);
    $numero = $a->punti()->where('chiave_fonte', 'fattura:' . $fattura->id)->value('numero');

    $risposta = $this->actingAs($s->admin)->getJson(route('admin.gestionale.memo.proposta', ['condominio' => $s->c->id, 'origine_tipo' => 'fattura_passiva',
        'origine_id' => $fattura->id]))->assertOk()->json();
    expect($risposta['gia_proposto'])->toBe('È già il punto ' . $numero . ' dell\'ordine del giorno del 15/12/2026: il tuo memo entra nello stesso punto, con la tua nota.');

    $r = $this->actingAs($s->admin)->postJson(route('admin.gestionale.memo.store', $s->c), ['testo' => 'Ratifica della colonna',
        'origine_tipo' => 'fattura_passiva', 'origine_id' => $fattura->id])->assertCreated();
    $memo = \App\Models\Assemblee\MemoDellAssemblea::query()->findOrFail($r->json('memo.id'));
    expect(array_count_values($a->punti()->pluck('chiave_fonte')->all())['fattura:' . $fattura->id])->toBe(1)
        ->and($memo->stato->value)->toBe('nel_punto')
        ->and($r->json('frase'))->toBe('Adesso: è il punto ' . $numero . ' dell\'ordine del giorno del 15/12/2026, con la tua nota.');
});
