<?php

/**
 * La migrazione dei promemoria della .52 riallinea i promemoria del caso misto scritti prima della .52 (decisione 90.1).
 *
 * Un promemoria scritto dalla 1.10.0 o dalla .51 ha il pagato e il restante delle sole quote positive (`IncassoRateController.php:250-268`
 * di v1.10.0, e `allineaPagato()` della .51) e non ha `importo_versato`; il netto (`importo_originale`) invece conta anche le quote a credito.
 * Su una persona che sulla rata ha quote dei due segni la finestra della .52 legge quei numeri come se venissero dallo stato di adesso, e sbaglia
 * fino al primo movimento sulla rata: chiede € 30,00 a chi ha ancora € 45,00 di credito (D2a), dice «usato o restituito» un credito intatto
 * (D2b) e «ancora a tuo favore» un credito già usato (D1). La migrazione ricalcola quei promemoria con lo stesso calcolo degli scrittori
 * (`EventiRataCondomino::stato()` e `applica()`), saldati compresi (per i misti sostituisce la 87.9), conserva la segnalazione della persona,
 * non tocca `updated_at` (il portale mostra i promemoria pagati per trenta giorni da lì), scrive a registro ogni riga e rieseguita non cambia
 * niente.
 *
 * Fonte dell'atteso: il promemoria che la .52 scrive dalle rotte vere per lo stesso scenario (incasso, «Usa credito»), letto prima di
 * rimettergli il meta e il testo della 1.10.0; i casi D1, D2a e D2b e le loro cifre sono quelli trovati verificando le correzioni della 1.11.0-beta.52.
 *
 * Cosa resta scoperto: MySQL (qui sqlite); un promemoria di prima con più persone sullo stesso evento (la 1.10.0 ne scriveva uno per persona: si
 * salta); i promemoria non misti scritti prima della .52 (fuori dalla decisione: la finestra li legge con il pagato, limite già dichiarato).
 *
 * I test che cominciano con «controprova» proteggono ciò che la migrazione non deve toccare: sono verdi prima e dopo.
 */

use App\Models\Evento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

const B52R_MIGRAZIONE = '2026_10_17_100000_riscrivi_descrizioni_promemoria_credito';
const B52R_CHIAVI = ['status', 'importo_originale', 'importo_pagato', 'importo_versato', 'importo_restante', 'da_compensare'];

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

/** Il caso misto D2: Bice ha C +€ 50,00 e D −€ 75,00 sulla rata 1, netto −€ 25,00. */
function b52rMisto($test): array
{
    $s = b52tScenario($test, '1.200,00', '900,00');
    $piano = b52tPiano($test, $s);
    b52tApprova($test, $s, $piano);
    b52tEmetti($test, $s, $piano, 1);

    return [$s, $piano, b52tQuotaId($s, $piano, 'bice', 'C', 1), b52tQuotaId($s, $piano, 'bice', 'D', 1)];
}

/** Il promemoria come la .52 l'ha appena scritto: le chiavi dello stato e il testo. È l'atteso dopo la migrazione. */
function b52rVero(Evento $e): array
{
    return ['meta' => b52rChiavi($e->meta), 'testo' => $e->description];
}

/** Le chiavi dello stato, in ordine alfabetico: l'ordine nel `meta` dipende da chi l'ha scritto e non dice niente. */
function b52rChiavi(array $meta, array $chiavi = B52R_CHIAVI): array
{
    $stato = array_intersect_key($meta, array_flip($chiavi));
    ksort($stato);

    return $stato;
}

/**
 * Rimette sul promemoria il meta e il testo che la 1.10.0 avrebbe scritto: pagato e restante delle sole quote positive, niente `importo_versato`
 * né `da_compensare`; il testo del credito con la frase vecchia se il netto è negativo, quello della scadenza altrimenti. `updated_at` di quattro
 * mesi fa. `$status` forza lo stato (una segnalazione della persona, o un «paid» scritto da un'altra versione).
 */
function b52rDiPrima(array $s, $piano, string $chi, ?string $status = null): Evento
{
    $e = b52tEvento($s, $piano, $chi, 1);
    $positive = collect(b52tQuote($s, $piano, $chi, 1))->filter(fn ($q) => $q[1] > 0);
    $dovuto = (int) $positive->sum(fn ($q) => $q[1]);
    $pagato = (int) $positive->sum(fn ($q) => $q[2]);
    $restante = $dovuto - $pagato;

    $meta = $e->meta;
    unset($meta['importo_versato'], $meta['da_compensare']);
    $meta['importo_pagato'] = $pagato;
    $meta['importo_restante'] = max(0, $restante);
    $meta['status'] = $status ?? ($restante <= 0 ? 'paid' : ($pagato > 0 ? 'partial' : 'pending'));

    $nome = $s['persone'][$chi]->nome;
    $testo = $meta['importo_originale'] < 0
        ? "Gentile {$nome}, questa voce rappresenta un credito a tuo favore registrato nella rata n. 1.\n\nNon è richiesto alcun pagamento: l'importo verrà utilizzato automaticamente per compensare le rate successive."
        : "Gentile {$nome}, ti ricordiamo la scadenza della rata condominiale n. 1.\n\nVerifica il dettaglio quote e il netto da versare nello scontrino qui sotto. Dopo aver effettuato il versamento, potrai segnalarlo all'amministratore cliccando sul pulsante corrispondente.";

    DB::table('eventi')->where('id', $e->id)->update(['meta' => json_encode($meta), 'description' => $testo, 'updated_at' => '2026-06-01 10:00:00']);

    return $e->fresh();
}

/** Esegue la migrazione e restituisce le righe di registro del riallineamento e del conteggio. */
function b52rMigra(): array
{
    $registro = [];
    Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$registro) {
        if (str_starts_with($m->message, 'Migrazione: promemoria')) {
            $registro[] = $m->message;
        }
    });
    (require database_path('migrations/'.B52R_MIGRAZIONE.'.php'))->up();

    return $registro;
}

it('R1 D2a: € 20,00 versati su C e il credito di D intatto; dopo la migrazione il promemoria dice ciò che dice la .52, compensazione di € 30,00 e niente da versare', function () {
    [$s, $piano, $qC] = b52rMisto($this);
    b52tIncasso($this, $s, 'bice', [$qC => 2000], 2000);
    $vero = b52rVero(b52tEvento($s, $piano, 'bice', 1));
    $prima = b52rDiPrima($s, $piano, 'bice');
    expect($prima->meta['importo_restante'])->toBe(3000);

    $registro = b52rMigra();

    $dopo = b52tEvento($s, $piano, 'bice', 1);
    expect(b52rVero($dopo))->toBe($vero)
        ->and($vero['meta'])->toMatchArray(['status' => 'partial', 'importo_restante' => -4500, 'da_compensare' => 3000, 'importo_versato' => 2000])
        ->and(DB::table('eventi')->where('id', $dopo->id)->value('updated_at'))->toBe('2026-06-01 10:00:00')
        ->and(collect($registro)->filter(fn ($r) => str_contains($r, "promemoria {$dopo->id} riallineato"))->count())->toBe(1);
});

it('R2 D2b: € 50,00 versati su C e il credito di D intatto; il «paid» della 1.10.0 col restante zero torna al credito di € 75,00', function () {
    [$s, $piano, $qC] = b52rMisto($this);
    b52tIncasso($this, $s, 'bice', [$qC => 5000], 5000);
    $vero = b52rVero(b52tEvento($s, $piano, 'bice', 1));
    b52rDiPrima($s, $piano, 'bice');

    b52rMigra();

    expect(b52rVero(b52tEvento($s, $piano, 'bice', 1)))->toBe($vero)
        ->and($vero['meta'])->toMatchArray(['status' => 'paid', 'importo_restante' => -7500])
        ->and($vero['testo'])->toContain('il credito resta a tuo favore');
});

it('R3 D1: € 40,00 su C e «Usa credito» dei € 10,00 di D; il pagato della 1.10.0 (€ 50,00) torna a € 40,00 e il credito non resta', function () {
    [$s, $piano, $qC, $qD] = b52tK3($this);
    b52tIncasso($this, $s, 'bice', [$qC => 4000], 4000);
    b52tIncasso($this, $s, 'bice', [$qD => -1000, $qC => 1000], 0);
    $vero = b52rVero(b52tEvento($s, $piano, 'bice', 1));
    expect(b52rDiPrima($s, $piano, 'bice')->meta['importo_pagato'])->toBe(5000);

    b52rMigra();

    expect(b52rVero(b52tEvento($s, $piano, 'bice', 1)))->toBe($vero)
        ->and($vero['meta'])->toMatchArray(['status' => 'paid', 'importo_pagato' => 4000, 'importo_versato' => 4000, 'importo_restante' => 0]);
});

it('R4 la segnalazione della persona resta: un promemoria «reported» si riallinea nei numeri e conserva lo stato', function () {
    [$s, $piano, $qC] = b52rMisto($this);
    b52tIncasso($this, $s, 'bice', [$qC => 2000], 2000);
    $vero = b52rVero(b52tEvento($s, $piano, 'bice', 1));
    b52rDiPrima($s, $piano, 'bice', 'reported');

    b52rMigra();

    $meta = b52tEvento($s, $piano, 'bice', 1)->meta;
    expect($meta['status'])->toBe('reported')
        ->and(b52rChiavi($meta, ['importo_pagato', 'importo_versato', 'importo_restante', 'da_compensare']))
        ->toBe(b52rChiavi($vero['meta'], ['importo_pagato', 'importo_versato', 'importo_restante', 'da_compensare']));
});

it('R5 rieseguita non cambia niente e non scrive righe di riallineamento', function () {
    [$s, $piano, $qC] = b52rMisto($this);
    b52tIncasso($this, $s, 'bice', [$qC => 2000], 2000);
    b52rDiPrima($s, $piano, 'bice');
    b52rMigra();
    $dopoIlPrimoGiro = DB::table('eventi')->where('id', b52tEvento($s, $piano, 'bice', 1)->id)->first();

    $registro = b52rMigra();

    expect(collect($registro)->filter(fn ($r) => str_contains($r, 'riallineato'))->all())->toBe([])
        ->and(DB::table('eventi')->where('id', $dopoIlPrimoGiro->id)->first())->toEqual($dopoIlPrimoGiro);
});

it('R6 un testo ritoccato a mano resta com\'è, e i numeri si riallineano', function () {
    [$s, $piano, $qC] = b52rMisto($this);
    b52tIncasso($this, $s, 'bice', [$qC => 2000], 2000);
    $vero = b52rVero(b52tEvento($s, $piano, 'bice', 1));
    $e = b52rDiPrima($s, $piano, 'bice');
    DB::table('eventi')->where('id', $e->id)->update(['description' => 'Gentile Bice, ne parliamo in assemblea.']);

    b52rMigra();

    $dopo = b52tEvento($s, $piano, 'bice', 1);
    expect($dopo->description)->toBe('Gentile Bice, ne parliamo in assemblea.')
        ->and(b52rChiavi($dopo->meta))->toBe($vero['meta']);
});

it('R7 un «paid» di prima con una quota ancora da compensare si riallinea, e il conteggio nel registro non lo conta più', function () {
    [$s, $piano, $qC] = b52rMisto($this);
    b52tIncasso($this, $s, 'bice', [$qC => 2000], 2000);
    $vero = b52rVero(b52tEvento($s, $piano, 'bice', 1));
    b52rDiPrima($s, $piano, 'bice', 'paid');

    $registro = b52rMigra();

    expect(b52rVero(b52tEvento($s, $piano, 'bice', 1))['meta'])->toBe($vero['meta'])
        ->and(collect($registro)->first(fn ($r) => str_contains($r, 'saldati con una quota ancora da pagare')))->toContain(': 0.');
});

it('controprova — un promemoria non misto scritto prima della .52 e uno misto già scritto dalla .52 non si toccano', function () {
    [$s, $piano, $qC] = b52rMisto($this);
    b52tIncasso($this, $s, 'bice', [$qC => 2000], 2000);
    $ugo = b52rDiPrima($s, $piano, 'ugo');
    $bice = b52tEvento($s, $piano, 'bice', 1);
    $prima = DB::table('eventi')->whereIn('id', [$ugo->id, $bice->id])->get()->keyBy('id');

    $registro = b52rMigra();

    expect(DB::table('eventi')->whereIn('id', [$ugo->id, $bice->id])->get()->keyBy('id'))->toEqual($prima)
        ->and(collect($registro)->filter(fn ($r) => str_contains($r, 'riallineato'))->all())->toBe([]);
});
