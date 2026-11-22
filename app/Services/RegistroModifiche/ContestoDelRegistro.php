<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

/**
 * Il contesto in cui nasce una riga: chi la scrive e se si è dentro un gesto.
 *
 * Tre pile, tutte rimesse in un `finally` da chi le apre (P10), così uno stato rimasto a metà non sporca la richiesta o il comando dopo:
 *  - l'autore dichiarato da un gesto (l'importazione, il ripristino, un comando che si firma da sé);
 *  - i comandi in corso, dagli eventi della console (`CommandStarting`/`CommandFinished`), e i lavori del pianificatore (`ScheduledTaskStarting`/…);
 *  - i lotti dei gesti aperti con `durante`: finché ce n'è uno, il tratto dei modelli non scrive righe sue ma conta nel lotto.
 */
final class ContestoDelRegistro
{
    /** @var list<Autore> */
    private static array $autori = [];

    /** @var list<string> */
    private static array $comandi = [];

    private static int $pianificati = 0;

    /** @var list<Lotto> */
    private static array $lotti = [];

    /** @var list<array{tipo: string, azione: string, id: int|null, classe: string}> */
    private static array $scrittureFuoriGesto = [];

    /** @var list<string> il «perché» dei ripristini aperti («Ripristina dalla Storia, modifica del …») */
    private static array $ripristini = [];

    /** I nomi dei comandi come li dice la riga: «comando lanciato da terminale: riallineamento dei crediti». */
    private const NOMI_DEI_COMANDI = [
        'kondomanager:riallinea-crediti' => 'riallineamento dei crediti',
        'kondomanager:riallinea-fondi' => 'riallineamento dei fondi',
        'kondomanager:verifica-gestione-fatture' => 'verifica della gestione delle fatture',
        'kondomanager:verifica-voci-di-entrata' => 'verifica delle voci di entrata',
        'kondomanager:sigilla-registro' => 'sigillo del registro delle modifiche',
        'kondomanager:conserva-registro' => 'conservazione del registro delle modifiche',
        'comunicazioni:pubblica-programmate' => 'pubblicazione delle comunicazioni programmate',
        'queue:work' => 'lavori in coda',
    ];

    /**
     * L'autore di una riga che nasce adesso. In ordine: quello dichiarato da un gesto; la persona in sessione; «Il programma» con l'origine del processo
     * (un lavoro del pianificatore, un comando lanciato da terminale, una richiesta senza utente).
     */
    public static function autore(): Autore
    {
        if (self::$autori !== []) {
            return self::$autori[array_key_last(self::$autori)];
        }

        $utente = Auth::user();
        if ($utente instanceof \App\Models\User) {
            return Autore::persona($utente);
        }

        $comando = self::$comandi === [] ? null : self::$comandi[array_key_last(self::$comandi)];
        if (self::$pianificati > 0 || $comando === 'queue:work') {
            return Autore::programma('pianificato', $comando === null ? 'lavoro pianificato' : 'lavoro pianificato: ' . self::nomeDelComando($comando));
        }

        if ($comando !== null) {
            return Autore::programma('comando', 'comando lanciato da terminale: ' . self::nomeDelComando($comando));
        }

        return app()->runningInConsole()
            ? Autore::programma('comando', 'comando lanciato da terminale')
            : Autore::programma('pagina', null);
    }

    /** Il nome di un comando come lo legge l'amministratore: dalla tabella, altrimenti la sua descrizione. */
    public static function nomeDelComando(string $comando): string
    {
        if (isset(self::NOMI_DEI_COMANDI[$comando])) {
            return self::NOMI_DEI_COMANDI[$comando];
        }

        try {
            $descrizione = (string) (Artisan::all()[$comando] ?? null)?->getDescription();
        } catch (\Throwable) {
            $descrizione = '';
        }

        return $descrizione !== '' ? mb_strtolower(mb_substr($descrizione, 0, 1)) . mb_substr($descrizione, 1) : $comando;
    }

    public static function apriAutore(Autore $autore): void
    {
        self::$autori[] = $autore;
    }

    public static function chiudiAutore(): void
    {
        array_pop(self::$autori);
    }

    public static function comandoIniziato(?string $comando): void
    {
        self::$comandi[] = (string) $comando;
    }

    public static function comandoFinito(): void
    {
        array_pop(self::$comandi);
    }

    public static function pianificatoIniziato(): void
    {
        self::$pianificati++;
    }

    public static function pianificatoFinito(): void
    {
        self::$pianificati = max(0, self::$pianificati - 1);
    }

    public static function apriLotto(Lotto $lotto): void
    {
        self::$lotti[] = $lotto;
    }

    public static function chiudiLotto(): ?Lotto
    {
        return array_pop(self::$lotti);
    }

    /** Toglie un lotto preciso dalla pila (un gesto aperto a mano e lasciato da un rollback). */
    public static function togliLotto(Lotto $lotto): void
    {
        self::$lotti = array_values(array_filter(self::$lotti, fn (Lotto $l) => $l !== $lotto));
    }

    /** Il lotto del gesto più esterno aperto, se ce n'è uno: lì si contano le scritture dei modelli. */
    public static function lottoAperto(): ?Lotto
    {
        return self::$lotti === [] ? null : self::$lotti[0];
    }

    /** @return list<Lotto> */
    public static function lottiAperti(): array
    {
        return self::$lotti;
    }

    public static function inUnGesto(): bool
    {
        return self::$lotti !== [];
    }

    /**
     * Una scrittura di un modello «solo gesto» fatta con Eloquent fuori da un gesto (P5): non si solleva (i test creano rate e quote a mano nella
     * preparazione), si annota. I test per area, che fanno il gesto vero dalla rotta, pretendono la lista vuota.
     */
    public static function annotaFuoriGesto(string $tipo, string $azione, ?int $id, string $classe): void
    {
        if (count(self::$scrittureFuoriGesto) < 1000) {
            self::$scrittureFuoriGesto[] = ['tipo' => $tipo, 'azione' => $azione, 'id' => $id, 'classe' => $classe];
        }
    }

    /** @return list<array{tipo: string, azione: string, id: int|null, classe: string}> */
    public static function scrittureFuoriGesto(): array
    {
        return self::$scrittureFuoriGesto;
    }

    public static function svuotaScrittureFuoriGesto(): void
    {
        self::$scrittureFuoriGesto = [];
    }

    public static function apriRipristino(string $perche): void
    {
        self::$ripristini[] = $perche;
    }

    public static function chiudiRipristino(): void
    {
        array_pop(self::$ripristini);
    }

    /** Il «perché» del ripristino in corso, se ce n'è uno: le righe «modificata» del tratto diventano «ripristinata». */
    public static function ripristinoInCorso(): ?string
    {
        return self::$ripristini === [] ? null : self::$ripristini[array_key_last(self::$ripristini)];
    }

    /** Per i test e per un processo che riparte: tutto vuoto. */
    public static function azzera(): void
    {
        self::$autori = [];
        self::$comandi = [];
        self::$pianificati = 0;
        self::$lotti = [];
        self::$scrittureFuoriGesto = [];
        self::$ripristini = [];
        RegistroModifiche::lasciaGesto();
        RigheRimandate::svuota();
    }
}
