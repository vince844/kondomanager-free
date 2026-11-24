<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Enums\Assemblee\ConduttorePunto;
use App\Enums\Assemblee\Maggioranza;
use App\Enums\Assemblee\MateriaPunto;
use App\Helpers\MoneyHelper;
use Illuminate\Validation\Rule;

/**
 * Un punto dell'ordine del giorno (§3 P2; la scena 2 della bozza delle scene della tappa 2, decisione 568): il punto, la frase per la bacheca, di che cosa
 * parla, la maggioranza, il conduttore, per chi e con quale tabella (facoltativi), l'importo; per la destinazione d'uso le parti comuni e la nuova destinazione. **L'importo arriva in euro come lo scrive l'amministratore e
 * diventa centesimi una volta sola, qui** (`MoneyHelper::toCents()`). Le palazzine, le scale e le tabelle le risolve il controller nel condominio (R18).
 *
 * Le stesse regole, gli stessi nomi dei campi e gli stessi campi valgono per i punti scritti in «Prepara l'assemblea», prima che l'assemblea ci sia
 * ({@see PreparaAssembleaRequest::puntiScritti()}).
 */
class PuntoRequest extends RichiestaDelleAssemblee
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::regoleDelPunto($this->isMethod('PATCH') ? 'sometimes' : 'required');
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return self::nomiDeiCampi();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + self::paroleDegliErrori();
    }

    /**
     * Le regole di un punto. Con `$prefisso` le stesse regole per un punto dentro un'altra richiesta: «Prepara l'assemblea» manda i punti scritti in
     * `punti.*.` ({@see PreparaAssembleaRequest}), e la palazzina e la scala si chiedono guardando il «per chi» dello stesso punto.
     *
     * @return array<string, mixed>
     */
    public static function regoleDelPunto(string $obbligo, string $prefisso = ''): array
    {
        return self::conIlPrefisso([
            'testo' => [$obbligo, 'string', 'max:2000'],
            'testo_pubblico' => ['nullable', 'string', 'max:2000'],
            'materia' => [$obbligo, Rule::in(MateriaPunto::valori())],
            'maggioranza' => ['nullable', Rule::in(Maggioranza::valori())],
            'maggioranza_libera' => ['nullable', 'array'],
            'maggioranza_libera.soglia' => ['nullable', 'string', 'max:255'],
            'maggioranza_libera.norma' => ['nullable', 'string', 'max:255'],
            'conduttore' => ['nullable', Rule::in(ConduttorePunto::valori())],
            'ambito' => ['nullable', Rule::in(AmbitoAssemblea::valoriDeiPunti())],
            'palazzina_id' => ['nullable', 'integer', 'required_if:' . $prefisso . 'ambito,palazzina'],
            'scala_id' => ['nullable', 'integer', 'required_if:' . $prefisso . 'ambito,scala'],
            'tabella_id' => ['nullable', 'integer'],
            'importo' => ['nullable', 'string', 'max:30', 'regex:/^[0-9.,\s€]*$/u'],
            // La modifica della destinazione d'uso (art. 1117-ter): a pena di nullità l'avviso dice le parti comuni e la nuova destinazione. Gli altri dati
            // del punto (il motivo della spesa) li scrive il programma, non la pagina.
            'dati' => ['nullable', 'array:parti_comuni,nuova_destinazione,delega'],
            'dati.parti_comuni' => ['nullable', 'string', 'max:500'],
            'dati.nuova_destinazione' => ['nullable', 'string', 'max:500'],
            // La delega all'amministratore (decisione 622 (3)): per che cosa e fino a quando; il tetto è l'importo del punto. Senza il tetto o senza l'ambito
            // la delega non vale finché non si scrivono (art. 1131 c. 1 c.c., i poteri «conferiti»): il punto si salva lo stesso, e la pagina lo dice.
            'dati.delega' => ['nullable', 'array:ambito,fino_al'],
            'dati.delega.ambito' => ['nullable', Rule::in(\App\Enums\Assemblee\AmbitoDellaDelega::valori())],
            'dati.delega.fino_al' => ['nullable', 'date_format:Y-m-d'],
            // «Mettilo lo stesso» (decisione 699 (3)): il punto di tutto il condominio nell'assemblea di un gruppo, scelto dall'amministratore con il rischio.
            'mettilo_lo_stesso' => ['sometimes', 'boolean'],
        ], $prefisso);
    }

    /** I nomi dei campi come li dice la finestra del punto. @return array<string, string> */
    public static function nomiDeiCampi(string $prefisso = ''): array
    {
        return self::conIlPrefisso([
            'testo' => 'il punto',
            'testo_pubblico' => 'la frase per la bacheca',
            'materia' => 'di che cosa parla',
            'maggioranza' => 'la maggioranza',
            'maggioranza_libera.soglia' => 'l\'altra maggioranza',
            'maggioranza_libera.norma' => 'la norma dell\'altra maggioranza',
            'conduttore' => 'il conduttore',
            'ambito' => 'per chi',
            'palazzina_id' => 'la palazzina',
            'scala_id' => 'la scala',
            'tabella_id' => 'con quale tabella',
            'importo' => 'l\'importo',
            'dati.parti_comuni' => 'le parti comuni che cambiano',
            'dati.nuova_destinazione' => 'la nuova destinazione d\'uso',
            'dati.delega.ambito' => 'per che cosa',
            'dati.delega.fino_al' => 'fino al',
            'mettilo_lo_stesso' => '«Mettilo lo stesso»',
        ], $prefisso);
    }

    /** Le frasi degli errori del punto (oltre a quelle di tutte le richieste delle assemblee). @return array<string, string> */
    public static function paroleDegliErrori(string $prefisso = ''): array
    {
        return self::conIlPrefisso([
            'testo.required' => 'Scrivi il punto, come andrà nell\'avviso.',
            'materia.required' => 'Scegli di che cosa parla il punto.',
            'palazzina_id.required_if' => 'Scegli la palazzina del punto.',
            'scala_id.required_if' => 'Scegli la scala del punto.',
            'importo.regex' => 'L\'importo si scrive così: 8.200,00.',
            'dati.delega.fino_al.date_format' => 'Scegli il giorno fino a cui vale la delega.',
        ], $prefisso);
    }

    /** I campi per il servizio, con l'importo in centesimi. @return array<string, mixed> */
    public function campi(): array
    {
        return self::campiDelPunto($this->validated());
    }

    /**
     * I campi validati di un punto per il servizio: solo quelli delle regole (R18: la fonte, la chiave del sospeso, il memo e gli oggetti non arrivano dalla
     * pagina), **l'importo da euro a centesimi una volta sola, qui** (`MoneyHelper::toCents()`), i due campi della destinazione d'uso vuoti come mancanti, e
     * per tutto il condominio né palazzina né scala.
     *
     * @param array<string, mixed> $dati
     * @return array<string, mixed>
     */
    public static function campiDelPunto(array $dati): array
    {
        $campi = array_filter(array_keys(self::regoleDelPunto('required')), fn (string $k) => ! str_contains($k, '.'));
        $dati = array_intersect_key($dati, array_flip($campi));
        if (array_key_exists('importo', $dati)) {
            $dati['importo'] = $dati['importo'] === null || trim((string) $dati['importo']) === '' ? null : MoneyHelper::toCents($dati['importo']);
        }
        if (array_key_exists('dati', $dati)) {
            // Vuoti, i due campi non ci sono: chi li legge dice che mancano. La delega resta un gruppo: per che cosa e fino a quando, vuoti come mancanti.
            $dati['dati'] = array_map(fn ($v) => is_array($v) ? self::delegaDelPunto($v) : ($v === null || trim((string) $v) === '' ? null : trim((string) $v)),
                (array) ($dati['dati'] ?? []));
        }
        if (array_key_exists('ambito', $dati) && ($dati['ambito'] ?? null) === AmbitoAssemblea::CONDOMINIO->value) {
            $dati['palazzina_id'] = null;
            $dati['scala_id'] = null;
        }

        return $dati;
    }

    /**
     * La delega del punto: l'ambito e il giorno, vuoti come mancanti; null se tutti e due mancano.
     *
     * @param array<string, mixed> $delega
     * @return ?array{ambito: ?string, fino_al: ?string}
     */
    private static function delegaDelPunto(array $delega): ?array
    {
        $ambito = trim((string) ($delega['ambito'] ?? ''));
        $fino = trim((string) ($delega['fino_al'] ?? ''));

        return $ambito === '' && $fino === '' ? null : ['ambito' => $ambito === '' ? null : $ambito, 'fino_al' => $fino === '' ? null : $fino];
    }

    /** @param array<string, mixed> $voci @return array<string, mixed> */
    private static function conIlPrefisso(array $voci, string $prefisso): array
    {
        if ($prefisso === '') {
            return $voci;
        }

        return array_combine(array_map(fn (string $k) => $prefisso . $k, array_keys($voci)), array_values($voci));
    }
}
