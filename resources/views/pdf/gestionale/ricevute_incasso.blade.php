@extends('pdf.base')

{{--
  La ricevuta dell'incasso (decisioni 197, 200 e 202): ognuna comincia su una pagina nuova, anche quando il PDF ne porta molte. Ogni voce di `$ricevute` ha il
  contenuto (`RicevutaIncasso::per()`, o quello dell'originale per una copia, in centesimi), le scelte dell'emissione (bollo e come è stato
  assolto, nota, «Stampa la causale»), la data di emissione e, per la posta, se in alto a destra va l'indirizzo della persona.

  `etichetta` ('ANTEPRIMA' o 'PROVA', decisione 204) dice in testa che il PDF non è una ricevuta: non è stato emesso, e non ha un numero di emissione.

  La firma dell'amministratore va su ogni ricevuta, non una volta in fondo al documento: il layout la stampa alla fine del contenuto, quindi
  qui la si prende per sé e la si toglie al layout.

  L'impaginazione è quella di una lettera: in alto a sinistra chi emette la ricevuta (il condominio, con indirizzo e codice fiscale), in alto
  a destra a chi è intestata (quando la ricevuta va per posta), subito sotto il titolo. Il condominio compare così su ogni ricevuta, non in
  un'intestazione ripetuta su ogni pagina del PDF: il layout non scrive la sua (`$intestazione_propria`), e nel piè di pagina non scrive «Documento
  emesso il …» (`$piede_senza_data`), perché la data di emissione sta già sotto il numero.
--}}
@php
    $firmaRicevuta = $firma_stampe_absolute_path ?? null;
    $firma_stampe_absolute_path = null;
    $intestazione_propria = true;
    $piede_senza_data = true;
    $euro = fn (int $cents) => '€ ' . number_format($cents / 100, 2, ',', '.');
    $giorno = fn (?string $data) => $data ? \Carbon\Carbon::parse($data)->format('d/m/Y') : '—';
    $statiBollo = ['assolta' => 'Assolta', 'non_dovuta' => 'Non dovuta', 'da_assolvere' => 'Da assolvere'];
    // Come è stato assolto il bollo: «— contrassegno n. …» (un numero per ricevuta) o «in modo virtuale — …» (l'autorizzazione).
    $comeAssolta = fn (?string $modo, ?string $estremi) => match ($modo) {
        'contrassegno' => ' — contrassegno' . (filled($estremi) ? ' n. ' . $estremi : ''),
        'virtuale'     => ' in modo virtuale' . (filled($estremi) ? ' — ' . $estremi : ''),
        default        => filled($estremi) ? ' — ' . $estremi : '',
    };
@endphp

@section('title', count($ricevute) === 1 ? 'Ricevuta di pagamento ' . ($ricevute[0]['contenuto']['numero'] ?? '') : 'Ricevute di pagamento')

@section('content')
@foreach($ricevute as $i => $voce)
    @php $r = $voce['contenuto']; @endphp
    @if($i > 0)
        <pagebreak resetpagenum="1" />
    @endif

    @php
        // Chi emette la ricevuta: il condominio. Il nome, l'indirizzo e il codice fiscale vengono dal condominio (come le altre stampe), con ripiego
        // sul contenuto della ricevuta quando la vista è chiamata senza.
        $mittenteNome = $condominio->nome ?? ($r['condominio']['nome'] ?? null);
        $mittenteCf = $condominio->codice_fiscale ?? ($r['condominio']['codice_fiscale'] ?? null);
        $mittenteVia = isset($condominio) ? trim((string) $condominio->indirizzo) : '';
        $mittenteCitta = isset($condominio)
            ? trim(implode(' ', array_filter([$condominio->cap, $condominio->comune, filled($condominio->provincia) ? '(' . $condominio->provincia . ')' : null], 'filled')))
            : trim((string) ($r['condominio']['indirizzo'] ?? ''));
        $conIndirizzo = !empty($voce['indirizzo_per_posta']) && !empty($r['posizione']);
    @endphp

    @if(!empty($voce['etichetta']))
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 8px;">
            <tr>
                <td style="padding: 6px 10px; border: 1.5px dashed #9c4221; color: #9c4221; font-size: 11pt; font-weight: bold; text-align: center;">{{ $voce['etichetta'] }} — non è una ricevuta</td>
            </tr>
        </table>
    @endif

    {{-- La testata: a sinistra il condominio che emette, a destra a chi è intestata la ricevuta (solo se va per posta). La cella di sinistra è larga
         100 mm: il destinatario comincia a 112 mm dal bordo sinistro del foglio, a destra, dove si legge un indirizzo. --}}
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px;">
        <tr>
            <td style="width: 100mm; padding: 0; vertical-align: top;">
                @if(filled($mittenteNome))
                    <div style="font-size: 12pt; font-weight: bold; color: #1e3a5f; line-height: 1.25;">{{ $mittenteNome }}</div>
                @endif
                <div style="font-size: 9pt; color: #333; margin-top: 2px; line-height: 1.45;">
                    @if(filled($mittenteVia)){{ $mittenteVia }}<br>@endif
                    @if(filled($mittenteCitta)){{ $mittenteCitta }}<br>@endif
                    @if(filled($mittenteCf))C.F. {{ $mittenteCf }}@endif
                </div>
            </td>
            <td style="padding: 0; vertical-align: top;">
                @if($conIndirizzo)
                    <div style="font-size: 9pt; color: #555; line-height: 1.25;">Gentile</div>
                    <div style="font-size: 11pt; font-weight: bold; color: #222; line-height: 1.3;">{{ $r['posizione']['nome'] }}</div>
                    @if(filled($r['posizione']['indirizzo'] ?? null))
                        <div style="font-size: 9.5pt; color: #333; margin-top: 2px; line-height: 1.4;">{{ $r['posizione']['indirizzo'] }}</div>
                    @endif
                @endif
            </td>
        </tr>
    </table>

    <div style="border-bottom: 2px solid #1e3a5f; padding-bottom: 8px; margin-bottom: 10px;">
        <h2 style="margin: 0; font-size: 14pt; color: #1e3a5f; letter-spacing: 0.5px;">
            RICEVUTA DI PAGAMENTO
            @if(!empty($voce['copia_del']))<span style="color: #9c4221;"> — COPIA</span>@endif
        </h2>
        <div style="font-size: 9pt; color: #444; margin-top: 3px;">
            Numero <strong>{{ $r['numero'] }}</strong>
            @if(!empty($voce['etichetta']))
                &nbsp;|&nbsp; <strong>Non emessa</strong>
            @else
                &nbsp;|&nbsp; Emessa il <strong>{{ $voce['emessa_il'] }}</strong>
            @endif
            @if(!empty($voce['copia_del']))
                &nbsp;|&nbsp; L'originale è del <strong>{{ $voce['copia_del'] }}</strong>
            @endif
        </div>
    </div>

    @if($r['annullato'])
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;">
            <tr>
                <td style="padding: 8px 10px; border: 1.5px solid #c53030; background: #fff5f5; color: #9b2c2c; font-size: 10pt;">
                    @php $dello = !empty($r['storno']) ? ' il ' . $giorno($r['storno']['data']) . ' con lo storno n. ' . $r['storno']['protocollo'] : ''; @endphp
                    <strong>INCASSO ANNULLATO</strong>
                    Questo incasso è stato annullato{{ $dello }}: le quote qui sotto non risultano più pagate con questo incasso.
                </td>
            </tr>
        </table>
    @endif

    <table style="width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 10px;">
        <tr>
            <td style="width: 38mm; padding: 3px 0; vertical-align: top; color: #555;">Data del versamento</td>
            <td style="padding: 3px 0; vertical-align: top;"><strong>{{ $giorno($r['data_versamento']) }}</strong></td>
        </tr>
        @if(!empty($r['versato_da']))
            <tr>
                <td style="width: 38mm; padding: 3px 0; vertical-align: top; color: #555;">Ricevuto da</td>
                <td style="padding: 3px 0; vertical-align: top;"><strong>{{ $r['versato_da']['nome'] }}</strong></td>
            </tr>
            <tr>
                <td style="width: 38mm; padding: 3px 0; vertical-align: top; color: #555;">Per conto di</td>
                <td style="padding: 3px 0; vertical-align: top;">
                    <strong>{{ $r['posizione']['nome'] ?? '—' }}</strong>
                    @if(!empty($r['posizione']['codice_fiscale']))<br>C.F. {{ $r['posizione']['codice_fiscale'] }}@endif
                </td>
            </tr>
        @else
            <tr>
                <td style="width: 38mm; padding: 3px 0; vertical-align: top; color: #555;">Ricevuto da</td>
                <td style="padding: 3px 0; vertical-align: top;">
                    <strong>{{ $r['posizione']['nome'] ?? '—' }}</strong>
                    @if(!empty($r['posizione']['codice_fiscale']))<br>C.F. {{ $r['posizione']['codice_fiscale'] }}@endif
                </td>
            </tr>
        @endif
        <tr>
            <td style="width: 38mm; padding: 3px 0; vertical-align: top; color: #555;">Importo versato</td>
            <td style="padding: 3px 0; vertical-align: top; font-size: 11pt;"><strong>{{ $euro($r['importo_versato']) }}</strong></td>
        </tr>
        @if(!empty($r['cassa']))
            <tr>
                <td style="width: 38mm; padding: 3px 0; vertical-align: top; color: #555;">Entrato in</td>
                <td style="padding: 3px 0; vertical-align: top;">{{ $r['cassa']['nome'] }} ({{ $r['cassa']['tipo'] }})</td>
            </tr>
        @endif
        @if(!empty($r['gestione']))
            <tr>
                <td style="width: 38mm; padding: 3px 0; vertical-align: top; color: #555;">Gestione</td>
                <td style="padding: 3px 0; vertical-align: top;">{{ $r['gestione'] }}</td>
            </tr>
        @endif
        {{-- La causale è un testo libero e può portare note interne: si stampa solo se l'amministratore lo sceglie (decisione 202). --}}
        @if(!empty($voce['stampa_causale']) && filled($r['causale'] ?? null))
            <tr>
                <td style="width: 38mm; padding: 3px 0; vertical-align: top; color: #555;">Causale</td>
                <td style="padding: 3px 0; vertical-align: top;">{{ $r['causale'] }}</td>
            </tr>
        @endif
    </table>

    <div style="font-size: 8pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; color: #1e3a5f; border-bottom: 1px solid #cbd5e0; padding-bottom: 3px; margin-bottom: 4px;">
        {{ $r['credito_usato'] > 0 ? 'Quote a cui sono imputati il versamento e il credito usato' : 'Quote a cui è imputato il versamento' }}
    </div>
    {{-- Titoli brevi, come nella finestra; la rata è la colonna più larga, gli importi sono quattro colonne uguali, allineate a destra.
         Una tabella per piano e gestione (decisione 206.1): il loro nome una volta sola, e sotto i titoli delle colonne, così si leggono come
         colonne di quel piano; tutti e due nel thead, che mPDF ripete in cima alla pagina dopo quando il gruppo va a capo. Nelle righe il nome
         breve della rata. Il totale sta in una tabella a sé, con le stesse larghezze, perché un tfoot si ripeterebbe in fondo a ogni pagina. --}}
    @forelse(collect($r['quote'])->groupBy(fn ($q) => ($q['piano'] ?? '') . '|' . ($q['gestione'] ?? ''))->values() as $gruppo)
        @php $primaDelGruppo = $gruppo->first(); @endphp
        <table style="width: 100%; border-collapse: collapse; font-size: 8pt;{{ $loop->first ? '' : ' margin-top: 6px;' }}">
            <thead>
                @if(filled($primaDelGruppo['piano'] ?? null) || filled($primaDelGruppo['gestione'] ?? null))
                    <tr>
                        <td colspan="7" style="padding: 0 4px 3px; color: #1e3a5f; font-weight: bold;">{{ implode(' — ', array_filter([$primaDelGruppo['piano'] ?? null, $primaDelGruppo['gestione'] ?? null], 'filled')) }}</td>
                    </tr>
                @endif
                <tr style="background: #eef2f8;">
                    <th style="width: 25%; padding: 4px; text-align: left; border-bottom: 1px solid #b8c5d6;">Rata</th>
                    <th style="width: 15%; padding: 4px; text-align: left; border-bottom: 1px solid #b8c5d6;">Unità</th>
                    <th style="width: 13%; padding: 4px; text-align: left; border-bottom: 1px solid #b8c5d6;">Scadenza</th>
                    <th style="width: 11%; padding: 4px; text-align: right; border-bottom: 1px solid #b8c5d6;">Quota</th>
                    <th style="width: 12%; padding: 4px; text-align: right; border-bottom: 1px solid #b8c5d6;">Già versato</th>
                    <th style="width: 12%; padding: 4px; text-align: right; border-bottom: 1px solid #b8c5d6;">Imputato</th>
                    <th style="width: 12%; padding: 4px; text-align: right; border-bottom: 1px solid #b8c5d6;">Resta</th>
                </tr>
            </thead>
            <tbody>
                @foreach($gruppo as $q)
                @php $bordoRiga = ($q['riaddebito'] ?? 0) > 0 ? 'none' : '1px solid #edf2f7'; @endphp
                <tr>
                    <td style="padding: 4px; border-bottom: {{ $bordoRiga }}; vertical-align: top;">
                        <strong>{{ \App\Services\Gestionale\Ricevute\RicevutaIncasso::nomeBreve((string) $q['rata'], $q['piano'] ?? null) }}</strong>
                    </td>
                    <td style="padding: 4px; border-bottom: {{ $bordoRiga }}; vertical-align: top;">{{ $q['unita'] ?? '—' }}</td>
                    <td style="padding: 4px; border-bottom: {{ $bordoRiga }}; vertical-align: top;">{{ $giorno($q['scadenza']) }}</td>
                    <td style="padding: 4px; border-bottom: {{ $bordoRiga }}; text-align: right; vertical-align: top; white-space: nowrap;">{{ $euro($q['importo']) }}</td>
                    <td style="padding: 4px; border-bottom: {{ $bordoRiga }}; text-align: right; vertical-align: top; white-space: nowrap;">{{ $euro($q['prima']) }}</td>
                    <td style="padding: 4px; border-bottom: {{ $bordoRiga }}; text-align: right; vertical-align: top; white-space: nowrap;">
                        <strong>{{ $euro($q['imputato']) }}</strong>
                        @if(($q['dal_credito'] ?? 0) > 0)<br><span style="color: #666;">di cui dal credito {{ $euro($q['dal_credito']) }}</span>@endif
                    </td>
                    <td style="padding: 4px; border-bottom: {{ $bordoRiga }}; text-align: right; vertical-align: top; white-space: nowrap;">{{ $euro($q['dopo']) }}</td>
                </tr>
                @if(($q['riaddebito'] ?? 0) > 0)
                    <tr>
                        <td colspan="7" style="padding: 0 4px 4px; border-bottom: 1px solid #edf2f7; color: #9c4221;">Prima di questo versamento sulla quota erano tornati a debito {{ $euro($q['riaddebito']) }}: il suo credito era stato usato, poi il versamento che lo aveva prodotto è stato annullato.</td>
                    </tr>
                @endif
                @endforeach
            </tbody>
        </table>
    @empty
        <table style="width: 100%; border-collapse: collapse; font-size: 8pt;">
            <tr>
                <td style="padding: 8px; text-align: center; color: #888;">Il versamento non è imputato a nessuna quota.</td>
            </tr>
        </table>
    @endforelse
    @if(count($r['quote']) > 1)
        <table style="width: 100%; border-collapse: collapse; font-size: 8pt;">
            <tr style="background: #f5f7fa;">
                <td style="width: 53%; padding: 4px; font-weight: bold;">Totale</td>
                <td style="width: 11%; padding: 4px; text-align: right; font-weight: bold; white-space: nowrap;">{{ $euro($r['totali']['importo']) }}</td>
                <td style="width: 12%; padding: 4px; text-align: right; font-weight: bold; white-space: nowrap;">{{ $euro($r['totali']['prima']) }}</td>
                <td style="width: 12%; padding: 4px; text-align: right; font-weight: bold; white-space: nowrap;">{{ $euro($r['totali']['imputato']) }}</td>
                <td style="width: 12%; padding: 4px; text-align: right; font-weight: bold; white-space: nowrap;">{{ $euro($r['totali']['dopo']) }}</td>
            </tr>
        </table>
    @endif

    @if($r['credito_usato'] > 0 || $r['eccedenza'] > 0)
        <table style="width: 100%; border-collapse: collapse; font-size: 8.5pt; margin-top: 8px;">
            @if($r['credito_usato'] > 0)
                <tr>
                    <td style="width: 38mm; padding: 3px 0; vertical-align: top; color: #555;">Credito usato</td>
                    <td style="padding: 3px 0; vertical-align: top;">
                        <strong>{{ $euro($r['credito_usato']) }}</strong>
                        @foreach($r['crediti'] as $c)
                            <br><span style="color: #666;">{{ $euro($c['importo']) }} dal credito {{ !empty($c['persona']) ? 'di ' . $c['persona'] . ', sulla' : 'della' }} {{ $c['rata'] }}</span>
                        @endforeach
                    </td>
                </tr>
            @endif
            @if($r['eccedenza'] > 0)
                {{-- La parte in più: a credito, o, sulla rata di chi ha versato per un altro, quanto la riduce (senza il suo residuo, che è un dato suo). --}}
                <tr>
                    <td style="width: 38mm; padding: 3px 0; vertical-align: top; color: #555;">Parte in più</td>
                    <td style="padding: 3px 0; vertical-align: top;">
                        <strong>{{ $euro($r['eccedenza']) }}</strong>
                        @foreach($r['eccedenze'] as $e)
                            @if(($e['riduce'] ?? 0) > 0)
                                <br><span style="color: #666;">{{ $euro($e['riduce']) }} a {{ $e['persona'] ?? '—' }}: riduce la sua {{ $e['rata'] }}</span>
                            @endif
                            @if(($e['a_credito'] ?? 0) > 0)
                                <br><span style="color: #666;">{{ $euro($e['a_credito']) }} a credito di {{ $e['persona'] ?? '—' }}@if(!empty($e['rata'])), sulla {{ $e['rata'] }}@endif</span>
                            @endif
                        @endforeach
                    </td>
                </tr>
            @endif
        </table>
    @endif

    @if(!empty($voce['nota']))
        <table style="width: 100%; border-collapse: collapse; font-size: 8.5pt; margin-top: 8px;">
            <tr>
                <td style="padding: 6px 8px; border: 1px solid #d1dbe8; background: #f9fafb;">
                    <span style="font-size: 7pt; font-weight: bold; text-transform: uppercase; color: #888;">Nota</span><br>
                    {!! nl2br(e($voce['nota'])) !!}
                </td>
            </tr>
        </table>
    @endif

    <table style="width: 100%; border-collapse: collapse; font-size: 9pt; margin-top: 10px;">
        <tr>
            <td style="width: 38mm; padding: 3px 0; vertical-align: top; color: #555;">Imposta di bollo</td>
            <td style="padding: 3px 0; vertical-align: top;">
                <strong>{{ $statiBollo[$voce['bollo'] ?? ''] ?? (!empty($voce['etichetta']) ? 'Da scegliere' : '—') }}</strong>{{ ($voce['bollo'] ?? null) === 'assolta' ? $comeAssolta($voce['modo_bollo'] ?? null, $voce['estremi_bollo'] ?? null) : '' }}
            </td>
        </tr>
    </table>

    @if(!empty($firmaRicevuta))
        <div style="margin-top: 24px; text-align: right; page-break-inside: avoid;">
            <div style="font-size: 10pt; margin-bottom: 8px; color: #1e3a5f;">L'Amministratore</div>
            <img src="{{ $firmaRicevuta }}" style="max-width: 180px; max-height: 80px;">
        </div>
    @endif
@endforeach
@endsection
