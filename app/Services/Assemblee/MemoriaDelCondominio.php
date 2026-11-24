<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Enums\Assemblee\AChiMemo;
use App\Enums\Assemblee\AmbitoAssemblea;
use App\Enums\Assemblee\FontePunto;
use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\Assemblee\StatoMemo;
use App\Enums\EventoTipo;
use App\Helpers\DateHelper;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\MemoDellAssemblea;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\Gestionale\RispostaPromemoria;
use App\Models\User;
use App\Services\Gestionale\InboxService;
use App\Services\Gestionale\RispostePromemoria;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/**
 * La memoria del condominio, i memo «Da portare in assemblea» (decisioni 499 e 501; R10 del progetto della tappa 2).
 *
 * - **Senza data** il memo sta nella memoria, nella card della dashboard del condominio e nella proposta dell'ordine del giorno.
 * - **Con la data è anche un compito**, una riga sola nell'Action inbox, da quel giorno alle 09:00 «come si legge», con il memo come `eventable` e
 *   `meta.assegnato_a` per «Le mie». Il memo e il suo compito sono **una cosa sola**: le risposte al compito passano dal memo («Non serve più» lo chiude con
 *   il motivo, «Ricordamelo» sposta anche il suo giorno).
 * - Il compito **si chiude** quando il punto entra in un ordine del giorno («Chiuso: il punto è nell'ordine del giorno del 15/12/2026»); il memo **si chiude**
 *   con la delibera. Un punto tolto dall'ordine del giorno, o un'assemblea annullata, rimette il memo «in attesa», e se la sua data è ancora davanti il
 *   compito torna.
 *
 * Il memo e la sua nota restano nello studio. Niente si collega senza il clic (R3): salvare un memo non lega niente.
 */
final class MemoriaDelCondominio
{
    public const TIPO_DEL_COMPITO = 'memo_assemblea';

    /** L'ora del compito, come si legge nell'agenda. */
    public const ORA_DEL_COMPITO = '09:00';

    /** Quanti punti mostra la card della dashboard prima di «e altre N» (decisione 501, standard 6). */
    public const PUNTI_DELLA_CARD = 8;

    /**
     * Salva un memo. `$dati`: `testo`; facoltativi `nota`, `origine_tipo` e `origine_id` (risolti nel condominio, R18), `assemblea_id` (un'assemblea in
     * preparazione del condominio), `ricordami_dal` (Y-m-d), `a_chi` (`me`, `studio`, `collaboratore`), `assegnato_a` (l'utente del collaboratore),
     * `ambito`, `palazzina_id`, `scala_id`.
     *
     * @param array<string, mixed> $dati
     */
    public static function salva(Condominio $condominio, array $dati, User $utente): MemoDellAssemblea
    {
        $campi = self::campi($condominio, $dati, $utente, null);

        return DB::transaction(function () use ($condominio, $campi, $utente) {
            $memo = MemoDellAssemblea::create($campi + ['condominio_id' => $condominio->id, 'stato' => StatoMemo::IN_ATTESA->value, 'created_by' => $utente->id]);
            // La sua scheda è già un punto di un'assemblea che si prepara (o convocata): il memo entra in quel punto, con la sua nota, e non serve un compito
            // per ricordarlo. Un sospeso è un punto solo.
            $punto = $memo->origine_tipo === null ? null : self::puntoDellaStessaScheda($memo);
            if ($punto !== null) {
                self::entraNelPunto($memo, $punto);
            } elseif ($memo->ricordami_dal !== null) {
                self::creaIlCompito($memo);
            }

            return $memo->refresh();
        });
    }

    /** Il punto con la stessa chiave del memo in un'assemblea in preparazione o convocata del condominio, la prima per data. */
    private static function puntoDellaStessaScheda(MemoDellAssemblea $memo): ?PuntoDellOrdineDelGiorno
    {
        $condominio = Condominio::query()->find($memo->condominio_id);
        $trovato = $condominio === null ? null : ProposteDellOrdineDelGiorno::puntoConLaChiave($condominio, self::chiaveDelMemo($memo));

        return $trovato === null ? null : PuntoDellOrdineDelGiorno::query()->where('assemblea_id', $trovato['assemblea_id'])
            ->where('chiave_fonte', self::chiaveDelMemo($memo))->orderBy('numero')->first();
    }

    /**
     * La chiave del punto di un memo: quella del sospeso della sua scheda («fattura:45», «gestione:3»), o «memo:12» per un memo senza scheda. Due memo con
     * la stessa chiave sono lo stesso punto (decisione 499 (3)).
     */
    public static function chiaveDelMemo(MemoDellAssemblea $memo): string
    {
        if ($memo->origine_tipo === null || $memo->origine_id === null) {
            return 'memo:' . $memo->id;
        }
        $esercizio = $memo->origine_tipo === 'rendiconto_versione'
            ? (int) DB::table('rendiconto_versioni')->where('id', $memo->origine_id)->value('esercizio_id') : null;

        return PropostaDelPunto::chiave((string) $memo->origine_tipo, (int) $memo->origine_id, $esercizio ?: null);
    }

    /**
     * I memo in attesa con quella chiave, per la prossima assemblea o per questa: entrano tutti nel punto che nasce (o che c'è già) con la stessa chiave, così
     * nessuno resta indietro con il suo compito aperto.
     *
     * @return Collection<int, MemoDellAssemblea>
     */
    public static function inAttesaConLaChiave(Assemblea $assemblea, string $chiave): Collection
    {
        if (str_starts_with($chiave, 'memo:')) {
            return MemoDellAssemblea::query()->whereKey((int) substr($chiave, 5))->where('condominio_id', $assemblea->condominio_id)
                ->where('stato', StatoMemo::IN_ATTESA->value)->get();
        }

        return MemoDellAssemblea::query()->where('condominio_id', $assemblea->condominio_id)->where('stato', StatoMemo::IN_ATTESA->value)
            ->whereNotNull('origine_tipo')->where(fn ($q) => $q->whereNull('assemblea_id')->orWhere('assemblea_id', $assemblea->id))
            ->orderBy('id')->get()->filter(fn (MemoDellAssemblea $m) => self::chiaveDelMemo($m) === $chiave)->values();
    }

    /** Cambia un memo in attesa: il testo, la nota, l'assemblea, il giorno, a chi. Il compito segue il giorno. @param array<string, mixed> $dati */
    public static function aggiorna(MemoDellAssemblea $memo, array $dati, User $utente): MemoDellAssemblea
    {
        if (! $memo->eInAttesa()) {
            throw ValidationException::withMessages(['memo' => 'Questo memo non è più in attesa: non si cambia.']);
        }
        $condominio = $memo->condominio;
        $campi = self::campi($condominio, $dati + ['testo' => $memo->testo], $utente, $memo);

        return DB::transaction(function () use ($memo, $campi) {
            $giornoPrima = $memo->ricordami_dal?->format('Y-m-d');
            $memo->fill($campi)->save();
            $giornoDopo = $memo->ricordami_dal?->format('Y-m-d');
            $compito = $memo->evento_id === null ? null : Evento::query()->find($memo->evento_id);
            if ($giornoDopo === null) {
                ChiusuraDeiCompiti::chiudi($compito, 'Chiuso: il memo non ha più un giorno per ricordarlo.');
            } elseif ($compito === null || $compito->is_completed) {
                self::creaIlCompito($memo);
            } elseif ($giornoPrima !== $giornoDopo || ($compito->meta['assegnato_a'] ?? null) !== self::assegnatoA($memo)) {
                $inizio = self::inizioDelGiorno($giornoDopo);
                $compito->update(['start_time' => $inizio, 'end_time' => $inizio->copy()->addHour(),
                    'meta' => array_merge((array) $compito->meta, ['assegnato_a' => self::assegnatoA($memo), 'riga' => self::rigaDelCompito($memo)])]);
                InboxService::clearAdminCache();
            }

            return $memo->refresh();
        });
    }

    /** «Non serve più», con il motivo: il memo in attesa si chiude, e il suo compito con lui. Il memo già nel punto si toglie togliendo il punto. */
    public static function nonServe(MemoDellAssemblea $memo, string $motivo, User $utente): MemoDellAssemblea
    {
        if ($memo->stato === StatoMemo::NEL_PUNTO) {
            throw ValidationException::withMessages(['memo' => 'Questo memo è già un punto dell\'ordine del giorno: per toglierlo, togli il punto.']);
        }
        if (! $memo->eInAttesa()) {
            throw ValidationException::withMessages(['memo' => 'Questo memo non è più in attesa.']);
        }

        return DB::transaction(function () use ($memo, $motivo) {
            $memo->update(['stato' => StatoMemo::NON_SERVE->value, 'chiuso_il' => now(), 'chiuso_motivo' => trim($motivo)]);
            ChiusuraDeiCompiti::chiudi($memo->evento_id === null ? null : Evento::query()->find($memo->evento_id), 'Chiuso: il memo non serve più.');

            return $memo->refresh();
        });
    }

    /**
     * «Mettilo nell'ordine del giorno del 15/12»: il memo diventa un punto dell'assemblea in preparazione. Il memo di un gruppo ({@see gruppoDelMemo()}) va
     * solo in un'assemblea di quel gruppo.
     */
    public static function nellOrdine(MemoDellAssemblea $memo, Assemblea $assemblea, User $utente): PuntoDellOrdineDelGiorno
    {
        abort_if((int) $assemblea->condominio_id !== (int) $memo->condominio_id, 404);
        if (! $memo->eInAttesa()) {
            throw ValidationException::withMessages(['memo' => 'Questo memo non è più in attesa.']);
        }
        $gruppo = self::gruppoDelMemo($memo);
        if ($gruppo !== null && UnitaDelPunto::gruppoDellAssemblea($assemblea) !== $gruppo['tabella_id']) {
            throw ValidationException::withMessages(['memo' => 'Il memo è dei condòmini della tabella «' . $gruppo['nome'] . '»: lo propone la prossima assemblea dei '
                . 'condòmini della tabella «' . $gruppo['nome'] . '».']);
        }

        return OrdineDelGiorno::aggiungi($assemblea, self::comeProposta($memo), $utente);
    }

    /**
     * Un memo come punto proposto (lo stesso per la scena 4 e per «Mettilo nell'ordine del giorno»): il testo, la materia e la maggioranza dell'origine se c'è,
     * la chiave del sospeso dell'origine (un memo sullo sforo e lo sforo sono un punto solo, decisione 499 (3)), gli oggetti.
     *
     * @return array<string, mixed>
     */
    public static function comeProposta(MemoDellAssemblea $memo): array
    {
        $origine = $memo->origine_tipo === null || $memo->origine_id === null ? null
            : SchedaDelCondominio::cerca($memo->condominio, $memo->origine_tipo, (int) $memo->origine_id);
        $proposta = $origine === null ? null : PropostaDelPunto::perLaScheda($origine);
        $materia = $proposta['materia'] ?? 'altro';
        $maggioranza = $proposta['maggioranza'] ?? MaggioranzePerMateria::proposta($materia);

        return [
            'testo' => $memo->testo,
            'testo_pubblico' => $proposta['testo_pubblico'] ?? null,
            'materia' => $materia,
            'maggioranza' => $maggioranza,
            'avviso' => MaggioranzePerMateria::avviso($materia),
            'amministrazione' => MaggioranzePerMateria::amministrazione($materia, $maggioranza),
            'esercizio_id' => $proposta['esercizio_id'] ?? null,
            'importo' => $proposta['importo'] ?? null,
            'fonte' => FontePunto::MEMO->value,
            'chiave_fonte' => $proposta['chiave_fonte'] ?? ('memo:' . $memo->id),
            'oggetti' => $proposta['oggetti'] ?? [],
            'memo_id' => (int) $memo->id,
        ] + (isset($proposta['dati']) ? ['dati' => $proposta['dati']] : []);
    }

    /** Il punto in cui il memo è entrato: il memo è «nell'ordine del giorno», il compito si chiude con la frase (R10). */
    public static function entraNelPunto(MemoDellAssemblea $memo, PuntoDellOrdineDelGiorno $punto): void
    {
        $assemblea = $punto->assemblea;
        $memo->update(['stato' => StatoMemo::NEL_PUNTO->value, 'punto_id' => $punto->id, 'assemblea_id' => $assemblea->id]);
        ChiusuraDeiCompiti::chiudi($memo->evento_id === null ? null : Evento::query()->find($memo->evento_id),
            'Chiuso: il punto è nell\'ordine del giorno' . ($assemblea->data !== null ? ' del ' . $assemblea->data->format('d/m/Y') : '') . '.');
    }

    /**
     * Il memo torna «in attesa»: il punto è stato tolto, l'assemblea annullata, o tenuta fuori dal programma senza delibera su quel punto (con `$tieniIlPunto`
     * il memo ricorda il punto, e la proposta dice «non trattato il 15/12/2026»). Se la sua data è ancora davanti, il compito torna.
     */
    public static function tornaInAttesa(MemoDellAssemblea $memo, bool $tieniIlPunto = false, bool $assembleaChesiElimina = false): void
    {
        if ($assembleaChesiElimina && ! $tieniIlPunto && $memo->ambito === AmbitoAssemblea::GRUPPO && $memo->punto_id !== null) {
            // Fase 1-bis della beta.14: l'assemblea del gruppo che aveva preso il memo si elimina, e con lei il suo punto: il memo torna al punto da cui era nato
            // (il rinvio in un'altra assemblea dello stesso gruppo), così il gruppo si legge ancora e la proposta dice «rinviato il …».
            $origine = self::puntoDiOrigineDelMemo($memo);
            $memo->update(['stato' => StatoMemo::IN_ATTESA->value, 'punto_id' => $origine, 'assemblea_id' => null]);
            self::ridaiIlCompito($memo);

            return;
        }
        // Il memo di un gruppo che esce dal punto senza tenerlo resta del gruppo: va all'assemblea del gruppo da cui esce ({@see gruppoDelMemo()}).
        $delGruppo = ! $tieniIlPunto && $memo->ambito === AmbitoAssemblea::GRUPPO && $memo->punto_id !== null ? DB::table('assemblee_punti')
            ->join('assemblee', 'assemblee.id', '=', 'assemblee_punti.assemblea_id')->where('assemblee_punti.id', $memo->punto_id)
            ->where('assemblee.ambito', AmbitoAssemblea::GRUPPO->value)->value('assemblee.id') : null;
        $memo->update(['stato' => StatoMemo::IN_ATTESA->value, 'punto_id' => $tieniIlPunto ? $memo->punto_id : null,
            'assemblea_id' => $delGruppo === null ? null : (int) $delGruppo]);
        self::ridaiIlCompito($memo);
    }

    /** Se la data del memo è ancora davanti e il compito non c'è o è chiuso, il compito torna. */
    private static function ridaiIlCompito(MemoDellAssemblea $memo): void
    {
        $giorno = $memo->ricordami_dal?->format('Y-m-d');
        $compito = $memo->evento_id === null ? null : Evento::query()->find($memo->evento_id);
        if ($giorno !== null && $giorno >= DateHelper::oggiUtente() && ($compito === null || $compito->is_completed)) {
            self::creaIlCompito($memo);
        }
    }

    /**
     * Il punto da cui è nato il memo di un gruppo che esce dal punto di un'assemblea che si elimina: il punto con lo stesso testo di un'altra assemblea
     * della stessa tabella (la più recente), quello rinviato. Null se non c'è più (allora il memo non ha più un gruppo che si legge).
     */
    private static function puntoDiOrigineDelMemo(MemoDellAssemblea $memo): ?int
    {
        $eliminata = DB::table('assemblee_punti')->join('assemblee', 'assemblee.id', '=', 'assemblee_punti.assemblea_id')
            ->where('assemblee_punti.id', $memo->punto_id)->where('assemblee.ambito', AmbitoAssemblea::GRUPPO->value)->first(['assemblee.id', 'assemblee.tabella_id']);
        if ($eliminata === null) {
            return null;
        }
        $id = DB::table('assemblee_punti')->join('assemblee', 'assemblee.id', '=', 'assemblee_punti.assemblea_id')
            ->where('assemblee.condominio_id', $memo->condominio_id)->where('assemblee.ambito', AmbitoAssemblea::GRUPPO->value)
            ->where('assemblee.tabella_id', $eliminata->tabella_id)->where('assemblee.id', '!=', $eliminata->id)
            ->where('assemblee_punti.testo', $memo->testo)
            ->orderByDesc('assemblee.data')->orderByDesc('assemblee_punti.id')->value('assemblee_punti.id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Il punto scritto a mano e rinviato torna nella memoria del condominio: un memo in attesa, per la prossima assemblea, con il testo del punto, che ricorda
     * il punto (`punto_id`, come un memo tornato in attesa dopo un rinvio: la proposta dice «rinviato il …»). Rilievo C62. Il perimetro è quello
     * dell'assemblea: il punto rinviato nell'assemblea dei condòmini di un gruppo torna come memo del gruppo ({@see gruppoDelMemo()}), e lo propone solo la
     * prossima assemblea di quel gruppo.
     */
    public static function dalPuntoRinviato(PuntoDellOrdineDelGiorno $punto, User $utente): MemoDellAssemblea
    {
        $assemblea = $punto->assemblea;

        return MemoDellAssemblea::create(['condominio_id' => $assemblea->condominio_id, 'ambito' => $assemblea->ambito?->value ?? $assemblea->ambito,
            'palazzina_id' => $assemblea->palazzina_id, 'scala_id' => $assemblea->scala_id, 'testo' => (string) $punto->testo, 'punto_id' => $punto->id,
            'stato' => StatoMemo::IN_ATTESA->value, 'created_by' => $utente->id]);
    }

    /**
     * Il gruppo di un memo dei condòmini di un gruppo (Coda 734): il memo non ha una tabella sua, e il gruppo è la tabella dell'assemblea di un gruppo del
     * suo punto (il punto rinviato, o quello in cui è entrato dopo), o dell'assemblea a cui va. Null per gli altri memo, e per il memo del gruppo che non ha
     * più né l'uno né l'altra (l'assemblea eliminata): allora vale per tutto il condominio.
     *
     * @return array{tabella_id: int, nome: string}|null
     */
    public static function gruppoDelMemo(MemoDellAssemblea $memo): ?array
    {
        if ($memo->ambito !== AmbitoAssemblea::GRUPPO) {
            return null;
        }
        $assemblee = array_filter([$memo->punto_id === null ? null : DB::table('assemblee_punti')->where('id', $memo->punto_id)->value('assemblea_id'),
            $memo->assemblea_id]);
        foreach ($assemblee as $id) {
            $tabella = DB::table('assemblee')->join('tabelle', 'tabelle.id', '=', 'assemblee.tabella_id')->where('assemblee.id', (int) $id)
                ->where('assemblee.ambito', AmbitoAssemblea::GRUPPO->value)->first(['tabelle.id', 'tabelle.nome']);
            if ($tabella !== null) {
                return ['tabella_id' => (int) $tabella->id, 'nome' => (string) $tabella->nome];
            }
        }

        return null;
    }

    /** La delibera sul suo punto chiude il memo (R10). */
    public static function deliberato(MemoDellAssemblea $memo): void
    {
        $memo->update(['stato' => StatoMemo::DELIBERATO->value, 'chiuso_il' => now()]);
        ChiusuraDeiCompiti::chiudi($memo->evento_id === null ? null : Evento::query()->find($memo->evento_id), 'Chiuso: l\'assemblea ha deliberato.');
    }

    /** I memo entrati in un punto. @return Collection<int, MemoDellAssemblea> */
    public static function delPunto(PuntoDellOrdineDelGiorno $punto): Collection
    {
        return MemoDellAssemblea::query()->where('punto_id', $punto->id)->get();
    }

    /** I memo che stanno ancora nel punto: solo loro tornano in attesa quando il punto si toglie (uno chiuso intanto resta chiuso). @return Collection<int, MemoDellAssemblea> */
    public static function nelPunto(PuntoDellOrdineDelGiorno $punto): Collection
    {
        return MemoDellAssemblea::query()->where('punto_id', $punto->id)->where('stato', StatoMemo::NEL_PUNTO->value)->get();
    }

    /**
     * La card «Per la prossima assemblea» della dashboard del condominio (§3 P9): i punti che aspettano un'assemblea, con il badge dell'origine, oltre gli otto
     * «e altre N», e l'assemblea in preparazione se c'è. Senza punti la card non c'è (`totale` 0). La stessa lettura dell'ordine del giorno proposto, senza
     * «Varie ed eventuali» e senza i fatti calcolati della nota sintetica (le righe scritte nella nota sì): quelli li legge «Prepara l'assemblea», perché
     * rifarli a ogni apertura della dashboard e a ogni filtro dell'archivio costa il conguaglio di tutte le persone.
     *
     * @return array{punti: list<array<string, mixed>>, con_la_nota: bool, totale: int, altre: int, in_preparazione: ?array{id: int, nome: string}}
     */
    public static function perLaDashboard(Condominio $condominio): array
    {
        $tutti = [];
        foreach (ProposteDellOrdineDelGiorno::per($condominio, null, conLaNota: false) as $gruppo) {
            foreach ($gruppo['punti'] as $p) {
                // Il rendiconto e il preventivo di una gestione di un gruppo li propone l'assemblea del gruppo, non la card; il memo del gruppo sì, con la frase.
                if (($p['chiave_fonte'] ?? null) === ProposteDellOrdineDelGiorno::CHIAVE_VARIE || (isset($p['gruppo_id']) && ($p['memo_id'] ?? null) === null)) {
                    continue;
                }
                $tutti[] = ['testo' => $p['testo'], 'origine' => $gruppo['titolo'], 'gruppo' => $gruppo['chiave'], 'chiave_fonte' => $p['chiave_fonte'],
                    'memo_id' => $p['memo_id'] ?? null, 'frase' => $p['frase'] ?? null];
            }
        }
        // Il menu ⋯ di ogni punto (decisione 574): dove si apre la sua scheda e, per un memo, il memo per «Modifica» e «Non serve più». Solo per i punti
        // che la card mostra.
        $mostrati = array_map(function (array $p) use ($condominio) {
            $memo = $p['memo_id'] === null ? null : MemoDellAssemblea::query()->find((int) $p['memo_id']);

            return $p + ['apri' => self::apriDelPunto($condominio, (string) $p['chiave_fonte'], $memo), 'memo' => $memo === null ? null : self::perLaFinestra($memo)];
        }, array_slice($tutti, 0, self::PUNTI_DELLA_CARD));
        $preparazione = Assemblea::query()->where('condominio_id', $condominio->id)->where('stato', StatoAssemblea::IN_PREPARAZIONE->value)
            ->orderBy('data')->orderBy('id')->first();

        return [
            'punti' => $mostrati,
            // La nota sintetica di un esercizio con il rendiconto ancora da approvare può aggiungere punti che la card non calcola: la card lo dice. Si sa
            // dalle due tabelle, senza rileggere la nota.
            'con_la_nota' => DB::table('note_sintetiche')->where('note_sintetiche.condominio_id', $condominio->id)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('rendiconto_versioni as rv')->whereColumn('rv.esercizio_id', 'note_sintetiche.esercizio_id')
                    ->where('rv.stato', 'approvata'))->exists(),
            'totale' => count($tutti),
            'altre' => max(0, count($tutti) - self::PUNTI_DELLA_CARD),
            'in_preparazione' => $preparazione === null ? null : ['id' => (int) $preparazione->id, 'nome' => $preparazione->nome()],
        ];
    }

    /** Il memo come lo apre la finestra in modifica: i campi che si cambiano, e da dove nasce. @return array<string, mixed> */
    public static function perLaFinestra(MemoDellAssemblea $memo): array
    {
        return ['id' => (int) $memo->id, 'testo' => $memo->testo, 'nota' => $memo->nota, 'assemblea_id' => $memo->assemblea_id === null ? null : (int) $memo->assemblea_id,
            'ricordami_dal' => $memo->ricordami_dal?->format('Y-m-d'), 'a_chi' => $memo->a_chi?->value, 'assegnato_a' => $memo->assegnato_a === null ? null : (int) $memo->assegnato_a,
            'origine_tipo' => $memo->origine_tipo, 'origine_id' => $memo->origine_id === null ? null : (int) $memo->origine_id];
    }

    /**
     * «Apri …» del punto nella card: la scheda da cui nasce il memo ({@see apriLOrigine()}), o quella del sospeso dalla sua chiave: la fattura, il piano,
     * il rendiconto, i piani rate del preventivo, la nota sintetica, l'azione del compito, la riga dei sei mesi. Dove una pagina non c'è, niente: nessuna
     * voce finta.
     *
     * @return array{voce: string, url: string}|null
     */
    public static function apriDelPunto(Condominio $condominio, string $chiave, ?MemoDellAssemblea $memo): ?array
    {
        if ($memo !== null) {
            $origine = self::apriLOrigine($memo);
            if (isset($origine['action_url'], $origine['apri'])) {
                return ['voce' => $origine['apri'], 'url' => $origine['action_url']];
            }
        }
        $pezzi = explode(':', $chiave);
        $tipo = $pezzi[0];
        $id = (int) ($pezzi[1] ?? 0);
        $c = (int) $condominio->id;
        if ($id === 0) {
            return null;
        }
        if ($tipo === DispensaInAssemblea::TIPO) {
            return DispensaInAssemblea::apri($c, $id);
        }
        if ($tipo === 'compito') {
            $meta = (array) (Evento::query()->whereKey($id)->value('meta') ?? []);

            return ! empty($meta['action_url']) && ! empty($meta['titolo_azione']) ? ['voce' => (string) $meta['titolo_azione'], 'url' => (string) $meta['action_url']] : null;
        }
        [$rotta, $parametri, $voce] = match ($tipo) {
            'fattura' => ['admin.gestionale.fatture.show', ['condominio' => $c, 'fattura' => $id],
                'Apri la fattura n. ' . DB::table('fatture_passive')->where('id', $id)->value('numero_documento')],
            'piano_rate' => ['admin.gestionale.esercizi.piani-rate.show', ['condominio' => $c,
                'esercizio' => (int) DB::table('piani_rate')->where('id', $id)->value('esercizio_id'), 'pianoRate' => $id], 'Apri il piano rate'],
            'rendiconto' => ['admin.gestionale.esercizi.rendiconto.sintesi', ['condominio' => $c, 'esercizio' => $id], 'Apri il rendiconto'],
            'preventivo' => ['admin.gestionale.esercizi.piani-rate.index', ['condominio' => $c, 'esercizio' => $id], 'Apri i piani rate dell\'esercizio'],
            'nota' => ['admin.gestionale.esercizi.rendiconto.nota', ['condominio' => $c, 'esercizio' => $id], 'Apri la nota sintetica'],
            default => [null, [], null],
        };
        if ($rotta === null || ! Route::has($rotta) || in_array(0, $parametri, true)) {
            return null;
        }

        return ['voce' => (string) $voce, 'url' => route($rotta, $parametri, false)];
    }

    /**
     * Una risposta al compito del memo, dal menu del compito: passa dalle risposte dei compiti, che chiamano {@see dopoLaRispostaDelCompito()} nella loro
     * transazione. `$risposta`: `fatto`, `non_serve` (con `motivo`), `ricordamelo` (con `giorno` e `nota`).
     *
     * @param array<string, mixed> $dati
     */
    public static function rispostaDelCompito(Evento $compito, string $risposta, array $dati, User $utente): RispostaPromemoria
    {
        $risposte = app(RispostePromemoria::class);

        return match ($risposta) {
            RispostePromemoria::NON_SERVE => $risposte->nonServeDelCompito($compito, (string) ($dati['motivo'] ?? ''), $utente),
            RispostePromemoria::RICORDAMELO => $risposte->ricordameloDelCompito($compito, (string) $dati['giorno'], $dati['nota'] ?? null, $utente),
            default => $risposte->fattoDelCompito($compito, $dati['nota'] ?? null, $utente),
        };
    }

    /**
     * Dopo la risposta di una persona al compito di un memo, nella stessa transazione: «Non serve più» chiude il memo con il motivo; «Ricordamelo» sposta il
     * suo giorno; «Fatto» toglie il promemoria e il memo resta nella memoria, in attesa dell'assemblea. Per gli altri compiti non fa niente.
     *
     * @param array<string, mixed> $dati
     */
    public static function dopoLaRispostaDelCompito(Evento $compito, string $stato, array $dati, ?User $utente): void
    {
        if ((($compito->meta ?? [])['type'] ?? null) !== self::TIPO_DEL_COMPITO) {
            return;
        }
        $memo = self::delCompito($compito);
        if ($memo === null || ! $memo->eInAttesa()) {
            return;
        }
        match ($stato) {
            RispostePromemoria::NON_SERVE => $memo->update(['stato' => StatoMemo::NON_SERVE->value, 'chiuso_il' => now(),
                'chiuso_motivo' => $dati['motivo'] ?? null]),
            RispostePromemoria::RICORDAMELO => $memo->update(['ricordami_dal' => (string) $dati['giorno']]),
            default => $memo->update(['ricordami_dal' => null]),
        };
    }

    /** Il memo di un compito: l'`eventable`, o `meta.memo_id`. */
    public static function delCompito(Evento $compito): ?MemoDellAssemblea
    {
        $id = $compito->eventable_type === (new MemoDellAssemblea())->getMorphClass() ? $compito->eventable_id : (($compito->meta ?? [])['memo_id'] ?? null);

        return $id === null ? null : MemoDellAssemblea::query()->find((int) $id);
    }

    /**
     * La prossima azione del compito (P10): «Mettilo nell'ordine del giorno del 15/12» se un'assemblea del condominio è in preparazione, altrimenti «Prepara
     * l'assemblea» con il punto già spuntato. Le pagine delle assemblee portano le rotte: senza, nessuna azione (il compito dice «Segna come fatto»).
     *
     * @return array{testo: string, url: string}|null
     */
    public static function azioneDelCompito(Evento $compito): ?array
    {
        $memo = self::delCompito($compito);
        if ($memo === null) {
            return null;
        }
        $assemblea = $memo->assemblea_id !== null ? Assemblea::query()->find($memo->assemblea_id) : null;
        if ($assemblea === null || ! $assemblea->eInPreparazione()) {
            $assemblea = Assemblea::query()->where('condominio_id', $memo->condominio_id)->where('stato', StatoAssemblea::IN_PREPARAZIONE->value)
                ->orderBy('data')->orderBy('id')->first();
        }
        if ($assemblea !== null && Route::has('admin.gestionale.assemblee.show')) {
            return ['testo' => 'Mettilo nell\'ordine del giorno' . ($assemblea->data !== null ? ' del ' . $assemblea->data->format('d/m') : ''),
                'url' => route('admin.gestionale.assemblee.show', ['condominio' => $memo->condominio_id, 'assemblea' => $assemblea->id, 'memo' => $memo->id], false)];
        }
        if ($assemblea === null && Route::has('admin.gestionale.assemblee.create')) {
            return ['testo' => 'Prepara l\'assemblea', 'url' => route('admin.gestionale.assemblee.create', ['condominio' => $memo->condominio_id, 'memo' => $memo->id], false)];
        }

        return null;
    }

    /** Il compito del memo, da `ricordami_dal` alle 09:00 come si legge. */
    private static function creaIlCompito(MemoDellAssemblea $memo): Evento
    {
        $testo = trim(preg_replace('/\s+/u', ' ', (string) $memo->testo) ?? '');
        $breve = mb_strlen($testo) > 90 ? rtrim(mb_substr($testo, 0, 89)) . '…' : $testo;
        $compito = InboxService::createTask(
            EventoTipo::MEMO_ASSEMBLEA,
            'Da portare in assemblea: ' . $breve,
            $testo,
            self::inizioDelGiorno($memo->ricordami_dal->format('Y-m-d')),
            $memo->created_by === null ? null : (int) $memo->created_by,
            (int) $memo->condominio_id,
            ['memo_id' => (int) $memo->id],
            null,
            ['memo_id' => (int) $memo->id, 'assegnato_a' => self::assegnatoA($memo), 'creato_da' => $memo->created_by, 'riga' => self::rigaDelCompito($memo)]
                + self::apriLOrigine($memo),
            $memo->getMorphClass(),
            (int) $memo->id,
        );
        $memo->evento_id = $compito->id;
        $memo->save();

        return $compito;
    }

    /**
     * «Apri la fattura n. 45» nel menu del compito (P10, scena 3 della bozza del memo) e nel menu ⋯ della card: l'indirizzo della pagina della scheda da cui
     * nasce il memo, e la voce del menu. La fattura, il piano, la gestione, il fondo, la dispensa, la regola del recupero, la segnalazione hanno la loro
     * pagina; l'accordo di rientro sta nelle rate scadute della persona, i sei mesi di un esercizio nella loro riga del riquadro della dashboard. Va in
     * `meta.action_url` e `meta.apri`, letti dall'elenco dell'Inbox; la prossima azione del memo resta la sua (`azioneDelCompito`).
     *
     * @return array{action_url?: string, apri?: string}
     */
    public static function apriLOrigine(MemoDellAssemblea $memo): array
    {
        $tipo = $memo->origine_tipo;
        $id = $memo->origine_id === null ? null : (int) $memo->origine_id;
        $condominio = (int) $memo->condominio_id;
        if ($tipo === null || $id === null) {
            return [];
        }
        if ($tipo === DispensaInAssemblea::TIPO) {
            $apri = DispensaInAssemblea::apri($condominio, $id);

            return $apri === null ? [] : ['action_url' => $apri['url'], 'apri' => $apri['voce']];
        }
        [$rotta, $parametri, $voce] = match ($tipo) {
            'fattura_passiva' => ['admin.gestionale.fatture.show', ['condominio' => $condominio, 'fattura' => $id],
                'Apri la fattura n. ' . DB::table('fatture_passive')->where('id', $id)->value('numero_documento')],
            'piano_rate' => ['admin.gestionale.esercizi.piani-rate.show', ['condominio' => $condominio,
                'esercizio' => (int) DB::table('piani_rate')->where('id', $id)->value('esercizio_id'), 'pianoRate' => $id], 'Apri il piano rate'],
            // La gestione si apre nell'esercizio più recente in cui c'è.
            'gestione' => ['admin.gestionale.esercizi.gestioni.edit', ['condominio' => $condominio,
                'esercizio' => (int) DB::table('esercizio_gestione')->where('gestione_id', $id)->max('esercizio_id'), 'gestione' => $id],
                'Apri la gestione «' . DB::table('gestioni')->where('id', $id)->value('nome') . '»'],
            // Il fondo (o il conto) ha la sua pagina nella modifica: quella di dettaglio non c'è.
            'cassa' => ['admin.gestionale.casse.edit', ['condominio' => $condominio, 'cassa' => $id], self::apriLaCassa($id)],
            // L'accordo di rientro non ha una pagina sua: sta nelle rate scadute della persona, dove si apre la sua finestra.
            'accordo_rientro' => ['admin.gestionale.rate-scadute.index', ['condominio' => $condominio,
                'anagrafica_id' => (int) DB::table('accordi_rientro')->where('id', $id)->value('anagrafica_id')], 'Apri le rate scadute di '
                . DB::table('anagrafiche')->where('id', DB::table('accordi_rientro')->where('id', $id)->value('anagrafica_id'))->value('nome')],
            'dispensa_sei_mesi' => ['admin.gestionale.dispense.index', ['condominio' => $condominio], 'Apri le dispense dei sei mesi'],
            'regola_recupero' => ['admin.gestionale.recupero-crediti.index', ['condominio' => $condominio], 'Apri il recupero dei crediti'],
            'segnalazione' => ['admin.segnalazioni.show', ['segnalazione' => $id], 'Apri la segnalazione'],
            default => [null, [], null],
        };
        if ($rotta === null || ! Route::has($rotta) || in_array(0, $parametri, true)) {
            return [];
        }

        return ['action_url' => route($rotta, $parametri, false), 'apri' => (string) $voce];
    }

    /** «Apri il fondo «Fondo facciata»», o «Apri «Banca della prova»» per un conto. */
    private static function apriLaCassa(int $id): string
    {
        $cassa = DB::table('casse')->where('id', $id)->first(['nome', 'tipo']);

        return $cassa === null ? 'Apri il fondo' : ($cassa->tipo === 'fondo' ? 'Apri il fondo «' . $cassa->nome . '»' : 'Apri «' . $cassa->nome . '»');
    }

    /** «Dal 25/11 · Via Roma 12 · da Andrea Costa, il 18/11 · Fattura n. 45 · Idraulica Bianchi · € 1.800,00» (P10: la pagina scrive «da te» a chi l'ha scritto). */
    private static function rigaDelCompito(MemoDellAssemblea $memo): string
    {
        $autore = $memo->created_by === null ? null : User::query()->whereKey($memo->created_by)->value('name');
        $pezzi = ['Dal ' . $memo->ricordami_dal?->format('d/m'), (string) Condominio::query()->whereKey($memo->condominio_id)->value('nome')];
        if ($autore !== null) {
            $pezzi[] = 'da ' . $autore . ($memo->created_at !== null ? ', il ' . $memo->created_at->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m') : '');
        }
        if ($memo->origine_nome !== null && $memo->origine_nome !== '') {
            $pezzi[] = $memo->origine_nome;
        }

        return implode(' · ', array_values(array_filter($pezzi, fn ($p) => $p !== null && $p !== '')));
    }

    /** Per «Le mie»: chi l'ha scritto con «A me», il collaboratore con «A un collaboratore», nessuno con «A tutto lo studio». */
    private static function assegnatoA(MemoDellAssemblea $memo): ?int
    {
        return match ($memo->a_chi) {
            AChiMemo::ME => $memo->created_by === null ? null : (int) $memo->created_by,
            AChiMemo::COLLABORATORE => $memo->assegnato_a === null ? null : (int) $memo->assegnato_a,
            default => null,
        };
    }

    private static function inizioDelGiorno(string $giorno): Carbon
    {
        return Carbon::parse($giorno . ' ' . self::ORA_DEL_COMPITO . ':00', (string) config('app.timezone'));
    }

    /**
     * I campi del memo dai dati della pagina, con i controlli che il server fa da sé: l'origine e l'assemblea sono del condominio (R18), l'assemblea è in
     * preparazione, il collaboratore è un utente attivo dello studio che vede le assemblee.
     *
     * @param array<string, mixed> $dati
     * @return array<string, mixed>
     */
    private static function campi(Condominio $condominio, array $dati, User $utente, ?MemoDellAssemblea $memo): array
    {
        $testo = trim((string) ($dati['testo'] ?? ''));
        if ($testo === '') {
            throw ValidationException::withMessages(['testo' => 'Scrivi il punto da portare in assemblea.']);
        }
        $campi = ['testo' => $testo];
        foreach (['nota', 'ricordami_dal'] as $chiave) {
            if (array_key_exists($chiave, $dati)) {
                $campi[$chiave] = $dati[$chiave] === '' ? null : $dati[$chiave];
            }
        }
        if ($memo === null && ! empty($dati['origine_tipo']) && ! empty($dati['origine_id'])) {
            $origine = SchedaDelCondominio::risolvi($condominio, (string) $dati['origine_tipo'], (int) $dati['origine_id']);
            $campi += ['origine_tipo' => SchedaDelCondominio::tipoDi($origine), 'origine_id' => (int) $origine->getKey(),
                'origine_nome' => SchedaDelCondominio::nomeDi($origine)];
        }
        if (array_key_exists('assemblea_id', $dati)) {
            $campi['assemblea_id'] = null;
            if (! empty($dati['assemblea_id'])) {
                $assemblea = SchedaDelCondominio::risolvi($condominio, 'assemblea', (int) $dati['assemblea_id']);
                if (! $assemblea->eInPreparazione()) {
                    throw ValidationException::withMessages(['assemblea_id' => 'Scegli un\'assemblea in preparazione, o «La prossima».']);
                }
                $campi['assemblea_id'] = (int) $assemblea->id;
            }
        }
        $aChi = AChiMemo::tryFrom((string) ($dati['a_chi'] ?? ($memo?->a_chi?->value ?? AChiMemo::ME->value))) ?? AChiMemo::ME;
        $campi['a_chi'] = $aChi->value;
        $campi['assegnato_a'] = null;
        if ($aChi === AChiMemo::COLLABORATORE) {
            // Lo stesso controllo dell'elenco della finestra: un utente attivo dello studio che vede le assemblee, o il compito finirebbe a chi non lo apre.
            $collaboratore = User::query()->whereNull('suspended_at')->find((int) ($dati['assegnato_a'] ?? 0));
            if ($collaboratore === null || ! AccessoAlleAssemblee::vede($collaboratore)) {
                throw ValidationException::withMessages(['assegnato_a' => 'Scegli il collaboratore dello studio.']);
            }
            $campi['assegnato_a'] = (int) $collaboratore->id;
        }
        if (array_key_exists('ambito', $dati) || $memo === null) {
            [$campi['ambito'], $campi['palazzina_id'], $campi['scala_id']] = self::perimetro($condominio, $dati);
        }

        return $campi;
    }

    /**
     * Il perimetro, con la palazzina e la scala del condominio (R18). I condòmini di un gruppo (Coda 734) non hanno né palazzina né scala: il gruppo è la
     * tabella dell'assemblea, che risolve chi la prepara ({@see PreparazioneDellAssemblea}).
     *
     * @param array<string, mixed> $dati
     * @return array{0: string, 1: ?int, 2: ?int}
     */
    public static function perimetro(Condominio $condominio, array $dati): array
    {
        $ambito = AmbitoAssemblea::tryFrom((string) ($dati['ambito'] ?? 'condominio')) ?? AmbitoAssemblea::CONDOMINIO;
        $palazzina = null;
        $scala = null;
        if ($ambito === AmbitoAssemblea::GRUPPO) {
            return [$ambito->value, null, null];
        }
        if ($ambito === AmbitoAssemblea::PALAZZINA) {
            $palazzina = (int) SchedaDelCondominio::risolvi($condominio, 'palazzina', (int) ($dati['palazzina_id'] ?? 0))->getKey();
        }
        if ($ambito === AmbitoAssemblea::SCALA) {
            $s = SchedaDelCondominio::risolvi($condominio, 'scala', (int) ($dati['scala_id'] ?? 0));
            $scala = (int) $s->getKey();
            $palazzina = $s->getAttribute('palazzina_id') === null ? null : (int) $s->getAttribute('palazzina_id');
        }

        return [$ambito->value, $palazzina, $scala];
    }
}
