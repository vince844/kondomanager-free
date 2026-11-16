{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
@extends('pdf.base')

{{--
  L'accordo di rientro con il calendario, per la persona: chi scrive per il condominio e a chi, il giorno dell'accordo, il debito riconosciuto rata per
  rata, il calendario delle rate dell'accordo (la somma è il debito riconosciuto), la regola di decadenza come l'ha scritta l'amministratore,
  l'autorizzazione dell'assemblea, la nota, e lo spazio per le firme. Le cifre in centesimi, le date `Y-m-d`: `$accordo` è la lettura del server
  (`AccordiRientroController::stampa`). Una pagina.

  Le parole sono quelle dell'accordo: il debito «riconosciuto», le rate «del calendario»; la regola di decadenza è scritta come condizione, e il programma
  non scrive che è avvenuta.
--}}
@php
    $carta ??= app(\App\Services\PDF\CartaDiStampa::class)->perGruppo(\App\Services\PDF\GruppoCarta::PER_LA_PERSONA);
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta, '#1e3a5f');
    $firma = $firma_stampe_absolute_path ?? null;
    $firma_stampe_absolute_path = null;
    $intestazione_propria = true;
    $piede_senza_data = true;
    // «€» legato alla cifra: non va a capo da solo.
    $euro = fn (int $cents) => "€\u{00A0}" . number_format($cents / 100, 2, ',', '.');
    $giorno = fn (?string $data) => $data ? \Carbon\Carbon::parse($data)->format('d/m/Y') : '';
    $condominioVia = trim((string) ($condominio->indirizzo ?? ''));
    $condominioCitta = trim(implode(' ', array_filter([$condominio->cap ?? null, $condominio->comune ?? null, filled($condominio->provincia ?? null) ? '(' . $condominio->provincia . ')' : null], 'filled')));
    $luogo = \App\Services\Gestionale\Lettere\EmissioneLettere::studio()['comune'] ?? ($condominio->comune ?: null);
    $cella = 'padding: 3px 4px; border-bottom: 1px solid #e3e8ef;';
    $testa = 'padding: 4px; border-bottom: 1px solid #c0ccd8;';
@endphp

@section('title', 'Accordo di rientro — ' . $persona['nome'])

@section('content')
    {{-- `overflow: wrap`: una parola più larga della colonna va a capo dove la colonna finisce. --}}
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; overflow: wrap;">
        <tr>
            <td style="width: {{ \App\Services\PDF\Carta::larghezzaMittente($carta) }}; padding: 0 {{ \App\Services\PDF\Carta::SPAZIO_PRIMA_DEL_DESTINATARIO }}mm 0 0; vertical-align: top;">
                @if($carta->haStudio() || $carta->cartaIntestata() !== null)
                    @if($carta->haStudio())
                        @include('pdf._carta.mittente')
                    @endif
                    <div style="font-size: 9pt; color: #333; line-height: 1.4;">
                        Amministratore del <strong>{{ \App\Services\Gestionale\Lettere\TestiLettere::condominioConNome((string) $condominio->nome) }}</strong>
                        @if($condominioVia !== '')<br>{{ $condominioVia }}@endif
                        @if($condominioCitta !== '')<br>{{ $condominioCitta }}@endif
                        @if(filled($condominio->codice_fiscale ?? null))<br>C.F. {{ $condominio->codice_fiscale }}@endif
                    </div>
                @else
                    <div style="font-size: 11pt; font-weight: bold; color: {{ $coloreTitoli }}; line-height: 1.25;">{{ $condominio->nome }}</div>
                    <div style="font-size: 9pt; color: #333; margin-top: 2px; line-height: 1.45;">
                        @if($condominioVia !== ''){{ $condominioVia }}<br>@endif
                        @if($condominioCitta !== ''){{ $condominioCitta }}<br>@endif
                        @if(filled($condominio->codice_fiscale ?? null))C.F. {{ $condominio->codice_fiscale }}@endif
                    </div>
                @endif
            </td>
            <td style="padding: 0; vertical-align: top; text-align: right;">
                <div style="font-size: 9pt; color: #555; line-height: 1.25;">Gentile</div>
                <div style="font-size: 11pt; font-weight: bold; color: #222; line-height: 1.3;">{{ $persona['nome'] }}</div>
                @if(filled($persona['indirizzo'] ?? null))
                    <div style="font-size: 9.5pt; color: #333; margin-top: 2px; line-height: 1.4;">{{ $persona['indirizzo'] }}</div>
                @endif
            </td>
        </tr>
    </table>

    <div style="font-size: 9pt; color: #444; margin-bottom: 8px;">{{ trim(($luogo ? $luogo . ', ' : '') . $giorno($accordo['data_accordo'])) }}</div>

    <div style="border-bottom: 2px solid {{ $coloreTitoli }}; padding-bottom: 6px; margin-bottom: 10px;">
        <h2 style="margin: 0; font-size: 14pt; color: {{ $coloreTitoli }}; letter-spacing: 0.5px;">Accordo di rientro</h2>
        <div style="font-size: 9pt; color: #444; margin-top: 3px;">del {{ $giorno($accordo['data_accordo']) }}</div>
    </div>

    {{-- Un accordo chiuso lo dice in testa: la stampa di un accordo con la decadenza registrata non sembra un accordo in corso. --}}
    @if(filled($accordo['decadenza_registrata_il'] ?? null))
        <div style="font-size: 10pt; font-weight: bold; color: #8a4b00; margin-bottom: 8px;">Decadenza registrata il {{ $giorno($accordo['decadenza_registrata_il']) }}{{ filled($accordo['nota_chiusura'] ?? null) ? ': ' . $accordo['nota_chiusura'] : '.' }}</div>
    @elseif(filled($accordo['chiuso_il'] ?? null))
        <div style="font-size: 10pt; font-weight: bold; color: #8a4b00; margin-bottom: 8px;">Accordo chiuso il {{ $giorno($accordo['chiuso_il']) }}{{ filled($accordo['nota_chiusura'] ?? null) ? ': ' . $accordo['nota_chiusura'] : '.' }}</div>
    @endif

    <div style="font-size: 10pt; line-height: 1.5; margin-bottom: 8px;">
        Il debito riconosciuto da {{ $persona['nome'] }} è di <strong>{{ $euro($accordo['riconosciuti']) }}</strong>, per le rate qui sotto. Si versa con le rate
        del calendario, fino al {{ $giorno($accordo['fino_al']) }}.
    </div>

    <h3 style="font-size: 10.5pt; color: {{ $coloreTitoli }}; margin: 6px 0 4px;">Il debito riconosciuto</h3>
    <table style="width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 8px;">
        <thead>
            <tr style="background: #eef2f7; color: #1e3a5f;">
                <th style="text-align: left; {{ $testa }}">Unità</th>
                <th style="text-align: left; {{ $testa }}">Che cos'è</th>
                <th style="text-align: left; {{ $testa }}">Scadenza</th>
                <th style="text-align: right; {{ $testa }}">Riconosciuto</th>
            </tr>
        </thead>
        <tbody>
            @foreach($accordo['rate'] as $r)
                <tr>
                    <td style="{{ $cella }}">{{ $r['unita'] ?? '' }}</td>
                    <td style="{{ $cella }}">{{ $r['causale'] ?? ($r['numero_rata'] === 0 ? 'Rata 0 (pregresso)' : 'Rata ' . $r['numero_rata']) . (filled($r['piano'] ?? null) ? ' — ' . $r['piano'] : '') }}</td>
                    <td style="{{ $cella }}">{{ $giorno($r['scadenza'] ?? null) }}</td>
                    <td style="{{ $cella }} text-align: right; white-space: nowrap;">{{ $euro((int) $r['riconosciuto']) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="3" style="padding: 4px; text-align: right; font-weight: bold;">Totale</td>
                <td style="padding: 4px; text-align: right; font-weight: bold; white-space: nowrap;">{{ $euro($accordo['riconosciuti']) }}</td>
            </tr>
        </tbody>
    </table>

    <h3 style="font-size: 10.5pt; color: {{ $coloreTitoli }}; margin: 6px 0 4px;">Il calendario</h3>
    <table style="width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 8px;">
        <thead>
            <tr style="background: #eef2f7; color: #1e3a5f;">
                <th style="text-align: left; {{ $testa }}">Rata</th>
                <th style="text-align: left; {{ $testa }}">Il giorno</th>
                <th style="text-align: right; {{ $testa }}">Importo</th>
            </tr>
        </thead>
        <tbody>
            @foreach($accordo['calendario'] as $r)
                <tr>
                    <td style="{{ $cella }}">{{ $r['numero'] }}</td>
                    <td style="{{ $cella }}">{{ $giorno($r['data']) }}</td>
                    <td style="{{ $cella }} text-align: right; white-space: nowrap;">{{ $euro((int) $r['importo']) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="2" style="padding: 4px; text-align: right; font-weight: bold;">Totale</td>
                <td style="padding: 4px; text-align: right; font-weight: bold; white-space: nowrap;">{{ $euro((int) array_sum(array_column($accordo['calendario'], 'importo'))) }}</td>
            </tr>
        </tbody>
    </table>

    <table style="width: 100%; border-collapse: collapse; font-size: 9pt; margin-top: 6px;">
        <tr>
            <td style="width: 38mm; padding: 3px 0; vertical-align: top; color: #555;">Decadenza</td>
            <td style="padding: 3px 0; vertical-align: top;">{{ $regola }}</td>
        </tr>
        <tr>
            <td style="width: 38mm; padding: 3px 0; vertical-align: top; color: #555;">Assemblea</td>
            <td style="padding: 3px 0; vertical-align: top;">{{ $autorizzazione }}</td>
        </tr>
        @if(filled($accordo['nota'] ?? null))
            <tr>
                <td style="width: 38mm; padding: 3px 0; vertical-align: top; color: #555;">Nota</td>
                <td style="padding: 3px 0; vertical-align: top;">{{ $accordo['nota'] }}</td>
            </tr>
        @endif
    </table>

    <table style="width: 100%; border-collapse: collapse; font-size: 9.5pt; margin-top: 28px; page-break-inside: avoid;">
        <tr>
            <td style="width: 50%; vertical-align: top; padding-right: 10mm;">
                <div style="color: #1e3a5f; margin-bottom: 4px;">{{ $persona['nome'] }}</div>
                <table style="border-collapse: collapse; width: 60mm;"><tr><td style="height: 16mm; border-bottom: 1px solid #888;">&nbsp;</td></tr></table>
            </td>
            <td style="width: 50%; vertical-align: top; text-align: right;">
                <div style="color: #1e3a5f; margin-bottom: 4px;">L'Amministratore</div>
                @if(!empty($firma))
                    <img src="{{ $firma }}" style="max-width: 180px; max-height: 60px;">
                @else
                    <table style="border-collapse: collapse; width: 60mm; margin-left: auto;"><tr><td style="height: 16mm; border-bottom: 1px solid #888;">&nbsp;</td></tr></table>
                @endif
            </td>
        </tr>
    </table>
@endsection
