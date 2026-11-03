<?php

/**
 * Cosa resta scoperto: la finestra del portale che mostra il rifiuto (ha il suo vitest `EventDetailsDialogBeta52.test.ts`, con il Dialog vero); la
 * segnalazione «Sì, salda con il credito» (stessa rotta, stessa guardia, parte solo con una rata da versare); le altre tre risposte della rotta
 * (`Già pagata.`, `Già segnalato.`, `Segnalazione inviata con successo.`), che restano come sono; MySQL.
 *
 * La segnalazione «Ho pagato l'intera rata» e la guardia su una rata senza niente da versare.
 *
 * Su un promemoria con `importo_restante` zero o a credito (`partial` dopo il versamento del netto, `pending` con un credito) la segnalazione
 * crea un compito «Verifica incasso» per € 0,00 e un `prefill_importo` a zero. La guardia la rifiuta prima di scrivere, e la rilegge sotto lock
 * insieme allo stato: fra l'apertura della richiesta e il lock un incasso può aver già portato il restante a zero.
 *
 * Il rifiuto è un errore sul campo `evento` (`back()->withErrors`), non un flash: la rotta condivide `flash.message` e la pagina del portale non
 * mostra le altre chiavi. Dice perché e che cosa fare: «Su questa rata non resta niente da versare: la compensazione di € 10,00 la registra
 * l'amministratore. Non devi fare altro.» (con una compensazione da fare) oppure «Su questa rata non resta niente da versare. Non devi fare altro.».
 *
 * La guardia guarda il valore di `importo_restante` solo se la chiave c'è: un promemoria costruito a mano senza la chiave non si rifiuta
 * (difesa: sulla rotta resta il controllo di sempre sullo stato).
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 */

use App\Enums\EventoTipo;
use App\Models\Evento;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

const B52T_RIFIUTO_CON_COMPENSAZIONE = "Su questa rata non resta niente da versare: la compensazione di € 10,00 la registra l'amministratore. Non devi fare altro.";
const B52T_RIFIUTO_SENZA_COMPENSAZIONE = 'Su questa rata non resta niente da versare. Non devi fare altro.';

function b52tCompitiDiVerifica(): int
{
    return Evento::where('tipo', EventoTipo::VERIFICA_PAGAMENTO->value)->count();
}

it('T2.9 la segnalazione su un promemoria con nulla da versare si rifiuta con un errore sul campo, dice perché e che cosa fare, e non crea compiti', function () {
    [$s, $piano, $qC] = b52tK3($this);
    b52tIncasso($this, $s, 'bice', [$qC => 4000], 4000);
    $utente = b52tUtentePortale($s, 'bice');
    $evento = b52tEvento($s, $piano, 'bice', 1);

    $r = $this->actingAs($utente)->post(route('user.eventi.report_payment', $evento));

    // un errore sul campo e non un flash con una chiave che nessuna pagina del portale legge: la finestra lo riceve in `onError` e lo mostra
    $r->assertRedirect()->assertSessionHasErrors(['evento' => B52T_RIFIUTO_CON_COMPENSAZIONE]);
    expect($evento->fresh()->meta['status'])->toBe('partial');
    expect($evento->fresh()->meta)->not->toHaveKey('reported_at');
    expect(b52tCompitiDiVerifica())->toBe(0);
});

it('T2.9b senza niente da compensare la frase dice che non resta niente da versare e che non c\'è altro da fare', function () {
    $s = b52tScenario($this, '1.200,00', '240,00', soloEntrata: true);
    $piano = b52tPiano($this, $s);
    b52tApprova($this, $s, $piano);
    $evento = b52tEvento($s, $piano, 'bice', 1);
    // un credito puro nasce «paid» e il controllo di sempre risponde «Già pagata»: per arrivare alla guardia nuova serve un promemoria
    // non pagato con restante a credito, uno stato che il codice di oggi non scrive e che si costruisce a mano
    $evento->update(['meta' => array_merge($evento->meta, ['status' => 'pending'])]);
    $utente = b52tUtentePortale($s, 'bice');

    $r = $this->actingAs($utente)->post(route('user.eventi.report_payment', $evento));

    $r->assertRedirect()->assertSessionHasErrors(['evento' => B52T_RIFIUTO_SENZA_COMPENSAZIONE]);
    expect(b52tCompitiDiVerifica())->toBe(0);
});

it('T2.9c la guardia sotto lock: il promemoria letto all\'apertura è ancora da versare, ma nel frattempo l\'incasso ha portato il restante a zero → si rifiuta', function () {
    [$s, $piano] = b52tK3($this);
    $utente = b52tUtentePortale($s, 'bice');
    $evento = b52tEvento($s, $piano, 'bice', 1);
    expect([$evento->meta['status'], $evento->meta['importo_restante']])->toBe(['pending', 4000]);
    $dopoLIncasso = array_merge($evento->meta, ['status' => 'partial', 'importo_pagato' => 4000, 'importo_restante' => 0, 'da_compensare' => 1000]);
    // la riga cambia nel database appena il modello della richiesta è stato letto: la copia in memoria resta quella di prima
    $scattato = false;
    Evento::retrieved(function (Evento $e) use ($evento, $dopoLIncasso, &$scattato) {
        if (! $scattato && $e->id === $evento->id) {
            $scattato = true;
            DB::table('eventi')->where('id', $e->id)->update(['meta' => json_encode($dopoLIncasso)]);
        }
    });

    $r = $this->actingAs($utente)->post(route('user.eventi.report_payment', $evento));

    expect($scattato)->toBeTrue();
    $r->assertRedirect()->assertSessionHasErrors(['evento' => B52T_RIFIUTO_CON_COMPENSAZIONE]);
    expect(Evento::find($evento->id)->meta['status'])->toBe('partial');
    expect(b52tCompitiDiVerifica())->toBe(0);
});

it('T2.9d controprova: K3 alla creazione (restano € 40,00 da versare, € 10,00 da compensare) → la segnalazione è accettata e il compito dice € 40,00', function () {
    [$s, $piano] = b52tK3($this);
    $utente = b52tUtentePortale($s, 'bice');
    $evento = b52tEvento($s, $piano, 'bice', 1);

    $this->actingAs($utente)->post(route('user.eventi.report_payment', $evento))->assertSessionHas('success')->assertSessionHasNoErrors();

    $compito = Evento::where('tipo', EventoTipo::VERIFICA_PAGAMENTO->value)->firstOrFail();
    expect((int) $compito->meta['importo_dichiarato'])->toBe(4000);
    expect($compito->description)->toContain('€ 40,00');
    expect($evento->fresh()->meta['status'])->toBe('reported');
});

it('T2.9e controprova: un promemoria costruito a mano senza la chiave `importo_restante` non si rifiuta (la guardia guarda il valore solo se c\'è)', function () {
    [$s, $piano] = b52tK3($this);
    $utente = b52tUtentePortale($s, 'bice');
    $evento = b52tEvento($s, $piano, 'bice', 1);
    $meta = $evento->meta;
    unset($meta['importo_restante']);
    $evento->update(['meta' => $meta]);

    $this->actingAs($utente)->post(route('user.eventi.report_payment', $evento))->assertSessionHas('success')->assertSessionHasNoErrors();

    expect($evento->fresh()->meta['status'])->toBe('reported');
    expect(b52tCompitiDiVerifica())->toBe(1);
});
