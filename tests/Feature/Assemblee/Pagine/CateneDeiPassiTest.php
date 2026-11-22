<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Evento;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\RispostaPromemoria;
use App\Services\Assemblee\OrdineDelGiorno;
use App\Services\Assemblee\ProposteDellOrdineDelGiorno;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

require_once __DIR__ . '/../Convocazione/ConvocazioneDiViaRoma12.php';
require_once __DIR__ . '/../../Gestionale/Beta52VoceEntrataScenari.php';

/**
 * 1.12.0-beta.13: le catene dei passi guidati, ognuna da un capo all'altro, dalle rotte, con ciò che l'amministratore vede ai due capi.
 *
 * 1. La stampa delle lettere → nessun cambio di stato (la pagina dice «In preparazione», date e punti si cambiano); la prima spedizione registrata →
 *    «convocata» → date e punti bloccati → il passo «Convocazione» resta quello di adesso.
 * 2. L'ultima spedizione registrata → il compito «Spedire la convocazione» dell'Action inbox chiuso, con la sua frase → il passo «Convocazione» fatto.
 * 3. Il pagamento della fattura sbloccata per l'urgenza → «Pagata per l'urgenza» nella pagina della fattura e nel punto proposto per la prossima assemblea.
 *
 * Che cosa non copre: come la pagina disegna le parole (i vitest della strada, della scheda e della pagina della fattura).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    $this->withoutVite();
});
afterEach(fn () => Carbon::setTestNow());

/** Le prop della pagina dell'assemblea. @return array<string, mixed> */
function cateneDeiPassiPagina($t, Beta51Scenario $s, $a): array
{
    return $t->get(route('admin.gestionale.assemblee.show', [$s->c, $a]))->assertOk()->viewData('page')['props'];
}

/** Gli id degli ultimi invii delle persone date. @param list<string> $chi @return list<int> */
function cdpInvii($a, Beta51Scenario $s, array $chi): array
{
    return array_map(fn ($x) => (int) ConvocazioneDiViaRoma12::invio($a, $s, $x)->id, $chi);
}

it('catena 1: la stampa non cambia lo stato; la prima spedizione registrata fa «convocata», blocca date e punti, e il passo «Convocazione» resta di adesso', function () {
    ConvocazioneDiViaRoma12::oggi();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s, ['tabella_id' => $s->tabelle['Proprietà']->id]);
    $this->actingAs($s->admin);
    $sedute = [['convocazione' => 'prima', 'numero' => 1, 'giorno' => '2026-12-14', 'ora' => '08:00'],
        ['convocazione' => 'seconda', 'numero' => 1, 'giorno' => '2026-12-15', 'ora' => '18:30']];

    // Un capo: «Stampa le lettere». La pagina dice ancora «In preparazione», e date e punti si cambiano.
    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    $pagina = cateneDeiPassiPagina($this, $s, $a);
    expect($pagina['assemblea']['stato'])->toBe('in_preparazione')->and($pagina['assemblea']['stato_parola'])->toBe('In preparazione')
        ->and($pagina['assemblea']['in_preparazione'])->toBeTrue()
        ->and($pagina['passi'][2])->toMatchArray(['stato' => 'adesso', 'riga' => '0 su 9 raggiunte · 9 da spedire · 1 senza un mezzo valido']);
    $this->patch(route('admin.gestionale.assemblee.sedute', [$s->c, $a]), ['sedute' => $sedute])->assertSessionHasNoErrors();

    // L'altro capo: la raccomandata di Neri Carla registrata spedita. «Convocata», date e punti bloccati, il passo resta da finire.
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => cdpInvii($a, $s, ['carla']), 'canale' => 'raccomandata',
        'data' => '2026-11-26'])->assertSessionHasNoErrors();
    $pagina = cateneDeiPassiPagina($this, $s, $a);
    expect($pagina['assemblea']['stato'])->toBe('convocata')->and($pagina['assemblea']['stato_parola'])->toBe('Convocata')
        ->and($pagina['assemblea']['in_preparazione'])->toBeFalse()
        ->and($pagina['passi'][0]['stato'])->toBe('fatto')
        ->and($pagina['passi'][2])->toMatchArray(['stato' => 'adesso', 'riga' => '0 su 9 raggiunte · 8 da spedire · 1 senza un mezzo valido']);
    $this->patch(route('admin.gestionale.assemblee.sedute', [$s->c, $a]), ['sedute' => $sedute])
        ->assertSessionHasErrors(['assemblea' => OrdineDelGiorno::FRASE_NON_SI_CAMBIA]);
    $this->post(route('admin.gestionale.assemblee.punti.store', [$s->c, $a]), ['testo' => 'Un punto in più', 'materia' => 'altro', 'maggioranza' => 'ordinaria'])
        ->assertSessionHasErrors(['assemblea' => OrdineDelGiorno::FRASE_NON_SI_CAMBIA]);
});

it('catena 2: l\'ultima spedizione chiude il compito dell\'Action inbox e fa il passo «Convocazione»', function () {
    ConvocazioneDiViaRoma12::oggi();
    ConvocazioneDiViaRoma12::conLaCasella();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $s->persone['bianchi']->forceFill(['indirizzo' => 'Via Roma 12, 20121 Milano'])->saveQuietly();
    $a = ConvocazioneDiViaRoma12::soloOrdinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]))->assertRedirect();
    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => cdpInvii($a, $s, ['marco', 'carla', 'gallo', 'bianchi']),
        'canale' => 'raccomandata', 'data' => '2026-11-26'])->assertSessionHasNoErrors();
    $compito = Evento::query()->where('meta->type', 'spedire_convocazione')->where('meta->context->assemblea_id', $a->id)->firstOrFail();

    // Un capo: manca solo la consegna a mano di Russo Anna. Il compito è aperto, il passo è quello di adesso.
    expect($compito->is_completed)->toBeFalse()->and($compito->description)->toBe('Assemblea ordinaria del 15/12/2026: 1 avviso da spedire.')
        ->and(cateneDeiPassiPagina($this, $s, $a)['passi'][2]['stato'])->toBe('adesso');

    // L'altro capo: l'ultima spedizione registrata. Il compito si chiude con la sua frase; il passo «Convocazione» è fatto.
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => cdpInvii($a, $s, ['anna']), 'canale' => 'a_mano',
        'data' => '2026-11-26'])->assertSessionHasNoErrors();
    $pagina = cateneDeiPassiPagina($this, $s, $a);
    expect($compito->refresh()->is_completed)->toBeTrue()
        ->and(RispostaPromemoria::query()->where('evento_id', $compito->id)->value('nota'))->toBe('Chiuso: la convocazione è partita per tutti i convocati.')
        ->and($pagina['passi'][2])->toMatchArray(['stato' => 'fatto', 'riga' => '1 su 7 raggiunta', 'manca' => []]);
});

it('catena 3: il pagamento della fattura sbloccata per l\'urgenza fa «Pagata per l\'urgenza» nella pagina della fattura e nel punto proposto', function () {
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
    $this->travelTo(Carbon::parse('2026-11-13 10:00', 'Europe/Rome'));
    $this->user = be52Utente();
    $s = be52Scenario($this);
    app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    $fornitore = be52Fornitore();
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadFattura($s, $fornitore, $s['spesa']->id, 900.00, [], 'FT-45'))
        ->assertSessionHasNoErrors();
    $fattura = FatturaPassiva::where('numero_documento', 'FT-45')->where('condominio_id', $s['c']->id)->firstOrFail();
    // Registrata oltre il preventivo, con la motivazione: la strada della registrazione fuori preventivo, qui scritta nei dati.
    $fattura->forceFill(['stato_approvazione' => 'sforo_motivato', 'dati_extra' => array_merge((array) $fattura->dati_extra,
        ['override_budget' => ['importo_sforo' => 30000, 'motivazione' => 'Perdita dalla colonna dell\'acqua']])])->save();
    $this->post(route('admin.gestionale.fatture.approva-sforo', [$s['c'], $fattura]), ['note' => 'Perdita dalla colonna dell\'acqua: riparata il 12/11'])
        ->assertSessionHasNoErrors();
    $paginaDellaFattura = fn () => $this->get(route('admin.gestionale.fatture.show', [$s['c'], $fattura]))->assertOk()->viewData('page')['props'];
    $frase = fn () => collect(ProposteDellOrdineDelGiorno::per($s['c']->refresh(), null))->flatMap(fn ($g) => $g['punti'])
        ->firstWhere('chiave_fonte', 'fattura:' . $fattura->id)['frase'] ?? null;

    // Un capo: sbloccata e non ancora pagata.
    expect($paginaDellaFattura()['ratifica']['sbloccata']['pagata'])->toBeFalse()
        ->and($frase())->toStartWith('Sbloccata per l\'urgenza: va riferita alla prima assemblea');

    // L'altro capo: il pagamento dalla pagina dei pagamenti ai fornitori.
    $this->post(route('admin.gestionale.pagamenti-fornitori.store', [$s['c']]), [
        'fornitore_id' => $fornitore->id, 'esercizio_id' => $s['e']->id, 'conto_corrente_id' => $s['cassa']->conto_contabile_id, 'data_pagamento' => '2026-11-13',
        'metodo_pagamento' => 'bonifico', 'iban_beneficiario' => 'IT60X0542811101000000123456', 'bonifico_parlante' => false,
        'allow_overdraft' => true, 'iban_confermato_manualmente' => true, 'conferma_duplicato_verificato' => true,
        'allocazioni' => [['fattura_id' => $fattura->id, 'tipo' => 'pagamento', 'importo_allocato_cents' => 90000]],
    ])->assertSessionHasNoErrors();
    expect($fattura->refresh()->stato_pagamento->value ?? $fattura->stato_pagamento)->toBe('pagata')
        ->and($paginaDellaFattura()['ratifica']['sbloccata']['pagata'])->toBeTrue()
        ->and($frase())->toStartWith('Pagata per l\'urgenza: va riferita alla prima assemblea');
});
