<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission as Permesso;
use App\Models\Evento;
use App\Models\Gestionale\RispostaPromemoria;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Gestionale\RispostePromemoria;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;

require_once __DIR__ . '/../Motore/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, R12 del progetto della tappa 2 con la decisione 561; scene 1a–1d della bozza delle scene della tappa 2): la ratifica dalla rotta
 * delle pagine (`fatture.ratifica`). La fattura n. 45 in sforo si ratifica con la delibera del 18/11/2026: approvata, la delibera nel collegamento, i due
 * compiti dello sforo chiusi con la frase della delibera, una riga del registro con le sue parole. La stessa fattura, prima sbloccata con «Paga per
 * l'urgenza» (`approva-sforo`, com'è oggi), resta «da ratificare» nell'elenco e nella pagina, con il motivo; ratificata dopo, la pagina dice «Ratificata con
 * la delibera del 18/11/2026, punto 1» e ricorda lo sblocco di prima. Una delibera di Corso Italia 3 chiesta da Via Roma 12 è 404; senza «Gestisce le
 * assemblee» 403.
 *
 * Che cosa non copre: le due finestre a video (`PagaPerLUrgenza.vue`, `RatificaDellaSpesa.vue`, provate a mano); la pagina del nuovo pagamento (le pendenze
 * con lo sforo); la pagina della scrittura (la stessa lettura, `RatificaPerLePagine::perLeScritture`).
 */

beforeEach(function () {
    $this->withoutVite();
});

/** Un compito dello sforo della fattura, come lo crea il listener della fattura. */
function rdpCompitoDelloSforo($s, $fattura, string $tipo): Evento
{
    $e = Evento::create(['title' => $tipo, 'start_time' => now(), 'end_time' => now()->addHour(), 'visibility' => 'hidden', 'is_approved' => true,
        'meta' => ['type' => $tipo, 'requires_action' => true, 'context' => ['fattura_id' => $fattura->id]]]);
    $e->condomini()->attach($s->c->id);

    return $e;
}

it('dalla rotta: la fattura in sforo si ratifica con la delibera, i compiti si chiudono, una riga del registro', function () {
    $this->travelTo(Carbon::parse('2026-11-19 10:00', 'Europe/Rome'));
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $convocare = rdpCompitoDelloSforo($s, $fattura, 'convocazione_urgenza');
    $ratifiche = rdpCompitoDelloSforo($s, $fattura, 'ratifica_sforo');
    $d = ViaRoma12::delibera($s, '2026-11-18', 'Ratifica della spesa urgente', 'approvata', '42', [], ['oggetto_tipo' => 'fattura_passiva', 'oggetto_id' => $fattura->id]);
    $prima = ViaRoma12::ultimaRiga();

    $this->actingAs($s->admin)->from('/fatture')->post(route('admin.gestionale.fatture.ratifica', [$s->c, $fattura]), ['delibera_id' => $d->id, 'nota' => 'Con la relazione dell\'idraulico'])
        ->assertRedirect('/fatture')->assertSessionHasNoErrors()->assertSessionHas('message.type', 'success');

    $f = $fattura->fresh();
    $righe = ViaRoma12::righe($prima, 'fattura_passiva');
    expect($f->stato_approvazione)->toBe('approvata')
        ->and($f->dati_extra['ratifica_assembleare']['deliberata_il'])->toBe('2026-11-18')
        ->and(CollegamentiDelleDelibere::delibera($f, 'ratifica')?->id)->toBe($d->id)
        ->and((bool) $convocare->fresh()->is_completed)->toBeTrue()->and((bool) $ratifiche->fresh()->is_completed)->toBeTrue()
        ->and(RispostaPromemoria::query()->where('chiave', RispostePromemoria::chiaveDelCompito($ratifiche->id))->value('nota'))
            ->toBe('Chiuso dalla delibera del 18/11/2026, punto 1 (verbale n. 42)')
        ->and($righe)->toHaveCount(1)
        ->and($righe[0]->riepilogo)->toStartWith('Fattura n. 45 di Idraulica Bianchi ratificata con delibera del 18/11/2026, punto 1 (verbale n. 42)');

    $pagina = $this->actingAs($s->admin)->get(route('admin.gestionale.fatture.show', [$s->c, $fattura]))->assertOk()->viewData('page')['props'];
    expect($pagina['ratifica']['stato'])->toBe('ratificata')
        ->and($pagina['ratifica']['delibera']['frase'])->toBe('Ratificata con la delibera del 18/11/2026, punto 1')
        ->and($pagina['ratifica']['delibera']['numero_verbale'])->toBe('42')
        ->and($pagina['ratifica']['delibera']['assemblea_id'])->toBe($d->assemblea_id)
        ->and($pagina['ratifica']['delibera']['nota'])->toBe('Con la relazione dell\'idraulico')
        ->and($pagina['ratifica']['sbloccata'])->toBeNull();
});

it('pagata prima per l\'urgenza: resta «da ratificare» nell\'elenco e nella pagina, con il motivo; ratificata dopo, la pagina ricorda lo sblocco', function () {
    $this->travelTo(Carbon::parse('2026-11-13 10:00', 'Europe/Rome'));
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.approva-sforo', [$s->c, $fattura]), ['note' => 'Perdita dalla colonna dell\'acqua: riparata il 12/11'])
        ->assertSessionHasNoErrors();

    $elenco = $this->actingAs($s->admin)->get(route('admin.gestionale.fatture.index', [$s->c]))->assertOk()->viewData('page')['props'];
    $riga = collect($elenco['fatture']['data'])->firstWhere('id', $fattura->id);
    $pagina = $this->actingAs($s->admin)->get(route('admin.gestionale.fatture.show', [$s->c, $fattura]))->viewData('page')['props'];
    expect($riga['aspetta_la_ratifica'])->toBeTrue()->and($riga['ratifica'])->toBe('da_ratificare')
        ->and($pagina['ratifica']['stato'])->toBe('da_ratificare')
        ->and($pagina['ratifica']['in_sforo'])->toBeFalse()
        ->and($pagina['ratifica']['sbloccata']['motivo'])->toBe('Perdita dalla colonna dell\'acqua: riparata il 12/11')
        ->and($pagina['ratifica']['sbloccata']['il'])->toBe('13/11/2026')
        ->and($pagina['ratifica']['in_assemblea']['frase'])->toStartWith('Proposta fra i punti della prossima assemblea: «Comunicazione dei lavori urgenti e ratifica della spesa di € 1.800,00')
        // Lo sblocco di oggi si riconosce dalla chiave `urgenza`: senza, la nota è quella di una ratifica di prima della 1.12.
        ->and($fattura->fresh()->dati_extra['ratifica_assembleare']['urgenza'])->toBeTrue();

    $this->travelTo(Carbon::parse('2026-11-19 10:00', 'Europe/Rome'));
    $d = ViaRoma12::delibera($s, '2026-11-18', 'Comunicazione dei lavori urgenti e ratifica della spesa', 'approvata', '42');
    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.ratifica', [$s->c, $fattura]), ['delibera_id' => $d->id])->assertSessionHasNoErrors();

    $elenco = $this->actingAs($s->admin)->get(route('admin.gestionale.fatture.index', [$s->c]))->viewData('page')['props'];
    $riga = collect($elenco['fatture']['data'])->firstWhere('id', $fattura->id);
    $pagina = $this->actingAs($s->admin)->get(route('admin.gestionale.fatture.show', [$s->c, $fattura]))->viewData('page')['props'];
    expect($riga['aspetta_la_ratifica'])->toBeFalse()->and($riga['ratifica'])->toBe('ratificata')
        ->and($pagina['ratifica']['stato'])->toBe('ratificata')
        ->and($pagina['ratifica']['delibera']['frase'])->toBe('Ratificata con la delibera del 18/11/2026, punto 1')
        ->and($pagina['ratifica']['sbloccata']['il'])->toBe('13/11/2026')
        ->and($pagina['ratifica']['sbloccata']['motivo'])->toBe('Perdita dalla colonna dell\'acqua: riparata il 12/11');
});

it('una delibera di un altro condominio è 404, e la fattura resta da ratificare', function () {
    $this->travelTo(Carbon::parse('2026-11-19 10:00', 'Europe/Rome'));
    $s = ViaRoma12::nuovo($this);
    $altro = ViaRoma12::nuovo($this, 'Corso Italia 3');
    $fattura = ViaRoma12::fatturaInSforo($s);
    $dAltrove = ViaRoma12::delibera($altro, '2026-11-18', 'Ratifica della spesa urgente', 'approvata', '7');

    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.ratifica', [$s->c, $fattura]), ['delibera_id' => $dAltrove->id])->assertNotFound();
    expect($fattura->fresh()->stato_approvazione)->toBe('sforo_motivato')->and(CollegamentiDelleDelibere::delibera($fattura->fresh(), 'ratifica'))->toBeNull();
});

it('senza «Gestisce le assemblee» la ratifica è 403, anche con «Vede le assemblee»', function () {
    $this->travelTo(Carbon::parse('2026-11-19 10:00', 'Europe/Rome'));
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $d = ViaRoma12::delibera($s, '2026-11-18', 'Ratifica della spesa urgente', 'approvata', '42');
    $marco = ViaRoma12::utente([Permesso::VIEW_ASSEMBLEE]);
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $marco->givePermissionTo('Accesso pannello amministratore');
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $this->actingAs($marco)->post(route('admin.gestionale.fatture.ratifica', [$s->c, $fattura]), ['delibera_id' => $d->id])->assertForbidden();
    expect($fattura->fresh()->stato_approvazione)->toBe('sforo_motivato');
});

it('una ratifica di prima: «Ratificata» nell\'elenco e nella pagina, con la nota; «Collega la delibera» dalla rotta della ratifica', function () {
    $this->travelTo(Carbon::parse('2026-11-19 10:00', 'Europe/Rome'));
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s, '12', 90000, 'approvata');
    $fattura->update(['dati_extra' => ['ratifica_assembleare' => ['note' => 'Ratificata nell\'assemblea del 10/05/2025', 'approvato_il' => '2025-05-12T10:00:00+00:00',
        'approvato_da' => $s->admin->id]]]);

    $elenco = $this->actingAs($s->admin)->get(route('admin.gestionale.fatture.index', [$s->c]))->assertOk()->viewData('page')['props'];
    $riga = collect($elenco['fatture']['data'])->firstWhere('id', $fattura->id);
    $pagina = $this->actingAs($s->admin)->get(route('admin.gestionale.fatture.show', [$s->c, $fattura]))->viewData('page')['props'];
    expect($riga['aspetta_la_ratifica'])->toBeFalse()->and($riga['ratifica'])->toBe('ratificata')
        ->and($pagina['ratifica']['stato'])->toBe('ratificata')
        ->and($pagina['ratifica']['di_prima'])->toBe(['il' => '12/05/2025', 'nota' => 'Ratificata nell\'assemblea del 10/05/2025'])
        ->and($pagina['ratifica']['sbloccata'])->toBeNull()->and($pagina['ratifica']['in_assemblea'])->toBeNull();

    $d = ViaRoma12::delibera($s, '2025-05-10', 'Ratifica della spesa', 'approvata', '3');
    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.ratifica', [$s->c, $fattura]), ['delibera_id' => $d->id])->assertSessionHasNoErrors();
    $pagina = $this->actingAs($s->admin)->get(route('admin.gestionale.fatture.show', [$s->c, $fattura]))->viewData('page')['props'];
    expect($pagina['ratifica']['delibera']['frase'])->toBe('Ratificata con la delibera del 10/05/2025, punto 1')
        ->and($pagina['ratifica']['di_prima']['nota'])->toBe('Ratificata nell\'assemblea del 10/05/2025');
});

it('una delibera di un giorno prima della fattura: la ratifica si registra, e il messaggio dice di controllarla', function () {
    $this->travelTo(Carbon::parse('2026-11-19 10:00', 'Europe/Rome'));
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $vecchia = ViaRoma12::delibera($s, '2025-05-14', 'Ratifica della spesa', 'approvata', '7');

    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.ratifica', [$s->c, $fattura]), ['delibera_id' => $vecchia->id])->assertSessionHasNoErrors();
    expect((string) session('message.message'))->toContain('La delibera è del 14/05/2025, prima della fattura del 12/11/2026: controlla che sia quella giusta.')
        ->and(\App\Services\Assemblee\RatificaDelloSforo::avvisoDellaData($fattura->fresh(), $vecchia))->not->toBeNull();

    $giusta = ViaRoma12::delibera($s, '2026-11-18', 'Ratifica della spesa urgente', 'approvata', '42');
    expect(\App\Services\Assemblee\RatificaDelloSforo::avvisoDellaData($fattura->fresh(), $giusta))->toBeNull();
});

it('pagata per l\'urgenza: il motivo va nel punto proposto, sotto in «Prepara l\'assemblea», nel testo per i condòmini e nei dati del punto per l\'avviso e la sala', function () {
    $this->travelTo(Carbon::parse('2026-11-13 10:00', 'Europe/Rome'));
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $motivo = 'Perdita dalla colonna dell\'acqua: riparata il 12/11 per non allagare gli appartamenti sotto';
    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.approva-sforo', [$s->c, $fattura]), ['note' => $motivo])->assertSessionHasNoErrors();

    $proposto = collect(\App\Services\Assemblee\ProposteDellOrdineDelGiorno::per($s->c, null))->firstWhere('chiave', 'dal_programma')['punti'][0];
    expect($proposto['testo'])->toBe('Comunicazione dei lavori urgenti e ratifica della spesa di € 1.800,00 per riparazione della colonna dell\'acqua (fattura n. 45 di Idraulica Bianchi)')
        // Sbloccata e non ancora pagata: «Sbloccata per l'urgenza»; «Pagata per l'urgenza» dopo il pagamento (`CateneDeiPassiTest`, catena 3).
        ->and($proposto['frase'])->toBe('Sbloccata per l\'urgenza: va riferita alla prima assemblea (art. 1135 c. 2 c.c.). Perché era urgente: ' . $motivo . '.')
        ->and($proposto['testo_pubblico'])->toBe("Comunicazione dei lavori urgenti e ratifica della spesa di € 1.800,00 per riparazione della colonna dell'acqua\nPerché era urgente: " . $motivo . '.')
        ->and($proposto['dati'])->toBe(['motivazione' => $motivo, 'motivazione_di' => 'urgenza'])
        ->and($proposto['spuntato'])->toBeTrue();

    // Preparata l'assemblea, il punto tiene il motivo: l'avviso e la sala lo leggono da `dati.motivazione` (con `dati.motivazione_di`).
    $a = ViaRoma12::ordinaria($s, [$proposto]);
    $punto = $a->punti()->where('chiave_fonte', 'fattura:' . $fattura->id)->sole();
    expect($punto->dati)->toBe(['motivazione' => $motivo, 'motivazione_di' => 'urgenza'])->and($punto->testo_pubblico)->toContain('Perché era urgente: ' . $motivo);

    // Un memo sulla stessa fattura porta lo stesso motivo nel punto.
    $memo = \App\Services\Assemblee\MemoriaDelCondominio::salva($s->c, ['testo' => 'La colonna dell\'acqua', 'origine_tipo' => 'fattura_passiva', 'origine_id' => $fattura->id], $s->admin);
    expect(\App\Services\Assemblee\MemoriaDelCondominio::comeProposta($memo)['dati'])->toBe(['motivazione' => $motivo, 'motivazione_di' => 'urgenza']);
});

it('la motivazione dello sforo, scritta alla registrazione, va nel punto della spesa oltre il preventivo non pagata; pagata per l\'urgenza vale il motivo dell\'urgenza', function () {
    $this->travelTo(Carbon::parse('2026-11-13 10:00', 'Europe/Rome'));
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $sforo = 'Rottura imprevista della colonna montante, non prevista nel preventivo';
    $fattura->update(['dati_extra' => ['override_budget' => ['motivazione' => $sforo, 'importo_sforo' => 30000, 'strategia_rientro' => 'conguaglio_fine_anno']]]);

    $proposto = collect(\App\Services\Assemblee\ProposteDellOrdineDelGiorno::per($s->c, null))->firstWhere('chiave', 'dal_programma')['punti'][0];
    expect($proposto['testo_pubblico'])->toBe("Ratifica della spesa oltre il preventivo di € 1.800,00 per riparazione della colonna dell'acqua\nMotivazione: " . $sforo . '.')
        ->and($proposto['frase'])->toBe('La spesa supera il preventivo: la ratifica è dell\'assemblea. Motivazione: ' . $sforo . '.')
        ->and($proposto['dati'])->toBe(['motivazione' => $sforo, 'motivazione_di' => 'sforo']);
    // La finestra «Paga per l'urgenza» la riceve per partire già scritta.
    $pagina = $this->actingAs($s->admin)->get(route('admin.gestionale.fatture.show', [$s->c, $fattura]))->viewData('page')['props'];
    expect($pagina['ratifica']['sforo']['motivazione'])->toBe($sforo);

    // Pagata per l'urgenza con un motivo suo: nel punto va l'ultimo, quello dell'urgenza.
    $urgenza = 'Perdita dalla colonna dell\'acqua: riparata il 12/11 per non allagare gli appartamenti sotto';
    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.approva-sforo', [$s->c, $fattura]), ['note' => $urgenza])->assertSessionHasNoErrors();
    $proposto = collect(\App\Services\Assemblee\ProposteDellOrdineDelGiorno::per($s->c, null))->firstWhere('chiave', 'dal_programma')['punti'][0];
    expect($proposto['testo_pubblico'])->toEndWith("\nPerché era urgente: " . $urgenza . '.')->not->toContain('Motivazione:')
        ->and($proposto['dati'])->toBe(['motivazione' => $urgenza, 'motivazione_di' => 'urgenza']);
});

it('senza un motivo dell\'urgenza il punto non stampa niente in più: la fattura in sforo e la ratifica di prima', function () {
    $this->travelTo(Carbon::parse('2026-11-13 10:00', 'Europe/Rome'));
    $s = ViaRoma12::nuovo($this);
    $sforo = ViaRoma12::fatturaInSforo($s);
    $diPrima = ViaRoma12::fatturaInSforo($s, '12', 90000, 'approvata');
    $diPrima->update(['dati_extra' => ['ratifica_assembleare' => ['note' => 'Ratificata nell\'assemblea del 10/05/2025', 'approvato_il' => '2025-05-12T10:00:00+00:00',
        'approvato_da' => $s->admin->id]]]);

    $p = \App\Services\Assemblee\PropostaDelPunto::perLaScheda($sforo->fresh());
    $q = \App\Services\Assemblee\PropostaDelPunto::perLaScheda($diPrima->fresh());
    expect($p['testo_pubblico'])->not->toContain('Perché era urgente')->and($p['dati'] ?? null)->toBeNull()
        ->and($q['testo_pubblico'])->not->toContain('Perché era urgente')->and($q['dati'] ?? null)->toBeNull();
});

it('dopo «Sblocca il pagamento» il messaggio dice che è sbloccato per l\'urgenza e che la ratifica arriva con la delibera, non che è ratificata', function () {
    $this->travelTo(Carbon::parse('2026-11-13 10:00', 'Europe/Rome'));
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $r = $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.approva-sforo', [$s->c, $fattura]), ['note' => 'Perdita dalla colonna dell\'acqua'])
        ->assertSessionHasNoErrors();
    $messaggio = json_encode(session()->all(), JSON_UNESCAPED_UNICODE);
    expect($messaggio)->toContain('Pagamento sbloccato per l\'urgenza: la paghi adesso. La ratifica arriva con la delibera della prima assemblea di ' . $s->c->nome . '.')
        ->and($messaggio)->not->toContain('ratificata con successo');
});

it('il punto proposto della fattura sbloccata arriva alla pagina senza il motivo, che ha il suo dato', function () {
    $this->travelTo(Carbon::parse('2026-11-13 10:00', 'Europe/Rome'));
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $motivo = 'Perdita dalla colonna dell\'acqua';
    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.approva-sforo', [$s->c, $fattura]), ['note' => $motivo])->assertSessionHasNoErrors();

    $pagina = $this->actingAs($s->admin)->get(route('admin.gestionale.fatture.show', [$s->c, $fattura]))->viewData('page')['props'];
    $in = $pagina['ratifica']['in_assemblea'];
    expect($in['proposta'])->toBeTrue()->and($in['assemblea_id'])->toBeNull()->and($in['numero'])->toBeNull()->and($in['il'])->toBeNull()
        ->and($in['testo'])->toStartWith('Comunicazione dei lavori urgenti e ratifica della spesa di € 1.800,00')
        ->and($in['testo'])->not->toContain('Perché era urgente')->not->toContain("\n")
        ->and($pagina['ratifica']['sbloccata']['motivo'])->toBe($motivo);

    // Il punto è già nell'ordine del giorno di un'assemblea: il numero, il giorno e l'assemblea, e il testo senza il motivo.
    $proposto = collect(\App\Services\Assemblee\ProposteDellOrdineDelGiorno::per($s->c, null))->firstWhere('chiave', 'dal_programma')['punti'][0];
    $assemblea = ViaRoma12::ordinaria($s, [$proposto]);
    $in = $this->actingAs($s->admin)->get(route('admin.gestionale.fatture.show', [$s->c, $fattura]))->viewData('page')['props']['ratifica']['in_assemblea'];
    expect($in['proposta'])->toBeFalse()->and($in['assemblea_id'])->toBe((int) $assemblea->id)->and($in['numero'])->toBe(1)->and($in['il'])->toBe('15/12/2026')
        ->and($in['testo'])->not->toContain('Perché era urgente')->not->toContain("\n");
});
