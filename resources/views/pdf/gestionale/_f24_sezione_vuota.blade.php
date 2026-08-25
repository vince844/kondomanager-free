{{--
    Una sezione del modello F24 che il condominio non compila.

    Restano sul foglio perché sul modulo prestampato ci sono: chi riceve la delega si aspetta
    quel modulo, non una versione ridotta con le sole righe usate. Le colonne degli importi
    sono presenti e vuote, e i totali portano le loro lettere — C/D, E/F, G/H, I/L — così le
    formule dei saldi restano leggibili come sull'originale.

    @param string $titolo         Il testo della fascia azzurra.
    @param list<string> $colonne  Le intestazioni di colonna, da sinistra.
    @param int $righe             Quante righe vuote ha questa sezione sul modulo.
    @param array{0: string, 1: string} $lettereTotale  Le lettere dei due totali.
    @param string $lettereSaldo   L'etichetta del saldo, es. «SALDO  (C-D)».
--}}
<div class="fascia" style="margin-top: 1mm;">{{ $titolo }}</div>

<table style="margin-top: 0.8mm;">
    <tr>
        @foreach($colonne as $colonna)
            <td class="eti" style="width: {{ round(41 / count($colonne), 2) }}%; text-align: center;">{{ $colonna }}</td>
        @endforeach
        <td class="eti" style="width: 16%; text-align: center;">importi a debito versati</td>
        <td class="eti" style="width: 16%; text-align: center;">importi a credito compensati</td>
        {{-- La striscia di destra, dove il modulo tiene il saldo di sezione. --}}
        <td style="width: 27%;"></td>
    </tr>

    @for($riga = 0; $riga < $righe; $riga++)
        <tr>
            @foreach($colonne as $colonna)
                <td class="box"></td>
            @endforeach
            <td style="padding: 0;">
                <table>
                    <tr>
                        <td class="box" style="width: 76%; border-right: none;"></td>
                        <td class="box virgola" style="border-left: none; border-right: none;">,</td>
                        <td class="box" style="width: 16%; border-left: none;"></td>
                    </tr>
                </table>
            </td>
            <td style="padding: 0;">
                <table>
                    <tr>
                        <td class="box" style="width: 76%; border-right: none;"></td>
                        <td class="box virgola" style="border-left: none; border-right: none;">,</td>
                        <td class="box" style="width: 16%; border-left: none;"></td>
                    </tr>
                </table>
            </td>
            <td></td>
        </tr>
    @endfor

    <tr>
        <td colspan="{{ count($colonne) }}" class="totale" style="padding-top: 1mm;">TOTALE&nbsp;&nbsp;&nbsp;{{ $lettereTotale[0] }}</td>
        <td style="padding: 1mm 0 0 0;">
            <table>
                <tr>
                    <td class="box" style="width: 74%; border-right: none;"></td>
                    <td class="box virgola" style="border-left: none; border-right: none;">,</td>
                    <td class="box" style="width: 18%; border-left: none;"></td>
                </tr>
            </table>
        </td>
        <td style="padding: 1mm 0 0 0;">
            <table>
                <tr>
                    <td class="totale" style="width: 10%;">{{ $lettereTotale[1] }}</td>
                    <td class="box" style="width: 66%; border-right: none;"></td>
                    <td class="box virgola" style="border-left: none; border-right: none;">,</td>
                    <td class="box" style="width: 16%; border-left: none;"></td>
                </tr>
            </table>
        </td>
        <td style="padding: 1mm 0 0 1.5mm;">
            <table>
                <tr>
                    <td class="saldo-eti" style="width: 50%; white-space: nowrap;">+/–&nbsp; {{ $lettereSaldo }}</td>
                    <td class="box" style="width: 34%; border-right: none;"></td>
                    <td class="box virgola" style="border-left: none; border-right: none;">,</td>
                    <td class="box" style="width: 12%; border-left: none;"></td>
                </tr>
            </table>
        </td>
    </tr>
</table>
