@extends('pdf.base')

@section('title', 'Crediti per anzianità')

{{--
  I crediti per anzianità (§ 10 del rendiconto): una riga per rata emessa e non pagata alla data, dall'array di `PosizioniDeiCondomini::crediti()`,
  lo stesso della pagina. La tabella a blocchi di 100 righe con le celle a classi, come «Rate scadute» (le misure accanto al tetto, in
  `RendicontoCondominiController`).
--}}
@php
    // Il meno come nel resto del fascicolo: l'euro prima, «€ -50,00».
    $euro = fn (int $c) => '€ ' . number_format($c / 100, 2, ',', '.');
    $giorno = fn (?string $d) => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y') : '';
    $c = $crediti;
    $fasce = ['1_30' => '1–30 giorni', '31_90' => '31–90 giorni', 'oltre_90' => 'oltre 90 giorni'];
    $colonne = array_values(array_filter([
        ['Persona', 'left', 13], ['Unità', 'left', 6], ['Rata', 'left', 13], ['Importo', 'right', 8], ['Scadenza', 'left', 7], ['Ritardo', 'left', 12],
        ['Annata della scadenza', 'left', 8], ['Annata del piano', 'left', 8],
        $c['finito'] ? ['Versato dopo', 'right', 7] : null, $c['finito'] ? ['Resta oggi', 'right', 7] : null, ['Ultima lettera partita o spedita', 'left', 11],
    ]));
    $blocchi = count($c['righe']) === 0 ? [[]] : array_chunk($c['righe'], 100);
@endphp

@section('content')

<div style="margin-bottom: 8px; border-bottom: 2px solid #1e3a5f; padding-bottom: 6px;">
    <h2 style="margin: 0; padding: 0; font-size: 13pt; color: #1e3a5f;">Crediti per anzianità — {{ $esercizio->nome }}</h2>
    <div style="font-size: 8.5pt; color: #444; margin-top: 2px;">Le rate non pagate, per ritardo.</div>
    <div style="font-size: 8.5pt; color: #444; margin-top: 3px;">
        {{ ucfirst($c['avvertenze']['data']) }} &nbsp;|&nbsp; Rate non pagate: <strong>{{ $euro($c['totali']['importo']) }}</strong>, di cui scadute <strong>{{ $euro($c['totali']['scaduto']) }}</strong>
        ({{ collect($fasce)->map(fn ($e, $k) => $e . ' ' . $euro($c['totali']['fasce'][$k]))->implode(', ') }})
    </div>
</div>

<div style="font-size: 7.5pt; color: #333; background: #f4f5f7; border-left: 3px solid #c9a227; padding: 6px 10px; margin-bottom: 8px;">
    <b>Come leggere.</b> {{ $c['avvertenze']['segno'] }} Le rate sono {{ $c['avvertenze']['data'] }}, una riga per rata emessa e non pagata a quella data. {{ $c['avvertenze']['ordine'] }}
    {{ $c['avvertenze']['annate'] }} @if($c['avvertenze']['versato_dopo']) {{ $c['avvertenze']['versato_dopo'] }} @endif L'ultima lettera partita o spedita a ogni persona, fino a oggi.
    Un pregresso senza un numero di giorni dice la sua origine, e sta oltre i 90 giorni.
</div>

<style>
    th.t { padding: 3px 4px; border-bottom: 1px solid #c0ccd8; text-align: left; }
    th.tn { padding: 3px 4px; border-bottom: 1px solid #c0ccd8; text-align: right; }
    td.c { padding: 3px 4px; border-bottom: 1px solid #e3e8ef; vertical-align: top; }
    td.cn { padding: 3px 4px; border-bottom: 1px solid #e3e8ef; vertical-align: top; text-align: right; }
    td.tt { padding: 4px; border-top: 1.5px solid #1e3a5f; font-weight: bold; text-align: right; }
</style>
@foreach($blocchi as $blocco)
@if(! $loop->first)<pagebreak />@endif
<table style="width: 100%; border-collapse: collapse; font-size: 7.5pt;">
    <thead>
        <tr style="background: #eef2f7; color: #1e3a5f;">
            @foreach($colonne as [$etichetta, $allinea, $larghezza])
                <th class="{{ $allinea === 'right' ? 'tn' : 't' }}" style="width: {{ $larghezza }}%;">{{ $etichetta }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach($blocco as $r)
            <tr>
                <td class="c">{{ $r['nome'] }}</td>
                <td class="c">{{ $r['unita'] ?? '—' }}</td>
                <td class="c">{{ $r['piano'] }}, rata {{ $r['numero_rata'] }} <span style="color: #777;">· {{ $r['gestione'] }}@if($r['pregresso_nella_rata']) · {{ \App\Services\Gestionale\PosizioniDeiCondomini::NOTA_PREGRESSO_NELLA_RATA }} @endif</span></td>
                <td class="cn">{{ $euro($r['importo']) }}</td>
                <td class="c">{{ $giorno($r['scadenza']) }}</td>
                <td class="c">{{ $r['origine'] !== null && $r['giorni'] === null ? $r['origine'] : ($r['scaduta'] ? $r['giorni'] . ' giorni, ' . $fasce[$r['fascia']] : 'non ancora scaduta') }}</td>
                <td class="c">{{ $r['annata_scadenza'] }}</td>
                <td class="c">{{ $r['annata_competenza'] }}</td>
                @if($c['finito'])
                    <td class="cn">{{ $r['versato_dopo'] ? $euro($r['versato_dopo']) : '' }}</td>
                    <td class="cn">{{ $euro($r['resta_oggi']) }}</td>
                @endif
                <td class="c">{{ $r['lettera'] ? $r['lettera']['tipo'] . ' del ' . $giorno($r['lettera']['data']) : '' }}</td>
            </tr>
        @endforeach
        @if(count($c['righe']) === 0)
            <tr><td colspan="{{ count($colonne) }}" style="padding: 10px; text-align: center; color: #777;">Nessuna rata non pagata a questa data.</td></tr>
        @endif
        @if($loop->last)
            <tr>
                <td class="tt" style="text-align: left;" colspan="3">Totale</td>
                <td class="tt">{{ $euro($c['totali']['importo']) }}</td>
                <td class="tt" colspan="4"></td>
                @if($c['finito'])
                    <td class="tt">{{ $euro($c['totali']['versato_dopo']) }}</td>
                    <td class="tt">{{ $euro($c['totali']['resta_oggi']) }}</td>
                @endif
                <td class="tt"></td>
            </tr>
        @endif
    </tbody>
</table>
@endforeach

<p style="font-size: 7.5pt; color: #555; margin-top: 6px; border-top: 1px solid #c0ccd8; padding-top: 4px;">
    {{ $c['avvertenze']['riservato'] }}
</p>

@endsection
