@extends('pdf.base')

@section('title', 'Prospetto degli oneri accessori – ' . $prospetto['unita']['etichetta'])

@section('content')
@php
    $euro = fn (int $c) => '€ ' . number_format($c / 100, 2, ',', '.');
    $data = fn (?string $iso) => $iso ? \Carbon\Carbon::parse($iso)->format('d/m/Y') : '—';
    $numero = fn (float $n) => rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
    $piuPiani = count($prospetto['piani']) > 1;
    $tratti = fn (?array $lista) => $lista ? implode(' + ', array_map(fn ($t) => $data($t['dal']) . '–' . $data($t['al']), $lista)) : '—';
    // 1.11.0-beta.50 (decisioni 32, 77 e 79): accanto ai giorni dopo l'uscita la fine della locazione e la sua scelta — «Non scriverlo»,
    // o nessun conguaglio quando la scelta non c'è (le fini di prima della .50) —, e chi li ha pagati.
    $allaFine = fn (array $v) => 'fine della locazione del ' . $data($v['uscita_del'] ?? null) . match ($v['scelta'] ?? null) {
        'non_scritto' => ', conguaglio non scritto',
        // Fase 1-bis della .50 (R2): con «Scrivi», i giorni in cui nessuno stava al posto dell'inquilino.
        'scritto' => ', nessuno al posto dell\'inquilino',
        default => ', nessun conguaglio',
    };
    // Coda 245 (1.11.0-beta.55): la parte con la regola del piano, e accanto ciò che ne è nelle rate; un credito si dice a parole, senza «€ -».
    $nelleRate = fn (array $v) => (int) ($v['nelle_rate'] ?? 0) === 0 ? '' : ((int) $v['nelle_rate'] < 0 ? '; a credito nelle sue rate ' : '; nelle sue rate ') . $euro(abs((int) $v['nelle_rate']));
    $come = fn (array $v) => match (true) {
        $v['modo'] === 'piano' && ! empty($v['chi']) => 'con la regola del piano, a chi sta al posto dell\'inquilino (' . $v['chi'] . ')' . $nelleRate($v),
        $v['modo'] === 'piano' => 'con la regola del piano' . $nelleRate($v),
        $v['modo'] === 'rate' => 'nelle sue rate',
        $v['modo'] === 'conguaglio' => 'con il conguaglio del cambio d\'inquilino del ' . $data($v['conguaglio_del'] ?? null),
        // Decisione 83.3 (1.11.0-beta.50): il cambio d'inquilino che le parti hanno regolato fra loro, seguito da una fine scritta.
        $v['modo'] === 'regolato' => 'regolata fra le parti al cambio d\'inquilino del ' . $data($v['conguaglio_del'] ?? null),
        $v['modo'] === 'restituzione' => 'da restituire a ' . ($v['a_chi'] ?? 'chi è uscito') . ' (' . $allaFine($v) . ')',
        $v['modo'] === 'da_restituire' => 'pagata da ' . $v['pagato_da'] . ', rimasta a suo carico (' . $allaFine($v) . '): si restituisce a chi l\'ha pagata, fra le parti',
        // Coda 364 (1.11.0-beta.55): i giorni di un conduttore entrato dopo un cambio d'inquilino, che chi è entrato con il cambio ha pagato con il conguaglio.
        $v['modo'] === 'entrato_dopo' => ((int) $v['importo'] < 0 ? 'da ricevere da ' : 'da restituire a ') . $v['da_chi'] . ': la parte di ' . $v['da_chi'] . ' dei giorni presi con il conguaglio del cambio d\'inquilino del ' . $data($v['conguaglio_del'] ?? null),
        $v['modo'] === 'proprietario' && ! empty($v['conguaglio_del']) => 'pagata da ' . $v['pagato_da'] . ' con il conguaglio del cambio d\'inquilino del ' . $data($v['conguaglio_del']),
        ($v['strada'] ?? null) === 'A' => 'pagata da ' . $v['pagato_da'] . ', rimasta a suo carico (' . $allaFine($v) . ')',
        ($v['strada'] ?? null) === 'B' => 'pagata da ' . $v['pagato_da'] . ' con il conguaglio della fine locazione del ' . $data($v['uscita_del'] ?? null) . (! empty($v['oppure']) ? ', o da ' . $v['oppure'] : ''),
        default => 'pagata da ' . $v['pagato_da'],
    };
@endphp

{{-- Il documento del proprietario per il conduttore: B3a, 1.11.0-beta.34 (ProspettoOneriAccessori). --}}
<div style="margin-bottom: 10px; border-bottom: 2px solid #1e3a5f; padding-bottom: 10px; padding-top: 5px;">
    <h2 style="margin: 0; padding: 0; font-size: 13pt; color: #1e3a5f; letter-spacing: 0.5px;">
        PROSPETTO DEGLI ONERI ACCESSORI — {{ mb_strtoupper($prospetto['unita']['etichetta']) }}
    </h2>
    <div style="font-size: 8.5pt; color: #444; margin-top: 3px;">
        Esercizio: <strong>{{ $esercizio->nome }}</strong>
        &nbsp;|&nbsp;
        (dal {{ $data($prospetto['esercizio']['dal']) }} al {{ $data($prospetto['esercizio']['al']) }})
        &nbsp;|&nbsp;
        Stampato il <strong>{{ now()->format('d/m/Y') }}</strong>
    </div>
</div>

<div style="background: #f5f8fc; border: 1px solid #d1dbe8; padding: 6px 9px; margin-bottom: 12px; font-size: 8pt; color: #333;">
    @foreach($prospetto['intestazione'] as $frase)
        <p style="margin: 0 0 4px 0;">{{ $frase }}</p>
    @endforeach
</div>

@forelse($prospetto['conduttori'] as $c)
    <div style="margin-top: 10px; page-break-inside: avoid;">
        <div style="font-size: 7pt; font-weight: bold; text-transform: uppercase; color: #888; letter-spacing: 0.5px;">{{ $c['ruolo'] }}</div>
        <div style="font-size: 11pt; font-weight: bold; color: #2a8050;">{{ $c['nome'] }}</div>
        <div style="font-size: 8pt; color: #444; margin-bottom: 4px;">
            @if(! empty($c['tratti']))
                {{ $c['ruolo'] === 'Comodatario' ? 'In comodato' : 'Conduttore' }} nell'esercizio: {{ $tratti($c['tratti']) }}.
            @endif
        </div>
        <div style="font-size: 7.5pt; color: #555; font-style: italic; margin-bottom: 6px;">{{ $c['nota_contratto'] }}</div>

        <table style="width: 100%; border-collapse: collapse; font-size: 7.8pt;">
            <thead>
                <tr style="background: #1e3a5f; color: #fff;">
                    <th style="padding: 4px 5px; text-align: left; color: #ffffff; font-weight: bold;">Voce</th>
                    <th style="padding: 4px 5px; text-align: left; color: #ffffff; font-weight: bold;">Tabella e quota dell'unità</th>
                    <th style="padding: 4px 5px; text-align: right; color: #ffffff; font-weight: bold;">Quota inquilino</th>
                    <th style="padding: 4px 5px; text-align: left; color: #ffffff; font-weight: bold;">Competenza</th>
                    <th style="padding: 4px 5px; text-align: right; color: #ffffff; font-weight: bold;">Giorni</th>
                    <th style="padding: 4px 5px; text-align: right; color: #ffffff; font-weight: bold;">Importo</th>
                    <th style="padding: 4px 5px; text-align: left; color: #ffffff; font-weight: bold;">Come</th>
                </tr>
            </thead>
            <tbody>
                @foreach($c['voci'] as $v)
                    <tr style="border-bottom: 1px solid #e3e8ef;">
                        <td style="padding: 3px 5px;">{{ $v['conto'] }}@if($piuPiani)<br><span style="color: #888; font-size: 6.8pt;">{{ $v['piano'] }}</span>@endif @if(! empty($v['nota']))<br><span style="color: #888; font-size: 6.8pt;">{{ $v['nota'] }}</span>@endif</td>
                        <td style="padding: 3px 5px;">
                            @if($v['millesimi'])
                                {{ $v['tabella'] }}<br><span style="color: #888; font-size: 6.8pt;">{{ $numero($v['millesimi']['valore']) }} su {{ $numero($v['millesimi']['somma']) }} {{ $v['millesimi']['unita'] }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td style="padding: 3px 5px; text-align: right;">{{ $v['quota_inquilino'] !== null ? $v['quota_inquilino'] . ' %' : '—' }}</td>
                        <td style="padding: 3px 5px;">{{ $tratti($v['competenza']) }}</td>
                        <td style="padding: 3px 5px; text-align: right;">{{ $v['giorni'] ?? '—' }}</td>
                        <td style="padding: 3px 5px; text-align: right; white-space: nowrap;">{{ $euro($v['importo']) }}</td>
                        <td style="padding: 3px 5px; font-size: 7pt;">{{ $come($v) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                {{-- 1.11.0-beta.51 (Coda 252.2): un totale negativo (un'entrata che nei giorni del conduttore supera la spesa) è a suo favore, e si dice con le
                     parole: «€ -209,26» è un segno meno che nessun conduttore sa leggere. La colonna «Importo» delle righe tiene il segno. --}}
                <tr style="background: #f5f8fc; font-weight: bold;">
                    <td colspan="5" style="padding: 4px 5px;">{{ $c['totale'] < 0 ? 'Totale a favore di ' : 'Totale a carico di ' }}{{ $c['nome'] }}</td>
                    <td style="padding: 4px 5px; text-align: right; white-space: nowrap;">{{ $euro(abs($c['totale'])) }}</td>
                    <td></td>
                </tr>
                {{-- Fase 1-bis della .47: la parte pagata con il conguaglio non è una rata, e ha la sua riga. Le righe «di cui» compaiono
                     quando le parti sono almeno due; quella da rimborsare, come prima, anche da sola. 1.11.0-beta.50: a chi è uscito con
                     i giorni dopo l'uscita rimasti nelle sue rate si deve, e ha pagato più del suo totale: le righe non sono «di cui»,
                     dicono quanto ha pagato e quanto gli si restituisce. --}}
                {{-- Coda 252.2: «da restituire a …: i giorni dopo la sua uscita» si dice solo di chi è uscito (`e_uscito`). Un conduttore ancora lì a cui
                     chi ha pagato i suoi giorni deve dei soldi (la parte da rimborsare negativa) li riceve: è a suo favore. --}}
                @if(! empty($c['regola_del_piano']))
                    {{-- Coda 245 (1.11.0-beta.55): sull'anello della regola del piano il piede dice ciò che il conduttore ha nelle sue rate, i conguagli dei passaggi e
                         chi rimborsa chi, con il nome e il passaggio che l'ha lasciato. Nessuna cifra con «€ -»: il verso è nelle parole. --}}
                    @php($rp = $c['regola_del_piano'])
                    @php($dettaglio = [])
                    @if($c['nelle_sue_rate'] !== 0)
                        @php($dettaglio[] = [$c['nelle_sue_rate'] < 0 ? 'a credito nelle sue rate' : 'nelle sue rate', abs($c['nelle_sue_rate'])])
                    @endif
                    @foreach($rp['conguagli'] as $x)
                        @php($dettaglio[] = ['con il conguaglio ' . (($x['tipo'] ?? 'fine') === 'cambio' ? 'del cambio d\'inquilino del ' : 'della fine locazione del ') . $data($x['del']) . ((int) $x['importo'] < 0 ? ', a suo credito' : ', a suo debito'), abs((int) $x['importo'])])
                    @endforeach
                    @foreach($rp['regolati'] as $x)
                        @php($dettaglio[] = ['regolata fra le parti alla fine della locazione del ' . $data($x['del']) . ((int) $x['importo'] < 0 ? ', a suo credito' : ', a suo debito'), abs((int) $x['importo'])])
                    @endforeach
                    @php($diChi = 0)
                    @foreach($rp['rimborsi'] as $x)
                        @php($diChi += $x['verso'] === 'a' ? (int) $x['importo'] : -(int) $x['importo'])
                        @php($dettaglio[] = [$x['verso'] === 'a'
                            ? 'da rimborsare a ' . $x['nome'] . (! empty($x['oppure']) ? ' (o a ' . $x['oppure'] . ')' : '') . ': ' . $x['perche']
                            : 'da restituire a ' . $c['nome'] . ($x['nome'] !== null ? ' da ' . $x['nome'] . (! empty($x['oppure']) ? ' (o da ' . $x['oppure'] . ')' : '') : ' fra le parti') . ': ' . $x['perche'], (int) $x['importo']])
                    @endforeach
                    @if($c['da_rimborsare'] - $diChi !== 0)
                        @php($dettaglio[] = [$c['da_rimborsare'] - $diChi < 0 ? 'a favore di ' . $c['nome'] . ': da ricevere da chi ha pagato i suoi giorni' : 'da rimborsare a chi le ha pagate', abs($c['da_rimborsare'] - $diChi)])
                    @endif
                    @foreach($dettaglio as [$etichetta, $cifra])
                        <tr>
                            <td colspan="5" style="padding: 2px 5px; font-size: 7.5pt;">{{ $etichetta }}</td>
                            <td style="padding: 2px 5px; text-align: right; font-size: 7.5pt; white-space: nowrap;">{{ $euro($cifra) }}</td>
                            <td></td>
                        </tr>
                    @endforeach
                @else
                {{-- Coda 364 (1.11.0-beta.55): di chi è entrato con il cambio d'inquilino, la parte dei conduttori entrati dopo nei giorni del suo conguaglio ha la sua
                     riga per persona, e le righe non sono «di cui»: con il conguaglio ha pagato più della sua parte, o meno. --}}
                @php($entratiDopo = $c['entrati_dopo'] ?? [])
                @php($daRimborsare = $c['da_rimborsare'] - (int) array_sum($entratiDopo))
                @php($eUscito = (bool) ($c['e_uscito'] ?? false))
                @php($restituire = $eUscito && $daRimborsare < 0)
                @php($ricevera = ! $eUscito && $daRimborsare < 0)
                @php($diCui = ! $restituire && $entratiDopo === [])
                @php($parti = count(array_filter([$c['nelle_sue_rate'], $c['con_il_conguaglio'] ?? 0, $c['regolato_fra_le_parti'] ?? 0, $daRimborsare, ...array_values($entratiDopo)], fn ($x) => $x !== 0)))
                @if($parti > 1 && $c['nelle_sue_rate'] !== 0)
                    <tr>
                        <td colspan="5" style="padding: 2px 5px; font-size: 7.5pt;">{{ $diCui ? 'di cui già nelle sue rate' : 'nelle sue rate' }}</td>
                        <td style="padding: 2px 5px; text-align: right; font-size: 7.5pt; white-space: nowrap;">{{ $euro($c['nelle_sue_rate']) }}</td>
                        <td></td>
                    </tr>
                @endif
                @if($parti > 1 && ($c['con_il_conguaglio'] ?? 0) !== 0)
                    <tr>
                        <td colspan="5" style="padding: 2px 5px; font-size: 7.5pt;">{{ $diCui ? 'di cui con il conguaglio del cambio d\'inquilino' : 'con il conguaglio del cambio d\'inquilino' }}</td>
                        <td style="padding: 2px 5px; text-align: right; font-size: 7.5pt; white-space: nowrap;">{{ $euro($c['con_il_conguaglio']) }}</td>
                        <td></td>
                    </tr>
                @endif
                @if($parti > 1 && ($c['regolato_fra_le_parti'] ?? 0) !== 0)
                    <tr>
                        <td colspan="5" style="padding: 2px 5px; font-size: 7.5pt;">{{ $diCui ? 'di cui regolata fra le parti al cambio d\'inquilino' : 'regolata fra le parti al cambio d\'inquilino' }}</td>
                        <td style="padding: 2px 5px; text-align: right; font-size: 7.5pt; white-space: nowrap;">{{ $euro($c['regolato_fra_le_parti']) }}</td>
                        <td></td>
                    </tr>
                @endif
                @if($daRimborsare !== 0)
                    <tr>
                        <td colspan="5" style="padding: 2px 5px; font-size: 7.5pt;">{{ match (true) {
                            $restituire => 'da restituire a ' . $c['nome'] . ': i giorni dopo la sua uscita, che ha pagato',
                            $ricevera => 'a favore di ' . $c['nome'] . ': da ricevere da chi ha pagato i suoi giorni',
                            default => 'di cui da rimborsare a chi le ha pagate',
                        } }}</td>
                        <td style="padding: 2px 5px; text-align: right; font-size: 7.5pt; white-space: nowrap;">{{ $euro(abs($daRimborsare)) }}</td>
                        <td></td>
                    </tr>
                @endif
                @foreach($entratiDopo as $nome => $cifra)
                    <tr>
                        <td colspan="5" style="padding: 2px 5px; font-size: 7.5pt;">{{ $cifra < 0
                            ? 'a favore di ' . $c['nome'] . ': da ricevere da ' . $nome . ', la parte di ' . $nome . ' dei giorni che ha pagato con il conguaglio'
                            : 'da restituire da ' . $c['nome'] . ' a ' . $nome . ': la parte di ' . $nome . ' dei giorni presi con il conguaglio, in cui l\'entrata supera la spesa' }}</td>
                        <td style="padding: 2px 5px; text-align: right; font-size: 7.5pt; white-space: nowrap;">{{ $euro(abs($cifra)) }}</td>
                        <td></td>
                    </tr>
                @endforeach
                @endif
            </tfoot>
        </table>
    </div>
@empty
    {{-- Coda 359 (1.11.0-beta.55, decisione 115.3): con un piano approvato rimasto fuori le sue voci a carico dell'inquilino ci sono, e non sono riportate. --}}
    @php($approvatiFuori = $prospetto['piani_approvati_fuori'] ?? [])
    @if($approvatiFuori !== [])
        <p style="font-size: 9pt; color: #555;">{{ count($approvatiFuori) === 1
            ? 'Le voci a carico dell\'inquilino del piano «' . $approvatiFuori[0] . '» non sono riportate: il piano è rimasto fuori, con il suo motivo qui sopra.'
            : 'Le voci a carico dell\'inquilino dei piani «' . implode('», «', $approvatiFuori) . '» non sono riportate: i piani sono rimasti fuori, con il loro motivo qui sopra.' }}</p>
    @else
        <p style="font-size: 9pt; color: #555;">Nessuna voce a carico dell'inquilino su questa unità nei piani rate approvati dell'esercizio.</p>
    @endif
@endforelse

{{-- Coda 245 (1.11.0-beta.55): con la regola del piano chi sta al posto dell'inquilino può avere da rimborsare o da restituire anche senza giorni senza conduttore. --}}
@if($prospetto['senza_conduttore']['totale'] !== 0 || ! empty($prospetto['senza_conduttore']['regola_del_piano']['rimborsi']) || ! empty($prospetto['senza_conduttore']['regola_del_piano']['conguagli']))
    <div style="margin-top: 14px; page-break-inside: avoid;">
        <div style="font-size: 9pt; font-weight: bold; color: #1e3a5f; margin-bottom: 3px;">Giorni senza conduttore</div>
        {{-- 1.11.0-beta.50 (strada A′): i giorni dopo l'uscita rimasti nelle rate di chi è uscito non restano a lui, gli si restituiscono. --}}
        @php($daRestituire = (int) collect($prospetto['senza_conduttore']['voci'])->where('modo', 'da_restituire')->sum('importo'))
        {{-- Coda 245 (1.11.0-beta.55): con la regola del piano la parte dei giorni senza conduttore è di chi sta al posto dell'inquilino, e ha il suo piede. --}}
        @php($delPianoSenza = (int) collect($prospetto['senza_conduttore']['voci'])->where('modo', 'piano')->sum('importo'))
        @php($restano = $prospetto['senza_conduttore']['totale'] - $daRestituire - $delPianoSenza)
        @if(isset($prospetto['senza_conduttore']['regola_del_piano']))
            <div style="font-size: 7.5pt; color: #555; margin-bottom: 5px;">Voci a carico dell'inquilino per giorni in cui nessun conduttore risulta registrato: con la regola del piano sono la parte di chi sta al posto dell'inquilino{{ $daRestituire !== 0 ? ', salvo i giorni che non copre nessuno, rimasti nelle rate di chi è uscito, che gli si restituiscono fra le parti' : '' }}.</div>
        @else
        <div style="font-size: 7.5pt; color: #555; margin-bottom: 5px;">{{ match (true) {
            $daRestituire === 0 => 'Voci a carico dell\'inquilino per giorni in cui nessun conduttore risulta registrato: restano a chi le ha pagate.',
            $restano === 0 => 'Voci a carico dell\'inquilino per giorni in cui nessun conduttore risulta registrato, dopo l\'uscita di un inquilino che le aveva nelle sue rate: si restituiscono a chi le ha pagate, fra le parti.',
            default => 'Voci a carico dell\'inquilino per giorni in cui nessun conduttore risulta registrato: restano a carico di chi le ha pagate, salvo i giorni dopo l\'uscita di un inquilino rimasti nelle sue rate, che gli si restituiscono fra le parti.',
        } }}</div>
        @endif
        <table style="width: 100%; border-collapse: collapse; font-size: 7.8pt;">
            @foreach($prospetto['senza_conduttore']['voci'] as $v)
                <tr style="border-bottom: 1px solid #e3e8ef;">
                    <td style="padding: 3px 5px;">{{ $v['conto'] }}@if($piuPiani) <span style="color: #888;">({{ $v['piano'] }})</span>@endif @if(! empty($v['nota']))<br><span style="color: #888; font-size: 6.8pt;">{{ $v['nota'] }}</span>@endif</td>
                    <td style="padding: 3px 5px; text-align: right;">{{ $v['giorni'] ?? '—' }} giorni</td>
                    <td style="padding: 3px 5px; text-align: right; white-space: nowrap;">{{ $euro($v['importo']) }}</td>
                    <td style="padding: 3px 5px; font-size: 7pt;">{{ $come($v) }}</td>
                </tr>
            @endforeach
            {{-- Coda 252.2 e 251-b (1.11.0-beta.51): nel piede nessuna cifra con «€ -»: il segno è nelle parole. Una parte negativa che resta a chi ha
                 pagato (un'entrata che supera la spesa) è a suo credito; una negativa da restituire a chi è uscito vuol dire che è lui a doverla
                 restituire. La colonna «Importo» delle righe tiene il segno: il verso lo dice la colonna «Come». --}}
            <tr style="background: #f5f8fc; font-weight: bold;">
                <td colspan="2" style="padding: 4px 5px;">{{ match (true) {
                    isset($prospetto['senza_conduttore']['regola_del_piano']) => $prospetto['senza_conduttore']['totale'] < 0 ? 'Totale a credito dei giorni senza conduttore' : 'Totale dei giorni senza conduttore',
                    $daRestituire === 0 && $restano < 0 => 'Totale a credito di chi ha pagato',
                    $daRestituire === 0 => 'Totale che resta a chi ha pagato',
                    $restano === 0 && $daRestituire < 0 => 'Totale che chi è uscito deve restituire, fra le parti',
                    $restano === 0 => 'Totale da restituire a chi le ha pagate, fra le parti',
                    $prospetto['senza_conduttore']['totale'] < 0 => 'Totale a credito dei giorni senza conduttore',
                    default => 'Totale dei giorni senza conduttore',
                } }}</td>
                <td style="padding: 4px 5px; text-align: right; white-space: nowrap;">{{ $euro(abs($prospetto['senza_conduttore']['totale'])) }}</td>
                <td></td>
            </tr>
            @if($daRestituire !== 0 && $restano !== 0)
                <tr><td colspan="2" style="padding: 2px 5px; font-size: 7.5pt;">{{ $restano < 0 ? 'di cui a credito di chi le ha pagate' : 'di cui restano a chi le ha pagate' }}</td><td style="padding: 2px 5px; text-align: right; font-size: 7.5pt; white-space: nowrap;">{{ $euro(abs($restano)) }}</td><td></td></tr>
                <tr><td colspan="2" style="padding: 2px 5px; font-size: 7.5pt;">{{ $daRestituire < 0 ? 'di cui che chi è uscito deve restituire, fra le parti' : 'di cui da restituire a chi le ha pagate, fra le parti' }}</td><td style="padding: 2px 5px; text-align: right; font-size: 7.5pt; white-space: nowrap;">{{ $euro(abs($daRestituire)) }}</td><td></td></tr>
            @endif
            @if(isset($prospetto['senza_conduttore']['regola_del_piano']))
                @php($rp = $prospetto['senza_conduttore']['regola_del_piano'])
                @foreach($rp['conguagli'] as $x)
                    <tr><td colspan="2" style="padding: 2px 5px; font-size: 7.5pt;">{{ 'con il conguaglio ' . (($x['tipo'] ?? 'fine') === 'cambio' ? 'del cambio d\'inquilino del ' : 'della fine locazione del ') . $data($x['del']) . ', ' . ((int) $x['importo'] < 0 ? 'a credito di ' : 'a debito di ') . $x['chi'] }}</td>
                        <td style="padding: 2px 5px; text-align: right; font-size: 7.5pt; white-space: nowrap;">{{ $euro(abs((int) $x['importo'])) }}</td><td></td></tr>
                @endforeach
                @foreach($rp['regolati'] as $x)
                    <tr><td colspan="2" style="padding: 2px 5px; font-size: 7.5pt;">{{ 'regolata fra le parti alla fine della locazione del ' . $data($x['del']) . ', ' . ((int) $x['importo'] < 0 ? 'a credito di ' : 'a debito di ') . $x['chi'] }}</td>
                        <td style="padding: 2px 5px; text-align: right; font-size: 7.5pt; white-space: nowrap;">{{ $euro(abs((int) $x['importo'])) }}</td><td></td></tr>
                @endforeach
                @foreach($rp['rimborsi'] as $x)
                    <tr><td colspan="2" style="padding: 2px 5px; font-size: 7.5pt;">{{ $x['verso'] === 'a'
                        ? 'da restituire a ' . $x['nome'] . ' da ' . $x['chi'] . ': ' . $x['perche']
                        : 'da rimborsare a ' . $x['chi'] . ($x['nome'] !== null ? ' da ' . $x['nome'] : ' fra le parti') . (! empty($x['oppure']) ? ' (o a ' . $x['oppure'] . ')' : '') . ': ' . $x['perche'] }}</td>
                        <td style="padding: 2px 5px; text-align: right; font-size: 7.5pt; white-space: nowrap;">{{ $euro((int) $x['importo']) }}</td><td></td></tr>
                @endforeach
            @endif
        </table>
    </div>
@endif

@if($prospetto['totale_inquilino'] !== 0)
    <p style="font-size: 7.5pt; color: #555; margin-top: 12px;">
        {{-- R22 (Fase 1-bis): il totale del ruolo inquilino si legge diviso, non come debito di un conduttore. 1.11.0-beta.50: la parte senza
             conduttore che si restituisce a chi è uscito non «resta a chi le ha pagate». 1.11.0-beta.51 (Coda 252.2 e 251-b): nessuna cifra con
             «€ -»: i conduttori che ricevono sono «a favore di …», una parte a credito si dice a credito. --}}
        @php($daRestituire = (int) collect($prospetto['senza_conduttore']['voci'])->where('modo', 'da_restituire')->sum('importo'))
        @php($delPianoSenza = (int) collect($prospetto['senza_conduttore']['voci'])->where('modo', 'piano')->sum('importo'))
        @php($restano = $prospetto['senza_conduttore']['totale'] - $daRestituire - $delPianoSenza)
        @php($conCredito = collect($prospetto['conduttori'])->contains(fn ($x) => $x['totale'] < 0))
        @php($alleParti = $conCredito
            ? collect($prospetto['conduttori'])->filter(fn ($x) => $x['totale'] !== 0)->map(fn ($x) => $x['totale'] < 0 ? $euro(abs($x['totale'])) . ' a favore di ' . $x['nome'] : $euro($x['totale']) . ' a carico di ' . $x['nome'])->values()->all()
            : [$euro($prospetto['totale_conduttori']) . ' ai conduttori'])
        @php($diviso = array_values(array_filter([
            ...$alleParti,
            $restano !== 0 ? ($restano < 0 ? $euro(abs($restano)) . ' a credito di chi le ha pagate' : $euro($restano) . ' che restano a chi le ha pagate') : null,
            $delPianoSenza !== 0 ? ($delPianoSenza < 0 ? $euro(abs($delPianoSenza)) . ' a credito di chi sta al posto dell\'inquilino nei giorni senza conduttore' : $euro($delPianoSenza) . ' a chi sta al posto dell\'inquilino nei giorni senza conduttore') : null,
            $daRestituire !== 0 ? ($daRestituire < 0 ? $euro(abs($daRestituire)) . ' che chi è uscito deve restituire, fra le parti' : $euro($daRestituire) . ' da restituire a chi è uscito, fra le parti') : null,
        ])))
        Totale delle voci che il riparto pone sul ruolo inquilino su questa unità nell'esercizio: <strong>{{ $prospetto['totale_inquilino'] < 0 ? $euro(abs($prospetto['totale_inquilino'])) . ' a credito' : $euro($prospetto['totale_inquilino']) }}</strong>{{ ($prospetto['senza_conduttore']['totale'] !== 0 || $conCredito) && $diviso !== [] ? ', di cui ' . (count($diviso) === 1 ? $diviso[0] : implode(', ', array_slice($diviso, 0, -1)) . ' e ' . end($diviso)) : '' }}.
    </p>
@endif

@endsection
