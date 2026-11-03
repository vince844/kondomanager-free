<?php

/*
 * `kondomanager:stats` (1.11.0-beta.33): i numeri dell'installazione per chi la ospita. Il test
 * fissa le chiavi del JSON — sono ciò che uno script legge — e che i conteggi contino le cose
 * giuste: i dimostrativi a parte, i byte dalla colonna `file_size`.
 */

use App\Models\Condominio;
use App\Models\Documento;
use App\Models\User;
use App\Services\Documenti\SpazioDocumenti;
use Illuminate\Support\Facades\Artisan;

it('stampa un JSON con le chiavi attese e i conteggi giusti', function () {
    Condominio::factory()->count(2)->create(['is_demo' => false]);
    Condominio::factory()->create(['is_demo' => true]);
    $autore = User::factory()->create();
    foreach ([1000, 2500] as $byte) {
        Documento::create(['name' => 'd', 'path' => 'documenti/'.$byte.'.pdf', 'mime_type' => 'application/pdf', 'file_size' => $byte, 'is_published' => true, 'is_approved' => true, 'created_by' => $autore->id]);
    }
    config(['kondomanager.limite_condomini' => 5, 'kondomanager.limite_spazio_mb' => 100]);

    Artisan::call('kondomanager:stats', ['--json' => true]);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($json))->toBe(['versione', 'condomini', 'condomini_dimostrativi', 'unita', 'documenti', 'byte_documenti', 'limite_condomini', 'limite_spazio_mb', 'disco_documenti', 'documenti_stato'])
        ->and($json['condomini'])->toBe(2)
        ->and($json['condomini_dimostrativi'])->toBe(1)
        ->and($json['documenti'])->toBe(2)
        ->and($json['byte_documenti'])->toBe(3500)
        ->and($json['limite_condomini'])->toBe(5)
        ->and($json['limite_spazio_mb'])->toBe(100)
        ->and($json['disco_documenti'])->toBe('local')
        ->and($json['versione'])->toBe(config('app.version'));
});

it('il limite di spazio è in byte, e zero o assente vuol dire nessun limite', function () {
    config(['kondomanager.limite_spazio_mb' => 0]);
    expect(app(SpazioDocumenti::class)->limiteByte())->toBeNull();

    config(['kondomanager.limite_spazio_mb' => 2]);
    expect(app(SpazioDocumenti::class)->limiteByte())->toBe(2 * 1024 * 1024);

    config()->offsetUnset('kondomanager');
    expect(app(SpazioDocumenti::class)->limiteByte())->toBeNull();
});
