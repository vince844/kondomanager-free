<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Verbale;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\EsitoSeduta;
use App\Enums\Assemblee\OrigineAssemblea;
use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\Assemblee\StatoVerbale;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\RegistroDelPerimetro;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\Condominio;
use App\Models\Palazzina;
use App\Models\Scala;
use App\Models\User;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Il registro dei verbali (V8; art. 1130 n. 7 c.c.: «Nel registro dei verbali delle assemblee sono altresì annotate: le eventuali mancate costituzioni
 * dell'assemblea, le deliberazioni nonché le brevi dichiarazioni»). **Un registro per perimetro** (decisione 598): quello del condominio, e quello della scala o
 * della palazzina per le loro assemblee, con la sua numerazione.
 *
 * - **Le righe**, per data: le registrazioni (il numero, il giorno, l'assemblea con il perimetro e la convocazione, «5 delibere · 1 dichiarazione», lo stato),
 *   le annotazioni delle sedute deserte («Mancata costituzione», senza numero), le riunioni registrate a mano con il numero scritto («A mano»), le
 *   registrazioni annullate con il perché (la riga resta: **il registro va solo avanti**, decisione 595 (5)).
 * - **Da dove parte** (D16): la prima volta il registro chiede se continua il libro di carta (l'ultimo numero, il giorno facoltativo) o comincia dal n. 1; si
 *   cambia finché il registro non ha un verbale registrato, e ogni cambio è una riga del registro delle modifiche.
 * - **Il numero proposto** è l'ultimo del registro più uno: i verbali registrati e annullati, le riunioni a mano con un numero intero, l'ultimo del libro.
 * - **Per la tappa delle delibere**: {@see registrato()} dice il verbale registrato di un'assemblea (il numero, i giorni, il documento), o null.
 */
final class RegistroDeiVerbali
{
    /** Il perimetro di un registro, con il suo nome: «Registro dei verbali di Via Roma 12», «… della scala B di Via Roma 12». @return array{chiave: string, nome: string, scala_id: ?int, palazzina_id: ?int} */
    public static function perimetro(Condominio $condominio, string $registro): array
    {
        [$tipo, $id] = array_pad(explode(':', $registro, 2), 2, null);
        $scala = $tipo === 'scala' && is_numeric($id) ? Scala::query()->whereKey((int) $id)->first() : null;
        $palazzina = $tipo === 'palazzina' && is_numeric($id) ? Palazzina::query()->whereKey((int) $id)->first() : null;
        $parte = $scala !== null ? 'della scala ' . $scala->name . ' ' : ($palazzina !== null ? 'della palazzina ' . $palazzina->name . ' ' : '');

        return ['chiave' => $registro, 'nome' => 'Registro dei verbali ' . $parte . 'di ' . $condominio->nome, 'scala_id' => $scala?->id === null ? null : (int) $scala->id,
            'palazzina_id' => $palazzina?->id === null ? null : (int) $palazzina->id];
    }

    /** Il registro è uno di questo condominio: `condominio`, o una scala o una palazzina sue; altrimenti 404. */
    public static function delCondominio(Condominio $condominio, ?string $registro): string
    {
        $registro = $registro === null || $registro === '' ? 'condominio' : $registro;
        if ($registro === 'condominio') {
            return $registro;
        }
        [$tipo, $id] = array_pad(explode(':', $registro, 2), 2, null);
        $ok = is_numeric($id) && match ($tipo) {
            'scala' => Scala::query()->whereKey((int) $id)->where('condominio_id', $condominio->id)->exists(),
            'palazzina' => Palazzina::query()->whereKey((int) $id)->where('condominio_id', $condominio->id)->exists(),
            default => false,
        };
        abort_unless($ok, 404);

        return $tipo . ':' . (int) $id;
    }

    /** I registri del condominio: il suo, e quelli delle scale e delle palazzine che hanno avuto un'assemblea loro. @return list<array<string, mixed>> */
    public function registri(Condominio $condominio): array
    {
        $chiavi = ['condominio'];
        foreach (Assemblea::query()->where('condominio_id', $condominio->id)->where('ambito', '!=', AmbitoAssemblea::CONDOMINIO->value)->get() as $a) {
            $chiavi[] = VerbaliDellAssemblea::registroDi($a);
        }
        foreach (RegistroDelPerimetro::query()->where('condominio_id', $condominio->id)->pluck('registro') as $r) {
            $chiavi[] = (string) $r;
        }

        return array_values(array_map(fn ($r) => self::perimetro($condominio, $r) + ['partenza' => $this->partenza($condominio, $r),
            'prossimo_numero' => $this->prossimoNumero($condominio, $r)], array_values(array_unique($chiavi))));
    }

    /** Il punto di partenza del registro, o null se non è ancora scelto. @return ?array{scelta: string, ultimo: ?int, del: ?string, si_cambia: bool} */
    public function partenza(Condominio $condominio, string $registro): ?array
    {
        $r = RegistroDelPerimetro::query()->where('condominio_id', $condominio->id)->where('registro', $registro)->first();

        return $r === null ? null : ['scelta' => (string) $r->partenza, 'ultimo' => $r->ultimo_del_libro, 'del' => $r->libro_del?->format('Y-m-d'),
            'si_cambia' => ! $this->haUnVerbale($condominio, $registro)];
    }

    /**
     * «Da dove parte il registro dei verbali»: continua il libro di carta (l'ultimo numero, il giorno facoltativo) o comincia dal n. 1. Si cambia finché il
     * registro non ha un verbale registrato; dentro la registrazione del primo verbale, nella stessa transazione.
     */
    public function impostaPartenza(Condominio $condominio, string $registro, string $scelta, mixed $ultimo, ?string $del, User $utente): RegistroDelPerimetro
    {
        if (! in_array($scelta, [RegistroDelPerimetro::LIBRO, RegistroDelPerimetro::DA_UNO], true)) {
            throw ValidationException::withMessages(['partenza' => 'Scegli se il registro continua il libro di carta o comincia dal n. 1.']);
        }
        if ($scelta === RegistroDelPerimetro::LIBRO && (! is_numeric($ultimo) || (int) $ultimo < 1 || (int) $ultimo > 99999)) {
            throw ValidationException::withMessages(['partenza' => 'Scrivi l\'ultimo numero del libro dei verbali di carta.']);
        }
        if ($del !== null && $del !== '' && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $del) !== 1 || $del > \App\Helpers\DateHelper::oggiUtente())) {
            throw ValidationException::withMessages(['partenza' => 'Il giorno dell\'ultimo verbale di carta non può essere dopo oggi.']);
        }
        if ($this->haUnVerbale($condominio, $registro)) {
            throw ValidationException::withMessages(['partenza' => 'Il registro ha già un verbale registrato: il numero va solo avanti, e il punto di partenza non si cambia più.']);
        }
        $perimetro = self::perimetro($condominio, $registro);
        $riga = RegistroDelPerimetro::query()->where('condominio_id', $condominio->id)->where('registro', $registro)->lockForUpdate()->first()
            ?? new RegistroDelPerimetro(['condominio_id' => $condominio->id, 'registro' => $registro, 'scala_id' => $perimetro['scala_id'],
                'palazzina_id' => $perimetro['palazzina_id'], 'created_by' => $utente->id]);
        $riga->fill(['partenza' => $scelta, 'ultimo_del_libro' => $scelta === RegistroDelPerimetro::LIBRO ? (int) $ultimo : null,
            'libro_del' => $scelta === RegistroDelPerimetro::LIBRO && filled($del) ? $del : null, 'updated_by' => $utente->id])->save();

        return $riga;
    }

    /** Il registro ha un verbale con il numero (registrato o annullato): il punto di partenza non si cambia più. */
    public function haUnVerbale(Condominio $condominio, string $registro): bool
    {
        return VerbaleDellAssemblea::query()->where('condominio_id', $condominio->id)->where('registro', $registro)->whereNotNull('numero')->exists();
    }

    /**
     * Il numero proposto: l'ultimo del registro più uno (i verbali registrati e annullati, le riunioni a mano del perimetro con un numero intero, l'ultimo del
     * libro di carta), o 1 dal n. 1; null se il registro non sa ancora da dove parte e non ha numeri.
     */
    public function prossimoNumero(Condominio $condominio, string $registro): ?int
    {
        $partenza = $this->partenza($condominio, $registro);
        $ultimo = max((int) (VerbaleDellAssemblea::query()->where('condominio_id', $condominio->id)->where('registro', $registro)->max('numero') ?? 0),
            (int) ($this->numeriAMano($condominio, $registro)->max() ?? 0), (int) ($partenza['ultimo'] ?? 0));
        if ($partenza === null && $ultimo === 0) {
            return null;
        }

        return $ultimo + 1;
    }

    /**
     * La frase dell'errore se il numero non si può usare nel registro, o null. `$soloAvanti` (di serie) vuole anche il numero non più basso del prossimo: il
     * registro va solo avanti; una riunione di carta di prima del registro (registrata dopo) lo spegne, e resta solo «già usato».
     */
    public function numeroUsato(Condominio $condominio, string $registro, int $numero, bool $soloAvanti = true): ?string
    {
        if ($numero < 1) {
            return 'Il numero del verbale è un numero intero, dal n. 1.';
        }
        $partenza = $this->partenza($condominio, $registro);
        if (($partenza['ultimo'] ?? null) !== null && $numero <= (int) $partenza['ultimo']) {
            return 'Il n. ' . $numero . ' è nel libro dei verbali di carta, che arriva al n. ' . $partenza['ultimo'] . ': il numero va solo avanti.';
        }
        $verbale = VerbaleDellAssemblea::query()->where('condominio_id', $condominio->id)->where('registro', $registro)->where('numero', $numero)->first();
        if ($verbale !== null) {
            return 'Il n. ' . $numero . ' è già nel registro' . ($verbale->stato === StatoVerbale::ANNULLATO ? ', annullato: un numero annullato non torna' : '') . '.';
        }
        if ($this->numeriAMano($condominio, $registro)->contains($numero)) {
            return 'Il n. ' . $numero . ' è già di una riunione registrata a mano.';
        }
        // Il registro va solo avanti: un numero più basso del prossimo entrerebbe dopo uno più alto, con una data successiva.
        $prossimo = $soloAvanti ? $this->prossimoNumero($condominio, $registro) : null;
        if ($prossimo !== null && $numero < $prossimo) {
            return 'Il registro va solo avanti: il prossimo è il ' . $prossimo . '.';
        }

        return null;
    }

    /** La conferma di un numero più alto del proposto: «Salti i numeri 46–419?». */
    public static function fraseDelSalto(int $proposto, int $numero): string
    {
        $saltati = $numero - 1 === $proposto ? 'il numero ' . $proposto : 'i numeri ' . $proposto . '–' . ($numero - 1);

        return 'Salti ' . $saltati . '? Il registro va solo avanti: un numero saltato non torna. Conferma il salto, o usa il ' . $proposto . '.';
    }

    /**
     * Le riunioni di carta del perimetro: quelle registrate a mano (`origine = a_mano`) e quelle preparate nel programma e tenute su carta (lo stato
     * `registrata_a_mano`, decisione 618): tutte e due sono riunioni il cui verbale sta nel libro di carta.
     *
     * @return Collection<int, Assemblea>
     */
    private function riunioniDiCarta(Condominio $condominio, string $registro): Collection
    {
        return Assemblea::query()->where('condominio_id', $condominio->id)
            ->where(fn ($q) => $q->where('origine', OrigineAssemblea::A_MANO->value)->orWhere('stato', StatoAssemblea::REGISTRATA_A_MANO->value))->get()
            ->filter(fn (Assemblea $a) => VerbaliDellAssemblea::registroDi($a) === $registro)->values();
    }

    /** I numeri interi delle riunioni registrate a mano del perimetro. @return Collection<int, int> */
    private function numeriAMano(Condominio $condominio, string $registro): Collection
    {
        return $this->riunioniDiCarta($condominio, $registro)->filter(fn (Assemblea $a) => preg_match('/^\d+$/', trim((string) $a->numero_verbale)) === 1)
            ->map(fn (Assemblea $a) => (int) trim((string) $a->numero_verbale))->values();
    }

    /**
     * Le righe del registro, per data (e per numero nello stesso giorno), dell'anno dato o di tutti.
     *
     * @return list<array<string, mixed>>
     */
    public function righe(Condominio $condominio, string $registro, ?int $anno = null): array
    {
        $assemblee = Assemblea::query()->where('condominio_id', $condominio->id)->with(['sedute', 'scala', 'palazzina'])->get()
            ->filter(fn (Assemblea $a) => VerbaliDellAssemblea::registroDi($a) === $registro)->keyBy('id');
        $righe = [];
        $verbali = VerbaleDellAssemblea::query()->where('condominio_id', $condominio->id)->where('registro', $registro)->whereNotNull('numero')
            ->with(['seduta', 'annullatoDa:id,name', 'registratoDa:id,name', 'sostituisce:id,numero', 'documento'])->get();
        foreach ($verbali as $v) {
            $a = $assemblee->get($v->assemblea_id);
            if ($a === null) {
                continue;
            }
            $righe[] = ['tipo' => 'verbale', 'id' => (int) $v->id, 'numero' => (int) $v->numero, 'giorno' => $v->seduta?->fissata_il?->format('Y-m-d'),
                'assemblea' => self::assemblea($a, $v->seduta), 'stato' => $v->stato?->value,
                'parola' => $v->stato === StatoVerbale::ANNULLATO ? 'Annullato' : 'Sottoscritto',
                'contenuto' => $this->contenuto($a, $v->seduta), 'sottoscritto_il' => $v->sottoscritto_il?->format('Y-m-d'),
                'registrato_il' => $v->registrato_il?->copy()->utc()->toIso8601ZuluString(), 'registrato_da' => $v->registratoDa?->name,
                'annullato' => $v->annullato_il === null ? null : ['il' => $v->annullato_il->copy()->utc()->toIso8601ZuluString(), 'da' => $v->annullatoDa?->name,
                    'motivo' => $v->annullato_motivo],
                'sostituisce' => $v->sostituisce?->numero, 'nel_libro_il' => $v->nel_libro_il?->format('Y-m-d'),
                'documento' => $v->documento === null ? null : ['id' => (int) $v->documento->id, 'nome' => (string) $v->documento->name]];
        }
        foreach ($assemblee as $a) {
            foreach ($a->sedute as $s) {
                if ($s->esito === EsitoSeduta::DESERTA) {
                    $righe[] = ['tipo' => 'annotazione', 'id' => (int) $s->id, 'numero' => null, 'giorno' => $s->fissata_il?->format('Y-m-d'),
                        'assemblea' => self::assemblea($a, $s), 'stato' => 'annotazione', 'parola' => 'Mancata costituzione', 'contenuto' => 'mancata costituzione',
                        'nel_libro_il' => $s->nel_libro_il?->format('Y-m-d')];
                }
            }
            if ($a->origine === OrigineAssemblea::A_MANO || $a->stato === StatoAssemblea::REGISTRATA_A_MANO) {
                $delibere = Delibera::query()->where('assemblea_id', $a->id)->count();
                $righe[] = ['tipo' => 'a_mano', 'id' => (int) $a->id, 'numero' => filled($a->numero_verbale) ? (string) $a->numero_verbale : null,
                    'giorno' => $a->data?->format('Y-m-d'), 'assemblea' => self::assemblea($a, null), 'stato' => 'a_mano', 'parola' => 'A mano',
                    'contenuto' => $delibere === 1 ? '1 delibera' : $delibere . ' delibere', 'verbale_caricato' => $a->verbale_documento_id !== null];
            }
        }
        if ($anno !== null) {
            $righe = array_values(array_filter($righe, fn ($r) => $r['giorno'] !== null && (int) substr((string) $r['giorno'], 0, 4) === $anno));
        }
        usort($righe, fn ($x, $y) => [(string) $x['giorno'], is_int($x['numero']) ? $x['numero'] : PHP_INT_MAX] <=> [(string) $y['giorno'], is_int($y['numero']) ? $y['numero'] : PHP_INT_MAX]);

        return $righe;
    }

    /** Gli anni del registro, dal più recente. @return list<int> */
    public function anni(Condominio $condominio, string $registro): array
    {
        $anni = array_values(array_unique(array_filter(array_map(fn ($r) => $r['giorno'] === null ? null : (int) substr((string) $r['giorno'], 0, 4),
            $this->righe($condominio, $registro)))));
        rsort($anni);

        return $anni;
    }

    /**
     * Il verbale registrato di un'assemblea, per la tappa delle delibere che tornano (V1 del suo progetto): l'ultimo registrato (per un'assemblea sospesa e
     * proseguita, quello della prosecuzione; {@see registrati()} li dice tutti), con il numero nel registro, i giorni della firma e della registrazione, il
     * documento della copia sottoscritta. Null finché non c'è.
     *
     * @return ?array{verbale_id: int, numero: int, registro: string, seduta_id: int, sottoscritto_il: ?string, registrato_il: ?string, documento_id: ?int}
     */
    public static function registrato(Assemblea $assemblea): ?array
    {
        return self::registrati($assemblea)[0] ?? null;
    }

    /** Tutti i verbali registrati di un'assemblea, dall'ultimo. @return list<array<string, mixed>> */
    public static function registrati(Assemblea $assemblea): array
    {
        return VerbaleDellAssemblea::query()->where('assemblea_id', $assemblea->id)->where('stato', StatoVerbale::REGISTRATO->value)->orderByDesc('registrato_il')
            ->orderByDesc('id')->get()->map(fn (VerbaleDellAssemblea $v) => ['verbale_id' => (int) $v->id, 'numero' => (int) $v->numero, 'registro' => (string) $v->registro,
                'seduta_id' => (int) $v->seduta_id, 'sottoscritto_il' => $v->sottoscritto_il?->format('Y-m-d'),
                'registrato_il' => $v->registrato_il?->copy()->utc()->toIso8601ZuluString(), 'documento_id' => $v->documento_id])->values()->all();
    }

    /** «Incollata nel libro» (scena 8): il giorno in cui la pagina di un verbale o di un'annotazione è incollata nel libro di carta. È un promemoria. */
    public function segnaNelLibro(Condominio $condominio, string $che, int $id, ?string $giorno, User $utente): void
    {
        if ($giorno !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $giorno) !== 1 || $giorno > \App\Helpers\DateHelper::oggiUtente())) {
            throw ValidationException::withMessages(['giorno' => 'Il giorno in cui la pagina è incollata non può essere dopo oggi.']);
        }
        if ($che === 'verbale') {
            $v = VerbaleDellAssemblea::query()->where('condominio_id', $condominio->id)->whereKey($id)->whereNotNull('numero')->first() ?? abort(404);
            RegistroModifiche::durante(GestiDelRegistro::delVerbale($v, $giorno === null ? 'pagina del libro di carta: «Da incollare»'
                : 'pagina incollata nel libro di carta il ' . \Carbon\Carbon::parse($giorno)->format('d/m/Y')), fn () => $v->update(['nel_libro_il' => $giorno]));

            return;
        }
        $s = SedutaDellAssemblea::query()->whereKey($id)->where('esito', EsitoSeduta::DESERTA->value)
            ->whereIn('assemblea_id', Assemblea::query()->where('condominio_id', $condominio->id)->select('id'))->first() ?? abort(404);
        $s->update(['nel_libro_il' => $giorno]);
    }

    /** «Ordinaria, seconda convocazione», con il perimetro: «Ordinaria della scala B, prima convocazione». @return array<string, mixed> */
    private static function assemblea(Assemblea $a, ?SedutaDellAssemblea $s): array
    {
        $perimetro = match ($a->ambito) {
            AmbitoAssemblea::SCALA => $a->scala?->name !== null ? ' della scala ' . $a->scala->name : '',
            AmbitoAssemblea::PALAZZINA => $a->palazzina?->name !== null ? ' della palazzina ' . $a->palazzina->name : '',
            default => '',
        };
        $convocazione = $s === null ? null : (((int) $s->numero > 1 ? 'prosecuzione della ' : '') . ($s->convocazione?->value === 'seconda' ? 'seconda' : 'prima') . ' convocazione');

        return ['id' => (int) $a->id, 'nome' => $a->nome(), 'parola' => ($a->eAMano() ? 'Riunione' : (string) $a->tipo?->parola()) . $perimetro
            . ($convocazione !== null ? ', ' . $convocazione : ''), 'perimetro' => trim($perimetro) ?: null];
    }

    /** «5 delibere · 1 dichiarazione»: le delibere votate della riunione e le dichiarazioni a verbale. */
    private function contenuto(Assemblea $a, ?SedutaDellAssemblea $s): string
    {
        if ($s === null) {
            return '';
        }
        $punti = \App\Models\Assemblee\VotazioneDelPunto::query()->where('seduta_id', $s->id)->pluck('punto_id')->unique();
        $delibere = Delibera::query()->where('assemblea_id', $a->id)->whereIn('punto_id', $punti)->whereIn('esito', [EsitoDelibera::APPROVATA->value,
            EsitoDelibera::APPROVATA_CON_MODIFICHE->value, EsitoDelibera::RESPINTA->value])->count();
        $dichiarazioni = \App\Models\Assemblee\DichiarazioneAVerbale::query()->where('seduta_id', $s->id)->count();

        return ($delibere === 1 ? '1 delibera' : $delibere . ' delibere') . ($dichiarazioni > 0 ? ' · ' . ($dichiarazioni === 1 ? '1 dichiarazione' : $dichiarazioni . ' dichiarazioni') : '');
    }
}
