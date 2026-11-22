<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Enums\Assemblee\ConvocazioneSeduta;
use App\Enums\Assemblee\ModalitaAssemblea;
use App\Enums\Assemblee\OrigineAssemblea;
use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\Assemblee\TipoAssemblea;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\MemoDellAssemblea;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\Condominio;
use App\Models\Palazzina;
use App\Models\Scala;
use App\Models\User;
use App\Services\Assemblee\Convocazione\CompitiDellaConvocazione;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Preparare un'assemblea del programma (§3 P2, R11, §6.3 del progetto della tappa 2): crearla con le date e i punti scelti fra le proposte, cambiarla finché
 * è in preparazione, eliminarla. `assemblee.data` la scrive solo questo servizio, con una regola sola (§13, rilievo 6): la seconda convocazione se c'è,
 * altrimenti la prima.
 *
 * Le date passano da {@see ControlloDelleDate}; quando cambiano, l'agenda si riallinea ({@see AgendaDellAssemblea}) e i compiti della convocazione seguono
 * ({@see CompitiDellaConvocazione::sincronizza()}, §13 rilievo 17).
 */
final class PreparazioneDellAssemblea
{
    /** I campi dell'assemblea che arrivano dalla pagina. */
    private const CAMPI = ['tipo', 'luogo', 'modalita', 'piattaforma', 'consenso_videoconferenza_il', 'consenso_videoconferenza_modo', 'tabella_id',
        'raccomandate_entro_il', 'affissione_dal', 'affissione_al'];

    /**
     * `$dati`: `tipo`, `ambito`, `palazzina_id`, `scala_id`, `luogo`, `modalita`, `piattaforma`, `consenso_videoconferenza_il`, `consenso_videoconferenza_modo`,
     * `tabella_id` (di serie la tabella di proprietà del perimetro), `raccomandate_entro_il`, `sedute` (`[[convocazione, numero, fissata_il, luogo], …]`).
     * `$puntiScelti`: le proposte spuntate ({@see ProposteDellOrdineDelGiorno::per()}), nell'ordine. `$puntiScritti`: i punti scritti dall'amministratore
     * nella pagina, con i campi di «Aggiungi un punto» già risolti nel condominio ({@see \App\Http\Controllers\Assemblee\PuntiController::nelCondominio()}),
     * nell'ordine della pagina. L'ordine del giorno: prima i proposti, poi i punti scritti, «Varie ed eventuali» per ultimo. Una riga sola nel registro, che
     * conta tutti i punti.
     *
     * @param array<string, mixed> $dati
     * @param list<array<string, mixed>> $puntiScelti
     * @param list<array<string, mixed>> $puntiScritti
     * @param list<string>|null $ordine l'ordine scelto nella pagina: le chiavi dei proposti (`chiave_fonte`) e `scritto:N` per il punto scritto N; si tiene
     *        solo ciò che si conosce, una volta sola; quello che manca segue nell'ordine di sempre; «Varie ed eventuali» è sempre l'ultimo
     */
    public static function crea(Condominio $condominio, array $dati, array $puntiScelti, User $utente, array $puntiScritti = [], ?array $ordine = null): Assemblea
    {
        $sedute = self::sedute($dati['sedute'] ?? []);
        [$ambito, $palazzina, $scala] = MemoriaDelCondominio::perimetro($condominio, $dati);
        $campi = self::campi($condominio, $dati) + ['ambito' => $ambito, 'palazzina_id' => $palazzina, 'scala_id' => $scala];
        if (! array_key_exists('tabella_id', $dati)) {
            $campi['tabella_id'] = self::tabellaProposta($condominio, $ambito, $palazzina, $scala);
        }

        return RegistroModifiche::durante(GestiDelRegistro::assembleaPreparata($condominio), function () use ($condominio, $campi, $sedute, $puntiScelti, $puntiScritti, $utente, $ordine) {
            $assemblea = Assemblea::create($campi + [
                'condominio_id' => $condominio->id,
                'tipo' => $campi['tipo'] ?? TipoAssemblea::ORDINARIA->value,
                'modalita' => $campi['modalita'] ?? ModalitaAssemblea::PRESENZA->value,
                'origine' => OrigineAssemblea::PROGRAMMA->value,
                'stato' => StatoAssemblea::IN_PREPARAZIONE->value,
                'created_by' => $utente->id,
                'updated_by' => $utente->id,
            ]);
            self::scriviLeSedute($assemblea, $sedute);
            // «Varie ed eventuali» aspetta i punti scritti, che entrano come con «Aggiungi un punto»: così resta l'ultimo senza rinumerare niente.
            $varie = fn (array $p) => ($p['chiave_fonte'] ?? null) === ProposteDellOrdineDelGiorno::CHIAVE_VARIE;
            foreach (self::inOrdine($ordine, array_values(array_filter($puntiScelti, fn ($p) => ! $varie($p))), $puntiScritti) as $punto) {
                OrdineDelGiorno::aggiungi($assemblea, $punto, $utente);
            }
            OrdineDelGiorno::dalleProposte($assemblea, array_values(array_filter($puntiScelti, $varie)), $utente);
            self::dopoLeDate($assemblea);

            return $assemblea->refresh();
        });
    }

    /**
     * I punti da scrivere prima di «Varie ed eventuali», nell'ordine scelto nella pagina: ciò che `$ordine` nomina (una chiave che non conosce o già vista non
     * conta), poi ciò che non nomina, nell'ordine di sempre (i proposti, poi i punti scritti). Senza `$ordine` è l'ordine di sempre.
     *
     * @param list<string>|null $ordine
     * @param list<array<string, mixed>> $proposti
     * @param list<array<string, mixed>> $scritti
     * @return list<array<string, mixed>>
     */
    private static function inOrdine(?array $ordine, array $proposti, array $scritti): array
    {
        $noti = [];
        foreach ($proposti as $i => $p) {
            $noti[(string) ($p['chiave_fonte'] ?? 'proposto:' . $i)] = $p;
        }
        foreach (array_values($scritti) as $i => $p) {
            $noti['scritto:' . $i] = $p;
        }
        $scelti = [];
        foreach ($ordine ?? [] as $chiave) {
            if (isset($noti[$chiave]) && ! isset($scelti[$chiave])) {
                $scelti[$chiave] = $noti[$chiave];
            }
        }

        // I punti scritti possono avere una chiave uguale a quella di un proposto solo se questa è `scritto:N`: i proposti non ne hanno.
        return array_values($scelti + $noti);
    }

    /**
     * Cambia un'assemblea in preparazione: i campi e, con `sedute`, le date (una riga per seduta cambiata: «Seconda convocazione · Il giorno e l'ora: 15/12/2026
     * alle 18:30 → 16/12/2026 alle 18:30»).
     *
     * @param array<string, mixed> $dati
     */
    public static function aggiorna(Assemblea $assemblea, array $dati, User $utente): Assemblea
    {
        OrdineDelGiorno::siPuoCambiare($assemblea);
        $condominio = $assemblea->condominio;
        $sedute = array_key_exists('sedute', $dati) ? self::sedute($dati['sedute']) : null;

        return DB::transaction(function () use ($assemblea, $condominio, $dati, $sedute, $utente) {
            $campi = self::campi($condominio, $dati);
            if (array_key_exists('ambito', $dati)) {
                [$campi['ambito'], $campi['palazzina_id'], $campi['scala_id']] = MemoriaDelCondominio::perimetro($condominio, $dati);
            }
            $assemblea->fill($campi + ['updated_by' => $utente->id])->save();
            if ($sedute !== null) {
                self::scriviLeSedute($assemblea, $sedute);
                self::dopoLeDate($assemblea);
            }

            return $assemblea->refresh();
        });
    }

    /**
     * Elimina un'assemblea in preparazione: i memo dei suoi punti tornano nella memoria (con il compito, se la data è ancora davanti), le voci dell'agenda se ne
     * vanno. Una riga sola: «Assemblea ordinaria del 15/12/2026 eliminata, in preparazione: 6 punti; 2 memo tornano nella memoria». Da convocata si annulla
     * con il motivo ({@see StatiDellAssemblea::annullaERiconvoca()}).
     */
    public static function elimina(Assemblea $assemblea, User $utente): void
    {
        OrdineDelGiorno::siPuoCambiare($assemblea);
        $punti = $assemblea->punti()->count();
        $memo = MemoDellAssemblea::query()->where(fn ($q) => $q->where('assemblea_id', $assemblea->id)
            ->orWhereIn('punto_id', $assemblea->punti()->select('id')))->get();
        RegistroModifiche::durante(GestiDelRegistro::assembleaEliminata($assemblea, $punti, $memo->count()), function () use ($assemblea, $memo) {
            foreach ($memo as $m) {
                // Solo i memo ancora nel punto tornano in attesa: uno chiuso intanto (la delibera della ratifica, «Non serve più») resta chiuso.
                if ($m->eInAttesa()) {
                    $m->update(['assemblea_id' => null]);
                } elseif ($m->stato === \App\Enums\Assemblee\StatoMemo::NEL_PUNTO) {
                    MemoriaDelCondominio::tornaInAttesa($m);
                }
            }
            // Le voci dell'agenda e i compiti che hanno l'assemblea come `eventable`: senza l'assemblea non portano più da nessuna parte.
            foreach (\App\Models\Evento::query()->where('eventable_type', $assemblea->getMorphClass())->where('eventable_id', $assemblea->id)->get() as $evento) {
                $evento->delete();
            }
            // I compiti della convocazione hanno l'assemblea nel contesto, non come `eventable`: si chiudono con la frase.
            app(CompitiDellaConvocazione::class)->chiudiTutti($assemblea);
            foreach ($assemblea->sedute()->get() as $seduta) {
                $seduta->delete();
            }
            foreach ($assemblea->punti()->get() as $punto) {
                $punto->delete();
            }
            $assemblea->delete();
        });
    }

    /**
     * Il giorno della riunione (§13, rilievo 6): la seconda convocazione se c'è, altrimenti la prima; per una riunione registrata a mano il suo giorno, che non
     * si tocca qui.
     */
    public static function giornoDellaRiunione(Assemblea $assemblea): ?string
    {
        $sedute = $assemblea->sedute()->where('numero', 1)->get();
        $seconda = $sedute->first(fn ($s) => $s->convocazione === ConvocazioneSeduta::SECONDA);
        $prima = $sedute->first(fn ($s) => $s->convocazione === ConvocazioneSeduta::PRIMA);
        $scelta = $seconda ?? $prima;

        return $scelta === null ? null : substr((string) $scelta->getAttributes()['fissata_il'], 0, 10);
    }

    /** Dopo le date: il giorno della riunione, l'agenda, i compiti della convocazione. */
    public static function dopoLeDate(Assemblea $assemblea): void
    {
        $giorno = self::giornoDellaRiunione($assemblea);
        if ($giorno !== ($assemblea->data?->format('Y-m-d'))) {
            $assemblea->forceFill(['data' => $giorno])->save();
        }
        AgendaDellAssemblea::sincronizza($assemblea);
        app(CompitiDellaConvocazione::class)->sincronizza($assemblea);
    }

    /**
     * Le sedute della pagina, controllate (R11): un errore di date è un errore della richiesta, con la frase del controllo.
     *
     * @param mixed $sedute
     * @return list<array{convocazione: string, numero: int, fissata_il: string, luogo: ?string}>
     */
    private static function sedute(mixed $sedute): array
    {
        $elenco = [];
        foreach ((array) $sedute as $s) {
            $elenco[] = ['convocazione' => (string) ($s['convocazione'] ?? ''), 'numero' => (int) ($s['numero'] ?? 1) ?: 1,
                'fissata_il' => str_replace('T', ' ', (string) ($s['fissata_il'] ?? '')), 'luogo' => isset($s['luogo']) && trim((string) $s['luogo']) !== '' ? trim((string) $s['luogo']) : null];
        }
        $controllo = ControlloDelleDate::controlla($elenco);
        if (! $controllo['valide']) {
            throw ValidationException::withMessages(['sedute' => $controllo['frase']]);
        }

        return $elenco;
    }

    /** Le sedute com'erano e come sono: si cambia quella che c'era (la sua riga dice il prima e il dopo), si crea la nuova, si toglie quella che non c'è più. */
    private static function scriviLeSedute(Assemblea $assemblea, array $sedute): void
    {
        $esistenti = $assemblea->sedute()->get()->keyBy(fn (SedutaDellAssemblea $s) => $s->convocazione?->value . ':' . $s->numero);
        $tenute = [];
        foreach ($sedute as $s) {
            $chiave = $s['convocazione'] . ':' . $s['numero'];
            $tenute[] = $chiave;
            $seduta = $esistenti[$chiave] ?? new SedutaDellAssemblea(['assemblea_id' => $assemblea->id, 'convocazione' => $s['convocazione'],
                'numero' => $s['numero']]);
            $seduta->fill(['fissata_il' => \Illuminate\Support\Carbon::parse($s['fissata_il'], (string) config('app.timezone')), 'luogo' => $s['luogo']]);
            if (! $seduta->exists || $seduta->isDirty()) {
                $seduta->save();
            }
        }
        foreach ($esistenti as $chiave => $seduta) {
            if (! in_array($chiave, $tenute, true)) {
                $seduta->delete();
            }
        }
    }

    /** @param array<string, mixed> $dati @return array<string, mixed> */
    private static function campi(Condominio $condominio, array $dati): array
    {
        $campi = array_intersect_key($dati, array_flip(self::CAMPI));
        foreach ($campi as $chiave => $valore) {
            if ($valore === '') {
                $campi[$chiave] = null;
            }
        }
        if (! empty($campi['tabella_id'])) {
            $campi['tabella_id'] = (int) SchedaDelCondominio::risolvi($condominio, 'tabella', (int) $campi['tabella_id'])->getKey();
        }

        return $campi;
    }

    /** La tabella del valore proposta: quella di proprietà del perimetro (R14), se l'amministratore l'ha scelta. */
    private static function tabellaProposta(Condominio $condominio, string $ambito, ?int $palazzina, ?int $scala): ?int
    {
        $perimetro = match ($ambito) {
            AmbitoAssemblea::SCALA->value => Scala::query()->find($scala),
            AmbitoAssemblea::PALAZZINA->value => Palazzina::query()->find($palazzina),
            default => $condominio,
        };

        return $perimetro === null ? null : TabellaDiProprieta::di($perimetro)?->id;
    }
}
