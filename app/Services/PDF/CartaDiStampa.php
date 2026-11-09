<?php

namespace App\Services\PDF;

use App\Models\CartaStampa;
use App\Services\Documenti\ArchivioDocumenti;
use App\Settings\PrintSettings;
use Illuminate\Database\QueryException;

/**
 * La carta dello studio di una stampa, dato il suo gruppo: i dati dello studio puliti, il logo, la carta del gruppo (`carte_stampa`, scelta in
 * `print.carta_per_gruppo`): il logo sì o no, il colore dei titoli e delle righe, la carta intestata. Un gruppo senza una carta scelta, o con una
 * carta che non c'è più, ha la predefinita; senza la tabella (un aggiornamento a metà), la carta di sempre: a campi, con il logo, i colori di sempre.
 * Il modello F24 non ha carta.
 *
 * La carta intestata vale per le stampe verticali: le orizzontali hanno la testata a campi della stessa carta (lo studio, il logo, il colore), perché
 * la carta è verticale. Una carta intestata che non si legge (il file non c'è più, il disco non risponde, il file non passa più i controlli del
 * caricamento) dà la stessa carta a campi, con una riga nel registro: la stampa esce, con lo studio scritto dal programma se ha il nome.
 *
 * Le carte, il logo e la carta intestata si leggono una volta per richiesta: su S3 `ArchivioDocumenti::percorsoLocalePubblico()` scarica il file a
 * ogni chiamata, e uno .zip di duecento ricevute lo scaricherebbe duecento volte. Un file nuovo ha un percorso nuovo, e si legge di nuovo; una carta
 * salvata o eliminata rifà questa classe ({@see CartaStampa}).
 */
final class CartaDiStampa
{
    /** @var array<string, ?string> il percorso locale del logo, per il percorso sul disco dei file pubblici */
    private array $loghi = [];

    /** @var array<int, CartaStampa>|false|null le carte per id; false: non ancora lette; null: la tabella non c'è */
    private array|false|null $carte = false;

    /** @var array<string, ?string> il percorso locale della carta intestata che si legge (null: non si legge), per il percorso sul disco dei file pubblici */
    private array $intestate = [];

    public function __construct(private readonly ArchivioDocumenti $archivio) {}

    /**
     * I dati dello studio dalle impostazioni di stampa, con i vuoti (o di soli spazi) nulli. Li usano le stampe, le lettere e le loro email.
     *
     * @return array{nome: ?string, indirizzo: ?string, email: ?string, pec: ?string, telefono: ?string, comune: ?string, partita_iva: ?string, codice_fiscale: ?string, sito: ?string}
     */
    public static function studio(): array
    {
        $s = app(PrintSettings::class);
        $pulito = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return ['nome' => $pulito($s->studio_nome), 'indirizzo' => $pulito($s->studio_indirizzo), 'email' => $pulito($s->studio_email),
            'pec' => $pulito($s->studio_pec), 'telefono' => $pulito($s->studio_telefono), 'comune' => $pulito($s->studio_comune),
            'partita_iva' => $pulito($s->studio_partita_iva), 'codice_fiscale' => $pulito($s->studio_codice_fiscale), 'sito' => $pulito($s->studio_sito)];
    }

    /**
     * La carta del gruppo. Con `$primaPaginaIntera` falso la stampa comincia con la riga dello studio (le parti del fascicolo); `$verticale` falso: una
     * stampa orizzontale, che non ha la carta intestata.
     */
    public function perGruppo(GruppoCarta $gruppo, bool $primaPaginaIntera = true, bool $verticale = true): Carta
    {
        if ($gruppo === GruppoCarta::NESSUNO) {
            return $this->carta($gruppo, null, $primaPaginaIntera, false, false);
        }

        return $this->carta($gruppo, $this->scelta($gruppo), $primaPaginaIntera, true, $verticale);
    }

    /** Una carta data, al posto di quella del gruppo: la prova della carta, dalla pagina delle impostazioni. */
    public function perCarta(CartaStampa $scelta, GruppoCarta $gruppo, bool $primaPaginaIntera = true, bool $verticale = true): Carta
    {
        return $this->carta($gruppo, $scelta, $primaPaginaIntera, true, $verticale);
    }

    /**
     * La carta: lo studio, il logo se la carta lo vuole, il colore della carta, la carta intestata sulle stampe verticali. Senza una carta, quella di
     * sempre (il logo sì, i colori di sempre).
     */
    private function carta(GruppoCarta $gruppo, ?CartaStampa $scelta, bool $primaPaginaIntera, bool $conLogo, bool $verticale): Carta
    {
        $studio = self::studio();
        $intestata = $verticale && $scelta !== null ? $this->intestataDi($scelta) : null;
        $logo = null;
        if ($conLogo && $intestata === null && ($scelta?->mostra_logo ?? true) && $studio['nome'] !== null) {
            $logo = $this->logo(app(PrintSettings::class)->logo_stampe_path);
        }

        return new Carta($gruppo, $studio, $logo, $primaPaginaIntera, self::colore($scelta), $intestata);
    }

    /**
     * La carta intestata della carta, se ce l'ha e si legge; null altrimenti. Una che non si legge lo scrive nel registro, una volta per richiesta, e
     * la stampa esce a campi. La dice anche la pagina delle impostazioni.
     */
    public function intestataDi(CartaStampa $scelta): ?CartaIntestata
    {
        if ($scelta->aspetto !== CartaStampa::ASPETTO_CARTA || ! is_string($scelta->carta_path) || trim($scelta->carta_path) === '') {
            return null;
        }
        $percorso = $scelta->carta_path;
        if (! array_key_exists($percorso, $this->intestate)) {
            $locale = $this->archivio->percorsoLocalePubblico($percorso);
            $siLegge = $locale !== null && CartaIntestata::siLegge($locale, (string) $scelta->carta_tipo, self::cartellaTemporanea());
            $this->intestate[$percorso] = $siLegge ? $locale : null;
            if (! $siLegge) {
                \Illuminate\Support\Facades\Log::warning('La carta intestata di una carta delle stampe non si legge: le stampe escono a campi (lo studio lo scrive il programma, se ha il nome).',
                    ['carta' => $scelta->id, 'percorso' => $percorso]);
            }
        }
        $locale = $this->intestate[$percorso];

        return $locale === null ? null : new CartaIntestata($locale, (string) $scelta->carta_tipo, max(1, (int) $scelta->carta_pagine),
            (float) ($scelta->margine_alto ?? 0), (float) ($scelta->margine_basso ?? 0), (float) ($scelta->margine_sinistro ?? 0), (float) ($scelta->margine_destro ?? 0));
    }

    /** La cartella dove mPDF scrive la sua cache, la stessa di `PdfService`. */
    public static function cartellaTemporanea(): string
    {
        $cartella = storage_path('framework/cache/mpdf');
        if (! is_dir($cartella)) {
            @mkdir($cartella, 0775, true);
        }

        return $cartella;
    }

    /**
     * Il colore della carta, se è un colore `#rrggbb`; altrimenti nullo, i colori di sempre. La pagina accetta solo quella forma, ma il colore va
     * dentro uno stile della stampa: uno scritto nel database da un'altra strada (un ripristino, uno strumento del database) con un `url(…)`
     * dentro farebbe scaricare a mPDF quell'indirizzo a ogni stampa.
     */
    private static function colore(?CartaStampa $scelta): ?string
    {
        $colore = $scelta?->colore;
        if ($colore === null || preg_match('/^#[0-9a-f]{6}$/iD', $colore) === 1) {
            return $colore;
        }
        \Illuminate\Support\Facades\Log::warning('Carta delle stampe con un colore che non è #rrggbb: la stampa esce con i colori di sempre.',
            ['carta' => $scelta->id, 'colore' => mb_substr($colore, 0, 200)]);

        return null;
    }

    /** La carta scelta per il gruppo, o la predefinita; null se non ce n'è una (la tabella vuota o assente). */
    private function scelta(GruppoCarta $gruppo): ?CartaStampa
    {
        if ($this->carte === false) {
            try {
                $this->carte = CartaStampa::query()->get()->keyBy('id')->all();
            } catch (QueryException) {
                $this->carte = null;
            }
        }
        if ($this->carte === null) {
            return null;
        }
        $id = app(PrintSettings::class)->carta_per_gruppo[$gruppo->value] ?? null;

        return ($id !== null ? ($this->carte[(int) $id] ?? null) : null)
            ?? collect($this->carte)->first(fn (CartaStampa $c) => $c->predefinita);
    }

    private function logo(?string $percorso): ?string
    {
        if ($percorso === null || trim($percorso) === '') {
            return null;
        }
        if (! array_key_exists($percorso, $this->loghi)) {
            $this->loghi[$percorso] = $this->archivio->percorsoLocalePubblico($percorso);
        }

        return $this->loghi[$percorso];
    }
}
