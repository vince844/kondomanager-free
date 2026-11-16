{{-- Una riga del conguaglio per persona (e il subtotale di chi ha più unità, in grassetto su fondo grigio): la usano il corpo della tabella e la sua coda.
     Chi ha avuto l'unità in più di un ruolo ha i giorni di ogni ruolo sotto il nome, dove vanno a capo: nella colonna dei giorni, che non va a capo,
     allargherebbero la colonna, e le due tabelle non avrebbero più le stesse colonne. --}}
<tr @if($r['subtotale']) class="sub" @endif>
    <td>{{ $r['unita'] ?? '—' }}</td>
    <td>{{ $r['nome'] }}@if(($r['giorni_per_ruolo'] ?? []) !== []) <br><span class="d">{{ \App\Services\Gestionale\ParoleDelConguaglio::giorni(null, $r['giorni_per_ruolo']) }}</span>@endif</td>
    <td class="n">{{ $r['giorni'] ?? '' }}</td>
    <td class="n">{{ $cella($r['a']) }}</td>
    <td class="n">{{ $cella($r['personali']) }}</td>
    <td class="nn">{{ $cella($r['b']) }} @if(($r['b_non_emesse'] ?? 0) !== 0) <br><span class="d">non ancora chieste {{ $euroNota($r['b_non_emesse']) }}</span> @endif</td>
    <td class="n"><b>{{ $segno($r['conguaglio']) }}</b></td>
    <td class="n">{{ $cella($gia($r)) }}</td>
    <td class="nn"><b>{{ $segno($r['saldo']) }}</b> @if($quandoChieste($r['saldo'], $r['non_emesse'] ?? 0) !== '') <br><span class="d">{{ $quandoChieste($r['saldo'], $r['non_emesse']) }}</span> @endif</td>
</tr>
