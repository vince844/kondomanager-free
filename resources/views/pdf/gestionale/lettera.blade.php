@extends('pdf.base')

{{--
  Le lettere ai condòmini: promemoria, sollecito, diffida, messa in mora. Ognuna comincia su una pagina nuova, anche quando il PDF ne porta
  molte. Ogni voce di `$lettere` è l'istantanea della lettera (`EmissioneLettere::voceDallaRiga()`, in centesimi): il tipo e il suo nome, che
  è il titolo; la persona com'era; il testo con i campi già riempiti; le righe con il residuo e il ritardo di allora; il totale; il termine;
  l'IBAN; il giorno della situazione. Di una rata coperta in parte da un accordo di rientro la riga dice la parte fuori dall'accordo
  (`da_versare`); la parte in accordo non è nella lettera.

  L'impaginazione è quella di una lettera: in alto a sinistra lo studio che scrive (se le impostazioni di stampa lo dicono) e il condominio, a
  destra il destinatario, a 112 mm dal bordo, per la busta a finestra. Il layout non scrive la sua intestazione né «Documento emesso il …»:
  la data sta sotto la testata. La firma: l'immagine per promemoria e sollecito; per diffida e messa in mora lo spazio per firmare a mano.
  `etichetta` ('ANTEPRIMA' o 'PROVA') dice in testa che il PDF non è una lettera mandata.
--}}
@php
    $firmaLettera = $firma_stampe_absolute_path ?? null;
    $firma_stampe_absolute_path = null;
    $intestazione_propria = true;
    $piede_senza_data = true;
    $euro = fn (int $cents) => '€ ' . number_format($cents / 100, 2, ',', '.');
    $giorno = fn (?string $data) => $data ? \Carbon\Carbon::parse($data)->format('d/m/Y') : '';
    $studio = $studio ?? ['nome' => null, 'indirizzo' => null, 'email' => null, 'pec' => null, 'telefono' => null];
    $righeStudio = array_values(array_filter([
        $studio['indirizzo'] ?? null,
        filled($studio['telefono'] ?? null) ? 'Tel. ' . $studio['telefono'] : null,
        filled($studio['email'] ?? null) ? 'Email ' . $studio['email'] : null,
        filled($studio['pec'] ?? null) ? 'PEC ' . $studio['pec'] : null,
    ], 'filled'));
    $condominioVia = trim((string) ($condominio->indirizzo ?? ''));
    $condominioCitta = trim(implode(' ', array_filter([$condominio->cap ?? null, $condominio->comune ?? null, filled($condominio->provincia ?? null) ? '(' . $condominio->provincia . ')' : null], 'filled')));
@endphp

@section('title', count($lettere) === 1 ? ($lettere[0]['titolo'] ?? 'Lettera') . ' — ' . ($lettere[0]['persona']['nome'] ?? '') : 'Lettere ai condòmini')

@section('content')
@foreach($lettere as $i => $l)
    @if($i > 0)
        <pagebreak resetpagenum="1" />
    @endif
    @php
        $promemoria = ($l['tipo'] ?? '') === 'promemoria';
        $aMano = in_array($l['tipo'] ?? '', ['diffida', 'messa_in_mora'], true);
        // La riga a parte del termine: solo se il testo non usa già [termine] (la scrive la voce della lettera).
        $termine = $l['riga_del_termine'] ?? null;
        $luogoData = trim(($l['luogo'] ? $l['luogo'] . ', ' : '') . $l['emessa_il']);
        $ibanTesto = filled($l['iban'] ?? null) ? 'IBAN ' . $l['iban'] . (filled($l['intestatario'] ?? null) ? ', intestato a ' . $l['intestatario'] : '') : null;
    @endphp

    @if(!empty($l['etichetta']))
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 8px;">
            <tr>
                <td style="padding: 6px 10px; border: 1.5px dashed #9c4221; color: #9c4221; font-size: 11pt; font-weight: bold; text-align: center;">{{ $l['etichetta'] }} — non è una lettera mandata</td>
            </tr>
        </table>
    @endif

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px;">
        <tr>
            <td style="width: 100mm; padding: 0; vertical-align: top;">
                @if(filled($studio['nome'] ?? null))
                    <div style="font-size: 12pt; font-weight: bold; color: #1e3a5f; line-height: 1.25;">{{ $studio['nome'] }}</div>
                    <div style="font-size: 8.5pt; color: #333; margin-top: 2px; line-height: 1.4;">
                        {!! implode('<br>', array_map('e', $righeStudio)) !!}
                    </div>
                    <div style="font-size: 9pt; color: #333; margin-top: 6px; line-height: 1.4;">
                        Amministratore del <strong>{{ \App\Services\Gestionale\Lettere\TestiLettere::condominioConNome((string) $condominio->nome) }}</strong>
                        @if($condominioVia !== '')<br>{{ $condominioVia }}@endif
                        @if($condominioCitta !== '')<br>{{ $condominioCitta }}@endif
                    </div>
                @else
                    <div style="font-size: 12pt; font-weight: bold; color: #1e3a5f; line-height: 1.25;">{{ $condominio->nome }}</div>
                    <div style="font-size: 9pt; color: #333; margin-top: 2px; line-height: 1.45;">
                        @if($condominioVia !== ''){{ $condominioVia }}<br>@endif
                        @if($condominioCitta !== ''){{ $condominioCitta }}<br>@endif
                        @if(filled($condominio->codice_fiscale ?? null))C.F. {{ $condominio->codice_fiscale }}@endif
                    </div>
                @endif
            </td>
            <td style="padding: 0; vertical-align: top;">
                <div style="font-size: 11pt; font-weight: bold; color: #222; line-height: 1.3;">{{ $l['persona']['nome'] ?? '' }}</div>
                @if(filled($l['persona']['indirizzo'] ?? null))
                    <div style="font-size: 9.5pt; color: #333; margin-top: 2px; line-height: 1.4;">{{ $l['persona']['indirizzo'] }}</div>
                @endif
            </td>
        </tr>
    </table>

    <div style="font-size: 9pt; color: #444; margin-bottom: 8px;">{{ $luogoData }}</div>

    <div style="border-bottom: 2px solid #1e3a5f; padding-bottom: 6px; margin-bottom: 10px;">
        <h2 style="margin: 0; font-size: 14pt; color: #1e3a5f; letter-spacing: 0.5px;">{{ $l['titolo'] }}</h2>
        {{-- Il nome con «Condominio» davanti una volta sola: un nome che comincia già con la parola non la ripete. --}}
        <div style="font-size: 9pt; color: #444; margin-top: 3px;">{{ $condominio_nel_titolo ?? \App\Services\Gestionale\Lettere\TestiLettere::condominioConNome((string) $condominio->nome) }}</div>
    </div>

    @if(filled($l['testo']))
        <div style="font-size: 10pt; line-height: 1.5; margin-bottom: 12px;">{!! nl2br(e($l['testo'])) !!}</div>
    @endif

    <table style="width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 6px;">
        <thead>
            <tr style="background: #eef2f7; color: #1e3a5f;">
                <th style="text-align: left; padding: 4px; border-bottom: 1px solid #c0ccd8;">Unità</th>
                <th style="text-align: left; padding: 4px; border-bottom: 1px solid #c0ccd8;">Rata</th>
                <th style="text-align: left; padding: 4px; border-bottom: 1px solid #c0ccd8;">Scadenza</th>
                <th style="text-align: right; padding: 4px; border-bottom: 1px solid #c0ccd8;">Da versare</th>
                @if(!$promemoria)
                    <th style="text-align: left; padding: 4px; border-bottom: 1px solid #c0ccd8;">Ritardo</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($l['righe'] as $r)
                @php
                    $nomeRata = ((int) ($r['numero_rata'] ?? 0)) === 0 ? 'Rata 0 (pregresso)' : 'Rata ' . $r['numero_rata'];
                    $nomeRata .= filled($r['piano'] ?? null) ? ' — ' . $r['piano'] : '';
                @endphp
                <tr>
                    <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef;">{{ $r['unita'] ?? '' }}</td>
                    <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef;">{{ $nomeRata }}</td>
                    <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef;">{{ $giorno($r['scadenza'] ?? null) }}</td>
                    <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef; text-align: right; white-space: nowrap;">{{ $euro((int) ($r['da_versare'] ?? $r['residuo'] ?? 0)) }}</td>
                    @if(!$promemoria)
                        <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef;">{{ $r['ritardo'] ?? '' }}</td>
                    @endif
                </tr>
            @endforeach
            <tr>
                <td colspan="3" style="padding: 4px; text-align: right; font-weight: bold;">Totale</td>
                <td style="padding: 4px; text-align: right; font-weight: bold; white-space: nowrap;">{{ $euro((int) $l['totale']) }}</td>
                @if(!$promemoria)<td></td>@endif
            </tr>
        </tbody>
    </table>
    <div style="font-size: 8pt; color: #555; margin-bottom: 10px;">Situazione al {{ $giorno($l['alla']) }}: i versamenti registrati dopo non sono compresi.</div>

    @if($termine !== null)
        <div style="font-size: 9.5pt; margin-bottom: 6px;">Termine per il versamento: <strong>{{ $termine }}</strong>.</div>
    @endif

    @if($ibanTesto !== null)
        <table style="width: 100%; border-collapse: collapse; font-size: 9pt; margin-top: 4px;">
            <tr>
                <td style="padding: 6px 8px; border: 1px solid #d1dbe8; background: #f9fafb;">
                    <span style="font-size: 7pt; font-weight: bold; text-transform: uppercase; color: #888;">Per il bonifico</span><br>
                    {{ $ibanTesto }}
                </td>
            </tr>
        </table>
    @endif

    @if($aMano)
        <div style="margin-top: 32px; text-align: right; page-break-inside: avoid;">
            <div style="font-size: 10pt; margin-bottom: 30px; color: #1e3a5f;">L'Amministratore</div>
            <div style="font-size: 8pt; color: #666;">Firma dell'amministratore ______________________________</div>
        </div>
    @elseif(!empty($firmaLettera))
        <div style="margin-top: 24px; text-align: right; page-break-inside: avoid;">
            <div style="font-size: 10pt; margin-bottom: 8px; color: #1e3a5f;">L'Amministratore</div>
            <img src="{{ $firmaLettera }}" style="max-width: 180px; max-height: 80px;">
        </div>
    @else
        <div style="margin-top: 24px; text-align: right; font-size: 10pt; color: #1e3a5f;">L'Amministratore</div>
    @endif
@endforeach
@endsection
