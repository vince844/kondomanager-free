<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission as Permesso;
use App\Enums\Role as Ruolo;
use App\Services\Assemblee\Sala\SalaSenzaRete;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/SalaDiViaRoma12.php';
require_once __DIR__ . '/../Pagine/RotteDelleAssemblee.php';

/**
 * La sala che si apre senza rete: l'operaio del browser di un'assemblea, servito dal programma con la sua configurazione.
 *
 * - vive nella cartella dell'assemblea (l'intestazione `Service-Worker-Allowed`) e tiene solo la sala e il verbale;
 * - tiene i file che servono a quelle due pagine, presi dal manifesto di Vite (l'ingresso con i suoi import, i fogli di stile, i caratteri, le pagine con i
 *   loro import, le traduzioni della lingua), e non le altre pagine;
 * - la cache ha il nome della versione compilata: un manifesto nuovo è un operaio nuovo, con una cache nuova; lo stesso manifesto, lo stesso operaio;
 * - senza manifesto (il server di sviluppo) l'operaio non c'è;
 * - si legge con «Vede le assemblee»; condòmini e fornitori mai; l'assemblea di un altro condominio è un 404.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    $this->manifesto = tempnam(sys_get_temp_dir(), 'manifesto') . '.json';
    file_put_contents($this->manifesto, json_encode(ssrManifesto()));
    app()->instance(SalaSenzaRete::class, new SalaSenzaRete($this->manifesto));
});
afterEach(function () {
    @unlink($this->manifesto);
    \Illuminate\Support\Carbon::setTestNow();
});

/** Un manifesto di Vite come quello della build: l'ingresso, la sala, un'altra pagina, le traduzioni. */
function ssrManifesto(string $sala = 'assets/Sala-AAAA.js'): array
{
    return [
        'resources/js/app.ts' => ['file' => 'assets/app-1111.js', 'src' => 'resources/js/app.ts', 'isEntry' => true, 'imports' => ['_vue-2222.js'],
            'dynamicImports' => ['resources/js/pages/gestionale/assemblee/Sala.vue', 'resources/js/pages/gestionale/assemblee/Assemblea.vue', 'lang/it.json'],
            'css' => ['assets/app-3333.css'], 'assets' => ['assets/inter-4444.woff2']],
        '_vue-2222.js' => ['file' => 'assets/vue-2222.js'],
        '_bottone-5555.js' => ['file' => 'assets/bottone-5555.js', 'imports' => ['_vue-2222.js']],
        'resources/js/pages/gestionale/assemblee/Sala.vue' => ['file' => $sala, 'isDynamicEntry' => true, 'imports' => ['_bottone-5555.js', 'resources/js/app.ts'],
            'css' => ['assets/Sala-6666.css']],
        'resources/js/pages/gestionale/assemblee/Assemblea.vue' => ['file' => 'assets/Assemblea-7777.js', 'isDynamicEntry' => true, 'imports' => ['_vue-2222.js']],
        'lang/it.json' => ['file' => 'assets/it-8888.js'],
        'lang/php_it.json' => ['file' => 'assets/php_it-9999.js'],
        'lang/en.json' => ['file' => 'assets/en-0000.js'],
    ];
}

function ssrSala($t): array
{
    $s = SalaDiViaRoma12::nuovo($t, false);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);

    return [$s, $a];
}

/** La configurazione in testa all'operaio. @return array<string, mixed> */
function ssrConfigurazione(string $script): array
{
    preg_match('/^const CONFIGURAZIONE = (.*);$/m', $script, $m);

    return json_decode($m[1] ?? 'null', true) ?? [];
}

it('l\'operaio della sala: un JavaScript che vive nella cartella dell\'assemblea, non si tiene in cache, con il suo codice', function () {
    [$s, $a] = ssrSala($this);
    $r = $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.sala.senza_rete', [$s->c, $a]))->assertOk();
    $ambito = '/admin/gestionale/' . $s->c->id . '/assemblee/' . $a->id . '/';

    expect($r->headers->get('Content-Type'))->toContain('javascript')
        ->and($r->headers->get('Service-Worker-Allowed'))->toBe($ambito)
        ->and($r->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($r->getContent())->toContain('addEventListener(\'fetch\'')->toContain('SPDX-License-Identifier: AGPL-3.0-or-later')
        ->and(ssrConfigurazione((string) $r->getContent()))->toMatchArray(['ambito' => $ambito, 'pagine' => ['sala', 'il-verbale'], 'attesa_ms' => 4000,
            'prefisso' => 'kondomanager-sala-' . $a->id . '-']);
});

it('l\'operaio tiene la pagina «Il verbale» al suo indirizzo, e non la riunione registrata a mano', function () {
    [$s, $a] = ssrSala($this);
    $configurazione = ssrConfigurazione((string) $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.sala.senza_rete', [$s->c, $a]))->getContent());
    $pagine = array_map(fn ($p) => $configurazione['ambito'] . $p, $configurazione['pagine']);

    expect($pagine)->toBe([(string) parse_url(route('admin.gestionale.assemblee.sala', [$s->c, $a]), PHP_URL_PATH),
        (string) parse_url(route('admin.gestionale.assemblee.verbale.pagina', [$s->c, $a]), PHP_URL_PATH)])
        ->not->toContain((string) parse_url(route('admin.gestionale.assemblee.verbale.show', [$s->c, $a]), PHP_URL_PATH));
});

it('tiene i file della sala dal manifesto, e non le altre pagine', function () {
    [$s, $a] = ssrSala($this);
    $file = ssrConfigurazione((string) $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.sala.senza_rete', [$s->c, $a]))->getContent())['file'];

    expect($file)->toEqualCanonicalizing(['/build/assets/app-1111.js', '/build/assets/vue-2222.js', '/build/assets/app-3333.css', '/build/assets/inter-4444.woff2',
        '/build/assets/Sala-AAAA.js', '/build/assets/bottone-5555.js', '/build/assets/Sala-6666.css', '/build/assets/it-8888.js', '/build/assets/php_it-9999.js'])
        ->not->toContain('/build/assets/Assemblea-7777.js')->not->toContain('/build/assets/en-0000.js');
});

it('la cache è legata alla versione compilata: lo stesso manifesto, lo stesso operaio; un manifesto nuovo, una cache nuova', function () {
    [$s, $a] = ssrSala($this);
    $operaio = fn () => (string) $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.sala.senza_rete', [$s->c, $a]))->getContent();
    $prima = $operaio();
    expect($operaio())->toBe($prima);

    file_put_contents($this->manifesto, json_encode(ssrManifesto('assets/Sala-BBBB.js')));
    $dopo = $operaio();
    $c1 = ssrConfigurazione($prima);
    $c2 = ssrConfigurazione($dopo);
    expect($dopo)->not->toBe($prima)
        ->and($c2['versione'])->not->toBe($c1['versione'])
        ->and($c2['cache'])->toBe('kondomanager-sala-' . $a->id . '-' . $c2['versione'])
        ->and($c1['cache'])->toBe('kondomanager-sala-' . $a->id . '-' . $c1['versione'])
        ->and($c2['file'])->toContain('/build/assets/Sala-BBBB.js')->not->toContain('/build/assets/Sala-AAAA.js');
});

it('senza il manifesto (il server di sviluppo) l\'operaio non c\'è', function () {
    [$s, $a] = ssrSala($this);
    app()->instance(SalaSenzaRete::class, new SalaSenzaRete(sys_get_temp_dir() . '/manifesto-che-non-esiste.json'));
    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.sala.senza_rete', [$s->c, $a]))->assertNotFound();
});

it('i permessi: con «Vede le assemblee»; condòmini, fornitori e chi non ha i permessi mai; l\'assemblea di un altro condominio è un 404', function () {
    [$s, $a] = ssrSala($this);
    $indirizzo = route('admin.gestionale.assemblee.sala.senza_rete', [$s->c, $a]);
    $tutti = [Permesso::VIEW_ASSEMBLEE, Permesso::MANAGE_ASSEMBLEE];
    $stati = [];
    foreach (['condòmino' => apUtente(Ruolo::UTENTE->value, $tutti), 'fornitore' => apUtente(Ruolo::FORNITORE->value, $tutti),
        'studio senza permessi' => apUtente(null, []), 'vede' => apUtente(null, [Permesso::VIEW_ASSEMBLEE])] as $nome => $u) {
        $stati[$nome] = $this->actingAs($u)->get($indirizzo)->status();
    }
    expect($stati)->toBe(['condòmino' => 403, 'fornitore' => 403, 'studio senza permessi' => 403, 'vede' => 200]);

    $altro = ViaRoma12::nuovo($this, 'Corso Italia 3');
    $sua = ViaRoma12::ordinaria($altro);
    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.sala.senza_rete', [$s->c, $sua]))->assertNotFound();
});

it('la pagina senza copia dice che cosa fare, in italiano, con il tema scuro', function () {
    $html = SalaSenzaRete::paginaSenzaCopia();
    expect($html)->toContain('lang="it"')->toContain('prefers-color-scheme: dark')
        ->toContain('Senza rete si riaprono solo la sala e il verbale, e solo su un computer dove si sono già aperti con la rete.')
        ->toContain('«Registra dal foglio della sala»');
});
