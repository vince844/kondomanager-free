<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Verbale;

use App\Enums\Assemblee\EsitoDelGesto;
use App\Enums\Assemblee\StatoVerbale;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\GestoDellaSala;
use App\Models\Assemblee\ParteDelVerbale;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\Assemblee\VersioneDelVerbale;
use App\Models\User;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * I gesti del testo del verbale (V4; la tenuta senza rete, decisioni 514 (5), 519): **un salvataggio è un gesto numerato** `{gesto, tipo: 'testo', dati:
 * {verbale_id (o seduta_id, per la discussione scritta senza rete prima di conoscere il verbale), parte, documento}, base, orologio, segnato_il}`, in ordine,
 * ognuno nella sua transazione, con la riga nel libro dei gesti dell'assemblea (`assemblee_gesti`, tipo `verbale_testo`) **senza il testo**: il verbale, la
 * parte, la revisione, l'impronta e i caratteri.
 *
 * - **Il numero**: lo stesso gesto due volte conta una volta e risponde «doppio» con la revisione di allora (la risposta persa, il gesto ripetuto).
 * - **Le due versioni** solo se dopo la `base` ha scritto **un altro** scrittore (o il programma: la generazione, un ripristino): lo scrittore è il dispositivo
 *   e la scheda del browser (`dati.scheda`), così la stessa macchina che manda più salvataggi di fila non è mai in conflitto con sé stessa, ma due schede dello
 *   stesso browser sì. In conflitto niente si sovrascrive: il testo arrivato è una versione in attesa, e sceglie l'amministratore («Tieni questa», «Tieni
 *   quella», «Uniscile»). **Finché la scelta è aperta**, ciò che lo stesso scrittore continua a mandare per quella parte non si applica: aggiorna la sua
 *   versione in attesa (il testo dell'altro dispositivo non si cancella mai). La risposta in conflitto non dà una revisione da cui ripartire.
 * - **Dopo la generazione** la discussione di un punto (`punto:<id>`) non è più letta dal verbale intero: arrivata tardi (il tablet rimasto senza rete), è
 *   una versione in attesa «arrivata dopo la generazione», che la pagina mostra con «Mettila nel punto 4 del verbale»; mai «applicato» senza esserlo.
 * - **Dopo la registrazione** un testo che arriva da un dispositivo rimasto senza rete non si perde e non si applica: è una versione «arrivata dopo la
 *   registrazione», e la pagina lo dice. Una parte di una registrazione annullata va al verbale che la sostituisce.
 * - **L'orologio**: l'ora del gesto oltre due minuti nel futuro si rifiuta. **Il controllo**: il verbale è di questa assemblea, la parte è sua, lo stato lo
 *   permette, la struttura passa da {@see StrutturaDelVerbale} sul server: mai quella del browser presa per buona.
 * - **Le versioni** nascono dopo due minuti senza scrivere ({@see VersioniDelVerbale::PAUSA}); un salvataggio non è una riga del registro, una versione sì.
 *
 * Le risposte: `{gesto, tipo, esito: applicato|doppio|in_conflitto|rifiutato, messaggio, verbale_id, parte, revisione, impronta, versione_id?, conflitto?}`.
 */
final class GestiDelVerbale
{
    public const TIPO = 'testo';

    /** Il tipo nel libro dei gesti dell'assemblea. */
    public const NEL_LIBRO = 'verbale_testo';

    public const TETTO = 50;

    public const SECONDI_AVANTI = 120;

    public function __construct(private readonly VersioniDelVerbale $versioni) {}

    /** @param list<array<string, mixed>> $gesti @return list<array<string, mixed>> */
    public function ricevi(Assemblea $assemblea, string $dispositivo, array $gesti, User $utente): array
    {
        $risposte = [];
        foreach ($gesti as $gesto) {
            $risposte[] = $this->uno($assemblea, $dispositivo, $gesto, $utente);
        }

        return $risposte;
    }

    /** Un gesto del testo. @param array<string, mixed> $gesto @return array<string, mixed> */
    public function uno(Assemblea $assemblea, string $dispositivo, array $gesto, User $utente): array
    {
        $numero = strtolower((string) $gesto['gesto']);
        $dati = (array) ($gesto['dati'] ?? []);
        $risposta = ['gesto' => $numero, 'tipo' => self::TIPO, 'esito' => null, 'messaggio' => null, 'verbale_id' => null, 'parte' => (string) ($dati['parte'] ?? ''),
            'revisione' => null, 'impronta' => null, 'versione' => null, 'conflitto' => null];
        $gia = GestoDellaSala::query()->where('gesto', $numero)->first();
        if ($gia !== null) {
            return $this->doppio($assemblea, $gia, $risposta);
        }
        $segnato = CarbonImmutable::parse((string) $gesto['segnato_il'])->utc();
        $orologio = isset($gesto['orologio']) && $gesto['orologio'] !== null ? CarbonImmutable::parse((string) $gesto['orologio'])->utc() : null;
        $adesso = CarbonImmutable::now()->utc();
        $base = isset($gesto['base']) && $gesto['base'] !== null ? (int) $gesto['base'] : null;
        $riga = ['assemblea_id' => $assemblea->id, 'gesto' => $numero, 'dispositivo' => $dispositivo, 'tipo' => self::NEL_LIBRO, 'base' => $base,
            'segnato_il' => $segnato, 'orologio' => $orologio, 'arrivato_il' => $adesso, 'utente_id' => $utente->id,
            'dati' => array_filter(['verbale_id' => $dati['verbale_id'] ?? null, 'seduta_id' => $dati['seduta_id'] ?? null, 'parte' => $dati['parte'] ?? null,
                'scheda' => self::scheda($dati)], fn ($v) => $v !== null)];
        if (($gesto['tipo'] ?? null) !== self::TIPO) {
            return $this->scritto($riga, $risposta, EsitoDelGesto::RIFIUTATO, 'Un gesto che il verbale non conosce: «' . ($gesto['tipo'] ?? '') . '».');
        }
        if ($segnato->gt($adesso->addSeconds(self::SECONDI_AVANTI))) {
            return $this->scritto($riga, $risposta, EsitoDelGesto::RIFIUTATO, 'L\'ora del salvataggio (' . ParoleDelVerbale::ora($segnato) . ') è avanti rispetto al server ('
                . ParoleDelVerbale::ora($adesso) . '): controlla l\'orologio di questo dispositivo.');
        }

        try {
            return DB::transaction(function () use ($assemblea, $dispositivo, $dati, $base, $segnato, $utente, $riga, $risposta, $numero) {
                $scritta = GestoDellaSala::create($riga + ['esito' => EsitoDelGesto::APPLICATO->value]);
                $esito = $this->applica($assemblea, $dispositivo, $dati, $base, $segnato, $utente, $numero);
                $scritta->update(['esito' => $esito['esito'], 'messaggio' => mb_substr((string) $esito['messaggio'], 0, 500) ?: null,
                    'dati' => array_filter(['verbale_id' => $esito['verbale_id'], 'parte' => $esito['parte'], 'revisione' => $esito['revisione'],
                        'impronta' => $esito['impronta'], 'caratteri' => $esito['caratteri'] ?? null, 'versione_id' => $esito['versione_id'] ?? null,
                        'scheda' => self::scheda($dati)], fn ($v) => $v !== null)]);
                unset($esito['caratteri']);

                return array_merge($risposta, $esito);
            });
        } catch (ValidationException $e) {
            return $this->scritto($riga, $risposta, EsitoDelGesto::RIFIUTATO, (string) collect($e->errors())->flatten()->first());
        } catch (QueryException $e) {
            $gia = GestoDellaSala::query()->where('gesto', $numero)->first();
            if ($gia !== null) {
                return $this->doppio($assemblea, $gia, $risposta);
            }

            throw $e;
        }
    }

    /** @param array<string, mixed> $dati @return array<string, mixed> */
    private function applica(Assemblea $assemblea, string $dispositivo, array $dati, ?int $base, CarbonImmutable $segnato, User $utente, string $numero): array
    {
        $verbale = $this->ilVerbale($assemblea, $dati, $utente);
        $parte = (string) ($dati['parte'] ?? '');
        $puntoId = $this->controllaLaParte($assemblea, $verbale, $parte);
        $documento = StrutturaDelVerbale::valida($dati['documento'] ?? null);
        if ($puntoId !== null && StrutturaDelVerbale::blocchi($documento) !== []) {
            throw ValidationException::withMessages(['gesto' => 'Le parti «Dati della sala» le mette il programma nel verbale: nella discussione si scrive solo ciò che si è detto.']);
        }
        $impronta = StrutturaDelVerbale::impronta($documento);
        $nomeParte = VersioniDelVerbale::nomeDellaParte($verbale, $parte);
        $scheda = self::scheda($dati);
        $esito = ['verbale_id' => (int) $verbale->id, 'parte' => $parte, 'impronta' => $impronta, 'caratteri' => StrutturaDelVerbale::caratteri($documento)];

        // Dopo la registrazione: il testo non si perde e non si applica.
        if (! $verbale->siScrive()) {
            $versione = RegistroModifiche::durante(GestiDelRegistro::delVerbale($verbale, 'arrivata una versione dopo la registrazione, ' . $nomeParte . ' · dal dispositivo «'
                . $dispositivo . '», scritta alle ' . ParoleDelVerbale::ora($segnato), $segnato),
                fn () => $this->versioni->nuova($verbale, $parte, $documento, 'arrivata dopo la registrazione: ' . $nomeParte, $dispositivo, $numero, $segnato, $utente,
                    VersioneDelVerbale::DOPO_LA_REGISTRAZIONE));

            return $esito + ['esito' => EsitoDelGesto::IN_CONFLITTO->value, 'revisione' => null, 'versione_id' => (int) $versione->id,
                'messaggio' => 'Il verbale n. ' . $verbale->numero . ' è registrato: il testo firmato non cambia. Questo testo resta da parte, fra le versioni.',
                'conflitto' => ['tipo' => VersioneDelVerbale::DOPO_LA_REGISTRAZIONE, 'versione_id' => (int) $versione->id]];
        }

        // Dopo la generazione, la discussione di un punto arrivata tardi: il verbale intero non la legge più, e resta da parte con la sua scelta.
        if ($puntoId !== null && $verbale->stato === StatoVerbale::BOZZA) {
            $versione = $this->inAttesa($verbale, $parte, VersioneDelVerbale::DOPO_LA_GENERAZIONE, $dispositivo, $scheda, $documento, $numero, $segnato, $utente,
                'arrivata dopo la generazione: ' . $nomeParte);
            app(CompitiDelVerbale::class)->sincronizza($assemblea);
            $numeroDelPunto = (int) preg_replace('/\D/', '', $nomeParte);

            return $esito + ['esito' => EsitoDelGesto::IN_CONFLITTO->value, 'revisione' => null, 'versione_id' => (int) $versione->id,
                'messaggio' => 'Il verbale è già generato: ' . $nomeParte . ' arrivata adesso non è nel testo. Resta da parte, fra le versioni: nella pagina del '
                    . 'verbale, «Mettila nel punto ' . $numeroDelPunto . ' del verbale».',
                'conflitto' => ['tipo' => VersioneDelVerbale::DOPO_LA_GENERAZIONE, 'versione_id' => (int) $versione->id]];
        }

        $riga = ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', $parte)->lockForUpdate()->first();
        $adesso = (int) ($riga?->revisione ?? 0);
        // Una scelta fra due versioni aperta su questa parte, con la versione di questo scrittore: ciò che manda ancora va nella sua versione in attesa.
        $aperta = $this->versioneAperta($verbale, $parte, VersioneDelVerbale::DUE_VERSIONI, $dispositivo, $scheda);
        if ($aperta !== null) {
            $aperta->update(['documento' => $documento, 'impronta' => $impronta, 'segnata_il' => $segnato, 'gesto' => $numero]);

            return $esito + ['esito' => EsitoDelGesto::IN_CONFLITTO->value, 'revisione' => null, 'versione_id' => (int) $aperta->id,
                'messaggio' => ucfirst($nomeParte) . ' ha due versioni da scegliere: finché non scegli, ciò che scrivi qui resta nella tua versione, e il testo '
                    . 'dell\'altro dispositivo non si tocca.',
                'conflitto' => ['tipo' => VersioneDelVerbale::DUE_VERSIONI, 'versione_id' => (int) $aperta->id]];
        }
        if ($base !== null && $base > $adesso) {
            throw ValidationException::withMessages(['gesto' => 'Il salvataggio parte da una revisione che il verbale non ha: ricarica la pagina.']);
        }
        if ($riga !== null && $this->scrittoDaUnAltro($riga, $base, $dispositivo, $scheda)) {
            $versione = RegistroModifiche::durante(GestiDelRegistro::delVerbale($verbale, 'due versioni: ' . $nomeParte . ', dal dispositivo «' . $dispositivo
                . '», scritta alle ' . ParoleDelVerbale::ora($segnato), $segnato),
                fn () => $this->versioni->nuova($verbale, $parte, $documento, 'due versioni: ' . $nomeParte . ', dal dispositivo «' . $dispositivo . '»', $dispositivo,
                    $numero, $segnato, $utente, VersioneDelVerbale::DUE_VERSIONI));
            app(CompitiDelVerbale::class)->sincronizza($assemblea);

            // La revisione di adesso non è una base da cui ripartire: chi riparte da lì cancellerebbe il testo dell'altro dispositivo.
            return $esito + ['esito' => EsitoDelGesto::IN_CONFLITTO->value, 'revisione' => null, 'versione_id' => (int) $versione->id,
                'messaggio' => ucfirst($nomeParte) . ' è stata cambiata anche da «' . $riga->dispositivo . '», alle ' . ParoleDelVerbale::ora($riga->scritta_il)
                    . '. Non sovrascrivo niente: le due versioni restano, e scegli tu quale tenere, o le unisci.',
                'conflitto' => ['tipo' => VersioneDelVerbale::DUE_VERSIONI, 'versione_id' => (int) $versione->id, 'revisione_di_adesso' => $adesso,
                    'dispositivo_di_adesso' => $riga->dispositivo, 'scritta_il_di_adesso' => $riga->scritta_il?->copy()->utc()->toIso8601ZuluString()]];
        }
        if ($riga !== null && StrutturaDelVerbale::impronta((array) $riga->documento) === $impronta) {
            app(ChiScriveIlVerbale::class)->haScritto($verbale, $parte, $dispositivo, $utente);

            return $esito + ['esito' => EsitoDelGesto::APPLICATO->value, 'revisione' => $adesso, 'messaggio' => 'Era già così: niente da cambiare.'];
        }
        // Dopo due minuti senza scrivere, il testo di prima diventa una versione: una riga del registro, con l'ora in cui era stato scritto.
        if ($riga !== null && $riga->scritta_il !== null && $segnato->diffInSeconds(CarbonImmutable::instance($riga->scritta_il), true) >= VersioniDelVerbale::PAUSA
            && $segnato->gt($riga->scritta_il)) {
            $perche = $verbale->stato === StatoVerbale::IN_SALA ? 'scritta in sala: ' . $nomeParte : ($puntoId !== null ? 'correzioni: ' . $nomeParte : 'correzioni');
            RegistroModifiche::durante(GestiDelRegistro::delVerbale($verbale, fn ($lotto, $v) => 'versione ' . ($v?->numero ?? '') . ', ' . $perche
                . ' · dal dispositivo «' . $riga->dispositivo . '»', $riga->scritta_il)->scriviSe(fn ($lotto, $v) => $v !== null),
                fn () => $this->versioni->tieniIlTestoDiAdesso($verbale, $riga, $perche, $utente));
        }
        $scritta = $this->versioni->scrivi($verbale, $parte, $puntoId, $documento, $dispositivo, $segnato, $utente, $scheda);
        app(ChiScriveIlVerbale::class)->haScritto($verbale, $parte, $dispositivo, $utente);

        return $esito + ['esito' => EsitoDelGesto::APPLICATO->value, 'revisione' => (int) $scritta->revisione, 'messaggio' => null];
    }

    /**
     * Dopo la base ha scritto un altro scrittore (un altro dispositivo, un'altra scheda dello stesso browser, il programma), o la base è troppo vecchia per
     * saperlo.
     */
    private function scrittoDaUnAltro(ParteDelVerbale $riga, ?int $base, string $dispositivo, ?string $scheda): bool
    {
        $adesso = (int) $riga->revisione;
        if ($base === null || $base >= $adesso) {
            return false;
        }
        $scrittori = (array) ($riga->scrittori ?? []);
        $dopo = array_filter($scrittori, fn ($s) => (int) $s[0] > $base);
        if (count($dopo) < $adesso - $base) {
            return true;
        }
        foreach ($dopo as $s) {
            if (($s[1] ?? null) !== $dispositivo || ($s[2] ?? null) !== $scheda) {
                return true;
            }
        }

        return false;
    }

    /** La scheda del browser che ha scritto il gesto (`dati.scheda`), o null: con lo stesso dispositivo, due schede sono due scrittori. */
    private static function scheda(array $dati): ?string
    {
        $scheda = $dati['scheda'] ?? null;

        return is_string($scheda) && preg_match('/^[A-Za-z0-9-]{1,40}$/', $scheda) === 1 ? $scheda : null;
    }

    /** La versione in attesa ancora aperta di una parte, scritta da questo scrittore. */
    private function versioneAperta(VerbaleDellAssemblea $verbale, string $parte, string $attesa, string $dispositivo, ?string $scheda): ?VersioneDelVerbale
    {
        return VersioneDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', $parte)->where('in_attesa', true)->whereNull('scelta_il')
            ->where('attesa', $attesa)->where('dispositivo', $dispositivo)->orderByDesc('id')->lockForUpdate()->get()
            ->first(fn (VersioneDelVerbale $v) => self::schedaDelGesto($v->gesto) === $scheda);
    }

    /** La scheda che ha scritto un gesto, dal libro dei gesti (la riga del gesto la tiene fra i suoi dati, senza il testo). */
    private static function schedaDelGesto(?string $gesto): ?string
    {
        if ($gesto === null) {
            return null;
        }
        $dati = (array) (GestoDellaSala::query()->where('gesto', $gesto)->value('dati') ?? []);

        return isset($dati['scheda']) && is_string($dati['scheda']) ? $dati['scheda'] : null;
    }

    /**
     * La versione in attesa di questo scrittore per una parte: quella aperta si aggiorna con il testo nuovo (un tablet che continua a mandare la stessa
     * discussione non fa dieci versioni), altrimenti nasce, con la sua riga nel registro.
     *
     * @param array<string, mixed> $documento
     */
    private function inAttesa(VerbaleDellAssemblea $verbale, string $parte, string $attesa, string $dispositivo, ?string $scheda, array $documento, string $numero,
        CarbonImmutable $segnato, User $utente, string $perche): VersioneDelVerbale
    {
        $aperta = $this->versioneAperta($verbale, $parte, $attesa, $dispositivo, $scheda);
        if ($aperta !== null) {
            $aperta->update(['documento' => $documento, 'impronta' => StrutturaDelVerbale::impronta($documento), 'segnata_il' => $segnato, 'gesto' => $numero]);

            return $aperta;
        }

        return RegistroModifiche::durante(GestiDelRegistro::delVerbale($verbale, $perche . ' · dal dispositivo «' . $dispositivo . '», scritta alle '
            . ParoleDelVerbale::ora($segnato), $segnato),
            fn () => $this->versioni->nuova($verbale, $parte, $documento, $perche . ', dal dispositivo «' . $dispositivo . '»', $dispositivo, $numero, $segnato,
                $utente, $attesa));
    }

    /**
     * Il verbale del gesto: dal suo numero (di questa assemblea), o dalla seduta (la discussione scritta senza rete prima che la pagina conoscesse il verbale).
     * Una registrazione annullata manda al verbale che la sostituisce.
     *
     * @param array<string, mixed> $dati
     */
    private function ilVerbale(Assemblea $assemblea, array $dati, User $utente): VerbaleDellAssemblea
    {
        if (($dati['verbale_id'] ?? null) !== null) {
            $verbale = VerbaliDellAssemblea::diQuestaAssemblea($assemblea, $dati['verbale_id']);
            if ($verbale->stato === StatoVerbale::ANNULLATO) {
                return VerbaliDellAssemblea::dellaSeduta($verbale->seduta) ?? $verbale;
            }

            return $verbale;
        }
        $seduta = is_numeric($dati['seduta_id'] ?? null) ? SedutaDellAssemblea::query()->where('assemblea_id', $assemblea->id)->whereKey((int) $dati['seduta_id'])->first() : null;
        if ($seduta === null) {
            throw ValidationException::withMessages(['gesto' => 'Il salvataggio non dice di quale verbale è.']);
        }

        return VerbaliDellAssemblea::perLaSeduta($seduta, $utente);
    }

    /** La parte è di questo verbale, e lo stato la permette: la discussione di un punto dell'assemblea, o il verbale intero dopo la generazione. */
    private function controllaLaParte(Assemblea $assemblea, VerbaleDellAssemblea $verbale, string $parte): ?int
    {
        if ($parte === 'intero') {
            if ($verbale->stato === StatoVerbale::IN_SALA) {
                throw ValidationException::withMessages(['gesto' => 'Il verbale non è ancora generato: adesso si scrive la discussione dei punti.']);
            }

            return null;
        }
        $punto = VersioniDelVerbale::puntoDi($parte);
        if ($punto === null || ! PuntoDellOrdineDelGiorno::query()->where('assemblea_id', $assemblea->id)->whereKey($punto)->exists()) {
            throw ValidationException::withMessages(['gesto' => 'La parte non è di questo verbale.']);
        }

        return $punto;
    }

    /** @param array<string, mixed> $riga @param array<string, mixed> $risposta @return array<string, mixed> */
    private function scritto(array $riga, array $risposta, EsitoDelGesto $esito, string $messaggio): array
    {
        try {
            GestoDellaSala::create($riga + ['esito' => $esito->value, 'messaggio' => mb_substr($messaggio, 0, 500)]);
        } catch (QueryException) {
            // Arrivato intanto da un'altra richiesta: vale quello.
        }

        return array_merge($risposta, ['esito' => $esito->value, 'messaggio' => $messaggio]);
    }

    /** @param array<string, mixed> $risposta @return array<string, mixed> */
    private function doppio(Assemblea $assemblea, GestoDellaSala $gia, array $risposta): array
    {
        if ((int) $gia->assemblea_id !== (int) $assemblea->id || $gia->tipo !== self::NEL_LIBRO) {
            return array_merge($risposta, ['esito' => EsitoDelGesto::RIFIUTATO->value, 'messaggio' => 'Questo numero di gesto è già usato.']);
        }
        $dati = (array) $gia->dati;

        return array_merge($risposta, ['esito' => EsitoDelGesto::DOPPIO->value, 'esito_di_allora' => $gia->esito?->value,
            'messaggio' => 'Questo salvataggio era già arrivato alle ' . ParoleDelVerbale::ora($gia->arrivato_il) . ': conta una volta.',
            'verbale_id' => isset($dati['verbale_id']) ? (int) $dati['verbale_id'] : null, 'parte' => (string) ($dati['parte'] ?? $risposta['parte']),
            'revisione' => isset($dati['revisione']) ? (int) $dati['revisione'] : null, 'impronta' => $dati['impronta'] ?? null,
            'versione_id' => isset($dati['versione_id']) ? (int) $dati['versione_id'] : null]);
    }
}
