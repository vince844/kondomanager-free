<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Enums\Assemblee\StatoDelega;
use App\Models\Anagrafica;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\CollegamentoAllaDelibera;
use App\Models\Assemblee\ConvocatoDellAssemblea;
use App\Models\Assemblee\DelegaDellAssemblea;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\DichiarazioneAVerbale;
use App\Models\Assemblee\PresenzaInSala;
use App\Models\Gestionale\AccordoRientro;
use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use App\Services\Assemblee\Verbale\DestinatariDelVerbale;
use App\Services\Assemblee\Verbale\VerbaliDellAssemblea;
use Illuminate\Support\Carbon;

/**
 * «Le sue assemblee» nella scheda della persona (decisione 622 (5), la scena 6 della bozza approvata con la decisione 623): **tutto ciò che la riguarda**, per ogni
 * assemblea in cui è convocata:
 *
 * - la convocazione: il mezzo e com'è andata la consegna («Per PEC · Consegnata il 05/12/2026»);
 * - in sala: di persona, in collegamento, per delega a chi, presente senza voto (entrata «insieme» a chi rappresenta l'unità), o assente;
 * - i voti, dal verbale registrato: i punti a favore in una riga, i contrari e le astensioni con la dichiarazione a verbale, se c'è;
 * - «Può impugnare fino al …» dove ha votato contro, si è astenuta o era assente (dalla trasmissione del verbale); dove ha votato a favore no;
 * - le delibere sulle sue cose: il suo accordo di rientro autorizzato.
 *
 * **Solo lo studio** (le delibere nel portale dei condòmini sono del CRM, decisione 586), con «Vede le assemblee». La Coda 669 ridisegnerà la scheda.
 * Si legge e basta.
 */
final class AssembleeDellaPersona
{
    /** @return ?list<array<string, mixed>> null senza «Vede le assemblee» */
    public static function perLaScheda(Anagrafica $persona, ?User $utente): ?array
    {
        if (! AccessoAlleAssemblee::vede($utente)) {
            return null;
        }
        $convocato = ConvocatoDellAssemblea::query()->where('anagrafica_id', $persona->id)->get()->keyBy(fn ($c) => (int) $c->assemblea_id);
        $assemblee = Assemblea::query()->with('condominio:id,nome')->whereIn('id', $convocato->keys())->orderByDesc('data')->orderByDesc('id')->get();

        return $assemblee->map(fn (Assemblea $a) => self::inUnAssemblea($a, $persona, $convocato->get((int) $a->id)))->values()->all();
    }

    /**
     * Il riepilogo di una riga per la scheda «Dettagli» e il numero della linguetta: quante assemblee, in quanti condomini, il giorno dell'ultima. Una
     * lettura sola, senza il giro dei verbali. Null senza «Vede le assemblee».
     *
     * @return ?array{totale: int, condomini: int, ultima: ?string}
     */
    public static function riepilogo(Anagrafica $persona, ?User $utente): ?array
    {
        if (! AccessoAlleAssemblee::vede($utente)) {
            return null;
        }
        $assemblee = Assemblea::query()->whereIn('id', ConvocatoDellAssemblea::query()->where('anagrafica_id', $persona->id)->select('assemblea_id'))->get(['id', 'condominio_id', 'data']);
        $ultima = $assemblee->max(fn (Assemblea $a) => $a->data?->format('Y-m-d'));

        return ['totale' => $assemblee->count(), 'condomini' => $assemblee->pluck('condominio_id')->unique()->count(),
            'ultima' => $ultima === null ? null : Carbon::parse($ultima)->format('d/m/Y')];
    }

    /**
     * La linguetta «Assemblee» (decisione 651): le card in testa contano **tutte** le assemblee della persona, l'elenco è filtrato per condominio e per
     * anno e a pagine. Il giro per assemblea è quello di `perLaScheda`, niente dati in più. Null senza «Vede le assemblee».
     *
     * @param  array{condominio?: ?int, anno?: ?int}  $filtri
     * @return ?array{righe: list<array<string, mixed>>, meta: array<string, int>, riepilogo: array<string, mixed>, condomini: list<array{id: int, nome: string}>, anni: list<int>}
     */
    public static function perLaLinguetta(Anagrafica $persona, ?User $utente, array $filtri, int $pagina, int $perPagina): ?array
    {
        $tutte = collect(self::perLaScheda($persona, $utente));
        if (! AccessoAlleAssemblee::vede($utente)) {
            return null;
        }
        $perPagina = max(1, $perPagina);
        $filtrate = $tutte
            ->when(($filtri['condominio'] ?? null) !== null, fn ($c) => $c->where('condominio_id', (int) $filtri['condominio']))
            ->when(($filtri['anno'] ?? null) !== null, fn ($c) => $c->where('anno', (int) $filtri['anno']))->values();
        $ultima = max(1, (int) ceil($filtrate->count() / $perPagina));
        $pagina = min(max(1, $pagina), $ultima);
        $oggi = Carbon::today();
        $aperti = $tutte->filter(fn ($r) => $r['puo_impugnare_fino_al'] !== null
            ? Carbon::createFromFormat('d/m/Y', $r['puo_impugnare_fino_al'])->startOfDay()->gte($oggi) : $r['impugnare_dopo_la_consegna']);
        $scadenze = $aperti->pluck('puo_impugnare_fino_al')->filter()->map(fn ($g) => Carbon::createFromFormat('d/m/Y', $g)->startOfDay())->sort()->values();

        return [
            'righe' => $filtrate->slice(($pagina - 1) * $perPagina, $perPagina)->values()->all(),
            'meta' => ['current_page' => $pagina, 'last_page' => $ultima, 'per_page' => $perPagina, 'total' => $filtrate->count()],
            'riepilogo' => [
                'totale' => $tutte->count(),
                'condomini' => $tutte->pluck('condominio_id')->unique()->count(),
                'ultima' => $tutte->first()['data'] ?? null,
                'puo_impugnare' => ['quante' => $aperti->count(), 'fino_al' => $scadenze->first()?->format('d/m/Y')],
            ],
            'condomini' => $tutte->unique('condominio_id')->map(fn ($r) => ['id' => $r['condominio_id'], 'nome' => $r['condominio']])->sortBy('nome')->values()->all(),
            'anni' => $tutte->pluck('anno')->unique()->sortDesc()->values()->all(),
        ];
    }

    /**
     * «Per lei», il badge della riga: «Può impugnare» (ambra) finché il termine corre, poi «Presente» (verde), «Per delega» o «Assente» (grigio); niente se
     * l'assemblea non si è ancora tenuta.
     *
     * @return ?array{parola: string, tono: string}
     */
    private static function perLei(?string $inSala, ?string $fino, bool $dopo): ?array
    {
        if (($fino !== null && Carbon::createFromFormat('d/m/Y', $fino)->startOfDay()->gte(Carbon::today())) || ($fino === null && $dopo)) {
            return ['parola' => 'Può impugnare', 'tono' => 'ambra'];
        }

        return match (true) {
            $inSala === null => null,
            str_starts_with($inSala, 'Per delega') => ['parola' => 'Per delega', 'tono' => 'grigio'],
            $inSala === 'Assente' => ['parola' => 'Assente', 'tono' => 'grigio'],
            default => ['parola' => 'Presente', 'tono' => 'verde'],
        };
    }

    /**
     * Una persona in un'assemblea. I voti si leggono **per votazione** (Coda 750 B2): il rendiconto e il riparto votati a parte sono due righe quando il voto
     * è diverso («Punto 1, il rendiconto · Contrario», e il riparto fra i favorevoli: «Favorevole ai punti 2 e 3 e al riparto del punto 1»); con lo stesso voto
     * il punto resta una riga sola.
     *
     * @return array<string, mixed>
     */
    private static function inUnAssemblea(Assemblea $a, Anagrafica $persona, ?ConvocatoDellAssemblea $convocato): array
    {
        $giorno = fn ($d) => $d === null ? null : Carbon::parse($d)->format('d/m/Y');
        $verbali = [];
        /** @var array<int, array{punto: int, oggetto: ?string, voto: string}> $voti votazione => il voto */
        $voti = [];
        $punti = [];
        $termini = [];
        $senzaData = false;
        foreach ($a->sedute()->orderBy('id')->get() as $seduta) {
            $verbale = VerbaliDellAssemblea::registratoDellaSeduta((int) $seduta->id);
            if ($verbale === null) {
                continue;
            }
            $verbali[] = (string) $verbale->numero;
            $d = app(DestinatariDelVerbale::class)->per($verbale);
            foreach ($d['punti'] as $p) {
                $punti[(int) $p['numero']] = ['id' => (int) $p['punto_id'], 'testo' => (string) $p['testo']];
            }
            $suo = collect($d['persone'])->firstWhere('anagrafica_id', (int) $persona->id);
            if ($suo === null) {
                continue;
            }
            // Le votazioni per cui riceve il verbale come assente (o per cui è stata tolta dalla lista): «Assente», anche se aggiunta a mano fra chi c'era.
            $assente = collect((array) $suo['termini'])->whereIn('perche', ['assente', 'tolto'])->flatMap(fn ($t) => (array) ($t['voci'] ?? []))
                ->keyBy(fn ($x) => (int) $x['votazione_id']);
            foreach ((array) ($suo['voti_per_votazione'] ?? []) as $x) {
                $voti[(int) $x['votazione_id']] = ['punto' => (int) $x['numero'], 'oggetto' => $x['oggetto'] ?? null,
                    'voto' => $assente->has((int) $x['votazione_id']) || $x['voto'] === null ? 'assente' : (string) $x['voto']];
            }
            foreach ($assente as $id => $x) {
                $voti[(int) $id] = ['punto' => (int) $x['numero'], 'oggetto' => $x['oggetto'] ?? null, 'voto' => 'assente'];
            }
            foreach ((array) $suo['termini'] as $t) {
                if (($t['fino_al'] ?? null) !== null) {
                    $termini[] = (string) $t['fino_al'];
                } else {
                    $senzaData = true;
                }
            }
        }
        uasort($voti, fn ($x, $y) => [$x['punto'], $x['oggetto'] === 'riparto' ? 1 : 0] <=> [$y['punto'], $y['oggetto'] === 'riparto' ? 1 : 0]);
        $dichiarazioni = DichiarazioneAVerbale::query()->where('anagrafica_id', $persona->id)
            ->whereIn('punto_id', array_column($punti, 'id'))->orderBy('id')->get()->groupBy(fn ($x) => (int) $x->punto_id);
        $favorevoli = [];
        $favorevoliInParte = [];
        $altri = [];
        foreach (collect($voti)->groupBy('punto') as $numero => $righe) {
            $numero = (int) $numero;
            $punto = $punti[$numero] ?? null;
            // Lo stesso voto in tutte e due le votazioni del punto: una riga sola, per il punto intero.
            $righe = $righe->pluck('voto')->unique()->count() === 1 ? collect([['punto' => $numero, 'oggetto' => null, 'voto' => $righe->first()['voto']]]) : $righe->values();
            $conLaDichiarazione = false;
            foreach ($righe as $r) {
                if ($r['voto'] === 'favorevole') {
                    if ($r['oggetto'] === null) {
                        $favorevoli[] = $numero;
                    } else {
                        $favorevoliInParte[] = ['punto' => $numero, 'oggetto' => $r['oggetto']];
                    }
                    continue;
                }
                $altri[] = ['punto' => $numero, 'oggetto' => $r['oggetto'], 'voto' => match ($r['voto']) {
                    'contrario' => 'Contrario', 'astenuto' => 'Astenuto', 'assente' => 'Assente', 'non_votato' => 'Non ha votato', default => ucfirst($r['voto']),
                }, 'testo' => $punto['testo'] ?? null,
                    // La dichiarazione è del punto: una volta, sulla prima riga.
                    'dichiarazioni' => $punto === null || $conLaDichiarazione ? [] : ($dichiarazioni->get($punto['id']) ?? collect())->pluck('testo')->map(fn ($t) => (string) $t)->values()->all()];
                $conLaDichiarazione = true;
            }
        }
        $delega = DelegaDellAssemblea::query()->where('assemblea_id', $a->id)->where('delegante_anagrafica_id', $persona->id)
            ->where('stato', StatoDelega::VALIDA->value)->orderByDesc('id')->first();
        $presenza = PresenzaInSala::query()->whereIn('seduta_id', $a->sedute()->select('id'))->where('anagrafica_id', $persona->id)->orderBy('entrata_il')->first();
        // Il comproprietario entrato «insieme» a chi rappresenta l'unità non ha una riga sua: c'era, senza voto (Coda 750 B3).
        $insieme = $presenza !== null ? null : PresenzaInSala::query()->whereIn('seduta_id', $a->sedute()->select('id'))->whereNotNull('insieme')->orderBy('entrata_il')
            ->get()->first(fn (PresenzaInSala $p) => in_array((int) $persona->id, array_map(fn ($x) => (int) ($x['anagrafica_id'] ?? 0), (array) $p->insieme), true));
        $tenuta = $a->sedute()->whereNotNull('aperta_il')->exists();
        $invio = $convocato?->invio_id === null ? null : RegistroInvio::query()->find($convocato->invio_id);

        $riga = [
            'id' => (int) $a->id,
            'condominio_id' => (int) $a->condominio_id,
            'nome' => $a->nome(),
            'condominio' => (string) ($a->condominio?->nome ?? ''),
            'data' => $a->data?->format('d/m/Y'),
            'anno' => $a->data === null ? null : (int) $a->data->format('Y'),
            'verbale' => $verbali === [] ? null : implode(' e ', $verbali),
            'convocazione' => self::convocazione($convocato, $invio),
            'in_sala' => match (true) {
                $delega !== null => 'Per delega a ' . $delega->delegato_nome,
                $presenza !== null => ($presenza->come?->parola() ?? 'In sala') === 'In sala' ? 'Presente di persona' : (string) $presenza->come?->parola(),
                $insieme !== null => (($insieme->come?->parola() ?? 'In sala') === 'In sala' ? 'Presente' : (string) $insieme->come?->parola()) . ', senza voto',
                $tenuta => 'Assente',
                default => null,
            },
            'per_delega_da' => $delega?->delegato_nome,
            'favorevole_ai_punti' => $favorevoli,
            'favorevole_alle_parti' => $favorevoliInParte,
            'altri_voti' => $altri,
            'puo_impugnare_fino_al' => $termini === [] ? null : $giorno(max($termini)),
            'impugnare_dopo_la_consegna' => $senzaData,
            'sulle_sue_cose' => self::sulleSueCose($a, $persona),
        ];
        $riga['per_lei'] = self::perLei($riga['in_sala'], $riga['puo_impugnare_fino_al'], $riga['impugnare_dopo_la_consegna']);

        return $riga;
    }

    /** «Per PEC · Consegnata il 05/12/2026», o il mezzo soltanto se l'avviso non è ancora partito. */
    private static function convocazione(?ConvocatoDellAssemblea $convocato, ?RegistroInvio $invio): ?string
    {
        if ($convocato === null) {
            return null;
        }
        $mezzo = match ($convocato->mezzo) {
            \App\Enums\Assemblee\MezzoConvocazione::PEC => 'Per PEC',
            \App\Enums\Assemblee\MezzoConvocazione::RACCOMANDATA => 'Per raccomandata',
            \App\Enums\Assemblee\MezzoConvocazione::A_MANO => 'A mano',
            \App\Enums\Assemblee\MezzoConvocazione::FAX => 'Per fax',
            default => null,
        };
        $esito = $invio?->esito === null ? null : \App\Enums\Assemblee\EsitoInvio::parolaDi($invio->esito);
        $quando = $invio?->ricevuta_il ?? $invio?->esito_il;
        $consegna = $esito === null ? ($invio !== null ? 'Spedita' : 'Non ancora spedita')
            : $esito . ($quando !== null ? ' il ' . Carbon::parse($quando)->format('d/m/Y') : '');

        return trim(($mezzo ?? '') . ($mezzo !== null ? ' · ' : '') . $consegna);
    }

    /** Le delibere dell'assemblea sulle sue cose: oggi il suo accordo di rientro. @return list<array{frase: string, url: ?string}> */
    private static function sulleSueCose(Assemblea $a, Anagrafica $persona): array
    {
        $accordi = AccordoRientro::query()->where('condominio_id', $a->condominio_id)->where('anagrafica_id', $persona->id)->pluck('id');
        if ($accordi->isEmpty()) {
            return [];
        }
        $collegamenti = CollegamentoAllaDelibera::query()->where('oggetto_tipo', 'accordo_rientro')->whereIn('oggetto_id', $accordi)
            ->whereIn('delibera_id', Delibera::query()->where('assemblea_id', $a->id)->select('id'))->get();

        return $collegamenti->map(function (CollegamentoAllaDelibera $c) {
            $d = Delibera::query()->with('punto')->find($c->delibera_id);

            return ['frase' => 'Punto ' . ($d?->punto?->numero ?? '—') . ', il suo piano di rientro, ' . mb_strtolower((string) $d?->esito?->parola()),
                'url' => null];
        })->values()->all();
    }
}
