<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use App\Services\RegistroModifiche\Schede\SchedaNelRegistro;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use WeakMap;

/**
 * Il primo livello del registro, il campo (§4 del progetto): che cosa scrivono il tratto delle schede e il tratto leggero dei modelli «solo gesto» a ogni
 * evento di un modello.
 *
 * - `created`: una riga «creata» (o «collegata» per un legame) con i dati scritti;
 * - `updated`: una riga «modificata» con i soli dati cambiati, com'erano e come sono (`getRawOriginal()` e gli attributi grezzi, lo stesso formattatore per
 *   tutti e due, P19); nessuna riga se, tolti gli esclusi e i campi del programma, non resta un campo cambiato (P8);
 * - `deleted`: una riga «eliminata» (o «scollegata») con i dati che c'erano; condomini, nome e soggetti letti in `deleting`, prima che le chiavi a cascata
 *   li portino via.
 *
 * Dentro un gesto aperto con `durante` il tratto non scrive righe sue: conta nel lotto. Un valore che non si sa scrivere non ferma la modifica (si ripiega
 * sul testo grezzo); un errore di scrittura della riga, nella transazione, sì (decisione 518).
 *
 * Durante un aggiornamento le migrazioni dei dati che usano i modelli girano prima di quella che crea il registro: finché la tabella non c'è, non si
 * scrive (e si ricontrolla a ogni scrittura, così una migrazione dopo quella del registro scrive).
 */
final class RigheDelTratto
{
    private static bool $tabellaCe = false;

    /** @var WeakMap<Model, array{condomini: array<int, string>, nome: string, soggetti: list<array{0: string, 1: int}>, scheda: array}>|null */
    private static ?WeakMap $primaDiEliminare = null;

    public static function creata(Model $modello): void
    {
        self::evento($modello, 'creata');
    }

    public static function modificata(Model $modello): void
    {
        self::evento($modello, 'modificata');
    }

    public static function inEliminazione(Model $modello): void
    {
        if ((ContestoDelRegistro::inUnGesto() && ! self::lasciataDaUnLotto($modello)) || ! self::registroPronto()) {
            return;
        }
        $descrizione = CatalogoDelRegistro::descrizionePer($modello);
        if ($descrizione === null || ! $descrizione->registra($modello)) {
            return;
        }
        self::$primaDiEliminare ??= new WeakMap();
        self::$primaDiEliminare[$modello] = [
            'condomini' => $descrizione->condomini($modello),
            'nome' => $descrizione->nome($modello),
            'soggetti' => $descrizione->soggetti($modello),
            'scheda' => $descrizione->scheda($modello),
            'riepilogo' => $descrizione->riepilogo($modello, $descrizione->azioneDi('eliminata')),
        ];
    }

    public static function eliminata(Model $modello): void
    {
        self::evento($modello, 'eliminata');
    }

    public static function ripristinata(Model $modello): void
    {
        self::evento($modello, 'ripristinata');
    }

    /** Il tratto leggero dei modelli «solo gesto»: dentro un gesto si contano, fuori si annotano (P5) o, per le scritture, scrivono la loro riga. */
    public static function scritturaDiUnGesto(Model $modello, string $azione): void
    {
        $tipo = CatalogoDelRegistro::tipoDi($modello);
        $lotto = ContestoDelRegistro::lottoAperto();
        if ($lotto !== null) {
            $lotto->conta($tipo, $azione, $modello->getKey() === null ? null : (int) $modello->getKey());

            return;
        }
        if (in_array($modello::class, CatalogoDelRegistro::CON_RIGA_PROPRIA, true)) {
            if (self::registroPronto()) {
                self::rigaDellaScrittura($modello, $azione);
            }

            return;
        }
        ContestoDelRegistro::annotaFuoriGesto($tipo, $azione, $modello->getKey() === null ? null : (int) $modello->getKey(), $modello::class);
    }

    private static function evento(Model $modello, string $evento): void
    {
        $descrizione = CatalogoDelRegistro::descrizionePer($modello);
        if ($descrizione === null || ! $descrizione->registra($modello)) {
            return;
        }
        $azione = $descrizione->azioneDi($evento);
        $ripristino = $evento === 'modificata' ? ContestoDelRegistro::ripristinoInCorso() : null;
        if ($ripristino !== null) {
            $azione = 'ripristinata';
        }

        $lotto = ContestoDelRegistro::lottoAperto();
        if ($lotto !== null && ! self::lasciataDaUnLotto($modello)) {
            $lotto->conta($descrizione->tipo(), $azione, $modello->getKey() === null ? null : (int) $modello->getKey());

            return;
        }
        if (! self::registroPronto()) {
            return;
        }

        $campi = match ($evento) {
            'modificata' => self::campiCambiati($modello, $descrizione),
            'eliminata' => self::campiScritti($modello, $descrizione, true),
            default => self::campiScritti($modello, $descrizione, false),
        };
        if ($evento === 'modificata' && $campi === []) {
            return;
        }
        // Decisione 554: una scheda nata in questa transazione e cambiata prima della sua fine (la comunicazione programmata salvata due volte) ha una
        // riga sola: i dati cambiati vanno nella «Creata» che aspetta.
        $chiave = $descrizione->tipo() . ':' . ($modello->getKey() === null ? '' : (int) $modello->getKey());
        if ($evento === 'modificata' && $ripristino === null && RigheRimandate::aggiungiLegame($chiave, ['aggiorna' => $campi])) {
            return;
        }

        $prima = $evento === 'eliminata' && self::$primaDiEliminare !== null && isset(self::$primaDiEliminare[$modello])
            ? self::$primaDiEliminare[$modello] : null;
        [$schedaTipo, $schedaId] = $prima['scheda'] ?? $descrizione->scheda($modello);
        $id = $modello->getKey() === null ? null : (int) $modello->getKey();
        $riepilogo = $prima !== null ? $prima['riepilogo'] : $descrizione->riepilogo($modello, $azione);
        $contaDati = $riepilogo === null;
        if ($riepilogo === null && in_array($evento, ['creata', 'eliminata'], true) && $campi !== []) {
            $riepilogo = 'Con ' . count($campi) . (count($campi) === 1 ? ' dato.' : ' dati.');
        }

        $riga = [
            'area' => $descrizione->areaDi($modello),
            'oggetto_tipo' => $descrizione->tipo(),
            'oggetto_id' => $id,
            'oggetto_nome' => $prima['nome'] ?? $descrizione->nome($modello),
            'scheda_tipo' => $schedaTipo === $descrizione->tipo() && $schedaId === $id ? null : $schedaTipo,
            'scheda_id' => $schedaTipo === $descrizione->tipo() && $schedaId === $id ? null : $schedaId,
            'azione' => $azione,
            'riepilogo' => $riepilogo,
            'perche' => $ripristino,
            'campi' => $campi,
        ];

        // Decisione 554: la riga di una scheda nuova si scrive alla fine della transazione della creazione, con i legami fatti nel frattempo e i condomini
        // letti allora (una riga sola, nel registro del condominio). I dati, il nome e l'autore sono quelli di adesso.
        if ($evento === 'creata' && $id !== null) {
            $riga += ['autore' => ContestoDelRegistro::autore(), 'avvenuta_il' => now()];
            RigheRimandate::rimanda($descrizione->tipo() . ':' . $id, function (array $legami) use ($riga, $descrizione, $modello, $contaDati): array {
                $soggetti = $descrizione->soggetti($modello);
                foreach ($legami as $legame) {
                    foreach ($legame['aggiorna'] ?? [] as $cambiato) {
                        $riga['campi'] = self::nellaCreata($riga['campi'], $cambiato);
                    }
                    if (($legame['campo'] ?? null) !== null) {
                        $riga['campi'][] = $legame['campo'];
                    }
                    array_push($soggetti, ...($legame['soggetti'] ?? []));
                }
                if ($contaDati && $riga['campi'] !== []) {
                    $riga['riepilogo'] = 'Con ' . count($riga['campi']) . (count($riga['campi']) === 1 ? ' dato.' : ' dati.');
                }

                return $riga + ['condomini' => $descrizione->condomini($modello), 'soggetti' => $soggetti];
            });

            return;
        }

        RegistroModifiche::scrivi($riga + [
            'condomini' => $prima['condomini'] ?? $descrizione->condomini($modello),
            'soggetti' => $prima['soggetti'] ?? $descrizione->soggetti($modello),
        ]);
    }

    /**
     * Un dato cambiato dopo la creazione, nella stessa transazione, dentro i dati della «Creata»: prende il posto di quello scritto, o si aggiunge;
     * un dato tornato vuoto se ne va.
     *
     * @param list<array<string, mixed>> $campi
     * @param array<string, mixed> $cambiato
     * @return list<array<string, mixed>>
     */
    private static function nellaCreata(array $campi, array $cambiato): array
    {
        $campi = array_values(array_filter($campi, fn ($c) => $c['chiave'] !== $cambiato['chiave']));
        if ($cambiato['dopo'] !== null) {
            $campi[] = ['prima' => null, 'valore_prima' => null] + $cambiato;
        }

        return $campi;
    }

    /** @return list<array<string, mixed>> */
    private static function campiCambiati(Model $modello, SchedaNelRegistro $descrizione): array
    {
        $campi = [];
        $attributi = $modello->getAttributes();
        foreach (array_keys($modello->getChanges()) as $colonna) {
            if (! $descrizione->registrata($colonna, $modello)) {
                continue;
            }
            $grezzoPrima = $modello->getRawOriginal($colonna);
            $grezzoDopo = $attributi[$colonna] ?? null;
            $formato = $descrizione->formato($colonna);
            $prima = FormatoDelValore::testo($grezzoPrima, $formato);
            $dopo = FormatoDelValore::testo($grezzoDopo, $formato);
            $segreto = $descrizione->segreto($colonna);
            if ($prima === $dopo && ! $segreto) {
                continue;
            }
            $campi[] = Campo::nuovo($colonna, self::etichetta($descrizione, $colonna), $prima, $dopo, $grezzoPrima, $grezzoDopo, $segreto);
        }

        return $campi;
    }

    /** I dati scritti di una scheda nuova o eliminata: solo quelli non vuoti. @return list<array<string, mixed>> */
    private static function campiScritti(Model $modello, SchedaNelRegistro $descrizione, bool $eliminata): array
    {
        $campi = [];
        foreach ($modello->getAttributes() as $colonna => $grezzo) {
            if ($grezzo === null || $grezzo === '' || ! $descrizione->registrata($colonna, $modello)) {
                continue;
            }
            $testo = FormatoDelValore::testo($grezzo, $descrizione->formato($colonna));
            $segreto = $descrizione->segreto($colonna);
            $campi[] = $eliminata
                ? Campo::nuovo($colonna, self::etichetta($descrizione, $colonna), $testo, null, $grezzo, null, $segreto)
                : Campo::nuovo($colonna, self::etichetta($descrizione, $colonna), null, $testo, null, $grezzo, $segreto);
        }

        return $campi;
    }

    /** La parola della pagina; una colonna senza parola la ferma il test della classificazione, qui non si ferma la modifica. */
    private static function etichetta(SchedaNelRegistro $descrizione, string $colonna): string
    {
        return $descrizione->etichetta($colonna) ?? Str::ucfirst(str_replace('_', ' ', $colonna));
    }

    /** La riga di una scrittura contabile fuori da un gesto (§5): «Scrittura registrata: <causale>, € 120,00», con il rimando alla scrittura. */
    private static function rigaDellaScrittura(Model $scrittura, string $azione): void
    {
        $causale = trim((string) ($scrittura->getAttribute('causale') ?: $scrittura->getAttribute('descrizione')));
        $protocollo = (string) $scrittura->getAttribute('numero_protocollo');
        if ($azione === 'modificata') {
            if (! $scrittura->wasChanged('stato') || $scrittura->getAttribute('stato') !== 'annullata') {
                return;
            }
            $riepilogo = 'Scrittura annullata: ' . $causale;
        } else {
            $riepilogo = ($azione === 'creata' ? 'Scrittura registrata: ' : 'Scrittura eliminata: ') . $causale;
        }

        $gesto = Gesto::su('contabilita', 'scrittura_contabile', (int) $scrittura->getKey(), 'Scrittura' . ($protocollo !== '' ? ' prot. ' . $protocollo : ''))
            ->riepilogo($riepilogo)
            ->rimando('scrittura_contabile', (int) $scrittura->getKey(), $protocollo !== '' ? 'Prot. ' . $protocollo : null)
            ->nelCondominio($scrittura->getAttribute('condominio_id') === null ? null : (int) $scrittura->getAttribute('condominio_id'));
        if ($azione !== 'creata') {
            RegistroModifiche::scrivi($gesto->perLaRiga());

            return;
        }

        // Decisione 556: «Scrittura registrata: Incasso rata, € 120,00». All'evento della testata le righe non ci sono ancora: la riga si scrive alla fine
        // della transazione che registra, con la cifra delle righe in dare (in centesimi, come le scrive il programma). Senza righe, senza cifra.
        $riga = $gesto->perLaRiga() + ['autore' => ContestoDelRegistro::autore(), 'avvenuta_il' => now()];
        $id = (int) $scrittura->getKey();
        RigheRimandate::rimanda('scrittura_contabile:' . $id, function () use ($riga, $id): array {
            $cifra = (int) DB::table('righe_scritture')->where('scrittura_id', $id)->where('tipo_riga', 'dare')->sum('importo');
            if ($cifra !== 0) {
                $riga['riepilogo'] = trim((string) $riga['riepilogo']) . ', ' . FormatoDelValore::testo($cifra, 'euro');
            }

            return $riga;
        });
    }

    /** Se un gesto aperto lascia scrivere a questa scheda la sua riga (Gesto::conIlTrattoDi). */
    private static function lasciataDaUnLotto(Model $modello): bool
    {
        foreach (ContestoDelRegistro::lottiAperti() as $lotto) {
            if ($lotto->lasciata($modello)) {
                return true;
            }
        }

        return false;
    }

    private static function registroPronto(): bool
    {
        if (self::$tabellaCe) {
            return true;
        }

        return self::$tabellaCe = Schema::hasTable('registro_modifiche');
    }

    /** Per i test che tolgono la tabella. */
    public static function dimenticaLaTabella(): void
    {
        self::$tabellaCe = false;
    }
}
