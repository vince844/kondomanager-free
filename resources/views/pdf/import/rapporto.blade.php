@extends('pdf.base')

@section('title', 'Rapporto di importazione')

@section('content')
    {{--
        Il documento di consegna della migrazione. Non è una ricevuta: è la risposta scritta a
        «cosa è entrato, cosa no, e cosa resta da guardare» — la domanda che l'amministratore si
        sente fare dal successore, dal revisore o dall'assemblea.
    --}}
    <h1 style="font-size: 14pt; margin: 0 0 2mm;">Rapporto di importazione</h1>

    <p style="font-size: 9pt; color: #555; margin: 0 0 6mm;">
        {{ $lotto->condominio?->nome ?? 'Condominio non determinato' }}
        @if($lotto->completato_at) &middot; {{ $lotto->completato_at->format('d/m/Y H:i') }} @endif
        &middot; {{ $creati }} record creati
        <br>
        Riferimento lotto <span style="font-family: monospace;">{{ $lotto->uuid }}</span>
        &middot; origine: {{ $lotto->sorgente }}
    </p>

    {{--
        Tre stati, non due. `quadra` è `false` sia quando lo scarto non è zero sia quando **non
        è mai stato calcolato** (il riparto non portava la riga «TOTALE COMPLESSIVO»): con due
        soli rami, un file senza totale usciva rosso con scritto «non quadrano» in grassetto —
        cioè «verificato e sbagliato» al posto di «non ho potuto verificare».
        È la differenza fra un documento che accusa e uno che dichiara un limite, su un foglio
        che l'amministratore può allegare a un verbale.
    --}}
    @if($saldi)
        @php
            $verificabile = $saldi['scarto'] !== null;
            $sfondo = ! $verificabile ? '#fbf7ec' : ($saldi['quadra'] ? '#eefaf1' : '#fdecec');
            $bordo  = ! $verificabile ? '#dcc99a' : ($saldi['quadra'] ? '#9ad6ae' : '#e59b9b');
        @endphp
        <table style="width: 100%; margin-bottom: 6mm; font-size: 9pt;">
            <tr>
                <td style="padding: 2mm 3mm; background: {{ $sfondo }}; border: 1px solid {{ $bordo }};">
                    <strong>Quadratura dei saldi di apertura:</strong>
                    @if(! $verificabile)
                        <strong>non verificabile.</strong> Il riparto caricato non portava la riga
                        del totale complessivo, quindi non c&rsquo;era alcun numero con cui
                        confrontare la somma delle posizioni importate. Va confrontata a mano con
                        l&rsquo;ultimo rendiconto approvato, prima di emettere il primo piano rate.
                    @elseif($saldi['quadra'])
                        scarto {{ $saldi['scarto'] }} &mdash; la somma delle posizioni importate
                        coincide con il totale scritto nel riparto di origine.
                    @else
                        scarto {{ $saldi['scarto'] }} &mdash; <strong>non quadrano.</strong>
                    @endif
                </td>
            </tr>
        </table>
    @endif

    <h2 style="font-size: 11pt; margin: 0 0 2mm;">Cosa è entrato</h2>

    <table style="width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 6mm;">
        <thead>
            <tr style="background: #f2f5f8;">
                <th style="text-align: left; padding: 1.5mm 2mm; border-bottom: 1px solid #c0ccd8;">Livello</th>
                <th style="text-align: right; padding: 1.5mm 2mm; border-bottom: 1px solid #c0ccd8;">Creati</th>
                <th style="text-align: right; padding: 1.5mm 2mm; border-bottom: 1px solid #c0ccd8;">Uniti</th>
                <th style="text-align: right; padding: 1.5mm 2mm; border-bottom: 1px solid #c0ccd8;">Saltati</th>
                <th style="text-align: left; padding: 1.5mm 2mm; border-bottom: 1px solid #c0ccd8;">Esito</th>
            </tr>
        </thead>
        <tbody>
            @foreach($livelli as $l)
                <tr>
                    <td style="padding: 1.5mm 2mm; border-bottom: 1px solid #e6ebf0;">{{ $l['etichetta'] }}</td>
                    <td style="padding: 1.5mm 2mm; border-bottom: 1px solid #e6ebf0; text-align: right;">{{ $l['creati'] }}</td>
                    <td style="padding: 1.5mm 2mm; border-bottom: 1px solid #e6ebf0; text-align: right;">{{ $l['uniti'] }}</td>
                    <td style="padding: 1.5mm 2mm; border-bottom: 1px solid #e6ebf0; text-align: right;">{{ $l['saltati'] }}</td>
                    <td style="padding: 1.5mm 2mm; border-bottom: 1px solid #e6ebf0;">
                        @if(! $l['riuscito']) fermato
                        @elseif($l['gia_a_posto']) era già a posto
                        @else completo
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if(count($controlli))
        <h2 style="font-size: 11pt; margin: 0 0 1mm;">Da controllare</h2>
        <p style="font-size: 8pt; color: #666; margin: 0 0 2mm;">
            Nessuna di queste ha impedito l'importazione. Lo stato è quello di oggi:
            le voci che il sistema sa verificare si chiudono da sole quando il problema sparisce.
        </p>

        <table style="width: 100%; border-collapse: collapse; font-size: 8.5pt;">
            @foreach($controlli as $c)
                <tr>
                    <td style="padding: 2mm; border-bottom: 1px solid #e6ebf0; vertical-align: top;">
                        <strong>{{ $c['titolo'] }}</strong>
                        @if($c['rimedio'])
                            <br><span style="color: #555;">{{ $c['rimedio'] }}</span>
                        @endif
                        @if($c['titolo'] !== $c['messaggio_originale'])
                            <br><span style="color: #888; font-size: 7.5pt;">
                                All'importazione: {{ $virgolette($c['messaggio_originale']) }}
                            </span>
                        @endif
                    </td>
                    <td style="padding: 2mm; border-bottom: 1px solid #e6ebf0; text-align: right;
                               vertical-align: top; white-space: nowrap; width: 28mm;">
                        {{ $etichetteStato[$c['stato']] ?? $c['stato'] }}
                    </td>
                </tr>
            @endforeach
        </table>
    @endif
@endsection
