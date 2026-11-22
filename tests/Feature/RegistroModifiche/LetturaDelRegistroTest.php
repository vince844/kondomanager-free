<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\RegistroModifica;
use App\Models\TitolaritaImmobile;
use App\Models\User;
use App\Services\RegistroModifiche\CatalogoDelRegistro;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Services\RegistroModifiche\LetturaDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Support\Carbon;

/**
 * 1.12.0-beta.13 (Coda 679, P14): la «Storia» di una scheda dalla più recente, con i legami che nominano la persona, senza
 * le righe annullate da un ripristino (contano tutti i ripristini non annullati a loro volta, letti dal più recente all'indietro: una copia fatta prima di
 * un ripristino, ripristinata dopo, rimette in vita le righe che quello aveva annullato; una copia che lo contiene no); le righe di un condominio con i filtri
 * del §14; la riga per la pagina senza `valore_*` (P18); le dichiarazioni dei tipi.
 *
 * Che cosa non copre: il ripristino vero (ha i suoi test: qui si scrive la riga `ripristino_copia` nella forma che la lettura usa); le pagine.
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->travelTo(Carbon::parse('2026-12-16 09:12:00', 'Europe/Rome'));
    $this->andrea = User::factory()->create(['name' => 'Andrea Costa']);
    $this->actingAs($this->andrea);
    $this->c = Condominio::factory()->create(['nome' => 'Via Roma 12']);
    $this->ida = Anagrafica::query()->create(['nome' => 'Ida Romano', 'indirizzo' => 'Via Roma 12', 'email' => 'ida@libero.it']);
    $this->ida->condomini()->attach($this->c->id);
});

function ldrRipristino(int $comune, string $copiaDel = '2026-12-10'): RegistroModifica
{
    return RegistroModifiche::scrivi(['area' => 'impostazioni', 'oggetto_tipo' => 'registro', 'oggetto_id' => null, 'oggetto_nome' => 'Copia del ' . $copiaDel,
        'azione' => 'ripristino_copia', 'riepilogo' => 'Ripristinata la copia', 'lotto' => ['sequenza_comune' => $comune, 'prima_sequenza_rimessa' => $comune + 1,
            'ultima_sequenza_rimessa' => $comune + 1, 'copia_del' => $copiaDel, 'oscuramenti_riapplicati' => 0]])->fresh();
}

it('la «Storia» della persona: le sue righe e il legame che la nomina, dalla più recente', function () {
    $this->ida->update(['email' => 'ida@gmail.com']);
    $unita = Immobile::query()->create(['condominio_id' => $this->c->id, 'nome' => 'Appartamento', 'interno' => '7', 'codice_immobile' => 'L-7']);
    TitolaritaImmobile::query()->create(['immobile_id' => $unita->id, 'anagrafica_id' => $this->ida->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'data_inizio' => '2019-01-01', 'attivo' => true]);

    $storia = LetturaDelRegistro::storia('anagrafica', $this->ida->id)->get();

    expect($storia->pluck('azione')->all())->toBe(['collegata', 'modificata', 'creata'])
        ->and(LetturaDelRegistro::storia('immobile', $unita->id)->pluck('azione')->all())->toBe(['collegata', 'creata']);
});

it('le righe annullate da un ripristino non sono nella «Storia»; una copia fatta prima di quel ripristino, ripristinata dopo, le rimette in vita', function () {
    $this->ida->update(['email' => 'uno@example.it']);
    $comune = (int) RegistroModifica::query()->max('sequenza');
    $this->ida->update(['email' => 'due@example.it']);
    $dopo = RegistroModifica::query()->orderByDesc('id')->first();

    $ripristino = ldrRipristino($comune);
    expect(LetturaDelRegistro::storia('anagrafica', $this->ida->id)->pluck('id')->all())->not->toContain($dopo->id)
        ->and(LetturaDelRegistro::perLaPagina($dopo->fresh())['annullata_dal_ripristino_del'])->toBe('16/12/2026');

    // La copia della sera, fatta dopo «due» e prima del ripristino: la sua ultima riga è «due», e il ripristino di prima non c'è.
    ldrRipristino((int) $dopo->fresh()->sequenza, '2026-12-16');
    expect(LetturaDelRegistro::storia('anagrafica', $this->ida->id)->pluck('id')->all())->toContain($dopo->id)
        ->and(LetturaDelRegistro::perLaPagina($dopo->fresh())['annullata_dal_ripristino_del'])->toBeNull()
        ->and(LetturaDelRegistro::eAnnullata($ripristino->fresh()))->toBeTrue();
});

it('una copia che contiene il primo ripristino, ripristinata dopo, lascia annullate le righe che quello aveva annullato', function () {
    $this->ida->update(['email' => 'uno@example.it']);
    $comune = (int) RegistroModifica::query()->max('sequenza');
    $this->ida->update(['email' => 'due@example.it']);
    $dopo = RegistroModifica::query()->orderByDesc('id')->first();

    $ripristino = ldrRipristino($comune);
    // La copia della notte, fatta dopo il ripristino: la sua ultima riga è il ripristino stesso.
    $this->travelTo(Carbon::parse('2026-12-17 09:00:00', 'Europe/Rome'));
    ldrRipristino((int) $ripristino->sequenza, '2026-12-16');

    expect(LetturaDelRegistro::storia('anagrafica', $this->ida->id)->pluck('id')->all())->not->toContain($dopo->id)
        ->and(LetturaDelRegistro::perLaPagina($dopo->fresh())['annullata_dal_ripristino_del'])->toBe('16/12/2026')
        ->and(LetturaDelRegistro::eAnnullata($ripristino->fresh()))->toBeFalse()
        ->and(LetturaDelRegistro::annullamento())->toHaveCount(2);
});

it('le righe del condominio con i filtri: chi, area, azione, mese, testo; la riga per la pagina senza i valori grezzi', function () {
    $this->ida->update(['email' => 'ida@gmail.com']);
    $altro = Condominio::factory()->create(['nome' => 'Corso Italia 3']);
    auth()->logout();
    RegistroModifiche::scrivi(['area' => 'condominio', 'oggetto_tipo' => 'condominio', 'oggetto_id' => $altro->id, 'oggetto_nome' => 'Corso Italia 3',
        'azione' => 'gesto', 'riepilogo' => 'Altro', 'condomini' => [$altro->id => 'Corso Italia 3']]);

    $righe = LetturaDelRegistro::delCondominio($this->c->id)->get();
    // Il condominio nuovo (la sua riga «creata») e la persona; non la riga dell'altro condominio.
    expect($righe->pluck('oggetto_nome')->unique()->sort()->values()->all())->toBe(['Anagrafica di Ida Romano', 'Condominio Via Roma 12'])
        ->and(LetturaDelRegistro::delCondominio($this->c->id, ['chi' => $this->andrea->id, 'azione' => 'modificata'])->count())->toBe(1)
        ->and(LetturaDelRegistro::delCondominio(null, ['chi' => 'programma'])->pluck('oggetto_nome')->all())->toBe(['Corso Italia 3'])
        ->and(LetturaDelRegistro::delCondominio($this->c->id, ['area' => 'persone', 'mese' => '2026-12', 'cerca' => 'gmail'])->count())->toBe(1)
        ->and(LetturaDelRegistro::delCondominio($this->c->id, ['mese' => '2026-11'])->count())->toBe(0);

    $pagina = LetturaDelRegistro::perLaPagina($righe->firstWhere('azione', 'modificata'));
    expect($pagina['campi'][0])->toBe(['chiave' => 'email', 'etichetta' => 'Indirizzo email primario', 'prima' => 'ida@libero.it', 'dopo' => 'ida@gmail.com',
        'segreto' => false])
        ->and(json_encode($pagina))->not->toContain('valore_')
        ->and($pagina)->not->toHaveKey('impronta');
});

it('le dichiarazioni dei tipi per le pagine e per l\'oscuramento: parola, etichette, ripristinabili, dati della persona', function () {
    $d = CatalogoDelRegistro::dichiarazioni();

    expect($d)->toHaveCount(count(CatalogoDelRegistro::TRATTO))
        ->and($d['anagrafica'])->toMatchArray(['parola' => 'Anagrafica', 'area' => 'persone'])
        ->and($d['anagrafica']['ripristinabili'])->toContain('email')
        ->and($d['anagrafica']['dati_della_persona'])->toContain('codice_fiscale')
        ->and($d['fattura_passiva']['ripristinabili'])->toBe([]);
});
