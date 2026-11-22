<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Ripristino;

use App\Models\Gestionale\RegistroInvio;
use App\Models\RegistroModifica;
use App\Models\User;
use App\Services\RegistroModifiche\CatalogoDelRegistro;
use App\Services\RegistroModifiche\FirmaDelRegistro;
use App\Services\RegistroModifiche\FormatoDelValore;
use App\Services\RegistroModifiche\ImpostazioniNelRegistro;
use App\Services\RegistroModifiche\LetturaDelRegistro;
use App\Services\RegistroModifiche\SchedeDellaStoria;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;

/**
 * «Ripristina» un campo di una riga «modificata» della «Storia» (§8 e P3 del progetto della tappa 1; decisione 518 (5)).
 *
 * Il ripristino è una modifica come le altre: passa dalle stesse regole della scheda e diventa una riga del registro con il nome di chi lo fa.
 *  1. **Si può?** ({@see perCuiNo()}): la riga è «modificata», non è annullata da un ripristino di una copia né oscurata; il campo è fra quelli che la
 *     scheda dichiara ripristinabili (mai in contabilità, nei piani rate e nei millesimi, §8), non è un segreto; la scheda c'è ancora e chi chiede la può
 *     modificare (la policy della pagina).
 *  2. **La scheda intera com'è oggi**, nella forma del modulo della pagina ({@see ModuloDellaScheda::modulo()}), con il solo campo sostituito dal valore di
 *     prima; la `FormRequest` di modifica della pagina, con la rotta della pagina legata alla scheda e l'utente, **validata per intero**
 *     (`validateResolved()`: `authorize`, `prepareForValidation`, le regole). Se rifiuta, l'errore della pagina torna alla finestra (P3: una scadenza
 *     del documento ormai passata).
 *  3. **Solo quel campo** si scrive, con la scrittura della pagina, dentro {@see RegistroModifiche::comeRipristino()}: il tratto scrive la riga
 *     «ripristinata» con il perché «Ripristina dalla Storia, modifica del 16/12/2026 alle 09:12».
 */
final class RipristinoDiUnCampo
{
    /** Le aree in cui non si ripristina mai (§8): la riga dice come si cambia. */
    public const AREE_SENZA_RIPRISTINO = ['contabilita', 'piani_rate', 'millesimi'];

    /** Il modulo della pagina per tipo di scheda. @var array<string, class-string<ModuloDellaScheda>> */
    public const MODULI = [
        'anagrafica' => ModuloAnagrafica::class,
        'fornitore' => ModuloFornitore::class,
        'condominio' => ModuloCondominio::class,
        'immobile' => ModuloImmobile::class,
        'palazzina' => ModuloPalazzina::class,
        'scala' => ModuloScala::class,
        'comunicazione' => ModuloComunicazione::class,
        'segnalazione' => ModuloSegnalazione::class,
        'documento' => ModuloDocumento::class,
        'impostazioni_stampe' => ModuloImpostazioniStampe::class,
    ];

    /** I campi di un indirizzo di posta: per questi la finestra dice se dal giorno della modifica qualcosa è partito al nuovo indirizzo. */
    public const INDIRIZZI = ['email', 'email_secondaria', 'pec', 'studio_email', 'studio_pec'];

    /** La frase sotto una riga «modificata» di un'area senza ripristino (§8; la bozza: «In contabilità non si ripristina: si storna, dalla fattura»). */
    public static function notaDellArea(RegistroModifica $riga): ?string
    {
        if ($riga->azione !== 'modificata') {
            return null;
        }

        return match ($riga->area) {
            'contabilita' => $riga->oggetto_tipo === 'fattura_passiva' ? 'In contabilità non si ripristina: si storna, dalla fattura.'
                : 'In contabilità non si ripristina: si corregge dalla scheda, con uno storno se serve.',
            'piani_rate', 'millesimi', 'assemblee' => 'Qui non si ripristina: si cambia dalla scheda.',
            default => null,
        };
    }

    /** I campi ripristinabili del tipo, come li dichiara la scheda (le impostazioni: senza il gruppo davanti). @return list<string> */
    public static function ripristinabili(string $tipo): array
    {
        if ($tipo === 'impostazioni_stampe') {
            return array_map(fn (string $c) => substr($c, strlen('print.')),
                array_values(array_filter(ImpostazioniNelRegistro::RIPRISTINABILI, fn (string $c) => str_starts_with($c, 'print.'))));
        }

        return CatalogoDelRegistro::dichiarazioni()[$tipo]['ripristinabili'] ?? [];
    }

    /** Il campo della riga, se c'è. @return array<string, mixed>|null */
    public static function campoDellaRiga(RegistroModifica $riga, string $campo): ?array
    {
        foreach ($riga->campi ?? [] as $c) {
            if (($c['chiave'] ?? null) === $campo) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Perché il campo di questa riga non si ripristina, con le parole della finestra; `null` se si può. Senza `$cosa` la si cerca (per un elenco di righe la
     * si passa, letta una volta); senza `$firmata` si controlla la firma della riga (per un elenco di righe la si passa, letta una volta per tutte).
     * Una riga senza la firma del programma non si rimette: «Ripristina» scriverebbe sulla scheda il valore di chi l'ha aggiunta con il database.
     */
    public static function perCuiNo(RegistroModifica $riga, string $campo, User $utente, ?array $annullamento = null, ?object $cosa = null,
        ?bool $firmata = null): ?string
    {
        if ($riga->azione !== 'modificata') {
            return 'Si rimette un dato di una modifica: questa riga non lo è.';
        }
        if (in_array($riga->area, self::AREE_SENZA_RIPRISTINO, true)) {
            return self::notaDellArea($riga) ?? 'Qui non si ripristina: si cambia dalla scheda.';
        }
        if ($riga->oscurata_il !== null) {
            return 'I dati di questa riga sono oscurati: non si rimettono.';
        }
        if (LetturaDelRegistro::eAnnullata($riga, $annullamento ?? LetturaDelRegistro::annullamento())) {
            return 'Questa modifica è stata annullata dal ripristino di una copia: non si rimette.';
        }
        if (! ($firmata ?? self::firmate([$riga])[(int) $riga->id])) {
            return 'Questa riga non ha la firma del programma: è stata aggiunta o cambiata fuori dal programma, e non si rimette.';
        }
        $modulo = isset(self::MODULI[$riga->oggetto_tipo]) ? new (self::MODULI[$riga->oggetto_tipo])() : null;
        $c = self::campoDellaRiga($riga, $campo);
        if ($modulo === null || $c === null || ! in_array($campo, self::ripristinabili($riga->oggetto_tipo), true)) {
            return 'Questo dato non si rimette dalla «Storia»: si cambia dalla scheda.';
        }
        if (! empty($c['segreto'])) {
            return 'È un dato segreto: il registro non tiene il valore, e non si rimette.';
        }
        $cosa ??= $modulo->cosa($riga->oggetto_id);
        if ($cosa === null) {
            return 'La scheda non c\'è più: non si rimette niente.';
        }
        if (! $modulo->puoModificare($utente, $cosa)) {
            return 'Per rimettere un dato serve il permesso di modificare la scheda.';
        }

        return null;
    }

    /**
     * Se la firma di nascita delle righe torna ({@see FirmaDelRegistro}): i condomini e i soggetti letti una volta per tutte.
     *
     * @param iterable<RegistroModifica> $righe
     * @return array<int, bool> riga => firmata
     */
    public static function firmate(iterable $righe): array
    {
        return FirmaDelRegistro::delleRighe($righe);
    }

    /**
     * Ciò che la finestra della bozza mostra: il titolo («Rimettere l'email di prima?»), chi e quando, «Adesso» e «Torna a», la frase sul registro degli
     * invii per un indirizzo di posta, il pulsante.
     *
     * @return array<string, mixed>
     */
    public static function anteprima(RegistroModifica $riga, string $campo, User $utente): array
    {
        self::autorizza($riga, $utente);
        $motivo = self::perCuiNo($riga, $campo, $utente);
        if ($motivo !== null) {
            throw new RipristinoNonPossibile($motivo);
        }
        $modulo = new (self::MODULI[$riga->oggetto_tipo])();
        $cosa = $modulo->cosa($riga->oggetto_id);
        $c = self::campoDellaRiga($riga, $campo);
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $quando = $riga->avvenuta_il->copy()->setTimezone($fuso);
        $oggetto = ParoleDelRipristino::oggetto($campo, (string) $c['etichetta'], $riga->oggetto_tipo);
        $adesso = self::testoDiOggi($riga, $campo, $modulo->valoreDiOggi($cosa, $campo));

        return [
            'riga_id' => $riga->id,
            'campo' => $campo,
            'etichetta' => $c['etichetta'],
            'titolo' => 'Rimettere ' . $oggetto . ' di prima?',
            'descrizione' => self::descrizione($riga, $campo, (string) $c['etichetta'], $cosa, $quando),
            'adesso' => $adesso,
            'torna_a' => $c['prima'],
            'gia_cosi' => self::uguali($modulo->valoreDiOggi($cosa, $campo), $c['valore_prima'] ?? null),
            'invii' => in_array($campo, self::INDIRIZZI, true) ? self::fraseDegliInvii($riga, (string) ($c['dopo'] ?? '')) : null,
            'pulsante' => 'Rimetti ' . $oggetto . ' di prima',
        ];
    }

    /**
     * La riga sotto il titolo della finestra, la frase della bozza (scena 1): «Email di Ida Romano, cambiata da Marco Galli il 16/12/2026 alle 09:12.». Il
     * nome della scheda senza la parola del tipo, come il titolo della «Storia» ({@see SchedeDellaStoria::titolo()}): «di Ida Romano», «della comunicazione
     * «Lavori al portone»», «delle impostazioni delle stampe».
     */
    private static function descrizione(RegistroModifica $riga, string $campo, string $etichetta, ?object $cosa, \Illuminate\Support\Carbon $quando): string
    {
        [$dato, $cambiato] = ParoleDelRipristino::datoECambiato($campo, $etichetta, $riga->oggetto_tipo);
        $titolo = SchedeDellaStoria::titolo($riga->oggetto_tipo, $cosa instanceof \Illuminate\Database\Eloquent\Model ? $cosa : null, $riga->oggetto_nome);
        $di = (string) preg_replace('/^Storia\s*:?\s*/u', '', $titolo);
        if (preg_match('/^(di|del|dello|della|dei|degli|delle|dell\')\b/u', $di) !== 1 && ! str_starts_with($di, 'dell\'')) {
            $di = 'di ' . $di;
        }

        return $dato . ' ' . $di . ', ' . $cambiato . ' da ' . $riga->autore_nome . ' il ' . $quando->format('d/m/Y') . ' alle ' . $quando->format('H:i') . '.';
    }

    /**
     * Rimette il valore di prima: la `FormRequest` della pagina validata per intero sulla scheda com'è oggi con il solo campo cambiato, poi la scrittura
     * del solo campo, in una riga «ripristinata».
     *
     * @throws RipristinoNonPossibile quando non si può, con la frase
     * @throws \Illuminate\Validation\ValidationException quando la scheda non passa le regole della pagina
     * @throws AuthorizationException quando la pagina non autorizza
     */
    public static function ripristina(RegistroModifica $riga, string $campo, User $utente): void
    {
        self::autorizza($riga, $utente);
        $motivo = self::perCuiNo($riga, $campo, $utente);
        if ($motivo !== null) {
            throw new RipristinoNonPossibile($motivo);
        }
        $modulo = new (self::MODULI[$riga->oggetto_tipo])();
        $cosa = $modulo->cosa($riga->oggetto_id);
        $c = self::campoDellaRiga($riga, $campo);
        $valore = $modulo->valoreDelModulo($campo, $c['valore_prima'] ?? null);
        if (self::uguali($modulo->valoreDiOggi($cosa, $campo), $c['valore_prima'] ?? null)) {
            throw new RipristinoNonPossibile('È già così: ' . ($c['prima'] ?? 'vuoto') . '. Non c\'è niente da rimettere.');
        }
        if (($no = $modulo->nonAmmesso($cosa, $campo, $valore)) !== null) {
            throw new RipristinoNonPossibile($no);
        }

        $dati = array_replace($modulo->modulo($cosa), [$campo => $valore]);
        $richiesta = self::richiestaDellaPagina($modulo, $cosa, $dati, $utente);
        $richiesta->validateResolved();
        $validato = $richiesta->validated();
        $nuovo = array_key_exists($campo, $validato) ? $validato[$campo] : $valore;

        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $quando = $riga->avvenuta_il->copy()->setTimezone($fuso);
        $perche = 'Ripristina dalla Storia, modifica del ' . $quando->format('d/m/Y') . ' alle ' . $quando->format('H:i');

        RegistroModifiche::comeRipristino($perche, fn () => DB::transaction(fn () => $modulo->scrivi($cosa, $campo, $nuovo, $utente)));
    }

    /**
     * «Ripristina» chiede di poter aprire la «Storia» della scheda della riga, con le regole del cassetto ({@see SchedeDellaStoria::puoVedere()}: le storie
     * dello studio solo all'amministratore), e in più il permesso di modificare quella scheda (§15): senza, un 403 come la pagina di modifica, prima di ogni
     * altra risposta. Così la finestra non mostra chi ha cambiato che cosa a chi la «Storia» non la può aprire.
     *
     * @throws AuthorizationException
     */
    public static function autorizza(RegistroModifica $riga, User $utente): void
    {
        $tipo = (string) ($riga->scheda_tipo ?? $riga->oggetto_tipo);
        $id = $riga->scheda_tipo !== null ? $riga->scheda_id : $riga->oggetto_id;
        if (! SchedeDellaStoria::esiste($tipo)
            || ! SchedeDellaStoria::puoVedere($utente, $tipo, SchedeDellaStoria::cosa($tipo, SchedeDellaStoria::conId($tipo) ? $id : null))) {
            throw new AuthorizationException(__('errors.403_message'));
        }
        $modulo = isset(self::MODULI[$riga->oggetto_tipo]) ? new (self::MODULI[$riga->oggetto_tipo])() : null;
        $cosa = $modulo?->cosa($riga->oggetto_id);
        if ($modulo !== null && $cosa !== null && ! $modulo->puoModificare($utente, $cosa)) {
            throw new AuthorizationException('Per rimettere un dato serve il permesso di modificare la scheda.');
        }
    }

    /** La `FormRequest` di modifica della pagina, con la rotta della pagina legata alla scheda e l'utente: come la crea Laravel per la pagina. */
    private static function richiestaDellaPagina(ModuloDellaScheda $modulo, object $cosa, array $dati, User $utente): FormRequest
    {
        $parametri = $modulo->parametri($cosa);
        $url = route($modulo->rotta(), array_map(fn ($p) => $p instanceof \Illuminate\Database\Eloquent\Model ? $p->getRouteKey() : $p, $parametri));
        $base = Request::create($url, $modulo->metodo(), $dati);
        $base->headers->set('Accept', 'application/json');

        $classe = $modulo->richiesta();
        /** @var FormRequest $richiesta */
        $richiesta = $classe::createFrom($base, new $classe());
        $richiesta->setContainer(app())->setRedirector(app('redirect'));
        $richiesta->setUserResolver(fn () => $utente);

        /** @var Route $rotta */
        $rotta = clone app('router')->getRoutes()->getByName($modulo->rotta());
        $rotta->bind($richiesta);
        foreach ($parametri as $nome => $valore) {
            $rotta->setParameter($nome, $valore);
        }
        $richiesta->setRouteResolver(fn () => $rotta);

        return $richiesta;
    }

    /** Il valore di oggi con le parole della pagina, come il registro scrive il «prima» e il «dopo». */
    private static function testoDiOggi(RegistroModifica $riga, string $campo, mixed $grezzo): ?string
    {
        $descrizione = CatalogoDelRegistro::descrizionePerTipo($riga->oggetto_tipo);
        $formato = $descrizione?->formato($campo) ?? (ImpostazioniNelRegistro::ETICHETTE['print'][$campo][1] ?? 'testo');

        return FormatoDelValore::testo($grezzo, $formato);
    }

    private static function uguali(mixed $oggi, mixed $prima): bool
    {
        $norma = function (mixed $v): ?string {
            if ($v === null || $v === '') {
                return null;
            }
            $v = (string) (\App\Services\RegistroModifiche\Campo::grezzo($v) ?? '');

            return preg_match('/^\d{4}-\d{2}-\d{2} 00:00:00$/', $v) ? substr($v, 0, 10) : $v;
        };

        return $norma($oggi) === $norma($prima);
    }

    /**
     * La frase della bozza per un indirizzo di posta: se dal giorno della modifica il registro degli invii ha qualcosa partito al nuovo indirizzo, il
     * ripristino non lo cambia e non lo rimanda. `null` se non c'è niente.
     */
    public static function fraseDegliInvii(RegistroModifica $riga, string $indirizzo): ?string
    {
        $indirizzo = trim($indirizzo);
        if ($indirizzo === '' || ! filter_var($indirizzo, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $invii = RegistroInvio::query()->where('canale', '!=', 'stampa')->where('emesso_il', '>=', $riga->avvenuta_il)
            ->where('destinatario', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], $indirizzo) . '%')
            ->when($riga->oggetto_tipo === 'anagrafica', fn ($q) => $q->where('anagrafica_id', $riga->oggetto_id))
            ->orderByDesc('emesso_il')->orderByDesc('id')->get(['tipo', 'numero', 'emesso_il']);
        if ($invii->isEmpty()) {
            return null;
        }
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $dal = $riga->avvenuta_il->copy()->setTimezone($fuso)->format('d/m');
        $ultimo = $invii->first();
        $cosa = ParoleDelRipristino::documentoInviato((string) $ultimo->tipo) . ($ultimo->numero ? ' n. ' . $ultimo->numero : '');
        if ($invii->count() === 1) {
            return "Dal {$dal} a «{$indirizzo}» è partita un'email ({$cosa}, nel registro degli invii): il ripristino non la cambia e non la rimanda.";
        }

        return "Dal {$dal} a «{$indirizzo}» sono partite {$invii->count()} email (l'ultima, {$cosa}, nel registro degli invii): il ripristino non le cambia e "
            . 'non le rimanda.';
    }
}
