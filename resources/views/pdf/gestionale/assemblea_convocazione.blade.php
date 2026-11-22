{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
@extends('pdf.base')

{{--
  L'avviso di convocazione di un'assemblea, uno per persona: ognuno comincia su una pagina nuova, anche quando il PDF ne porta molti. Ogni voce di
  `$avvisi` è l'istantanea dell'avviso (`EmissioneDelleConvocazioni::avviso()`): la persona com'era il giorno dell'invio, con le sue unità; il tipo
  dell'assemblea; le date con le ore, il luogo o la piattaforma; l'ordine del giorno con la frase specifica di ogni punto (per il conduttore solo i suoi
  punti, con la frase che lo dice, decisione 566); in fondo la delega staccabile già intestata, con «Non si può delegare l'amministratore» (art. 67 c. 5
  disp. att.).

  L'impaginazione è quella delle lettere: in alto a sinistra lo studio che scrive (se le impostazioni di stampa lo dicono) e sotto il condominio, a destra il
  destinatario; con la carta intestata lo studio è sulla carta. `etichetta` ('ANTEPRIMA') dice in testa che il PDF non è un avviso spedito.
--}}
@php
    $firmaAvviso = $firma_stampe_absolute_path ?? null;
    $firma_stampe_absolute_path = null;
    $intestazione_propria = true;
    $piede_senza_data = true;
    $carta ??= app(\App\Services\PDF\CartaDiStampa::class)->perGruppo(\App\Services\PDF\GruppoCarta::RENDICONTO_E_ASSEMBLEA);
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta, '#1e3a5f');
    $condominioVia = trim((string) ($condominio->indirizzo ?? ''));
    $condominioCitta = trim(implode(' ', array_filter([$condominio->cap ?? null, $condominio->comune ?? null, filled($condominio->provincia ?? null) ? '(' . $condominio->provincia . ')' : null], 'filled')));
@endphp

@section('title', count($avvisi) === 1 ? 'Convocazione — ' . ($avvisi[0]['persona']['nome'] ?? '') : 'Convocazioni dell\'assemblea')

@section('content')
@foreach($avvisi as $i => $a)
    @if($i > 0)
        <pagebreak resetpagenum="1" />
    @endif

    @if(!empty($a['etichetta']))
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 8px;">
            <tr>
                <td style="padding: 6px 10px; border: 1.5px dashed #9c4221; color: #9c4221; font-size: 11pt; font-weight: bold; text-align: center;">{{ $a['etichetta'] }} — non è un avviso spedito</td>
            </tr>
        </table>
    @endif

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 14px; overflow: wrap;">
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
                @if(trim((string) ($a['persona']['nome'] ?? '')) === '')
                    {{-- L'avviso senza nome (il fascicolo, una copia per tutti): il destinatario sono i condòmini, con l'indirizzo del condominio. --}}
                    <div style="font-size: 11pt; font-weight: bold; color: #222; line-height: 1.3;">Ai condòmini del {{ \App\Services\Gestionale\Lettere\TestiLettere::condominioConNome((string) $condominio->nome) }}</div>
                    @if($condominioVia !== '' || $condominioCitta !== '')
                        <div style="font-size: 9.5pt; color: #333; margin-top: 2px; line-height: 1.4;">{{ $condominioVia }}@if($condominioVia !== '' && $condominioCitta !== '')<br>@endif{{ $condominioCitta }}</div>
                    @endif
                @else
                <div style="font-size: 11pt; font-weight: bold; color: #222; line-height: 1.3;">{{ $a['persona']['nome'] ?? '' }}</div>
                @if(filled($a['persona']['indirizzo'] ?? null))
                    <div style="font-size: 9.5pt; color: #333; margin-top: 2px; line-height: 1.4;">{{ $a['persona']['indirizzo'] }}</div>
                @endif
                @if(filled($a['persona']['recapito'] ?? null))
                    <div style="font-size: 8.5pt; color: #555; margin-top: 2px;">{{ $a['persona']['recapito'] }}</div>
                @endif
                @endif
            </td>
        </tr>
    </table>

    <div style="font-size: 9pt; color: #444; margin-bottom: 8px;">{{ $a['luogo_data'] }}</div>

    <div style="border-bottom: 2px solid {{ $coloreTitoli }}; padding-bottom: 6px; margin-bottom: 10px;">
        <h2 style="margin: 0; font-size: 14pt; color: {{ $coloreTitoli }}; letter-spacing: 0.5px;">{{ $a['titolo'] }}</h2>
        <div style="font-size: 9pt; color: #444; margin-top: 3px;">{{ $a['sottotitolo'] }}</div>
    </div>

    <div style="font-size: 10pt; line-height: 1.5; margin-bottom: 8px;">{{ $a['saluto'] }}</div>
    <div style="font-size: 10pt; line-height: 1.5; margin-bottom: 8px;">{{ $a['apertura'] }}</div>

    <table style="width: 100%; border-collapse: collapse; font-size: 9.5pt; margin-bottom: 8px;">
        @foreach($a['sedute'] as $s)
            <tr>
                <td style="width: 34%; padding: 4px 6px; border-bottom: 1px solid #e3e8ef; font-weight: bold; color: {{ $coloreTitoli }};">{{ $s['nome'] }}</td>
                <td style="padding: 4px 6px; border-bottom: 1px solid #e3e8ef;">{{ $s['quando'] }}@if(filled($s['dove'] ?? null))<br><span style="color: #444;">{{ $s['dove'] }}</span>@endif</td>
            </tr>
        @endforeach
    </table>
    @foreach($a['righe_della_riunione'] as $riga)
        <div style="font-size: 9pt; color: #333; line-height: 1.45; margin-bottom: 4px;">{{ $riga }}</div>
    @endforeach

    @if(filled($a['conduttore'] ?? null))
        <table style="width: 100%; border-collapse: collapse; margin: 6px 0 4px;">
            <tr><td style="padding: 6px 8px; border: 1px solid #d1dbe8; background: #f9fafb; font-size: 9.5pt;">{{ $a['conduttore'] }}</td></tr>
        </table>
    @endif

    <div style="font-size: 10.5pt; font-weight: bold; color: {{ $coloreTitoli }}; margin: 10px 0 4px; letter-spacing: 0.3px;">{{ $a['titolo_ordine'] }}</div>
    <table style="width: 100%; border-collapse: collapse; font-size: 9.5pt; margin-bottom: 8px;">
        @foreach($a['punti'] as $p)
            <tr>
                <td style="width: 7mm; padding: 4px 4px 4px 0; vertical-align: top; font-weight: bold;">{{ $p['numero'] }}.</td>
                <td style="padding: 4px 0; vertical-align: top; line-height: 1.45;">
                    {{ $p['testo'] }}
                    @foreach($p['note'] as $nota)
                        <br><span style="font-size: 8.5pt; color: #555;">{{ $nota }}</span>
                    @endforeach
                </td>
            </tr>
        @endforeach
    </table>

    @foreach($a['chiusura'] as $riga)
        <div style="font-size: 9.5pt; line-height: 1.5; margin-bottom: 5px;">{{ $riga }}</div>
    @endforeach

    @if(!empty($firmaAvviso))
        <div style="margin-top: 14px; text-align: right; page-break-inside: avoid;">
            <div style="font-size: 10pt; margin-bottom: 6px; color: {{ $coloreTitoli }};">L'Amministratore</div>
            <img src="{{ $firmaAvviso }}" style="max-width: 160px; max-height: 64px;">
        </div>
    @else
        <div style="margin-top: 14px; text-align: right; font-size: 10pt; color: {{ $coloreTitoli }};">L'Amministratore</div>
    @endif

    {{-- La delega staccabile: si ritaglia lungo la linea e si consegna al delegato. Sta tutta su una pagina. --}}
    <div style="page-break-inside: avoid; margin-top: 18px;">
        <div style="border-top: 1.2px dashed #777; font-size: 8.5pt; color: #666; padding-top: 2px; text-align: center;">✂ {{ $a['delega']['ritaglia'] }}</div>
        <div style="font-size: 11pt; font-weight: bold; color: {{ $coloreTitoli }}; text-align: center; margin: 8px 0 6px; letter-spacing: 1px;">DELEGA</div>
        <div style="font-size: 9.5pt; line-height: 1.7;">{{ $a['delega']['testo'] }}</div>
        <div style="font-size: 9.5pt; line-height: 1.9; margin-top: 4px;">{{ $a['delega']['delegato'] }}</div>
        <div style="font-size: 9.5pt; line-height: 1.6; margin-top: 2px;">{{ $a['delega']['poteri'] }}</div>
        <table style="width: 100%; border-collapse: collapse; font-size: 9.5pt; margin-top: 14px;">
            <tr>
                <td style="width: 50%; padding: 0;">Data ____________________</td>
                <td style="width: 50%; padding: 0; text-align: right;">Firma ______________________________</td>
            </tr>
        </table>
        <div style="font-size: 8pt; color: #555; margin-top: 8px;">{{ $a['delega']['avvertenza'] }}</div>
    </div>
@endforeach
@endsection
