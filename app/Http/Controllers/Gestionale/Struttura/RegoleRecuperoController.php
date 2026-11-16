<?php

namespace App\Http\Controllers\Gestionale\Struttura;

use App\Enums\Permission;
use App\Helpers\DateHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RegolaRecupero;
use App\Services\Gestionale\Accessori\RegoleDelRecupero;
use App\Services\Gestionale\Accessori\TassoLegaleAMano;
use App\Services\Gestionale\Addebiti\AddebitiIndividuali;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Le regole del recupero dei crediti del condominio, nella sua scheda: la storia delle regole, la regola nuova dal giorno in cui comincia, l'eliminazione
 * di una regola che nessun interesse registrato ha usato. Una regola non si riscrive: per cambiarla se ne scrive una nuova, e la vecchia resta per i
 * giorni in cui valeva.
 *
 * Nella stessa pagina la tabella del tasso legale: per un anno che il programma non ha ancora lo studio può scriverlo a mano, con la fonte, una volta per
 * tutti i condomini ({@see TassoLegaleAMano}). Lo scrive, lo corregge e lo toglie solo chi gestisce le impostazioni generali: per gli altri 403.
 *
 * Le rotte stanno nel gruppo del gestionale, dietro il pannello. La regola dell'indirizzo deve essere di questo condominio: altrimenti 404.
 */
class RegoleRecuperoController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio;

    /** I caratteri del riferimento di un tasso scritto. */
    public const RIFERIMENTO_MASSIMO = 150;

    public function index(Request $request, Condominio $condominio): Response
    {
        $regole = (new RegoleDelRecupero())->delCondominio((int) $condominio->id);
        $oggi = DateHelper::oggiUtente();
        $aMano = TassoLegaleAMano::perAnno();
        $delProgramma = RegoleDelRecupero::anniDelProgramma();
        // Gli anni del programma, dal più recente; se lo studio aveva scritto a mano un'altra cifra per lo stesso anno, vale il programma e lo si dice.
        $tabella = collect($delProgramma)->sortKeysDesc()->map(fn (int $e4, int $anno) => ['anno' => $anno, 'tasso' => RegoleDelRecupero::percentuale($e4),
            'a_mano_diverso' => isset($aMano[$anno]) ? TassoLegaleAMano::fraseDiversa($aMano[$anno], $e4) : null])->values()->all();
        $ultimo = TassoLegaleAMano::ultimoAnnoDelProgramma();
        $legaliAMano = collect($aMano)->filter(fn (array $r, int $anno) => $anno > $ultimo)->sortKeysDesc()->map(fn (array $r) => [
            'anno' => $r['anno'], 'tasso' => RegoleDelRecupero::percentuale($r['tasso_e4']), 'tasso_annuo' => self::tassoDaScrivere($r['tasso_e4']),
            'fonte' => $r['fonte'], 'scritto_da' => $r['scritto_da_nome'], 'scritto_il' => $r['scritto_il'],
        ])->values()->all();
        $daScrivere = TassoLegaleAMano::anniDaScrivere($oggi);
        $mancanti = collect($daScrivere)->reject(fn (int $anno) => isset($aMano[$anno]))->sortDesc()
            ->map(fn (int $anno) => ['anno' => $anno, 'frase' => TassoLegaleAMano::fraseAnnoMancante($anno, $oggi)])->values()->all();
        $diOggi = RegoleDelRecupero::alGiorno($regole, $oggi);

        return Inertia::render('gestionale/struttura/RegoleRecupero', [
            'condominio' => new CondominioResource($condominio),
            'condomini' => $this->getCondomini(),
            'esercizio' => $this->getEsercizioCorrente($condominio),
            'regole' => array_map(fn (array $r) => $r + ['usata' => $this->usataDa($condominio, $regole->firstWhere('id', $r['id']))],
                RegoleDelRecupero::perLaPagina($regole)),
            'di_oggi' => $diOggi === null ? null : (int) $diOggi->id,
            'tasso_di_oggi' => RegoleDelRecupero::tassoPerLaLettera($regole, $oggi),
            'tabella_legale' => $tabella,
            'legali_a_mano' => $legaliAMano,
            'anni_mancanti' => $mancanti,
            'anni_da_scrivere' => $daScrivere,
            'puo_scrivere_tasso_legale' => $this->gestisceLeImpostazioni($request),
            'fonti' => collect(RegolaRecupero::FONTI)->map(fn ($nome, $k) => ['valore' => $k, 'nome' => $nome])->values()->all(),
            'frase_senza_regole' => RegoleDelRecupero::FRASE_SENZA_REGOLE,
            'avvisi_fonti' => collect(array_keys(RegolaRecupero::FONTI))->mapWithKeys(fn ($f) => [$f => RegoleDelRecupero::avvisoDellaFonte($f)])->filter()->all(),
            'riferimento_massimo' => self::RIFERIMENTO_MASSIMO,
            'oggi' => $oggi,
        ]);
    }

    public function store(Request $request, Condominio $condominio): RedirectResponse
    {
        $dati = $request->validate([
            'dal' => ['required', 'date_format:Y-m-d'],
            'tasso_tipo' => ['required', Rule::in([RegolaRecupero::TASSO_LEGALE, RegolaRecupero::TASSO_SCRITTO])],
            'tasso_annuo' => ['nullable', 'required_if:tasso_tipo,' . RegolaRecupero::TASSO_SCRITTO, 'string', 'max:12'],
            'fonte' => ['nullable', 'required_if:tasso_tipo,' . RegolaRecupero::TASSO_SCRITTO, Rule::in(array_keys(RegolaRecupero::FONTI))],
            // Il riferimento di un tasso scritto si stampa per intero accanto al tasso, anche nella causale degli interessi, che sta in una riga della
            // scrittura: 150 caratteri lasciano posto alla data, al tasso e al tasso legale dei giorni prima o dopo.
            'riferimento' => ['nullable', 'required_if:tasso_tipo,' . RegolaRecupero::TASSO_SCRITTO, 'string', 'max:' . self::RIFERIMENTO_MASSIMO],
            'giorni_decorrenza' => ['nullable', 'integer', 'min:0', 'max:365'],
            'giorni_tolleranza' => ['nullable', 'integer', 'min:0', 'max:365'],
            'termine_lettere_giorni' => ['nullable', 'integer', 'min:1', 'max:365'],
        ], [
            'dal.required' => 'Scrivi il giorno da cui la regola vale.',
            'tasso_tipo.required' => 'Scegli il tasso: quello legale dell\'anno, o un tasso scritto con la sua fonte.',
            'tasso_annuo.required_if' => 'Scrivi la misura del tasso, in punti percentuali l\'anno.',
            'fonte.required_if' => 'Scegli da dove viene il tasso: il regolamento, una delibera, altro.',
            'riferimento.required_if' => 'Scrivi il riferimento del tasso: da dove viene, così si può controllare.',
            'riferimento.max' => 'Il riferimento sta in ' . self::RIFERIMENTO_MASSIMO . ' caratteri: si stampa per intero accanto al tasso.',
        ]);
        $tasso = null;
        if ($dati['tasso_tipo'] === RegolaRecupero::TASSO_SCRITTO) {
            $tasso = self::tasso((string) $dati['tasso_annuo']);
            if ($tasso === null) {
                throw ValidationException::withMessages(['tasso_annuo' => 'Scrivi il tasso come un numero, per esempio 3 o 2,5: più di zero, al più 100, con al più quattro decimali.']);
            }
        }
        if (RegolaRecupero::where('condominio_id', $condominio->id)->whereDate('dal', $dati['dal'])->exists()) {
            throw ValidationException::withMessages(['dal' => 'C\'è già una regola dal ' . CarbonImmutable::parse($dati['dal'])->format('d/m/Y')
                . ': una regola non si riscrive. Scrivine una con un altro giorno di inizio.']);
        }
        // Una regola che comincia dentro giorni già registrati non li cambia: lo dice, non lo impedisce.
        $registrati = $this->giorniRegistrati($condominio, $dati['dal']);
        RegolaRecupero::create([
            'condominio_id' => $condominio->id,
            'dal' => $dati['dal'],
            'tasso_tipo' => $dati['tasso_tipo'],
            'tasso_annuo' => $tasso,
            'fonte' => $dati['tasso_tipo'] === RegolaRecupero::TASSO_SCRITTO ? $dati['fonte'] : null,
            'riferimento' => filled($dati['riferimento'] ?? null) ? trim((string) $dati['riferimento']) : null,
            'giorni_decorrenza' => (int) ($dati['giorni_decorrenza'] ?? 0),
            'giorni_tolleranza' => $dati['giorni_tolleranza'] ?? null,
            'termine_lettere_giorni' => $dati['termine_lettere_giorni'] ?? null,
            'creata_da' => $request->user()?->id,
        ]);

        $frase = 'Regola registrata: vale dal ' . CarbonImmutable::parse($dati['dal'])->format('d/m/Y') . '. Le regole di prima restano per i loro giorni.';
        if ($registrati !== null) {
            return back()->with($this->flashWarning($frase . ' Dal ' . CarbonImmutable::parse($dati['dal'])->format('d/m/Y') . ' al '
                . CarbonImmutable::parse($registrati)->format('d/m/Y') . ' ci sono interessi registrati con il tasso di prima: restano come sono.'));
        }

        return back()->with($this->flashSuccess($frase));
    }

    public function destroy(Condominio $condominio, string|int $regola): RedirectResponse
    {
        $r = $this->assicuraRegola($condominio, $regola);
        if (($quando = $this->usataDa($condominio, $r)) !== null) {
            throw ValidationException::withMessages(['regola' => 'Questa regola è servita per gli interessi registrati al ' . CarbonImmutable::parse($quando)->format('d/m/Y')
                . ': resta nella storia. Per cambiarla, scrivi una regola nuova con il suo giorno di inizio.']);
        }
        $r->delete();

        return back()->with($this->flashSuccess('Regola eliminata.'));
    }

    /**
     * Il tasso legale di un anno che il programma non ha ancora, scritto a mano dallo studio con la fonte: per tutti i condomini. Scritto di nuovo, un
     * anno si corregge. Solo chi gestisce le impostazioni generali.
     */
    public function scriviTassoLegale(Request $request, Condominio $condominio): RedirectResponse
    {
        abort_unless($this->gestisceLeImpostazioni($request), 403);
        $dati = $request->validate([
            'anno' => ['required', 'integer', 'min:1900', 'max:2999'],
            'tasso' => ['required', 'string', 'max:12'],
            'fonte' => ['required', 'string', 'max:255'],
        ], [
            'anno.required' => 'Scegli l\'anno.',
            'tasso.required' => 'Scrivi il tasso, in punti percentuali l\'anno.',
            'fonte.required' => 'Scrivi la fonte: il decreto del Ministero dell\'economia e la Gazzetta Ufficiale.',
        ]);
        $oggi = DateHelper::oggiUtente();
        $anno = (int) $dati['anno'];
        if (($perche = TassoLegaleAMano::percheNo($anno, $oggi)) !== null) {
            throw ValidationException::withMessages(['anno' => $perche]);
        }
        $tasso = self::tasso((string) $dati['tasso']);
        if ($tasso === null) {
            throw ValidationException::withMessages(['tasso' => 'Scrivi il tasso come un numero, per esempio 2 o 2,5: più di zero, al più 100, con al più quattro decimali.']);
        }
        $e4 = (int) str_replace('.', '', $tasso);
        $corretto = TassoLegaleAMano::scrivi($anno, $e4, (string) $dati['fonte'], $request->user(), $oggi);

        return back()->with($this->flashSuccess('Tasso legale del ' . $anno . ($corretto ? ' corretto: ' : ' registrato: ') . RegoleDelRecupero::percentuale($e4)
            . ', per tutti i condomini.' . ($corretto ? ' Gli interessi già registrati restano come sono.' : '')));
    }

    /** Toglie il tasso legale di un anno scritto a mano: gli interessi già registrati restano come sono. Solo chi gestisce le impostazioni generali. */
    public function togliTassoLegale(Request $request, Condominio $condominio): RedirectResponse
    {
        abort_unless($this->gestisceLeImpostazioni($request), 403);
        $anno = (int) $request->validate(['anno' => ['required', 'integer']], ['anno.required' => 'Scegli l\'anno.'])['anno'];
        if (! TassoLegaleAMano::togli($anno)) {
            throw ValidationException::withMessages(['anno' => 'Il ' . $anno . ' non ha un tasso legale scritto a mano.']);
        }

        return back()->with($this->flashSuccess('Tasso legale del ' . $anno . ' tolto. Gli interessi già registrati restano come sono.'));
    }

    /** Chi gestisce le impostazioni generali: scrive il tasso legale di un anno per tutti i condomini. */
    private function gestisceLeImpostazioni(Request $request): bool
    {
        return $request->user()?->can(Permission::MANAGE_GENERAL_SETTINGS->value) === true;
    }

    /** La regola dell'indirizzo, se è di questo condominio; altrimenti 404. */
    private function assicuraRegola(Condominio $condominio, string|int $id): RegolaRecupero
    {
        abort_unless(is_numeric($id), 404);
        $regola = RegolaRecupero::find((int) $id);
        abort_unless($regola !== null && (int) $regola->condominio_id === (int) $condominio->id, 404);

        return $regola;
    }

    /**
     * Il giorno degli interessi registrati più recenti che hanno usato la regola: un loro tratto ha il tasso di questa regola, o la regola ha spostato
     * il loro primo giorno con i giorni di decorrenza. Null se nessuno: una regola scritta dopo, con il giorno nel passato, non è usata dagli interessi
     * registrati prima con il tasso di prima.
     */
    private function usataDa(Condominio $condominio, ?RegolaRecupero $regola): ?string
    {
        if ($regola === null) {
            return null;
        }
        $ultima = null;
        foreach ($this->interessiRegistrati($condominio) as $d) {
            foreach ($d['interessi']['righe'] ?? [] as $r) {
                $usata = (int) ($r['regola_inizio'] ?? 0) === (int) $regola->id
                    || collect($r['tratti'] ?? [])->contains(fn ($t) => (int) ($t['regola_id'] ?? 0) === (int) $regola->id);
                if ($usata) {
                    $ultima = max($ultima ?? '', (string) $d['interessi']['alla']);
                }
            }
        }

        return $ultima;
    }

    /** L'ultimo giorno di interessi registrati dal giorno dato in poi, in qualunque tratto; null se nessuno. */
    private function giorniRegistrati(Condominio $condominio, string $dal): ?string
    {
        $ultimo = null;
        foreach ($this->interessiRegistrati($condominio) as $d) {
            foreach ($d['interessi']['righe'] ?? [] as $r) {
                foreach ($r['tratti'] ?? [] as $t) {
                    if ($t['al'] >= $dal) {
                        $ultimo = max($ultimo ?? '', (string) $t['al']);
                    }
                }
            }
        }

        return $ultimo;
    }

    /** @return list<array<string, mixed>> i dati delle quote degli interessi registrati del condominio */
    private function interessiRegistrati(Condominio $condominio): array
    {
        return RataQuote::where('tipo', RataQuote::TIPO_ACCESSORIO)
            ->whereIn('rata_id', DB::table('rate')->whereIn('piano_rate_id', PianoRate::degliAddebiti()->where('condominio_id', $condominio->id)->select('id'))->select('id'))
            ->get(['id', 'regole_calcolo'])->map(fn (RataQuote $q) => AddebitiIndividuali::dati($q))->filter(fn ($d) => isset($d['interessi']['alla']))->values()->all();
    }

    /** Il tasso come lo si riscrive per correggerlo: 22.500 → «2,25», 30.000 → «3». */
    private static function tassoDaScrivere(int $tassoE4): string
    {
        $decimali = rtrim(str_pad((string) ($tassoE4 % 10000), 4, '0', STR_PAD_LEFT), '0');

        return intdiv($tassoE4, 10000) . ($decimali === '' ? '' : ',' . $decimali);
    }

    /** «3», «2,5», «3.125» → «3.0000», «2.5000», «3.1250»; null se non è un tasso fra zero (escluso) e 100. */
    public static function tasso(string $testo): ?string
    {
        $testo = trim(str_replace([' ', "\u{00A0}", '%'], '', $testo));
        if (! preg_match('/^(\d{1,3})(?:[.,](\d{1,4}))?$/', $testo, $m)) {
            return null;
        }
        $e4 = (int) $m[1] * 10000 + (int) str_pad($m[2] ?? '', 4, '0');
        if ($e4 <= 0 || $e4 > 1_000_000) {
            return null;
        }

        return intdiv($e4, 10000) . '.' . str_pad((string) ($e4 % 10000), 4, '0', STR_PAD_LEFT);
    }
}
