<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito\Effetti;

use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\RuoloCollegamento;
use App\Enums\Assemblee\StatoVersionePreventivo;
use App\Enums\Assemblee\TipoCosaDaFare;
use App\Enums\StatoPianoRate;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\VersionePreventivo;
use App\Models\User;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\Convocazione\VersioniDelPreventivo;
use App\Services\Assemblee\PropostaDelPunto;
use App\Services\Assemblee\Seguito\ContestoDellaDelibera;
use App\Services\Assemblee\Seguito\CoseDaFare;
use App\Services\Assemblee\Seguito\RigaDelSeguito;
use App\Services\Gestionale\ApprovazioneDelPiano;
use App\Services\RegistroModifiche\FormatoDelValore;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Catena 2, il preventivo e i piani rate. Il punto «Approvazione del preventivo 2027 e del piano di riparto» ha la versione del preventivo fermata (con il
 * piano ordinario in bozza dell'esercizio): approvata, la versione diventa «approvata» ({@see VersioniDelPreventivo::approva()}) e il piano passa ad approvato
 * con il giorno della riunione e il numero del verbale ({@see ApprovazioneDelPiano}, le regole della pagina del piano: sul piano emesso con un'altra data la
 * data non cambia e la differenza si dice).
 *
 * **Si approva il preventivo spedito** (decisione 622 (8)): la versione fermata entro la prima spedizione della convocazione
 * ({@see VersioniDelPreventivo::spedita()}); una fermata dopo resta fuori, con la frase. **Il piano si approva solo se chiede ciò che l'assemblea ha
 * approvato** ({@see VersioniDelPreventivo::differenzeDaOggi()}: le voci del piano, il riparto, le rate generate): diverso, la sua riga resta fuori con la
 * differenza, e nasce la cosa da fare «Rimetti il piano come il preventivo approvato», che tiene la delibera «Da eseguire» e si chiude da sola quando «Applica»
 * approva il piano (R5). Con modifiche (R6) la versione e il piano aspettano una versione fermata dopo il giorno della delibera, con la cosa «Correggi il
 * preventivo e fermalo di nuovo». Il deficit da ripianare: approvato, «Prepara il piano di ripianamento».
 */
final class SulPreventivo implements Effetto
{
    public const VERSIONE = 'preventivo';

    public const PIANO = 'piano';

    public function righe(ContestoDellaDelibera $c): array
    {
        if (! $c->approvata()) {
            return [];
        }
        $righe = [];
        $deficit = $c->scheda('compito');
        if ($deficit !== null && (((array) $deficit->getAttribute('meta'))['type'] ?? null) === 'pianifica_ripianamento_deficit') {
            $righe[] = RigaDelSeguito::cosa($c, TipoCosaDaFare::PREPARA_RIPIANAMENTO, 'deficit', 'Deficit da ripianare: prepara il piano di ripianamento',
                'Approvato, ma resta da fare: il piano straordinario si prepara con la delibera già scelta, e salvato si chiude anche il compito del deficit.',
                'Prepara il piano di ripianamento', true, $deficit);
        }
        if ($c->materia() !== MateriaPunto::PREVENTIVO || $c->punto === null) {
            return $righe;
        }
        $anno = PropostaDelPunto::anno($c->punto->esercizio_id);
        $diQuesta = $this->approvataDaQuesta($c);
        $spedita = VersioniDelPreventivo::spedita($c->punto, $c->limiteDellaSpedizione());
        if ($c->conModifiche()) {
            $versione = $diQuesta ?? VersioniDelPreventivo::fermataDopo($c->punto, $c->fineDelGiorno());
            if ($versione === null) {
                return $spedita === null ? $righe : [...$righe,
                    RigaDelSeguito::senzaSpunta($c, 'fuori', self::VERSIONE, 'Preventivo ' . $anno . ': approvato con modifiche',
                        'L\'assemblea ha cambiato il preventivo: si approva la versione fermata dopo il ' . $c->giorno() . ', e il piano si rifà su quella.',
                        'Correggi il preventivo e fermalo di nuovo: si approva la versione nuova.', $spedita, false, ['aspetta' => true],
                        RuoloCollegamento::APPROVAZIONE->value),
                    RigaDelSeguito::cosa($c, TipoCosaDaFare::RIFAI_VERSIONE, self::VERSIONE, 'Preventivo ' . $anno . ': correggi e fermalo di nuovo',
                        'Approvato con modifiche: la versione nuova e il piano rifatto si approvano qui, con «Applica».', 'Correggi il preventivo ' . $anno
                        . ' e fermalo di nuovo'),
                ];
            }
        } else {
            $versione = $diQuesta ?? $spedita;
        }
        if ($versione !== null) {
            $righe[] = $this->rigaDellaVersione($c, $versione, $anno);
            if (! $c->conModifiche()) {
                foreach ($this->fermateDopoLaSpedizione($c, $versione) as $dopo) {
                    $righe[] = $this->rigaDellaFermataDopo($c, $dopo, $versione, $anno);
                }
            }
        }
        $piano = $this->piano($c, $versione);
        if ($piano !== null) {
            array_push($righe, ...$this->righeDelPiano($c, $piano, $versione));
        }

        return $righe;
    }

    public function applica(ContestoDellaDelibera $c, array $riga, User $utente): string
    {
        $id = (int) ($riga['scheda']['id'] ?? 0);
        if ($riga['catena'] === self::VERSIONE) {
            $versione = VersionePreventivo::query()->whereKey($id)->where('condominio_id', $c->delibera->condominio_id)->first()
                ?? throw ValidationException::withMessages(['riga' => 'La versione del preventivo non c\'è più.']);
            VersioniDelPreventivo::approva($versione, $c->delibera, $utente);
            $rimetti = $c->conModifiche() ? $this->rimettiIlPianoSeDiverso($c, $versione, $utente) : null;

            return 'Preventivo, versione ' . $versione->numero . ': approvata il ' . $c->giorno() . '.' . ($rimetti !== null ? ' ' . $rimetti : '');
        }
        $piano = PianoRate::query()->whereKey($id)->where('condominio_id', $c->delibera->condominio_id)->first()
            ?? throw ValidationException::withMessages(['riga' => 'Il piano non c\'è più.']);
        $versione = $c->punto === null ? null : ($this->approvataDaQuesta($c) ?? ($c->conModifiche()
            ? VersioniDelPreventivo::fermataDopo($c->punto, $c->fineDelGiorno()) : VersioniDelPreventivo::spedita($c->punto, $c->limiteDellaSpedizione())));
        $diverso = $this->differenza($versione, $piano);
        if ($diverso !== null) {
            throw ValidationException::withMessages(['riga' => $diverso['frase']]);
        }
        if ($piano->stato === StatoPianoRate::APPROVATO && CollegamentiDelleDelibere::delibera($piano, RuoloCollegamento::DELIBERA->value)?->id !== $c->delibera->id) {
            throw ValidationException::withMessages(['riga' => $this->giaApprovato($piano)]);
        }

        return ApprovazioneDelPiano::dallaDelibera($piano, $c->delibera, $utente);
    }

    /**
     * Con modifiche (R6): approvata la versione corretta, se il piano non chiede ciò che dice nasce da sola «Rimetti il piano come il preventivo approvato»,
     * con la data e la persona di «Correggi il preventivo e fermalo di nuovo»: la delibera resta «Da eseguire» finché il piano non torna (la stessa regola del
     * piano fuori alla conferma). Una cosa che non si scrive non ferma l'approvazione della versione. Rende la frase, o null.
     */
    private function rimettiIlPianoSeDiverso(ContestoDellaDelibera $c, VersionePreventivo $versione, User $utente): ?string
    {
        $piano = $this->piano($c, $versione);
        if ($piano === null || $piano->stato === StatoPianoRate::APPROVATO || $piano->tipo_autorizzazione === 'urgenza' || $this->differenza($versione, $piano) === null
            || CosaDaFareDellaDelibera::query()->where('delibera_id', $c->delibera->id)->where('tipo', TipoCosaDaFare::RIMETTI_PIANO->value)->exists()) {
            return null;
        }
        $prima = CosaDaFareDellaDelibera::query()->where('delibera_id', $c->delibera->id)->where('tipo', TipoCosaDaFare::RIFAI_VERSIONE->value)
            ->orderByDesc('id')->first();
        try {
            $cosa = CoseDaFare::crea($c->delibera, TipoCosaDaFare::RIMETTI_PIANO, 'Rimetti il piano «' . $piano->nome . '» come il preventivo approvato',
                $prima?->entro_il?->format('Y-m-d'), $prima?->a_chi?->value, $prima?->assegnato_a === null ? null : (int) $prima->assegnato_a, $utente, $piano);
        } catch (ValidationException) {
            return null;
        }

        return 'Il piano «' . $piano->nome . '» non torna con la versione corretta: ' . lcfirst((string) $cosa->tipo?->parola()) . '.';
    }

    /** La versione del punto già approvata da questa delibera (il collegamento `approvazione`), o null. */
    private function approvataDaQuesta(ContestoDellaDelibera $c): ?VersionePreventivo
    {
        if ($c->punto === null) {
            return null;
        }

        return VersionePreventivo::query()->where('punto_id', $c->punto->id)->orderByDesc('numero')->get()
            ->first(fn (VersionePreventivo $v) => CollegamentiDelleDelibere::delibera($v, RuoloCollegamento::APPROVAZIONE->value)?->id === $c->delibera->id);
    }

    /** @return array<string, mixed> */
    private function rigaDellaVersione(ContestoDellaDelibera $c, VersionePreventivo $versione, string $anno): array
    {
        $titolo = 'Preventivo ' . $anno . ', versione ' . $versione->numero . ' fermata il ' . self::fermataIl($versione) . ': approvato';
        $diQuesta = CollegamentiDelleDelibere::delibera($versione, RuoloCollegamento::APPROVAZIONE->value)?->id === $c->delibera->id;
        $siApprova = in_array($versione->stato, [StatoVersionePreventivo::PRESENTATA, StatoVersionePreventivo::SOSTITUITA], true);
        if (! $siApprova && ! $diQuesta) {
            $perche = 'La versione ' . $versione->numero . ' è ' . mb_strtolower((string) $versione->stato?->parola()) . ': si approva una versione presentata.';

            return RigaDelSeguito::senzaSpunta($c, 'fuori', self::VERSIONE, $titolo, $perche, $perche, $versione, false, [], RuoloCollegamento::APPROVAZIONE->value);
        }
        $totale = FormatoDelValore::testo((int) (((array) $versione->cifre)['totale'] ?? 0), 'euro');

        return RigaDelSeguito::applica($c, $versione, RuoloCollegamento::APPROVAZIONE->value, self::VERSIONE, $titolo, $c->conModifiche()
            ? 'Si applica adesso: ' . $totale . ', la versione corretta fermata il ' . self::fermataIl($versione) . '.'
            : 'Si applica adesso: ' . $totale . ', quello spedito con la convocazione.');
    }

    /** Le versioni del punto fermate dopo quella spedita e non approvate: restano fuori. @return list<VersionePreventivo> */
    private function fermateDopoLaSpedizione(ContestoDellaDelibera $c, VersionePreventivo $spedita): array
    {
        return VersionePreventivo::query()->where('punto_id', $spedita->punto_id)->where('numero', '>', $spedita->numero)
            ->where('stato', '!=', StatoVersionePreventivo::APPROVATA->value)->orderBy('numero')->get()->all();
    }

    /** «La versione 2 è stata fermata il 17/12/2026, dopo la spedizione: l'assemblea ha approvato la versione 1, € 48.000,00.» @return array<string, mixed> */
    private function rigaDellaFermataDopo(ContestoDellaDelibera $c, VersionePreventivo $dopo, VersionePreventivo $spedita, string $anno): array
    {
        $perche = 'La versione ' . $dopo->numero . ' è stata fermata il ' . self::fermataIl($dopo) . ', dopo la spedizione: l\'assemblea ha approvato la versione '
            . $spedita->numero . ', ' . FormatoDelValore::testo((int) (((array) $spedita->cifre)['totale'] ?? 0), 'euro') . '.';

        return RigaDelSeguito::senzaSpunta($c, 'fuori', self::VERSIONE, 'Preventivo ' . $anno . ', versione ' . $dopo->numero . ' fermata il ' . self::fermataIl($dopo)
            . ': resta fuori', $perche, $perche, $dopo, false, [], RuoloCollegamento::APPROVAZIONE->value);
    }

    /** La riga del piano e, se resta fuori, la cosa che lo rimette come il preventivo approvato. @return list<array<string, mixed>> */
    private function righeDelPiano(ContestoDellaDelibera $c, PianoRate $piano, ?VersionePreventivo $versione): array
    {
        $titolo = 'Piano rate «' . $piano->nome . '»: approvato';
        $ruolo = RuoloCollegamento::DELIBERA->value;
        $diQuesta = CollegamentiDelleDelibere::delibera($piano, $ruolo)?->id === $c->delibera->id;
        if ($diQuesta) {
            return [RigaDelSeguito::applica($c, $piano, $ruolo, self::PIANO, $titolo, 'Applicata: il piano è approvato con la delibera.')];
        }
        if ($piano->tipo_autorizzazione === 'urgenza' || $piano->rifiutoDegliAddebiti() !== null) {
            return [RigaDelSeguito::senzaSpunta($c, 'gia_fatto', self::PIANO, $titolo, 'Il piano non si approva con una delibera.', null, $piano, false, [], $ruolo)];
        }
        if ($piano->stato === StatoPianoRate::APPROVATO) {
            $perche = $this->giaApprovato($piano);

            return [RigaDelSeguito::senzaSpunta($c, 'fuori', self::PIANO, $titolo, $perche, $perche, $piano, false, [], $ruolo)];
        }
        $differenza = $this->differenza($versione, $piano);
        if ($differenza !== null) {
            return [
                RigaDelSeguito::senzaSpunta($c, 'fuori', self::PIANO, 'Piano rate «' . $piano->nome . '»: resta fuori', $differenza['frase']
                    . ' Rimetti il piano come il preventivo approvato, poi «Applica».', $differenza['frase'], $piano, false,
                    ['differenza' => $differenza, 'aspetta' => true], $ruolo),
                RigaDelSeguito::cosa($c, TipoCosaDaFare::RIMETTI_PIANO, self::PIANO, 'Piano rate «' . $piano->nome . '»: rimettilo come il preventivo approvato',
                    'La delibera resta «Da eseguire» finché il piano non chiede ciò che l\'assemblea ha approvato: rimesso, «Applica» lo approva e la cosa si '
                    . 'chiude da sola.', 'Rimetti il piano «' . $piano->nome . '» come il preventivo approvato', true, $piano),
            ];
        }

        return [RigaDelSeguito::applica($c, $piano, $ruolo, self::PIANO, $titolo, 'Si applica adesso: il piano passa da bozza ad approvato con la data della riunione.')];
    }

    /** Il piano della versione, o un piano fra gli oggetti del punto. */
    private function piano(ContestoDellaDelibera $c, ?VersionePreventivo $versione): ?PianoRate
    {
        if ($versione?->piano_rate_id !== null) {
            $piano = PianoRate::query()->whereKey($versione->piano_rate_id)->where('condominio_id', $c->delibera->condominio_id)->first();
            if ($piano !== null) {
                return $piano;
            }
        }
        $scheda = $c->scheda('piano_rate');

        return $scheda instanceof PianoRate ? $scheda : null;
    }

    /**
     * Decisione 622 (8): «Il piano chiede € 49.200,00, l'assemblea ha approvato € 48.000,00», con le voci cambiate, il riparto cambiato e le rate generate
     * su un'altra cifra. Null se il piano chiede ciò che l'assemblea ha approvato.
     *
     * @return ?array<string, mixed>
     */
    private function differenza(?VersionePreventivo $versione, PianoRate $piano): ?array
    {
        if ($versione === null) {
            return null;
        }
        $d = app(VersioniDelPreventivo::class)->differenzeDaOggi($versione, $piano);
        if ($d['uguale']) {
            return null;
        }
        $euro = fn (int $c) => (string) FormatoDelValore::testo($c, 'euro');
        $voci = array_map(fn ($v) => ['nome' => $v['nome'], 'prima' => $v['prima'], 'dopo' => $v['dopo'],
            'frase' => 'la voce «' . $v['nome'] . '» è passata da ' . $euro($v['prima']) . ' a ' . $euro($v['dopo'])], $d['voci']);
        $tabelle = array_map(fn ($t) => ['nome' => $t['nome'], 'prima' => self::tabelleInParole($t['prima']), 'dopo' => self::tabelleInParole($t['dopo']),
            'frase' => 'la voce «' . $t['nome'] . '» è passata ' . self::daA($t['prima'], 'da') . ' ' . self::daA($t['dopo'], 'a')], $d['tabelle']);
        $rate = $d['rate'] === null ? null : ['chiede' => $d['rate']['chiede'], 'generate_il' => $d['rate']['generate_il'], 'frase' => 'rate generate'
            . ($d['rate']['generate_il'] !== null ? ' il ' . Carbon::parse($d['rate']['generate_il'])->format('d/m/Y') : '')];
        $frasi = [];
        if ($voci !== [] || $rate !== null || $d['oggi'] !== $d['fermato']) {
            $frasi[] = 'Il piano chiede ' . $euro($d['oggi']) . ($rate !== null ? ' (' . $rate['frase'] . ')' : '') . ', l\'assemblea ha approvato '
                . $euro($d['fermato']) . '.';
        }
        if ($tabelle !== []) {
            $frasi[] = 'Il riparto non è quello approvato: ' . implode('; ', array_column($tabelle, 'frase')) . '.';
        }

        return ['frase' => implode(' ', $frasi), 'fermato' => $d['fermato'], 'oggi' => $d['oggi'], 'voci' => $voci, 'tabelle' => $tabelle, 'rate' => $rate];
    }

    /** «la tabella «Proprietà»» o «le tabelle «Proprietà» 60 %, «Scala A» 40 %». @param list<array{nome: string, coefficiente: float}> $tabelle */
    private static function tabelleInParole(array $tabelle): string
    {
        if (count($tabelle) === 1 && abs($tabelle[0]['coefficiente'] - 100) < 0.001) {
            return 'la tabella «' . $tabelle[0]['nome'] . '»';
        }

        return $tabelle === [] ? 'nessuna tabella' : 'le tabelle ' . implode(', ', array_map(fn ($t) => '«' . $t['nome'] . '» '
            . rtrim(rtrim(number_format($t['coefficiente'], 2, ',', '.'), '0'), ',') . ' %', $tabelle));
    }

    /** «dalla tabella «Proprietà»», «alle tabelle …»: la preposizione articolata. @param list<array{nome: string, coefficiente: float}> $tabelle */
    private static function daA(array $tabelle, string $preposizione): string
    {
        $parole = self::tabelleInParole($tabelle);
        foreach (['la tabella' => 'lla tabella', 'le tabelle' => 'lle tabelle'] as $articolo => $articolata) {
            if (str_starts_with($parole, $articolo)) {
                return $preposizione . $articolata . substr($parole, strlen($articolo));
            }
        }

        return $preposizione . ' ' . $parole;
    }

    /** Il giorno in cui la versione è stata fermata, nel fuso dell'utente. */
    private static function fermataIl(VersionePreventivo $versione): string
    {
        return (string) $versione->created_at?->copy()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y');
    }

    private function giaApprovato(PianoRate $piano): string
    {
        return 'Il piano «' . $piano->nome . '» è già approvato' . ($piano->approvato_il !== null ? ' dal ' . $piano->approvato_il->format('d/m/Y') : '')
            . ': la delibera si collega dalla pagina del piano.';
    }
}
