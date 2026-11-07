@extends('pdf.base')

{{--
  L'avviso di compensazione: alla persona le cui rate un credito ha coperto, senza un versamento nuovo. Ogni voce di `$avvisi` è l'istantanea
  della riga del registro (`AvvisiCompensazione::istantanea()`, in centesimi): l'incasso (numero e data), la persona, le quote coperte con
  l'importo, di chi era il credito, il bollo scelto. Ognuno comincia su una pagina nuova.

  In alto il condominio e, a destra, il destinatario per la busta a finestra; la riga dell'imposta di bollo come la ricevuta, con lo stato che ha
  scelto l'amministratore (o «—»), senza dire se è dovuto. La firma è quella delle stampe, come la ricevuta.
--}}
@php
    $firmaAvviso = $firma_stampe_absolute_path ?? null;
    $firma_stampe_absolute_path = null;
    $intestazione_propria = true;
    $piede_senza_data = true;
    $euro = fn (int $cents) => '€ ' . number_format($cents / 100, 2, ',', '.');
    $giorno = fn (?string $data) => $data ? \Carbon\Carbon::parse($data)->format('d/m/Y') : '';
    $statiBollo = ['assolta' => 'Assolta', 'non_dovuta' => 'Non dovuta', 'da_assolvere' => 'Da assolvere'];
    $condominioVia = trim((string) ($condominio->indirizzo ?? ''));
    // Il luogo in testa all'avviso: il comune dello studio, se è scritto nelle impostazioni di stampa; senza, quello del condominio.
    $luogo = \App\Services\Gestionale\Lettere\EmissioneLettere::studio()['comune'] ?? ($condominio->comune ?: null);
    $condominioCitta = trim(implode(' ', array_filter([$condominio->cap ?? null, $condominio->comune ?? null, filled($condominio->provincia ?? null) ? '(' . $condominio->provincia . ')' : null], 'filled')));
@endphp

@section('title', count($avvisi) === 1 ? 'Avviso di compensazione — ' . ($avvisi[0]['persona']['nome'] ?? '') : 'Avvisi di compensazione')

@section('content')
@foreach($avvisi as $i => $a)
    @if($i > 0)
        <pagebreak resetpagenum="1" />
    @endif
    @php
        $nome = (string) ($a['persona']['nome'] ?? '');
        $di = (array) ($a['credito_di'] ?? []);
        $credito = $di === [] ? 'un credito' : ($di === [$nome] ? 'il suo credito' : 'il credito di ' . implode(', ', $di));
        $indirizzo = \App\Models\Anagrafica::whereKey($a['persona']['id'] ?? 0)->value('indirizzo');
    @endphp

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px;">
        <tr>
            <td style="width: 100mm; padding: 0; vertical-align: top;">
                <div style="font-size: 12pt; font-weight: bold; color: #1e3a5f; line-height: 1.25;">{{ $condominio->nome }}</div>
                <div style="font-size: 9pt; color: #333; margin-top: 2px; line-height: 1.45;">
                    @if($condominioVia !== ''){{ $condominioVia }}<br>@endif
                    @if($condominioCitta !== ''){{ $condominioCitta }}<br>@endif
                    @if(filled($condominio->codice_fiscale ?? null))C.F. {{ $condominio->codice_fiscale }}@endif
                </div>
            </td>
            <td style="padding: 0; vertical-align: top;">
                <div style="font-size: 9pt; color: #555; line-height: 1.25;">Gentile</div>
                <div style="font-size: 11pt; font-weight: bold; color: #222; line-height: 1.3;">{{ $nome }}</div>
                @if(filled($indirizzo))
                    <div style="font-size: 9.5pt; color: #333; margin-top: 2px; line-height: 1.4;">{{ $indirizzo }}</div>
                @endif
            </td>
        </tr>
    </table>

    <div style="font-size: 9pt; color: #444; margin-bottom: 8px;">{{ trim(($luogo ? $luogo . ', ' : '') . $giorno($a['emesso_il'] ?? null)) }}</div>

    <div style="border-bottom: 2px solid #1e3a5f; padding-bottom: 6px; margin-bottom: 10px;">
        <h2 style="margin: 0; font-size: 14pt; color: #1e3a5f; letter-spacing: 0.5px;">Avviso di compensazione</h2>
        <div style="font-size: 9pt; color: #444; margin-top: 3px;">Registrazione n. {{ $a['incasso']['numero'] ?? '' }} del {{ $giorno($a['incasso']['data'] ?? null) }}</div>
    </div>

    <div style="font-size: 10pt; line-height: 1.5; margin-bottom: 12px;">
        Il {{ $giorno($a['incasso']['data'] ?? null) }} {{ $credito }} è stato usato per le rate qui sotto, senza un versamento nuovo: per un totale di
        <strong>{{ $euro((int) ($a['importo'] ?? 0)) }}</strong>.
    </div>

    <table style="width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 6px;">
        <thead>
            <tr style="background: #eef2f7; color: #1e3a5f;">
                <th style="text-align: left; padding: 4px; border-bottom: 1px solid #c0ccd8;">Unità</th>
                <th style="text-align: left; padding: 4px; border-bottom: 1px solid #c0ccd8;">Rata</th>
                <th style="text-align: left; padding: 4px; border-bottom: 1px solid #c0ccd8;">Scadenza</th>
                <th style="text-align: right; padding: 4px; border-bottom: 1px solid #c0ccd8;">Coperto dal credito</th>
            </tr>
        </thead>
        <tbody>
            @foreach((array) ($a['quote'] ?? []) as $q)
                <tr>
                    <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef;">{{ $q['unita'] ?? '' }}</td>
                    <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef;">{{ $q['rata'] ?? '' }}{{ filled($q['piano'] ?? null) ? ' — ' . $q['piano'] : '' }}</td>
                    <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef;">{{ $giorno($q['scadenza'] ?? null) }}</td>
                    <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef; text-align: right; white-space: nowrap;">{{ $euro((int) ($q['importo'] ?? 0)) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="3" style="padding: 4px; text-align: right; font-weight: bold;">Totale</td>
                <td style="padding: 4px; text-align: right; font-weight: bold; white-space: nowrap;">{{ $euro((int) ($a['importo'] ?? 0)) }}</td>
            </tr>
        </tbody>
    </table>

    <table style="width: 100%; border-collapse: collapse; font-size: 9pt; margin-top: 10px;">
        <tr>
            <td style="width: 38mm; padding: 3px 0; vertical-align: top; color: #555;">Imposta di bollo</td>
            <td style="padding: 3px 0; vertical-align: top;"><strong>{{ $statiBollo[$a['bollo'] ?? ''] ?? '—' }}</strong></td>
        </tr>
    </table>

    @if(!empty($firmaAvviso))
        <div style="margin-top: 24px; text-align: right; page-break-inside: avoid;">
            <div style="font-size: 10pt; margin-bottom: 8px; color: #1e3a5f;">L'Amministratore</div>
            <img src="{{ $firmaAvviso }}" style="max-width: 180px; max-height: 80px;">
        </div>
    @endif
@endforeach
@endsection
