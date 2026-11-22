<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee;

use App\Models\Assemblee\Delibera;
use App\Services\Assemblee\SchedaDelCondominio;

/**
 * Le delibere scelte nel modulo della fattura (§1.4 del progetto della tappa 2): la **competenza** «Spesa deliberata il …» (ruolo `competenza`) e la
 * **spesa imprevista** «Delibera assembleare» (ruolo `origine_della_spesa`). La delibera arriva come id e passa dal risolutore nel condominio della rotta (R18:
 * una delibera di un altro condominio è 404); **le colonne di oggi si scrivono dalla delibera**, prima della validazione, per la strada che hanno già:
 * `competenza_dal` = `competenza_al` = il giorno della riunione, e `dati_extra.log_legale_sopravvenienza.data_assemblea` = il giorno della riunione (solo con
 * l'origine «delibera assembleare»). Il collegamento lo fa il controller dopo il salvataggio, nella stessa transazione.
 *
 * La delibera della spesa la manda la finestra «Fuori preventivo» dentro i suoi dati (`log_legale_sopravvenienza.delibera_id`): qui esce dai dati della
 * fattura e diventa `delibera_spesa_id`, così in `dati_extra` resta solo la data.
 */
trait LeDelibereDellaFattura
{
    /** Le delibere risolte, per il controller. @var array{competenza: ?Delibera, spesa: ?Delibera} */
    private array $delibereDellaFattura = ['competenza' => null, 'spesa' => null];

    protected function prepareForValidation(): void
    {
        $this->riempiDalleDelibere();
    }

    protected function riempiDalleDelibere(): void
    {
        $condominio = $this->route('condominio');
        if ($this->filled('delibera_competenza_id') && is_numeric($this->input('delibera_competenza_id'))) {
            self::soloChiGestisce($this);
            /** @var Delibera $d */
            $d = SchedaDelCondominio::risolvi($condominio, 'delibera', (int) $this->input('delibera_competenza_id'));
            $this->delibereDellaFattura['competenza'] = $d;
            $giorno = $d->deliberata_il?->format('Y-m-d');
            $this->merge(['competenza_dal' => $giorno, 'competenza_al' => $giorno]);
        }

        $extra = $this->input('dati_extra');
        $log = is_array($extra) ? ($extra['log_legale_sopravvenienza'] ?? null) : null;
        $id = is_array($log) && isset($log['delibera_id']) ? $log['delibera_id'] : $this->input('delibera_spesa_id');
        if (is_array($log) && array_key_exists('delibera_id', $log)) {
            unset($log['delibera_id']);
        }
        if ($id !== null && $id !== '' && is_numeric($id) && is_array($log) && ($log['origine_decisionale'] ?? null) === 'delibera_assembleare') {
            self::soloChiGestisce($this);
            /** @var Delibera $d */
            $d = SchedaDelCondominio::risolvi($condominio, 'delibera', (int) $id);
            $this->delibereDellaFattura['spesa'] = $d;
            $log['data_assemblea'] = $d->deliberata_il?->format('Y-m-d');
            $this->merge(['delibera_spesa_id' => (int) $d->id]);
        } elseif ($this->has('delibera_spesa_id')) {
            $this->merge(['delibera_spesa_id' => null]);
        }
        if (is_array($extra) && is_array($log)) {
            $extra['log_legale_sopravvenienza'] = $log;
            $this->merge(['dati_extra' => $extra]);
        }
    }

    /** Il permesso prima di tutto: chi non gestisce le assemblee riceve 403, non gli errori del modulo né un 404 sulla delibera. */
    private static function soloChiGestisce(\Illuminate\Http\Request $richiesta): void
    {
        \Illuminate\Support\Facades\Gate::allowIf(\App\Services\Assemblee\AccessoAlleAssemblee::gestisce($richiesta->user()), __('errors.403_message'));
    }

    /** Le regole dei due campi. @return array<string, mixed> */
    protected function regoleDelleDelibere(): array
    {
        return [
            'delibera_competenza_id' => ['nullable', 'integer'],
            'delibera_spesa_id' => ['nullable', 'integer'],
        ];
    }

    /** I nomi dei due campi come li dice la pagina. @return array<string, string> */
    protected function nomiDelleDelibere(): array
    {
        return ['delibera_competenza_id' => 'la delibera della competenza', 'delibera_spesa_id' => 'la delibera della spesa'];
    }

    /** Le delibere scelte, già risolte nel condominio. @return array{competenza: ?Delibera, spesa: ?Delibera} */
    public function delibereDellaFattura(): array
    {
        return $this->delibereDellaFattura;
    }
}
