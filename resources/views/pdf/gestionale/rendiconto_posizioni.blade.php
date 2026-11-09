@extends('pdf.base')
{{-- I titoli e i loro filetti hanno il colore della carta dello studio; senza un colore, quello di sempre. --}}
@php
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#1e3a5f');
@endphp

@section('title', 'Posizioni dei condòmini')

{{--
  Le posizioni dei condòmini (§ 9 del rendiconto): una riga per persona e unità, dall'array di `PosizioniDeiCondomini::posizioni()`, lo stesso della
  pagina. La tabella a blocchi di 100 righe con le celle a classi, come «Rate scadute»: mPDF tiene in memoria la tabella intera (le misure accanto
  al tetto, in `RendicontoCondominiController`).
--}}
@php
    // Il meno come nel resto del fascicolo: l'euro prima, «€ -50,00».
    $euro = fn (int $c) => '€ ' . number_format($c / 100, 2, ',', '.');
    $cella = fn (int $c) => $c === 0 ? '' : $euro($c);
    $p = $posizioni;
    $m = $p['mostra'];
    $colonne = array_values(array_filter([
        ['nome', 'Persona', 'left', 20], ['unita', 'Unità', 'left', 9], ['ordinarie', 'Ordinarie dell\'esercizio', 'right', 10], ['straordinarie', 'Straordinarie dell\'esercizio', 'right', 10],
        ['precedenti', 'Esercizi precedenti', 'right', 10], $m['successivi'] ? ['successivi', 'Esercizi successivi', 'right', 9] : null,
        $m['non_emesse'] ? ['non_emesse', 'Su rate non emesse', 'right', 9] : null, ['crediti', 'Crediti sulle rate', 'right', 9], ['totale', 'Totale', 'right', 10],
        $m['anticipi'] ? ['anticipi', 'Anticipi', 'right', 9] : null,
    ]));
    $blocchi = count($p['righe']) === 0 ? [[]] : array_chunk($p['righe'], 100);
    $rc = $p['riga_crediti'];
    $ra = $p['riga_anticipi'];
@endphp

@section('content')

<div style="margin-bottom: 8px; border-bottom: 2px solid {{ $coloreTitoli }}; padding-bottom: 6px;">
    <h2 style="margin: 0; padding: 0; font-size: 13pt; color: {{ $coloreTitoli }};">Posizioni dei condòmini — {{ $esercizio->nome }}</h2>
    <div style="font-size: 8.5pt; color: #444; margin-top: 3px;">{{ ucfirst($p['avvertenze']['data']) }} &nbsp;|&nbsp; Totale delle posizioni, senza gli anticipi: <strong>{{ $euro($p['totali']['totale']) }}</strong></div>
</div>

<div style="font-size: 7.5pt; color: #333; background: #f4f5f7; border-left: 3px solid #c9a227; padding: 6px 10px; margin-bottom: 8px;">
    <b>Come leggere.</b> {{ $p['avvertenze']['segno'] }} Le posizioni sono {{ $p['avvertenze']['data'] }}. {{ $p['avvertenze']['ordine'] }} {{ $p['avvertenze']['esercizio_della_rata'] }}
    I crediti sulle rate (un versamento oltre il dovuto di una rata) hanno il segno meno. {{ $p['avvertenze']['anticipi'] }}
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
            @foreach($colonne as [$chiave, $etichetta, $allinea, $larghezza])
                <th class="{{ $allinea === 'right' ? 'tn' : 't' }}" style="width: {{ $larghezza }}%;">{{ $etichetta }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach($blocco as $r)
            <tr>
                @foreach($colonne as [$chiave, $etichetta, $allinea])
                    @if($chiave === 'nome')
                        <td class="c">{{ $r['nome'] }}</td>
                    @elseif($chiave === 'unita')
                        <td class="c">{{ $r['unita'] ?? '—' }}@if($r['pregresso_nella_rata']) <span style="color: #777;">· contiene anche una parte di pregresso</span> @endif</td>
                    @else
                        <td class="cn">{{ $cella($r[$chiave]) }}</td>
                    @endif
                @endforeach
            </tr>
        @endforeach
        @if(count($p['righe']) === 0)
            <tr><td colspan="{{ count($colonne) }}" style="padding: 10px; text-align: center; color: #777;">Nessuna posizione diversa da zero a questa data.</td></tr>
        @endif
        @if($loop->last)
            <tr>
                <td class="tt" style="text-align: left;" colspan="2">Totale</td>
                @foreach(array_slice($colonne, 2) as [$chiave])
                    <td class="tt">{{ $euro($p['totali'][$chiave]) }}</td>
                @endforeach
            </tr>
        @endif
    </tbody>
</table>
@endforeach

<p style="font-size: 7.5pt; color: #333; margin-top: 8px;">
    Crediti verso i condòmini della situazione patrimoniale <b>{{ $euro($rc['crediti']) }}</b> = posizioni senza gli anticipi <b>{{ $euro($rc['posizioni']) }}</b>
    + spese anticipate per singole unità, non ancora chieste <b>{{ $euro($rc['spese_anticipate']) }}</b>@if($rc['differenza'] !== 0) — differenza <b>{{ $euro($rc['differenza']) }}</b> @endif.
    <br>Anticipi ricevuti dai condòmini <b>{{ $euro($ra['anticipi']) }}</b> = conto «Anticipi ricevuti dai condòmini» <b>{{ $euro($ra['conto']) }}</b>@if($ra['differenza'] !== 0) — differenza <b>{{ $euro($ra['differenza']) }}</b> @endif.
</p>

<p style="font-size: 7.5pt; color: #555; margin-top: 6px; border-top: 1px solid #c0ccd8; padding-top: 4px;">
    {{ $p['avvertenze']['riservato'] }}
</p>

@endsection
