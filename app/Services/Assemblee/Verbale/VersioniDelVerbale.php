<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Verbale;

use App\Enums\Assemblee\StatoVerbale;
use App\Models\Assemblee\ParteDelVerbale;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\Assemblee\VersioneDelVerbale;
use App\Models\User;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Le versioni del verbale (V5) e il testo di adesso di ogni parte.
 *
 * - **Quando nasce una versione** (§0.4 (2) del progetto): dopo due minuti senza scrivere (il testo com'era prima della pausa: «per un po'»), e a ogni gesto
 *   importante: generato dal modello, rigenerato (il testo di prima resta), ripristino, bozza stampata, scelta fra due versioni, registrazione, la discussione
 *   di un punto quando la sala chiude la riunione. Ogni versione è una riga del registro delle modifiche (non ogni salvataggio), con l'ora del gesto.
 * - **«Le versioni»** con «Vedi» e «Ripristina»: il ripristino è una versione («ripristino della versione 2»), il testo di prima resta. Registrato il verbale,
 *   «Ripristina» non c'è più: il testo è quello firmato.
 * - Le versioni **in attesa** (due versioni, o arrivata dopo la registrazione) non sono il testo: aspettano la scelta, e non si ripristinano finché non si sceglie.
 */
final class VersioniDelVerbale
{
    /** Dopo quanti secondi senza scrivere il testo di prima diventa una versione. */
    public const PAUSA = 120;

    /** Quante revisioni tiene `scrittori` di una parte (per sapere chi ha scritto dopo la base). */
    public const SCRITTORI = 200;

    /**
     * Il testo di adesso di una parte: la revisione cresce di uno, con chi l'ha scritto (il dispositivo e la scheda del browser) e l'ora del gesto. Crea la
     * parte se non c'è.
     *
     * @param array<string, mixed> $documento già valido
     */
    public function scrivi(VerbaleDellAssemblea $verbale, string $parte, ?int $puntoId, array $documento, ?string $dispositivo, CarbonInterface $quando,
        ?User $utente, ?string $scheda = null): ParteDelVerbale
    {
        $riga = ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', $parte)->lockForUpdate()->first()
            ?? new ParteDelVerbale(['verbale_id' => $verbale->id, 'parte' => $parte, 'punto_id' => $puntoId ?? self::puntoDi($parte), 'revisione' => 0]);
        $revisione = (int) $riga->revisione + 1;
        $scrittori = array_slice([...(array) ($riga->scrittori ?? []), $scheda === null ? [$revisione, $dispositivo] : [$revisione, $dispositivo, $scheda]],
            -self::SCRITTORI);
        $riga->fill(['documento' => $documento, 'revisione' => $revisione, 'dispositivo' => $dispositivo, 'scrittori' => $scrittori, 'scritta_il' => $quando,
            'aggiornata_da' => $utente?->id])->save();

        return $riga;
    }

    /**
     * Una versione nuova di una parte, numerata dopo l'ultima della stessa parte.
     *
     * @param array<string, mixed> $documento già valido
     */
    public function nuova(VerbaleDellAssemblea $verbale, string $parte, array $documento, string $perche, ?string $dispositivo, ?string $gesto,
        CarbonInterface $segnataIl, ?User $utente, ?string $attesa = null): VersioneDelVerbale
    {
        $numero = (int) VersioneDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', $parte)->max('numero') + 1;

        return VersioneDelVerbale::create(['verbale_id' => $verbale->id, 'parte' => $parte, 'numero' => $numero, 'documento' => $documento,
            'impronta' => StrutturaDelVerbale::impronta($documento), 'perche' => mb_substr($perche, 0, 200), 'dispositivo' => $dispositivo, 'gesto' => $gesto,
            'segnata_il' => $segnataIl, 'in_attesa' => $attesa !== null, 'attesa' => $attesa, 'created_by' => $utente?->id]);
    }

    /**
     * Il testo di adesso di una parte, fra le versioni, se non c'è già com'è: prima di rigenerare, di aggiornare le parti della sala, di ripristinare. Null se
     * la parte non c'è o l'ultima versione ha già questo testo.
     */
    public function tieniIlTestoDiAdesso(VerbaleDellAssemblea $verbale, ParteDelVerbale $parte, string $perche, ?User $utente): ?VersioneDelVerbale
    {
        $documento = (array) $parte->documento;
        $ultima = VersioneDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', $parte->parte)->where('in_attesa', false)
            ->orderByDesc('numero')->first();
        if ($ultima !== null && $ultima->impronta === StrutturaDelVerbale::impronta($documento)) {
            return null;
        }

        return $this->nuova($verbale, $parte->parte, $documento, $perche, $parte->dispositivo, null, $parte->scritta_il ?? now(), $utente);
    }

    /**
     * «Le versioni» di una parte, dalla più recente: il numero, il perché, chi, l'ora del gesto, l'impronta, se è in attesa e se si ripristina.
     *
     * @return list<array<string, mixed>>
     */
    public function elenco(VerbaleDellAssemblea $verbale, string $parte = 'intero'): array
    {
        $siRipristina = $verbale->siScrive();

        return VersioneDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', $parte)->with('autore:id,name')->orderByDesc('numero')->get()
            ->map(fn (VersioneDelVerbale $v) => self::voce($v) + ['ripristina' => $siRipristina && ! $v->in_attesa])->values()->all();
    }

    /** @return array<string, mixed> */
    public static function voce(VersioneDelVerbale $v): array
    {
        return ['id' => (int) $v->id, 'parte' => $v->parte, 'numero' => (int) $v->numero, 'perche' => $v->perche, 'impronta' => $v->impronta,
            'dispositivo' => $v->dispositivo, 'segnata_il' => $v->segnata_il?->copy()->utc()->toIso8601ZuluString(),
            'arrivata_il' => $v->created_at?->copy()->utc()->toIso8601ZuluString(), 'chi' => $v->autore?->name, 'in_attesa' => (bool) $v->in_attesa,
            'attesa' => $v->attesa, 'scelta' => $v->scelta, 'scelta_il' => $v->scelta_il?->copy()->utc()->toIso8601ZuluString()];
    }

    /** «Vedi»: il testo di una versione di questo verbale. @return array<string, mixed> */
    public function vedi(VerbaleDellAssemblea $verbale, int $id): array
    {
        $v = VersioneDelVerbale::query()->where('verbale_id', $verbale->id)->whereKey($id)->first() ?? abort(404);

        return self::voce($v) + ['documento' => $v->documento];
    }

    /** «Ripristina» una versione: il testo di adesso resta fra le versioni, il testo della versione torna, ed è una versione «ripristino della versione 2». */
    public function ripristina(VerbaleDellAssemblea $verbale, int $id, User $utente): VersioneDelVerbale
    {
        if (! $verbale->siScrive()) {
            throw ValidationException::withMessages(['versione' => 'Il verbale è registrato: il testo è quello firmato, e «Ripristina» non c\'è più.']);
        }
        $v = VersioneDelVerbale::query()->where('verbale_id', $verbale->id)->whereKey($id)->first() ?? abort(404);
        if ($v->in_attesa) {
            throw ValidationException::withMessages(['versione' => 'Questa versione aspetta una scelta: scegli prima quale tenere.']);
        }
        $frase = 'ripristino della versione ' . $v->numero . ($v->parte !== 'intero' ? ' (' . self::nomeDellaParte($verbale, $v->parte) . ')' : '');

        return RegistroModifiche::durante(GestiDelRegistro::delVerbale($verbale, fn ($lotto, $nuova) => $frase . ', versione ' . $nuova->numero),
            function () use ($verbale, $v, $utente, $frase) {
                $parte = ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', $v->parte)->lockForUpdate()->first();
                if ($parte !== null) {
                    $this->tieniIlTestoDiAdesso($verbale, $parte, 'il testo prima del ripristino', $utente);
                }
                $documento = (array) $v->documento;
                $this->scrivi($verbale, $v->parte, null, $documento, 'ripristino', now(), $utente);

                return $this->nuova($verbale, $v->parte, $documento, $frase, 'ripristino', null, now(), $utente);
            });
    }

    /**
     * La scelta su una versione in attesa (N6 e scena 5): per le due versioni «Tieni questa» (`questa`: il testo arrivato diventa il testo, quello di prima resta
     * fra le versioni), «Tieni quella» (`quella`: resta il testo di adesso), «Uniscile» (`uniscile`: il testo unito nell'editor, `$documento`); per il testo
     * arrivato dopo la registrazione «Ho visto» (`vista`); per la discussione arrivata dopo la generazione «Mettila nel punto 4 del verbale» (`metti`) o
     * «Lasciala fra le versioni» (`quella`). La scelta è una versione e una riga del registro.
     *
     * @param ?array<string, mixed> $documento il testo unito, per «Uniscile»
     */
    public function scegli(VerbaleDellAssemblea $verbale, int $id, string $scelta, ?array $documento, User $utente): VersioneDelVerbale
    {
        $v = VersioneDelVerbale::query()->where('verbale_id', $verbale->id)->whereKey($id)->first() ?? abort(404);
        if (! $v->aperta()) {
            throw ValidationException::withMessages(['scelta' => 'Su questa versione non c\'è una scelta aperta.']);
        }
        $ammesse = match ($v->attesa) {
            VersioneDelVerbale::DOPO_LA_REGISTRAZIONE => ['vista'],
            VersioneDelVerbale::DOPO_LA_GENERAZIONE => ['metti', 'quella'],
            default => ['questa', 'quella', 'uniscile'],
        };
        if (! in_array($scelta, $ammesse, true)) {
            throw ValidationException::withMessages(['scelta' => match ($v->attesa) {
                VersioneDelVerbale::DOPO_LA_REGISTRAZIONE => 'Il verbale registrato è la copia firmata: questa versione resta da parte («Ho visto»).',
                VersioneDelVerbale::DOPO_LA_GENERAZIONE => 'Scegli «Mettila nel verbale» o «Lasciala fra le versioni».',
                default => 'Scegli «Tieni questa», «Tieni quella» o «Uniscile».',
            }]);
        }
        if (in_array($scelta, ['questa', 'uniscile', 'metti'], true) && ! $verbale->siScrive()) {
            throw ValidationException::withMessages(['scelta' => 'Il verbale è registrato: il testo firmato non cambia.']);
        }
        $unito = $scelta === 'uniscile' ? StrutturaDelVerbale::valida($documento) : null;
        $nome = self::nomeDellaParte($verbale, $v->parte);
        $numero = (int) preg_replace('/\D/', '', $nome);
        $frase = match (true) {
            $scelta === 'metti' => $nome . ' arrivata dopo la generazione messa nel punto ' . $numero . ' del verbale, dal dispositivo «' . $v->dispositivo . '»',
            $scelta === 'quella' && $v->attesa === VersioneDelVerbale::DOPO_LA_GENERAZIONE => $nome . ' arrivata dopo la generazione lasciata fra le versioni, dal '
                . 'dispositivo «' . $v->dispositivo . '»',
            $scelta === 'questa' => 'fra due versioni di ' . $nome . ' tenuta quella del dispositivo «' . $v->dispositivo . '»',
            $scelta === 'quella' => 'fra due versioni di ' . $nome . ' tenuto il testo di adesso; quella del dispositivo «' . $v->dispositivo . '» resta fra le versioni',
            $scelta === 'uniscile' => 'le due versioni di ' . $nome . ' unite',
            default => 'vista la versione arrivata dopo la registrazione, ' . $nome . ', dal dispositivo «' . $v->dispositivo . '»',
        };
        RegistroModifiche::durante(GestiDelRegistro::delVerbale($verbale, $frase), function () use ($verbale, $v, $scelta, $unito, $utente, $frase) {
            if ($scelta === 'metti') {
                app(ComposizioneDelVerbale::class)->mettiLaDiscussione($verbale, (int) self::puntoDi($v->parte), (array) $v->documento, $utente, $frase);
            }
            if ($scelta === 'questa' || $scelta === 'uniscile') {
                $parte = ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', $v->parte)->lockForUpdate()->first();
                if ($parte !== null) {
                    $this->tieniIlTestoDiAdesso($verbale, $parte, 'il testo prima della scelta', $utente);
                }
                $testo = $unito ?? (array) $v->documento;
                $this->scrivi($verbale, $v->parte, null, $testo, 'scelta', now(), $utente);
                $this->nuova($verbale, $v->parte, $testo, mb_substr($frase, 0, 200), 'scelta', null, now(), $utente);
            }
            $v->update(['scelta' => $scelta, 'scelta_il' => now(), 'scelta_da' => $utente->id]);
        });
        app(CompitiDelVerbale::class)->sincronizza($verbale->assemblea()->firstOrFail());

        return $v->refresh();
    }

    /**
     * La sala chiude (o sospende) la riunione: la discussione di ogni punto diventa una versione «scritta in sala», in una riga sola del registro, con l'ora
     * del gesto della sala (`$quando`).
     */
    public function allaChiusuraDellaSala(\App\Models\Assemblee\SedutaDellAssemblea $seduta, User $utente, ?\DateTimeInterface $quando = null): void
    {
        $verbale = VerbaliDellAssemblea::dellaSeduta($seduta);
        if ($verbale === null || ! $verbale->siScrive()) {
            return;
        }
        $parti = ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'like', 'punto:%')->get();
        if ($parti->isEmpty()) {
            return;
        }
        RegistroModifiche::durante(GestiDelRegistro::delVerbale($verbale, function ($lotto, $numeri) {
            return 'discussioni scritte in sala: ' . ($numeri === [] ? 'nessuna nuova' : (count($numeri) === 1 ? 'il punto ' : 'i punti ')
                . \App\Services\Assemblee\Convocazione\ConvocatiDellAssemblea::elenco(array_map('strval', $numeri)));
        }, $quando)->scriviSe(fn ($lotto, $numeri) => $numeri !== []), function () use ($verbale, $parti, $utente) {
            $numeri = [];
            foreach ($parti as $p) {
                $nome = self::nomeDellaParte($verbale, $p->parte);
                if ($this->tieniIlTestoDiAdesso($verbale, $p, 'scritta in sala: ' . $nome, $utente) !== null) {
                    $numeri[] = (int) preg_replace('/\D/', '', $nome);
                }
            }
            sort($numeri);

            return $numeri;
        });
    }

    /** Le scelte aperte del verbale: versioni in attesa non ancora scelte. */
    public static function scelteAperte(VerbaleDellAssemblea $verbale, ?string $attesa = null): int
    {
        return VersioneDelVerbale::query()->where('verbale_id', $verbale->id)->where('in_attesa', true)->whereNull('scelta_il')
            ->when($attesa !== null, fn ($q) => $q->where('attesa', $attesa))->count();
    }

    /** «la discussione del punto 4», «il verbale». */
    public static function nomeDellaParte(VerbaleDellAssemblea $verbale, string $parte): string
    {
        $punto = self::puntoDi($parte);
        if ($punto === null) {
            return 'il verbale';
        }
        $numero = \App\Models\Assemblee\PuntoDellOrdineDelGiorno::query()->whereKey($punto)->value('numero');

        return 'la discussione del punto ' . ($numero ?? '?');
    }

    /** Il punto di una parte `punto:<id>`, o null. */
    public static function puntoDi(string $parte): ?int
    {
        return preg_match('/^punto:(\d+)$/', $parte, $m) === 1 ? (int) $m[1] : null;
    }

    /** Lo stato in cui il testo si scrive ancora. */
    public static function siScrive(VerbaleDellAssemblea $verbale): bool
    {
        return in_array($verbale->stato, [StatoVerbale::IN_SALA, StatoVerbale::BOZZA], true);
    }
}
