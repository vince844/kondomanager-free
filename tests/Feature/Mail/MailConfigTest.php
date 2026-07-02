<?php

use App\Settings\MailSettings;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Mailable;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// Pulizia Mockery dopo ogni test
afterEach(function () {
    Mockery::close();
});

beforeEach(function () {
    Config::set('mail.default', 'smtp');
    $reflection = new ReflectionClass(\App\Providers\MailConfigServiceProvider::class);
    $reflection->setStaticPropertyValue('defaultConfig', null);
});

it('carica correttamente la configurazione SMTP dal database quando abilitata', function () {
    $settings = app(MailSettings::class);
    $settings->mail_enabled = true;
    $settings->mail_host = 'smtp.test.it';
    $settings->mail_port = 587;
    $settings->mail_username = 'test-user';
    $settings->save();

    app(\App\Providers\MailConfigServiceProvider::class, ['app' => app()])->boot();

    expect(config('mail.mailers.smtp.host'))->toBe('smtp.test.it')
        ->and(config('mail.mailers.smtp.username'))->toBe('test-user')
        ->and(config('mail.mailers.smtp.port'))->toBe(587);
});

it('passa al driver log se la configurazione database è disabilitata e il .env è vuoto', function () {
    $settings = app(MailSettings::class);
    $settings->mail_enabled = false;
    $settings->save();
    
    Config::set('mail.mailers.smtp.host', '127.0.0.1'); 

    app(\App\Providers\MailConfigServiceProvider::class, ['app' => app()])->boot();

    expect(config('mail.default'))->toBe('log');
});

it('sincronizza la configurazione prima di un Job Email nella coda', function () {
    // 1. Configurazione nel DB
    $settings = app(MailSettings::class);
    $settings->mail_enabled = true;
    $settings->mail_host = 'db-smtp.com';
    $settings->save();

    // !!! TRUCCO: Forziamo il boot ORA che la tabella esiste e i dati ci sono.
    // Questo registra effettivamente l'hook Queue::before nel sistema degli eventi.
    app(\App\Providers\MailConfigServiceProvider::class, ['app' => app()])->boot();

    // 2. Mock del Job
    $mockJob = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $mockJob->shouldReceive('resolveName')->andReturn('Illuminate\Mail\SendQueuedMailable');
    $mockJob->shouldReceive('payload')->andReturn([]);
    $mockJob->shouldReceive('getJobId')->andReturn('123');

    $event = new JobProcessing('database', $mockJob);

    // 3. Prepariamo lo stato "vecchio"
    Config::set('mail.mailers.smtp.host', 'vecchio-host.com');
    
    // Scateniamo l'evento: il listener registrato nel boot() ora deve scattare
    event($event);

    // 4. Verifica che il listener abbia sovrascritto l'host
    expect(config('mail.mailers.smtp.host'))->toBe('db-smtp.com');
});

it('configura correttamente il mittente e il driver dopo l\'invio simulato', function () {
    $settings = app(MailSettings::class);
    $settings->mail_enabled = true;
    $settings->mail_from_address = 'noreply@kondomanager.it';
    $settings->mail_host = 'smtp.mailtrap.io';
    $settings->save();

    // Eseguiamo il boot
    app(\App\Providers\MailConfigServiceProvider::class, ['app' => app()])->boot();

    // Verifichiamo che il runtime di Laravel sia stato aggiornato
    expect(config('mail.mailers.smtp.host'))->toBe('smtp.mailtrap.io')
        ->and(config('mail.from.address'))->toBe('noreply@kondomanager.it');
});