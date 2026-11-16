<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Gestionale\Dashboard;

use App\Helpers\DateHelper;
use App\Http\Controllers\Controller;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Evento;
use App\Services\Gestionale\Debiti\SeiMesiPerAgire;
use App\Services\Gestionale\RispostePromemoria;
use App\Traits\HandleFlashMessages;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Le risposte dell'amministratore dal riquadro «Sei mesi per agire» e dal compito dell'Inbox: «Registra che hai agito» per una persona, «Fatto» per le
 * persone spuntate (o per il rendiconto), «Non serve», «Ricordamelo il…», «Riapri», «Mettilo in agenda» per il rendiconto. Le scrive solo il server, con
 * {@see RispostePromemoria}; un `registro` mandato dalla pagina non si legge.
 *
 * Chi può è chi chiude i compiti dell'Inbox (`EventoPolicy::chiudiCompito`, «Modifica scadenza in agenda»). L'esercizio e le persone della richiesta devono
 * essere di questo condominio, altrimenti 404 per tutta la richiesta; una persona del condominio che non è aperta nella riga è un rifiuto con la sua frase.
 */
class SeiMesiRisposteController extends Controller
{
    use HandleFlashMessages;

    public const TIPI = ['agito', 'fatto', 'non_serve', 'ricordamelo', 'riapri', 'in_agenda'];

    public const FRASE_SCRIVI_COSA = 'Scrivi che cosa hai fatto.';
    public const FRASE_GIORNO_DOPO_OGGI = 'Il giorno in cui hai agito non può essere dopo oggi.';
    public const FRASE_NON_NELLA_RIGA = 'Questa persona non ha rate di questo esercizio nel riquadro.';
    public const FRASE_PERCHE = 'Scrivi perché non serve.';
    public const FRASE_DA_DOMANI = 'Scegli un giorno da domani in poi.';
    public const FRASE_DA_OGGI = 'Scegli un giorno da oggi in poi.';
    public const FRASE_SPUNTA = 'Spunta almeno una persona.';
    public const FRASE_SCRIVI_IL_GIORNO = 'Scrivi il giorno in cui hai agito.';

    public function __invoke(Request $request, Condominio $condominio, SeiMesiPerAgire $seiMesi, RispostePromemoria $risposte): RedirectResponse
    {
        Gate::authorize('chiudiCompito', Evento::class);

        $base = $request->validate([
            'tipo' => ['required', Rule::in(self::TIPI)],
            'argomento' => ['required', Rule::in([RispostePromemoria::SEI_MESI, RispostePromemoria::RENDICONTO])],
            'esercizio_id' => ['required', 'integer'],
        ]);
        $esercizio = Esercizio::find((int) $base['esercizio_id']);
        abort_unless($esercizio !== null && (int) $esercizio->condominio_id === (int) $condominio->id, 404);
        $oggi = DateHelper::oggiUtente();
        $utente = $request->user();
        $anno = self::anno($esercizio);

        return match ($base['tipo']) {
            'agito' => $this->agito($request, $condominio, $esercizio, $seiMesi, $risposte, $oggi),
            'fatto' => $this->fatto($request, $condominio, $esercizio, $base['argomento'], $seiMesi, $risposte, $oggi),
            'non_serve' => $this->nonServe($request, $condominio, $esercizio, $base['argomento'], $seiMesi, $risposte),
            'ricordamelo' => $this->ricordamelo($request, $condominio, $esercizio, $base['argomento'], $risposte, $oggi, $anno),
            'in_agenda' => $this->inAgenda($request, $condominio, $esercizio, $risposte, $oggi, $anno),
            'riapri' => $this->riapri($request, $condominio, $esercizio, $base['argomento'], $risposte),
        };
    }

    private function agito(Request $request, Condominio $condominio, Esercizio $esercizio, SeiMesiPerAgire $seiMesi, RispostePromemoria $risposte, string $oggi): RedirectResponse
    {
        $dati = $request->validate([
            'anagrafica_id' => ['required', 'integer'],
            'giorno' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $oggi],
            'nota' => ['required', 'string', 'min:3', 'max:500'],
        ], self::messaggi(self::FRASE_SCRIVI_IL_GIORNO));
        $persona = $this->personaDelCondominio($condominio, (int) $dati['anagrafica_id']);
        $aperte = $seiMesi->aperteDellaRiga($condominio, (int) $esercizio->id);
        if (! isset($aperte[$persona->id])) {
            throw ValidationException::withMessages(['anagrafica_id' => self::FRASE_NON_NELLA_RIGA]);
        }
        $risposte->agito($condominio, (int) $esercizio->id, (int) $persona->id, $dati['giorno'], trim($dati['nota']), $request->user(), $aperte[$persona->id]);

        return back()->with($this->flashSuccess('Registrato: ' . $persona->nome . ' esce dal riquadro per l\'esercizio ' . self::anno($esercizio) . '.'));
    }

    private function fatto(Request $request, Condominio $condominio, Esercizio $esercizio, string $argomento, SeiMesiPerAgire $seiMesi, RispostePromemoria $risposte, string $oggi): RedirectResponse
    {
        if ($argomento === RispostePromemoria::RENDICONTO) {
            $dati = $request->validate(['nota' => ['nullable', 'string', 'max:500']], self::messaggi());
            $risposte->fatto($condominio, $argomento, (int) $esercizio->id, [], $oggi, trim((string) ($dati['nota'] ?? '')), $request->user());

            return back()->with($this->flashSuccess('Fatto: il compito è chiuso.'));
        }
        $dati = $request->validate([
            'persone' => ['required', 'array', 'min:1'],
            'persone.*' => ['integer'],
            'giorno' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $oggi],
            'nota' => ['required', 'string', 'min:3', 'max:500'],
        ], self::messaggi(self::FRASE_SCRIVI_IL_GIORNO));
        $ids = array_values(array_unique(array_map('intval', $dati['persone'])));
        abort_unless(Anagrafica::whereIn('id', $ids)->whereHas('condomini', fn ($q) => $q->where('condomini.id', $condominio->id))->count() === count($ids), 404);
        $aperte = $seiMesi->aperteDellaRiga($condominio, (int) $esercizio->id);
        if (array_diff($ids, array_keys($aperte)) !== []) {
            throw ValidationException::withMessages(['persone' => self::FRASE_NON_NELLA_RIGA]);
        }
        $risposte->fatto($condominio, $argomento, (int) $esercizio->id, array_intersect_key($aperte, array_flip($ids)), $dati['giorno'], trim($dati['nota']), $request->user());

        return back()->with($this->flashSuccess('Fatto: registrato per ' . (count($ids) === 1 ? '1 persona' : count($ids) . ' persone') . '.'));
    }

    private function nonServe(Request $request, Condominio $condominio, Esercizio $esercizio, string $argomento, SeiMesiPerAgire $seiMesi, RispostePromemoria $risposte): RedirectResponse
    {
        $dati = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:255']], self::messaggi());
        $persone = $argomento === RispostePromemoria::SEI_MESI ? array_keys($seiMesi->aperteDellaRiga($condominio, (int) $esercizio->id)) : [];
        $risposte->nonServe($condominio, $argomento, (int) $esercizio->id, trim($dati['motivo']), $request->user(), ['persone' => $persone]);

        return back()->with($this->flashSuccess('Non serve: il compito è chiuso.'));
    }

    private function ricordamelo(Request $request, Condominio $condominio, Esercizio $esercizio, string $argomento, RispostePromemoria $risposte, string $oggi, string $anno): RedirectResponse
    {
        $dati = $request->validate([
            'giorno' => ['required', 'date_format:Y-m-d', 'after:' . $oggi],
            'nota' => ['nullable', 'string', 'max:500'],
        ], self::messaggi(self::FRASE_DA_DOMANI));
        $titolo = $argomento === RispostePromemoria::SEI_MESI
            ? 'Ricordamelo: sei mesi per agire, esercizio ' . $anno . ' — ' . $condominio->nome
            : 'Ricordamelo: rendiconto ' . $anno . ' da portare in assemblea — ' . $condominio->nome;
        $risposte->ricordamelo($condominio, $argomento, (int) $esercizio->id, $dati['giorno'], $dati['nota'] ?? null, $request->user(), $titolo,
            self::ritorno($condominio, $argomento, $esercizio));

        return back()->with($this->flashSuccess('Ti ricordo il compito il ' . CarbonImmutable::parse($dati['giorno'])->format('d/m/Y') . ': è nell\'agenda.'));
    }

    private function inAgenda(Request $request, Condominio $condominio, Esercizio $esercizio, RispostePromemoria $risposte, string $oggi, string $anno): RedirectResponse
    {
        $request->merge(['giorno' => $request->input('giorno') ?: SeiMesiPerAgire::dataDelRendiconto((string) $esercizio->data_fine)]);
        $dati = $request->validate([
            'giorno' => ['required', 'date_format:Y-m-d', 'after_or_equal:' . $oggi],
            'nota' => ['nullable', 'string', 'max:500'],
        ], self::messaggi(self::FRASE_DA_OGGI));
        $risposte->inAgenda($condominio, (int) $esercizio->id, $dati['giorno'], $dati['nota'] ?? null, $request->user(),
            'Convocare l\'assemblea per il rendiconto ' . $anno . ' — ' . $condominio->nome, self::ritorno($condominio, RispostePromemoria::RENDICONTO, $esercizio));

        return back()->with($this->flashSuccess('In agenda il ' . CarbonImmutable::parse($dati['giorno'])->format('d/m/Y') . '.'));
    }

    private function riapri(Request $request, Condominio $condominio, Esercizio $esercizio, string $argomento, RispostePromemoria $risposte): RedirectResponse
    {
        $dati = $request->validate(['anagrafica_id' => ['nullable', 'integer']]);
        $persona = isset($dati['anagrafica_id']) ? $this->personaDelCondominio($condominio, (int) $dati['anagrafica_id']) : null;
        // Dove non c'è una risposta non c'è niente da riaprire: nessuna riga vuota.
        $esito = $risposte->riapri($condominio, $argomento, (int) $esercizio->id, $persona?->id, $request->user());
        abort_if($esito === null, 404);
        $n = $esito['persone'];

        return back()->with($this->flashSuccess($n > 0
            ? 'Riaperto per ' . ($n === 1 ? '1 persona: torna' : $n . ' persone: tornano') . ' nel riquadro. La riga del registro resta.'
            : 'Riaperto: la riga del registro resta.'));
    }

    /** La persona, se è di questo condominio; altrimenti 404. */
    private function personaDelCondominio(Condominio $condominio, int $id): Anagrafica
    {
        $persona = Anagrafica::whereKey($id)->whereHas('condomini', fn ($q) => $q->where('condomini.id', $condominio->id))->first();
        abort_if($persona === null, 404);

        return $persona;
    }

    /** Il ritorno dall'agenda al riquadro: la dashboard del condominio, alla riga. */
    private static function ritorno(Condominio $condominio, string $argomento, Esercizio $esercizio): string
    {
        return route('admin.gestionale.index', $condominio) . '#' . ($argomento === RispostePromemoria::SEI_MESI ? 'sei-mesi-' : 'rendiconto-') . $esercizio->id;
    }

    /** «2025» da «Esercizio 2025». */
    private static function anno(Esercizio $esercizio): string
    {
        return trim((string) preg_replace('/^esercizio\s+/iu', '', (string) $esercizio->nome));
    }

    /** I messaggi dei rifiuti, con le parole del riquadro. */
    private static function messaggi(string $giornoNelFuturo = self::FRASE_DA_DOMANI): array
    {
        return [
            'nota.required' => self::FRASE_SCRIVI_COSA, 'nota.min' => self::FRASE_SCRIVI_COSA,
            'giorno.before_or_equal' => self::FRASE_GIORNO_DOPO_OGGI,
            'giorno.after' => self::FRASE_DA_DOMANI, 'giorno.after_or_equal' => self::FRASE_DA_OGGI,
            'giorno.required' => $giornoNelFuturo, 'giorno.date_format' => $giornoNelFuturo,
            'motivo.required' => self::FRASE_PERCHE, 'motivo.min' => self::FRASE_PERCHE,
            'persone.required' => self::FRASE_SPUNTA, 'persone.min' => self::FRASE_SPUNTA,
        ];
    }
}
