{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
@extends('pdf.base')

@section('title', 'Distinta di Pagamento')

@section('content')

    <div style="text-align: right; margin-bottom: 20px; color: #555;">
        Data di stampa: {{ \App\Helpers\DateHelper::adessoUtente()->format('d/m/Y H:i') }}
    </div>

    <h1>Distinta di Pagamento Fornitore</h1>

    <div class="section">
        <div class="section-title">Dati del Pagamento</div>
        <table class="grid">
            <tr>
                <td class="label">Data pagamento:</td>
                <td class="value">{{ $pagamento->data_pagamento?->format('d/m/Y') }}</td>
            </tr>
            <tr>
                <td class="label">Protocollo contabile:</td>
                <td class="value text-bold">{{ $scrittura->numero_protocollo ?? 'N.D.' }}</td>
            </tr>
            <tr>
                <td class="label">Metodo di pagamento:</td>
                <td class="value">{{ $pagamento->metodo_pagamento->label() }}</td>
            </tr>
            <tr>
                <td class="label">Conto uscita:</td>
                <td class="value">
                    {{ $pagamento->contoCorrente?->nome ?? 'N.D.' }}
                    @if($pagamento->contoCorrente?->iban)
                        (IBAN: {{ $pagamento->contoCorrente->iban }})
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Fornitore</div>
        <table class="grid">
            <tr>
                <td class="label">Ragione sociale:</td>
                <td class="value text-bold">{{ $fornitore->ragione_sociale }}</td>
            </tr>
            <tr>
                <td class="label">Partita IVA / C.F.:</td>
                <td class="value">
                    {{ $fornitore->partita_iva ?? '-' }} / {{ $fornitore->codice_fiscale ?? '-' }}
                </td>
            </tr>
            <tr>
                <td class="label">IBAN destinatario:</td>
                <td class="value">{{ $fornitore->iban_principale ?? 'Non specificato' }}</td>
            </tr>
        </table>
    </div>

    {{--
        Coda 754 (3): una riga per ogni legame fra il pagamento e i documenti, con come si chiude. La fattura compensata con una nota di
        credito ha due righe (la parte pagata e la parte compensata) e la nota una sua, con il meno: sommarle tutte contava due volte la
        compensazione («Totale Allocato» € 1.403,00 su una fattura di € 1.220,00). Il piede dice le due cifre che contano, con la regola
        di `TipoAllocazioneFattura`: le righe «pagamento» sono l'uscita dal conto (uguale al «Netto pagato» del riepilogo), le righe
        «compensazione» delle fatture la parte chiusa con le note.
    --}}
    @php
        $euroConSegno = fn (int $centesimi) => ($centesimi < 0 ? '- ' : '').'€ '.number_format(abs($centesimi) / 100, 2, ',', '.');
        $tipoDellaRiga = fn ($documento) => $documento->pivot->tipo instanceof \BackedEnum ? $documento->pivot->tipo->value : (string) $documento->pivot->tipo;
        $eNota = fn ($documento) => $documento->tipo_documento === 'nota_credito';
        $noteUsate = $fatture->filter(fn ($d) => $eNota($d) && $tipoDellaRiga($d) === 'compensazione')->unique('id');
        $nomeNota = $noteUsate->count() === 1 ? ' '.($noteUsate->first()->numero_documento ?? "NC#{$noteUsate->first()->id}") : '';
        $uscitoDalConto = (int) $fatture->filter(fn ($d) => $tipoDellaRiga($d) === 'pagamento')->sum(fn ($d) => (int) $d->pivot->importo_allocato);
        $chiusoConNote = (int) $fatture->filter(fn ($d) => ! $eNota($d) && $tipoDellaRiga($d) === 'compensazione')->sum(fn ($d) => (int) $d->pivot->importo_allocato);
        // Le righe di una fattura una sotto l'altra (prima la parte pagata, poi quella compensata), le note in fondo.
        $righeDellaDistinta = $fatture->sortBy(fn ($d) => sprintf('%d-%012d-%d', $eNota($d) ? 1 : 0, $d->id, $tipoDellaRiga($d) === 'pagamento' ? 0 : 1))->values();
    @endphp
    <div class="section">
        <div class="section-title">Documenti del pagamento</div>
        @if($fatture->isEmpty())
            <p>Nessuna fattura associata in modo diretto (es. acconto generico o storno).</p>
        @else
            <table class="table-report">
                <thead>
                    <tr>
                        <th style="width: 14%;">N° Documento</th>
                        <th style="width: 12%;">Data</th>
                        <th class="text-right" style="width: 16%;">Importo del documento</th>
                        <th style="width: 40%;">Come si chiude</th>
                        <th class="text-right" style="width: 18%;">Importo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($righeDellaDistinta as $fattura)
                    @php
                        $nota = $eNota($fattura);
                        $tipo = $tipoDellaRiga($fattura);
                        $comeSiChiude = match (true) {
                            $nota && $tipo === 'compensazione' => 'Nota di credito usata',
                            $tipo === 'compensazione' => 'Compensazione con la nota di credito'.$nomeNota,
                            default => 'Pagamento',
                        };
                        // La nota toglie: la sua riga e il suo importo con il meno.
                        $importoDocumento = abs((int) $fattura->importo_imponibile + (int) $fattura->importo_iva) * ($nota ? -1 : 1);
                        $importoRiga = (int) $fattura->pivot->importo_allocato * ($nota ? -1 : 1);
                    @endphp
                    <tr>
                        <td>{{ $fattura->numero_documento ?? "FT#{$fattura->id}" }}</td>
                        <td>{{ $fattura->data_documento ? $fattura->data_documento->format('d/m/Y') : '' }}</td>
                        <td class="text-right">{{ $euroConSegno($importoDocumento) }}</td>
                        <td>{{ $comeSiChiude }}</td>
                        <td class="text-right text-bold">{{ $euroConSegno($importoRiga) }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" class="text-right text-bold">Uscito dal conto{{ $pagamento->importo_commissione > 0 ? ', senza le commissioni' : '' }}:</td>
                        <td class="text-right text-bold">{{ $euroConSegno($uscitoDalConto) }}</td>
                    </tr>
                    @if($chiusoConNote !== 0)
                    <tr>
                        <td colspan="4" class="text-right">Chiuso con note di credito:</td>
                        <td class="text-right">{{ $euroConSegno($chiusoConNote) }}</td>
                    </tr>
                    @endif
                </tfoot>
            </table>
        @endif
    </div>

    <div class="section">
        <div class="section-title">Riepilogo Importi</div>
        <div class="summary-box">
            <table class="grid" style="width: 70%; margin: 0 auto;">
                <tr>
                    <td class="label">Importo lordo:</td>
                    <td class="value text-right">€ {{ number_format($pagamento->importo_lordo / 100, 2, ',', '.') }}</td>
                </tr>
                @if($pagamento->importo_ritenuta > 0)
                <tr>
                    <td class="label" style="color: #ef4444;">Ritenuta d'acconto:</td>
                    <td class="value text-right" style="color: #ef4444;">- € {{ number_format($pagamento->importo_ritenuta / 100, 2, ',', '.') }}</td>
                </tr>
                @endif
                @if($pagamento->importo_commissione > 0)
                <tr>
                    <td class="label text-muted">Commissioni bancarie:</td>
                    <td class="value text-right text-muted">+ € {{ number_format($pagamento->importo_commissione / 100, 2, ',', '.') }}</td>
                </tr>
                @endif
                <tr>
                    <td colspan="2" style="border-bottom: 1px solid #cbd5e1; height: 10px;"></td>
                </tr>
                <tr>
                    <td class="label text-bold" style="padding-top: 10px;">Netto pagato:</td>
                    <td class="value text-right text-bold" style="padding-top: 10px; font-size: 11pt;">€ {{ number_format($pagamento->importo_netto / 100, 2, ',', '.') }}</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="section" style="margin-top: 30px;">
        <div class="section-title">Note</div>
        <p class="text-sm">
            <strong>Causale contabile:</strong> {{ $scrittura->causale ?? '-' }}
            <br>
            @if($pagamento->bonifico_parlante)
                <strong>Bonifico Parlante:</strong> Sì (Detrazione: {{ $pagamento->tipo_detrazione?->label() ?? 'N.D.' }})
                <br>
                @if($pagamento->beneficiari_detrazione)
                    <strong>Beneficiari:</strong>
                    {{ implode(', ', array_map(fn($b) => ($b['nome'] ?? '') . ' ' . ($b['cognome'] ?? '') . ' (' . ($b['codice_fiscale'] ?? '') . ')', $pagamento->beneficiari_detrazione)) }}
                @endif
            @endif
        </p>
    </div>

@endsection
