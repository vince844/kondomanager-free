<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Enums\Assemblee\MateriaPunto;
use App\Helpers\MoneyHelper;
use App\Models\Condominio;
use App\Models\Gestionale\FatturaPassiva;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Il punto proposto per un'origine: la frase della finestra veloce del memo («Ratifica della spesa oltre il preventivo di € 1.800,00 per
 * la riparazione della colonna dell'acqua (fattura n. 45 di Idraulica Bianchi)») e quella dell'ordine del giorno proposto, con la materia, la maggioranza
 * proposta, la chiave del sospeso e le schede di cui parla. Gli importi in centesimi scritti con `MoneyHelper`; **nessun dato di una persona nella frase
 * pubblica** (decisione 492 (5)): niente nomi di condòmini né di fornitori, che possono essere persone.
 */
final class PropostaDelPunto
{
    /**
     * @return array{testo: string, testo_pubblico: string, materia: string, maggioranza: ?string, avviso: ?string, amministrazione: string,
     *     chiave_fonte: string, origine_tipo: string, origine_id: int, origine_nome: string, oggetti: list<array{0: string, 1: int, 2: string}>,
     *     esercizio_id: ?int, importo: ?int}
     */
    public static function per(Condominio $condominio, string $tipo, int $id): array
    {
        $scheda = SchedaDelCondominio::risolvi($condominio, $tipo, $id);

        return self::perLaScheda($scheda);
    }

    /** Come {@see per()}, per una scheda già risolta. @return array<string, mixed> */
    public static function perLaScheda(Model $scheda): array
    {
        $tipo = SchedaDelCondominio::tipoDi($scheda);
        $id = (int) $scheda->getKey();
        $oggetti = [[$tipo, $id, 'origine']];
        $esercizio = null;
        $importo = null;
        $nota = null;
        [$testo, $pubblico, $materia] = match ($tipo) {
            'fattura_passiva' => self::dellaFattura($scheda, $oggetti, $importo),
            'piano_rate' => self::delPiano($scheda, $oggetti, $esercizio),
            'gestione' => ['Lavori «' . $scheda->getAttribute('nome') . '»: approvazione, impresa, piano di riparto',
                'Lavori «' . $scheda->getAttribute('nome') . '»: approvazione, impresa, piano di riparto', MateriaPunto::LAVORI->value],
            'cassa' => ['Utilizzo del fondo «' . $scheda->getAttribute('nome') . '»', 'Utilizzo del fondo «' . $scheda->getAttribute('nome') . '»',
                MateriaPunto::FONDO->value],
            'accordo_rientro' => self::dellAccordo($scheda, $oggetti, $nota),
            'dispensa_sei_mesi' => ['Dispensa dall\'agire entro sei mesi per la riscossione dei crediti (art. 1129 c. 9 c.c.)',
                'Dispensa dall\'agire entro sei mesi per la riscossione dei crediti (art. 1129 c. 9 c.c.)', MateriaPunto::DISPENSA->value],
            'regola_recupero' => ['Criteri per l\'addebito degli interessi e delle spese di recupero dei crediti',
                'Criteri per l\'addebito degli interessi e delle spese di recupero dei crediti', MateriaPunto::REGOLE->value],
            // La dispensa dei sei mesi (Coda 736): lo stesso punto che propone il programma, con la stessa chiave.
            'sei_mesi' => self::deiSeiMesi($scheda, $oggetti, $esercizio),
            'esercizio' => self::delRendiconto($scheda, $esercizio),
            'rendiconto_versione' => self::delRendiconto(\App\Models\Esercizio::query()->findOrFail($scheda->getAttribute('esercizio_id')), $esercizio),
            'compito' => self::delCompito($scheda, $oggetti),
            // Il nome della persona sta nella nota dello studio, mai nel testo del punto: l'avviso lo stampa a tutti i condòmini (Coda 749).
            'anagrafica' => self::dellaPersona($scheda, $nota),
            // Il titolo di una segnalazione non va dove non si vede (`CompitoDellaSegnalazione`): la frase dice solo il giorno.
            'segnalazione' => self::dellaSegnalazione($scheda),
            default => [SchedaDelCondominio::nomeDi($scheda), SchedaDelCondominio::nomeDi($scheda), MateriaPunto::ALTRO->value],
        };
        if ($esercizio !== null && ! in_array(['esercizio', $esercizio, 'parla_di'], $oggetti, true) && $tipo !== 'esercizio') {
            $oggetti[] = ['esercizio', $esercizio, 'parla_di'];
        }
        $maggioranza = MaggioranzePerMateria::proposta($materia);
        // La spesa oltre il preventivo porta la sua motivazione, scritta una volta sola: l'ultima, cioè il motivo dell'urgenza se è stata pagata per
        // l'urgenza, altrimenti quella della registrazione. Va sotto il punto per i condòmini (l'avviso di convocazione lo stampa) e nei dati del punto
        // (`dati.motivazione` con `dati.motivazione_di`, che leggono l'avviso e la sala). Senza una motivazione non si scrive niente in più.
        $motivazione = $tipo === 'fattura_passiva' ? self::motivazioneDellaSpesa($scheda) : null;
        if ($motivazione !== null) {
            $pubblico .= "\n" . self::fraseDellaMotivazione($motivazione['motivazione'], $motivazione['di']);
        }

        return [
            'testo' => $testo,
            'testo_pubblico' => $pubblico,
            'materia' => $materia,
            'maggioranza' => $maggioranza,
            'avviso' => MaggioranzePerMateria::avviso($materia),
            'amministrazione' => MaggioranzePerMateria::amministrazione($materia, $maggioranza),
            'chiave_fonte' => self::chiave($tipo, $id, $esercizio),
            'origine_tipo' => $tipo,
            'origine_id' => $id,
            'origine_nome' => SchedaDelCondominio::nomeDi($scheda),
            'oggetti' => $oggetti,
            'esercizio_id' => $esercizio,
            'importo' => $importo,
            'nota' => $nota,
        ] + ($motivazione !== null ? ['dati' => ['motivazione' => $motivazione['motivazione'], 'motivazione_di' => $motivazione['di']]] : []);
    }

    /**
     * La motivazione della spesa oltre il preventivo, l'ultima scritta: il motivo di «Perché era urgente» quando la fattura è stata pagata per l'urgenza (la
     * nota di `approva-sforo`, con la chiave `urgenza`), altrimenti, per la fattura ancora in sforo, la motivazione della registrazione
     * (`dati_extra.override_budget.motivazione`). `null` per la ratifica fatta prima con la nota, e per una fattura senza motivazione.
     *
     * @return array{motivazione: string, di: 'urgenza'|'sforo'}|null
     */
    public static function motivazioneDellaSpesa(Model $fattura): ?array
    {
        $extra = (array) ($fattura->getAttribute('dati_extra') ?? []);
        $nota = $extra['ratifica_assembleare'] ?? null;
        if (is_array($nota) && ($nota['urgenza'] ?? false) === true && trim((string) ($nota['note'] ?? '')) !== '') {
            return ['motivazione' => trim((string) $nota['note']), 'di' => 'urgenza'];
        }
        $sforo = trim((string) (($extra['override_budget'] ?? [])['motivazione'] ?? ''));
        if ($fattura->getAttribute('stato_approvazione') === 'sforo_motivato' && $sforo !== '') {
            return ['motivazione' => $sforo, 'di' => 'sforo'];
        }

        return null;
    }

    /** «Perché era urgente: perdita dalla colonna dell'acqua.» o «Motivazione: rottura imprevista.», con il punto in fondo se manca. */
    public static function fraseDellaMotivazione(string $motivazione, string $di): string
    {
        $motivazione = trim($motivazione);

        return ($di === 'urgenza' ? 'Perché era urgente: ' : 'Motivazione: ') . $motivazione . (preg_match('/[.!?]$/u', $motivazione) ? '' : '.');
    }

    /** La chiave del sospeso (§5): «fattura:45», «piano_rate:12», «rendiconto:7», «compito:88». */
    public static function chiave(string $tipo, int $id, ?int $esercizio = null): string
    {
        return match ($tipo) {
            'fattura_passiva' => 'fattura:' . $id,
            'esercizio', 'rendiconto_versione' => 'rendiconto:' . ($esercizio ?? $id),
            default => $tipo . ':' . $id,
        };
    }

    /** @param list<array{0: string, 1: int, 2: string}> $oggetti @return array{0: string, 1: string, 2: string} */
    private static function dellaFattura(Model $fattura, array &$oggetti, ?int &$importo): array
    {
        $importo = (int) $fattura->getAttribute('totale_documento');
        $euro = MoneyHelper::format($importo);
        $fornitore = DB::table('fornitori')->where('id', $fattura->getAttribute('fornitore_id'))->value('ragione_sociale');
        $descrizione = trim((string) DB::table('righe_fattura')->where('fattura_passiva_id', $fattura->getKey())->orderBy('id')->value('descrizione'));
        $per = $descrizione !== '' ? ' per ' . mb_strtolower(mb_substr($descrizione, 0, 1)) . mb_substr($descrizione, 1) : '';
        $estremi = ' (fattura n. ' . $fattura->getAttribute('numero_documento') . ($fornitore !== null ? ' di ' . $fornitore : '') . ')';
        if (($gestione = $fattura->getAttribute('gestione_id')) !== null) {
            $oggetti[] = ['gestione', (int) $gestione, 'parla_di'];
        }
        $inSforo = $fattura->getAttribute('stato_approvazione') === 'sforo_motivato';
        // Solo lo sblocco per l'urgenza (la chiave `urgenza`): una ratifica fatta prima della 1.12 con la nota è già ratificata.
        $conLaNota = $fattura instanceof FatturaPassiva && RatificaDelloSforo::sbloccataPerLUrgenza($fattura);
        if ($inSforo) {
            // Uno sforo non è per forza urgente: è una spesa oltre il preventivo, e il «per …» è la descrizione della prima riga, non il motivo.
            return ['Ratifica della spesa oltre il preventivo di ' . $euro . $per . $estremi, 'Ratifica della spesa oltre il preventivo di ' . $euro . $per,
                MateriaPunto::RATIFICA->value];
        }
        if ($conLaNota) {
            // Decisione 561: la spesa urgente si paga subito, e si riferisce alla prima assemblea (art. 1135 c. 2 c.c.).
            return ['Comunicazione dei lavori urgenti e ratifica della spesa di ' . $euro . $per . $estremi,
                'Comunicazione dei lavori urgenti e ratifica della spesa di ' . $euro . $per, MateriaPunto::RATIFICA->value];
        }

        return ['Spesa di ' . $euro . $per . $estremi, 'Spesa di ' . $euro . $per, MateriaPunto::ALTRO->value];
    }

    /** @param list<array{0: string, 1: int, 2: string}> $oggetti @return array{0: string, 1: string, 2: string} */
    private static function delPiano(Model $piano, array &$oggetti, ?int &$esercizio): array
    {
        $gestione = DB::table('gestioni')->where('id', $piano->getAttribute('gestione_id'))->first(['id', 'nome', 'tipo']);
        if ($gestione !== null) {
            $oggetti[] = ['gestione', (int) $gestione->id, 'parla_di'];
        }
        $nome = (string) $piano->getAttribute('nome');
        if ($piano->getAttribute('tipo_autorizzazione') === 'urgenza') {
            return ['Comunicazione dei lavori urgenti «' . $nome . '» e del loro riparto', 'Comunicazione dei lavori urgenti «' . $nome . '» e del loro riparto',
                MateriaPunto::COMUNICAZIONE_URGENZA->value];
        }
        if (($gestione?->tipo ?? null) === 'straordinaria' || $piano->getAttribute('tipo') === 'straordinario') {
            $lavori = 'Lavori «' . ($gestione->nome ?? $nome) . '»: approvazione, impresa, piano di riparto';

            return [$lavori, $lavori, MateriaPunto::LAVORI->value];
        }
        $esercizio = $piano->getAttribute('esercizio_id') === null ? null : (int) $piano->getAttribute('esercizio_id');
        $anno = self::anno($esercizio);
        $frase = 'Approvazione del preventivo' . ($anno !== '' ? ' ' . $anno : '') . ' e del piano di riparto';

        return [$frase, $frase, MateriaPunto::PREVENTIVO->value];
    }

    /**
     * L'accordo di rientro: il punto dice la somma, il numero delle rate e il giorno in cui finisce, **senza il nome** (decisione 673 (4)): è la stessa frase
     * della bacheca, e l'avviso di convocazione la stampa a tutti i condòmini. Il nome della persona e il giorno dell'accordo vanno nella nota dello studio.
     *
     * @param list<array{0: string, 1: int, 2: string}> $oggetti
     * @return array{0: string, 1: string, 2: string}
     */
    private static function dellAccordo(Model $accordo, array &$oggetti, ?string &$nota): array
    {
        $persona = DB::table('anagrafiche')->where('id', $accordo->getAttribute('anagrafica_id'))->value('nome');
        if ($accordo->getAttribute('anagrafica_id') !== null) {
            $oggetti[] = ['anagrafica', (int) $accordo->getAttribute('anagrafica_id'), 'parla_di'];
        }
        $giorno = $accordo->getAttribute('data_accordo');
        $nota = 'Accordo con ' . $persona . ($giorno !== null ? ' del ' . \Illuminate\Support\Carbon::parse($giorno)->format('d/m/Y') : '') . '.';

        $scadenze = DB::table('accordo_rientro_scadenze')->where('accordo_rientro_id', $accordo->getKey())->pluck('importo');
        $frase = 'Autorizzazione di un accordo di rientro';
        if ($scadenze->isNotEmpty()) {
            $frase .= ' di ' . MoneyHelper::format((int) $scadenze->sum()) . ' in ' . $scadenze->count() . ($scadenze->count() === 1 ? ' rata' : ' rate');
            $fino = $accordo->getAttribute('fino_al');
            if ($fino !== null) {
                $frase .= ', fino al ' . \Illuminate\Support\Carbon::parse($fino)->format('d/m/Y');
            }
        }

        return [$frase, $frase, MateriaPunto::ACCORDO->value];
    }

    /**
     * La situazione di una persona: il punto dice «un condòmino», il nome sta nella nota dello studio.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function dellaPersona(Model $persona, ?string &$nota): array
    {
        $nota = 'Situazione di ' . $persona->getAttribute('nome') . '.';

        return ['Situazione di un condòmino', 'Situazione di un condòmino', MateriaPunto::ALTRO->value];
    }

    /**
     * La dispensa dall'agire entro sei mesi per i crediti dell'esercizio ({@see DispensaInAssemblea::punto()}): le posizioni e la cifra della riga del
     * riquadro, senza nomi; l'esercizio di cui parla, al posto della scheda d'origine (il tipo `sei_mesi` non va negli oggetti del punto).
     *
     * @param list<array{0: string, 1: int, 2: string}> $oggetti
     * @return array{0: string, 1: string, 2: string}
     */
    private static function deiSeiMesi(Model $scheda, array &$oggetti, ?int &$esercizio): array
    {
        $esercizio = (int) $scheda->getKey();
        $oggetti = [];
        $condominio = Condominio::query()->find($scheda->getAttribute('condominio_id'));
        $p = DispensaInAssemblea::punto($esercizio, $condominio === null ? null : DispensaInAssemblea::riga($condominio, $esercizio));

        return [$p['testo'], $p['testo_pubblico'], $p['materia']];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function delRendiconto(Model $esercizio, ?int &$id): array
    {
        $id = (int) $esercizio->getKey();
        $anno = self::anno($id);
        $frase = 'Approvazione del rendiconto' . ($anno !== '' ? ' ' . $anno : '') . ' e dello stato di riparto';

        return [$frase, $frase, MateriaPunto::RENDICONTO->value];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function dellaSegnalazione(Model $segnalazione): array
    {
        return ['Intervento richiesto con la segnalazione del ' . $segnalazione->getAttribute('created_at')?->format('d/m/Y'),
            'Intervento richiesto con una segnalazione', MateriaPunto::ALTRO->value];
    }

    /**
     * Il compito dell'agenda. Quello di una segnalazione si propone come la segnalazione (il suo titolo può portare il nome di un condòmino e non va nella
     * frase per la bacheca); per gli altri il titolo resta nel testo dello studio e la frase per la bacheca è generica (decisione 492 (5)).
     *
     * @param list<array{0: string, 1: int, 2: string}> $oggetti
     * @return array{0: string, 1: string, 2: string}
     */
    private static function delCompito(Model $compito, array &$oggetti): array
    {
        $meta = (array) ($compito->getAttribute('meta') ?? []);
        $tipo = $meta['type'] ?? null;
        if ($tipo === 'pianifica_ripianamento_deficit') {
            // La cifra di oggi, come la pagina del piano (`RipianamentoDelDeficit::cifraDiOggi`): un pregresso scritto dopo la cambia; senza, quella del compito.
            $oggi = $compito instanceof \App\Models\Evento ? \App\Services\Gestionale\RipianamentoDelDeficit::cifraDiOggi($compito) : null;
            $buco = $oggi !== null ? $oggi['parte'] : ($meta['importo_buco'] ?? null);
            $frase = 'Ripianamento del deficit' . (is_numeric($buco) && (int) $buco > 0 ? ' di ' . MoneyHelper::format((int) $buco) : '');
            // Nel testo dello studio dice anche verso chi è il debito (B4, simulazione del 21/11); la frase per la bacheca non nomina il fornitore.
            $fornitore = self::fornitoreDelDeficit($compito, $meta);
            // Con il debito a rate oltre lo scoperto non c'è niente da ripianare: c'è un ripianamento da correggere, e il punto lo dice.
            if ($oggi !== null && $oggi['oltre'] > 0) {
                $frase = 'Correzione del ripianamento del debito ereditato';
                $cifra = ': ' . MoneyHelper::format((int) $oggi['oltre']) . ' a rate oltre lo scoperto';

                return [$frase . ($fornitore !== null ? ' verso ' . $fornitore : '') . $cifra, $frase . $cifra, MateriaPunto::ALTRO->value];
            }

            return [$frase . ($fornitore !== null ? ' (debito ereditato verso ' . $fornitore . ')' : ''), $frase, MateriaPunto::ALTRO->value];
        }
        $morph = (new \App\Models\Segnalazione())->getMorphClass();
        $idSegnalazione = $compito->getAttribute('eventable_type') === $morph ? $compito->getAttribute('eventable_id') : ($meta['context']['segnalazione_id'] ?? null);
        $segnalazione = $idSegnalazione === null ? null : \App\Models\Segnalazione::query()->find((int) $idSegnalazione);
        if ($segnalazione !== null) {
            $oggetti[] = ['segnalazione', (int) $segnalazione->id, 'parla_di'];

            return self::dellaSegnalazione($segnalazione);
        }
        $titolo = trim((string) $compito->getAttribute('title'));

        return [$titolo, 'Un punto dall\'agenda dello studio', MateriaPunto::ALTRO->value];
    }

    /**
     * Il fornitore del deficit ereditato: quello del saldo da cui nasce il compito (`meta.context.saldo_id`), o, se il saldo non c'è più, quello del titolo
     * «Deficit Cassa Ereditato: <fornitore>». Null se non si sa.
     *
     * @param array<string, mixed> $meta
     */
    private static function fornitoreDelDeficit(Model $compito, array $meta): ?string
    {
        $saldoId = $meta['context']['saldo_id'] ?? null;
        if ($saldoId !== null) {
            $nome = DB::table('saldi')->join('fornitori', 'fornitori.id', '=', 'saldi.fornitore_id')->where('saldi.id', (int) $saldoId)->value('fornitori.ragione_sociale');
            if (filled($nome)) {
                return trim((string) $nome);
            }
        }
        $titolo = trim((string) $compito->getAttribute('title'));
        $prefisso = 'Deficit Cassa Ereditato:';

        return str_starts_with($titolo, $prefisso) && trim(substr($titolo, strlen($prefisso))) !== '' ? trim(substr($titolo, strlen($prefisso))) : null;
    }

    /** «2025» da «Esercizio 2025». */
    public static function anno(?int $esercizioId): string
    {
        if ($esercizioId === null) {
            return '';
        }
        $nome = (string) DB::table('esercizi')->where('id', $esercizioId)->value('nome');

        return trim((string) preg_replace('/^esercizio\s+/iu', '', $nome));
    }
}
