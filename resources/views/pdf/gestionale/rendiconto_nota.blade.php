{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
{{--
  La nota sintetica esplicativa del rendiconto: la parte del fascicolo subito dopo il frontespizio, e la stampa da sola. Legge l'array
  di `NotaSinteticaDelRendiconto::perLaStampa()`: le parti che hanno qualcosa, con i fatti tenuti (la frase di oggi o quella riscritta), il testo
  dell'amministratore, gli elenchi, la dichiarazione, le cose da decidere in assemblea. Nessuna cifra si calcola qui.

  Il testo dell'amministratore è testo: si scrive com'è, con i suoi a capo; un tag scritto nel testo esce come testo. Le cifre degli elenchi hanno il simbolo
  dell'euro legato alla cifra, come le frasi.

  I titoli degli elenchi stanno nella testa della loro tabella: mPDF non lascia una testa da sola in fondo alla pagina, e la ripete sulla pagina dopo.
  Le cifre degli elenchi hanno il carattere della riga, non quello dei titoli.

  La carta del rendiconto, in verticale, con lo stile e la testa comuni delle sue stampe (`_rendiconto_stile`, `_rendiconto_testa`). Niente firma: il
  frontespizio del fascicolo ha la sua.

  ⚠️ Blade: una direttiva attaccata a un carattere di parola NON viene compilata. Ogni `@if`/`@endif` qui ha uno spazio o un a-capo prima.
--}}
@php
    $euro = fn (?int $c) => $c === null ? '' : \App\Services\Gestionale\FattiDellaNota::euro($c);
    $data = fn (?string $iso) => $iso === null ? '' : \Carbon\Carbon::parse($iso)->format('d/m/Y');
    $testo = fn (?string $t) => nl2br(e((string) $t), false);
    $titoloStampa = \App\Services\Gestionale\NotaSinteticaDelRendiconto::TITOLO;
    $tipi = \App\Services\Gestionale\NotaSinteticaDelRendiconto::TIPI_CONTRATTO;
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>{{ $titoloStampa }} – {{ $esercizio->nome }}</title>
@include('pdf.gestionale._rendiconto_stile')
<style>
    body { font-size: 9pt; }
    p.fatto { margin: 0 0 6px; line-height: 1.35; }
    p.testo { margin: 4px 0 8px; line-height: 1.35; }
    h3 { font-family: inter; font-size: 8pt; letter-spacing: 0.6pt; text-transform: uppercase; color: #5A6B7B; margin: 10px 0 4px; }
    table.p td { font-size: 8pt; }
    table.p td.cifra { text-align: right; white-space: nowrap; }
    table.p th.elenco, table.l th.elenco { font-family: inter; font-size: 8pt; letter-spacing: 0.6pt; text-transform: uppercase; color: #5A6B7B; text-align: left;
        border-bottom: 0; padding: 10px 0 4px; font-weight: bold; }
    table.l { width: 100%; border-collapse: collapse; }
    table.l td { padding: 0 0 4px; }
    .vota { margin: 8px 0 4px; padding: 7px 10px; background: #F4F5F7; border-left: 3px solid #C9A227; }
    .vota p { margin: 0 0 3px; }
</style>
</head>
<body>

@include('pdf.gestionale._rendiconto_testa', ['alla' => $nota['alla'] ?? $esercizio->data_fine->format('Y-m-d')])

<h1>{{ $titoloStampa }}</h1>
<p class="sotto">Rendiconto {{ $esercizio->nome }}, dal {{ $esercizio->data_inizio->format('d/m/Y') }} al {{ $esercizio->data_fine->format('d/m/Y') }}</p>

@foreach($nota['parti'] as $parte)
<h2>{{ $parte['titolo'] }}</h2>

@foreach($parte['fatti'] as $fatto)
<p class="fatto">{!! $testo($fatto['testo']) !!}</p>
@endforeach

@if($parte['testo'] !== '')
<p class="testo">{!! $testo($parte['testo']) !!}</p>
@endif

@if(! empty($parte['contratti']))
<table class="p">
    <thead>
        <tr><th colspan="5" class="elenco">Contratti, polizze e verifiche</th></tr>
        <tr><th>Che cosa</th><th>Con chi</th><th class="n">Importo</th><th>Scadenza</th><th>Rinnovo</th></tr>
    </thead>
    <tbody>
    @foreach($parte['contratti'] as $c)
        <tr>
            <td>{{ $c['tipo'] === 'contratto' ? '' : $tipi[$c['tipo']].': ' }}{{ $c['cosa'] }} @if($c['note'] !== '') <br><span class="d">{!! $testo($c['note']) !!}</span> @endif</td>
            <td>{{ $c['con_chi'] }}</td>
            <td class="cifra">{{ $euro($c['importo']) }}</td>
            <td>{{ $data($c['scadenza']) }}</td>
            <td>{{ $c['rinnovo'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif

@if(! empty($parte['dipendenti']))
<p class="testo">{{ $parte['dipendenti'] }}</p>
@endif

@if(! empty($parte['dichiarazione']))
<table class="l">
    <thead><tr><th class="elenco">Liti, impugnazioni e sinistri</th></tr></thead>
    <tbody>
    @foreach($parte['dichiarazione'] as $riga)
        <tr><td><p class="fatto">{!! $testo($riga) !!}</p></td></tr>
    @endforeach
    </tbody>
</table>
@endif

@if(! empty($parte['debiti_certi']))
<table class="p">
    <thead>
        <tr><th colspan="4" class="elenco">Altri debiti certi</th></tr>
        <tr><th colspan="4" style="font-size: 8pt; letter-spacing: 0; text-transform: none; font-weight: normal; color: #1F2A37; border-bottom: 0; padding: 0 0 4px;">Debiti che non sono fatture e non sono nel giornale: li ha scritti l'amministratore.</th></tr>
        <tr><th>Creditore</th><th class="n">Importo</th><th>Da dove viene</th><th>Scadenza</th></tr>
    </thead>
    <tbody>
    @foreach($parte['debiti_certi'] as $d)
        <tr><td>{{ $d['creditore'] }}</td><td class="cifra">{{ $euro($d['importo']) }}</td><td>{{ $d['fonte'] }}</td><td>{{ $data($d['scadenza']) }}</td></tr>
    @endforeach
    </tbody>
</table>
@endif

@if(! empty($parte['da_decidere']))
<div class="vota">
    <h3 style="margin-top: 0;">Da decidere in assemblea</h3>
    @foreach($parte['da_decidere'] as $riga)
    <p>{!! $testo($riga) !!}</p>
    @endforeach
</div>
@endif
@endforeach

</body>
</html>
