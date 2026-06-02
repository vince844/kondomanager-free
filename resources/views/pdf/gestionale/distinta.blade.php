@extends('pdf.base')

@section('title', 'Distinta di Pagamento')

@section('content')

    <div style="text-align: right; margin-bottom: 20px; color: #555;">
        Data di stampa: {{ now()->format('d/m/Y H:i') }}
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

    <div class="section">
        <div class="section-title">Fatture Saldate</div>
        @if($fatture->isEmpty())
            <p>Nessuna fattura associata in modo diretto (es. acconto generico o storno).</p>
        @else
            <table class="table-report">
                <thead>
                    <tr>
                        <th>N° Documento</th>
                        <th>Data</th>
                        <th class="text-right">Importo Lordo</th>
                        <th class="text-right">Allocato</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($fatture as $fattura)
                    <tr>
                        <td>{{ $fattura->numero_documento ?? "FT#{$fattura->id}" }}</td>
                        <td>{{ $fattura->data_documento ? $fattura->data_documento->format('d/m/Y') : '' }}</td>
                        <td class="text-right">€ {{ number_format(($fattura->importo_imponibile + $fattura->importo_iva) / 100, 2, ',', '.') }}</td>
                        <td class="text-right text-bold">€ {{ number_format($fattura->pivot->importo_allocato / 100, 2, ',', '.') }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" class="text-right text-bold">Totale Allocato:</td>
                        <td class="text-right text-bold">€ {{ number_format($fatture->sum('pivot.importo_allocato') / 100, 2, ',', '.') }}</td>
                    </tr>
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
