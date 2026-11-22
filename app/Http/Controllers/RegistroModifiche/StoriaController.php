<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\RegistroModifiche;

use App\Http\Controllers\Controller;
use App\Models\RegistroModifica;
use App\Services\RegistroModifiche\AccessoAlRegistro;
use App\Services\RegistroModifiche\FirmaDelRegistro;
use App\Services\RegistroModifiche\LetturaDelRegistro;
use App\Services\RegistroModifiche\RegistroPerLaPagina;
use App\Services\RegistroModifiche\Ripristino\RipristinoDiUnCampo;
use App\Services\RegistroModifiche\SchedeDellaStoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * La «Storia» di una scheda, per il cassetto (§8 del progetto della tappa 1, scena 1 della bozza): un JSON, perché il cassetto è lo stesso in trenta pagine
 * e non è una visita della pagina (standard 16).
 *
 * Chi: il permesso «Vede la storia delle schede», mai i ruoli `utente` e `fornitore` (P18); il tipo dall'elenco chiuso (404 fuori); le schede dello studio e
 * gli oggetti che non ci sono più solo l'amministratore; per le altre chi può vedere quella scheda ({@see SchedeDellaStoria::puoVedere()}).
 *
 * Il JSON porta solo `prima` e `dopo` dei campi, mai `valore_*` né le colonne tecniche (P18): le righe passano da {@see LetturaDelRegistro::perLaPagina()}.
 * Venti righe per volta, dalla più recente; «Mostra altre» chiede la pagina dopo.
 */
class StoriaController extends Controller
{
    public function __invoke(Request $request, string $tipo, ?int $id = null): JsonResponse
    {
        $utente = $request->user();
        Gate::allowIf(AccessoAlRegistro::vedeLaStoria($utente), __('errors.403_message'));
        abort_unless(SchedeDellaStoria::esiste($tipo), 404);
        abort_if(SchedeDellaStoria::conId($tipo) && $id === null, 404);

        $cosa = SchedeDellaStoria::cosa($tipo, $id);
        Gate::allowIf(SchedeDellaStoria::puoVedere($utente, $tipo, $cosa), __('errors.403_message'));

        $pagina = max(1, (int) $request->query('pagina', 1));
        $query = SchedeDellaStoria::righe($tipo, SchedeDellaStoria::conId($tipo) ? $id : null);
        $totale = (clone $query)->count();
        $righe = $query->forPage($pagina, LetturaDelRegistro::PER_PAGINA)->get();
        $prima = $totale === 0 ? null : (clone SchedeDellaStoria::righe($tipo, SchedeDellaStoria::conId($tipo) ? $id : null))->reorder()
            ->orderBy('avvenuta_il')->value('avvenuta_il');
        $annullamento = LetturaDelRegistro::annullamento();
        // «Vedi il lotto» / «Apri» delle righe con il rimando, letti per tipo una volta.
        $aperti = RegistroPerLaPagina::apriTutte($righe->filter(fn (RegistroModifica $r) => $r->rimando !== null));
        // La firma delle righe, letta per tutta la pagina: una riga aggiunta con il database ha il badge «Aggiunta fuori dal programma» e non si rimette.
        $firmate = FirmaDelRegistro::delleRighe($righe);

        // La cosa di ogni riga, letta una volta: serve a sapere se il campo si ripristina.
        $cose = [];
        $cosaDi = function (RegistroModifica $r) use (&$cose) {
            $chiave = $r->oggetto_tipo . ':' . $r->oggetto_id;
            if (! array_key_exists($chiave, $cose)) {
                $modulo = RipristinoDiUnCampo::MODULI[$r->oggetto_tipo] ?? null;
                $cose[$chiave] = $modulo === null ? null : (new $modulo())->cosa($r->oggetto_id);
            }

            return $cose[$chiave];
        };

        $nome = $cosa === null ? $righe->first()?->oggetto_nome : null;
        $oscuramento = AccessoAlRegistro::eAmministratore($utente) && in_array($tipo, ['anagrafica', 'fornitore'], true) && $cosa !== null
            ? ['soggetto_tipo' => $tipo, 'soggetto_id' => (int) $id, 'nome' => (string) ($tipo === 'anagrafica' ? $cosa->nome : $cosa->ragione_sociale)]
            : null;

        return response()->json([
            'titolo' => SchedeDellaStoria::titolo($tipo, $cosa, $nome),
            'sottotitolo' => $this->sottotitolo($tipo, $cosa, $totale, $prima),
            'totale' => $totale,
            'pagina' => $pagina,
            'altre' => max(0, $totale - $pagina * LetturaDelRegistro::PER_PAGINA),
            'righe' => $righe->map(function (RegistroModifica $r) use ($utente, $annullamento, $cosaDi, $aperti, $firmate) {
                $riga = LetturaDelRegistro::perLaPagina($r, $annullamento);
                $cosa = $r->azione === 'modificata' ? $cosaDi($r) : null;
                $riga['ripristina'] = collect($r->campi ?? [])->mapWithKeys(fn ($c) => [(string) $c['chiave']
                    => $r->azione === 'modificata'
                    && RipristinoDiUnCampo::perCuiNo($r, (string) $c['chiave'], $utente, $annullamento, $cosa, $firmate[(int) $r->id] ?? false) === null])->all();
                $riga['nota'] = RipristinoDiUnCampo::notaDellArea($r);
                $riga['fuori_dal_programma'] = ! ($firmate[(int) $r->id] ?? false);
                // «Vedi il lotto» / «Apri» di un gesto con il rimando (la bozza: l'importazione).
                $riga['apri'] = $r->rimando !== null ? ($aperti[(int) $r->id] ?? null) : null;

                return $riga;
            })->values()->all(),
            // «Oscura i suoi dati nel registro» (§10): solo l'amministratore, nella «Storia» di una persona o di un fornitore.
            'oscuramento' => $oscuramento,
        ]);
    }

    /** «Anagrafica · Condominio Via Roma 12 · 4 modifiche dal 01/11/2026» (la bozza). */
    private function sottotitolo(string $tipo, ?object $cosa, int $totale, ?string $prima): string
    {
        $parti = [SchedeDellaStoria::parola($tipo)];
        $condomini = $cosa instanceof \Illuminate\Database\Eloquent\Model ? array_values(SchedeDellaStoria::condomini($tipo, $cosa)) : [];
        if (count($condomini) === 1) {
            $parti[] = $this->conCondominio($condomini[0]);
        } elseif (count($condomini) > 1) {
            $parti[] = 'Condomini ' . implode(', ', array_map(fn ($c) => preg_replace('/^condominio\s+/i', '', $c), $condomini));
        } elseif (SchedeDellaStoria::delloStudio($tipo)) {
            $parti[] = 'Dello studio';
        }
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $dal = $prima === null ? null : \Illuminate\Support\Carbon::parse($prima, 'UTC')->setTimezone($fuso)->format('d/m/Y');
        $parti[] = match (true) {
            $totale === 0 => 'Nessuna modifica nel registro',
            $totale === 1 => '1 modifica, del ' . $dal,
            default => number_format($totale, 0, ',', '.') . ' modifiche dal ' . $dal,
        };

        return implode(' · ', $parti);
    }

    private function conCondominio(string $nome): string
    {
        return str_starts_with(mb_strtolower($nome), 'condominio') ? $nome : 'Condominio ' . $nome;
    }
}
