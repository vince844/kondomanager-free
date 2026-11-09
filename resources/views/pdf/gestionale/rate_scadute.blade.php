@extends('pdf.base')
{{-- I titoli e i loro filetti hanno il colore della carta dello studio; senza un colore, quello di sempre. --}}
@php
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#1e3a5f');
@endphp

@section('title', $titolo)

@php
    $euro = fn (int $c) => '€ ' . number_format($c / 100, 2, ',', '.');
    $giorno = fn (?string $d) => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y') : '';
    $chi = $righe->count() === 1 ? '1 persona' : $righe->count() . ' persone';
    $rigaNonEmesse = null;
    if (! empty($non_emesse) && ($non_emesse['importo'] > 0 || $non_emesse['saldi'] > 0)) {
        $saldi = $non_emesse['saldi'] > 0 ? $euro($non_emesse['saldi']) . ' di saldi non ancora in un piano approvato' : null;
        $rigaNonEmesse = $non_emesse['importo'] > 0
            ? ($non_emesse['persone'] === 1 ? '1 persona ha ' : $non_emesse['persone'] . ' persone hanno ') . $euro($non_emesse['importo'])
                . ' su rate con la scadenza passata e non ancora emesse (il pregresso compreso)' . ($saldi ? ', e ' . $saldi : '') . ': non sono in questo elenco.'
            : ucfirst($saldi) . ': non sono in questo elenco.';
    }
    $dispensa = function (?array $d) use ($giorno) {
        if ($d === null) {
            return '';
        }

        return ' — dispensa dell\'assemblea del ' . $giorno($d['data_delibera']) . ($d['numero_verbale'] ? ' (verbale n. ' . $d['numero_verbale'] . ')' : '');
    };
    $seiMesi = function (array $s) use ($giorno, $dispensa) {
        $testo = $s['data'] ? $giorno($s['data']) . $dispensa($s['dispensa']) : 'non calcolata';
        if ($s['mancano'] > 0) {
            $testo .= '; ' . ($s['mancano'] === 1 ? '1 rata senza data' : $s['mancano'] . ' rate senza data');
        }

        return $testo;
    };
@endphp

@section('content')

<div style="margin-bottom: 10px; border-bottom: 2px solid {{ $coloreTitoli }}; padding-bottom: 6px;">
    <h2 style="margin: 0; padding: 0; font-size: 13pt; color: {{ $coloreTitoli }};">{{ $titolo }}</h2>
    <div style="font-size: 8.5pt; color: #444; margin-top: 3px;">
        {{ $chi }}{{ $selezione ? ', scelte nell\'elenco' : '' }} &nbsp;|&nbsp; Totale {{ $in_scadenza ? 'in scadenza' : 'scaduto' }}: <strong>{{ $euro($totale) }}</strong>, senza togliere il credito di nessuno.
        {{ $oggi ? '' : 'Per una data passata il credito delle persone non si conosce, e non è nell\'elenco.' }}
    </div>
    @if($rigaNonEmesse)
        <div style="font-size: 8pt; color: #7a4b00; margin-top: 3px;">{{ $rigaNonEmesse }}</div>
    @endif
</div>

{{--
  La tabella si spezza in blocchi di 100 persone, come il Libro Giornale, e la ragione è la memoria, misurata con richieste vere su un
  condominio di N persone (le cifre accanto al tetto, in `RateScaduteController`). mPDF tiene in memoria la tabella intera: con una
  tabella sola (0,18 MB a persona) la stampa moriva di fatal error dentro mPDF fra 300 e 1.000 persone con 128M, pagina bianca e nessun
  messaggio.

  `<pagebreak />` fra un blocco e l'altro, perché mPDF ignora `page-break-before` su `<table>` e senza il salto l'intestazione del blocco
  dopo ricomparirebbe a metà pagina; le larghezze sulle intestazioni, perché ogni blocco è una tabella a sé e le colonne restino allineate.

  Le celle hanno una classe, non lo stile scritto in ognuna: l'HTML è la metà (con 3.500 persone 1,8 MB invece di 3,6), e mPDF ne fa
  copie più grandi dell'originale (con lo stile in ogni cella, a 3.500 persone moriva chiedendo un blocco solo di 17,8 MB). Il PDF che
  ne esce ha la stessa dimensione, nella misura.
--}}
@php
    $colonne = $in_scadenza
        ? array_merge([['Persona', 'left', $oggi ? 30 : 35], ['Unità', 'left', $oggi ? 25 : 30], ['In scadenza', 'right', $oggi ? 15 : 17], ['Prima scadenza', 'left', $oggi ? 15 : 18]],
            $oggi ? [['Credito', 'right', 15]] : [])
        : array_merge([['Persona', 'left', 18], ['Unità', 'left', 12], ['Scaduto', 'right', 9], ['1–30 giorni', 'right', 8], ['31–90 giorni', 'right', 8], ['oltre 90 giorni', 'right', 8],
            ['Ritardo', 'left', $oggi ? 15 : 18], ['Data dei sei mesi più vicina', 'left', $oggi ? 15 : 19]], $oggi ? [['Credito', 'right', 7]] : []);
    $blocchi = $righe->isEmpty() ? [collect()] : $righe->chunk(100);
@endphp
<style>
    th.t { padding: 3px 4px; border-bottom: 1px solid #c0ccd8; text-align: left; }
    th.tn { padding: 3px 4px; border-bottom: 1px solid #c0ccd8; text-align: right; }
    td.c { padding: 3px 4px; border-bottom: 1px solid #e3e8ef; vertical-align: top; }
    td.cn { padding: 3px 4px; border-bottom: 1px solid #e3e8ef; vertical-align: top; text-align: right; }
</style>
@foreach($blocchi as $blocco)
@if(! $loop->first)<pagebreak />@endif
<table style="width: 100%; border-collapse: collapse; font-size: 8pt;">
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
                <td class="c">{{ implode(', ', $r['unita']) }}</td>
                <td class="cn">{{ $euro($r['importo']) }}</td>
                @if($in_scadenza)
                    <td class="c">{{ $giorno($r['prima_scadenza']) }}</td>
                @else
                    <td class="cn">{{ $r['fasce']['1_30'] ? $euro($r['fasce']['1_30']) : '' }}</td>
                    <td class="cn">{{ $r['fasce']['31_90'] ? $euro($r['fasce']['31_90']) : '' }}</td>
                    <td class="cn">{{ $r['fasce']['oltre_90'] ? $euro($r['fasce']['oltre_90']) : '' }}</td>
                    <td class="c">{{ $r['ritardo_testo'] }}{{ $r['origine_non_dichiarata'] ? '; pregresso con origine non dichiarata' : '' }}{{ collect($r['accordi'] ?? [])->map(fn ($a) => '; ' . \App\Services\Gestionale\Debiti\AccordiRientro::fraseBreve($a))->implode('') }}</td>
                    <td class="c">{{ $seiMesi($r['sei_mesi']) }}</td>
                @endif
                @if($oggi)
                    <td class="cn">{{ ($r['credito'] ?? 0) > 0 ? $euro($r['credito']) : '' }}</td>
                @endif
            </tr>
        @endforeach
        @if($righe->isEmpty())
            <tr><td colspan="{{ count($colonne) }}" style="padding: 10px; text-align: center; color: #777;">Nessuna persona con questi filtri.</td></tr>
        @endif
    </tbody>
</table>
@endforeach

@unless($in_scadenza)
    <p style="font-size: 7.5pt; color: #555; margin-top: 8px;">
        Le due date dei sei mesi: dalla fine dell'esercizio che contiene la scadenza della rata, e dalla fine dell'esercizio di competenza del piano, più sei mesi; qui la più vicina senza una dispensa. {{ \App\Services\Gestionale\Debiti\SeiMesi::FRASE_PIU_SEI_MESI }} {{ \App\Services\Gestionale\Debiti\SeiMesi::FRASE_FESTIVO }} {{ \App\Services\Gestionale\Debiti\SeiMesi::TESTO_NORMA }}
    </p>
@endunless

<p style="font-size: 7.5pt; color: #555; margin-top: 6px; border-top: 1px solid #c0ccd8; padding-top: 4px;">
    Contiene dati personali: si mostra all'assemblea e a chi ha diritto di conoscerlo, come il condòmino o il creditore non ancora pagato che lo chiedono. Non va esposto in bacheca né in altri spazi che vedono anche gli estranei.
</p>

@endsection
