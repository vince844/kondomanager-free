<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use App\Models\Condominio;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Il secondo livello del registro (decisione 518): un gesto è una riga sola, con il riepilogo («Piano «Ordinario 2027» generato: 12 rate, 312 quote»)
 * e il rimando, dove il programma scrive in blocco o in tante righe che sono un gesto solo.
 *
 * Si costruisce prima del lavoro, con i nomi già copiati (il condominio che si sta per eliminare ha ancora il nome); il riepilogo e il rimando possono
 * essere funzioni, chiamate alla fine di `durante` con il lotto e l'esito del lavoro.
 */
final class Gesto
{
    private string $azione = 'gesto';

    private ?string $schedaTipo = null;

    private ?int $schedaId = null;

    private string|Closure|null $riepilogo = null;

    private ?string $perche = null;

    private array|Closure|null $rimando = null;

    /** @var list<array<string, mixed>> */
    private array $campi = [];

    /** @var array<int, string> */
    private array $condomini = [];

    /** @var array<string, array{0: string, 1: int}> */
    private array $soggetti = [];

    /** @var array<string, mixed> */
    private array $lottoAggiunto = [];

    private ?\DateTimeInterface $avvenutaIl = null;

    private bool $soloSeCambia = false;

    /** @var list<Model> */
    private array $conIlTratto = [];

    /** @var list<Closure> */
    private array $allaFine = [];

    private ?Closure $scriviSe = null;

    private function __construct(
        private string $area,
        private string $oggettoTipo,
        private ?int $oggettoId,
        private string $oggettoNome,
    ) {}

    public static function su(string $area, string $oggettoTipo, ?int $oggettoId, string $oggettoNome): self
    {
        return new self($area, $oggettoTipo, $oggettoId, $oggettoNome);
    }

    /**
     * Un gesto su una scheda registrata: area, tipo, nome, condomini e scheda dalla sua descrizione ({@see CatalogoDelRegistro}). Per un modello senza
     * descrizione, il tipo è il nome della tabella e l'area va data.
     */
    public static function sulModello(Model $modello, ?string $area = null): self
    {
        $descrizione = CatalogoDelRegistro::descrizionePer($modello);
        if ($descrizione === null) {
            $gesto = new self($area ?? 'condominio', CatalogoDelRegistro::tipoDi($modello), $modello->getKey() === null ? null : (int) $modello->getKey(),
                (string) ($modello->getAttribute('nome') ?? $modello->getAttribute('name') ?? ''));
            if (($id = $modello->getAttribute('condominio_id')) !== null) {
                $gesto->nelCondominio((int) $id);
            }

            return $gesto;
        }

        $gesto = new self($area ?? $descrizione->areaDi($modello), $descrizione->tipo(), $modello->getKey() === null ? null : (int) $modello->getKey(),
            $descrizione->nome($modello));
        $gesto->condomini = $descrizione->condomini($modello);
        [$schedaTipo, $schedaId] = $descrizione->scheda($modello);
        if ($schedaTipo !== $descrizione->tipo() || $schedaId !== $gesto->oggettoId) {
            $gesto->schedaTipo = $schedaTipo;
            $gesto->schedaId = $schedaId;
        }
        foreach ($descrizione->soggetti($modello) as [$tipo, $id]) {
            $gesto->soggetto($tipo, $id);
        }

        return $gesto;
    }

    public function azione(string $azione): self
    {
        $this->azione = $azione;

        return $this;
    }

    /** Il riepilogo, o una funzione `fn (Lotto $lotto, mixed $esito): string` chiamata alla fine del lavoro. */
    public function riepilogo(string|Closure|null $riepilogo): self
    {
        $this->riepilogo = $riepilogo;

        return $this;
    }

    public function perche(?string $perche): self
    {
        $this->perche = $perche === null || trim($perche) === '' ? null : $perche;

        return $this;
    }

    /** Il rimando `{tipo, id, etichetta}`, o una funzione `fn (Lotto $lotto, mixed $esito): ?array`. */
    public function rimando(string|Closure $tipo, ?int $id = null, ?string $etichetta = null): self
    {
        $this->rimando = $tipo instanceof Closure ? $tipo : ['tipo' => $tipo, 'id' => $id, 'etichetta' => $etichetta];

        return $this;
    }

    /** Un campo «com'era → com'è» del gesto (i millesimi di un'unità, i destinatari di una comunicazione). */
    public function campo(string $chiave, string $etichetta, ?string $prima, ?string $dopo, mixed $valorePrima = null, mixed $valoreDopo = null,
        bool $segreto = false): self
    {
        $this->campi[] = Campo::nuovo($chiave, $etichetta, $prima, $dopo, $valorePrima, $valoreDopo, $segreto);

        return $this;
    }

    /** @param list<array<string, mixed>> $campi */
    public function campi(array $campi): self
    {
        foreach ($campi as $campo) {
            $this->campi[] = $campo;
        }

        return $this;
    }

    /** Il condominio della riga, con il nome copiato adesso (prima di un'eliminazione). */
    public function nelCondominio(Condominio|int|null $condominio, ?string $nome = null): self
    {
        if ($condominio === null) {
            return $this;
        }
        if ($condominio instanceof Condominio) {
            $this->condomini[(int) $condominio->getKey()] = (string) $condominio->nome;

            return $this;
        }
        $this->condomini[$condominio] = $nome ?? (string) (Condominio::query()->whereKey($condominio)->value('nome') ?? '');

        return $this;
    }

    /** @param iterable<int|Condominio> $condomini */
    public function neiCondomini(iterable $condomini): self
    {
        $ids = [];
        foreach ($condomini as $c) {
            if ($c instanceof Condominio) {
                $this->condomini[(int) $c->getKey()] = (string) $c->nome;
            } elseif ($c !== null) {
                $ids[] = (int) $c;
            }
        }
        if ($ids !== []) {
            foreach (Condominio::query()->whereIn('id', $ids)->pluck('nome', 'id') as $id => $nome) {
                $this->condomini[(int) $id] = (string) $nome;
            }
        }

        return $this;
    }

    public function scheda(?string $tipo, ?int $id): self
    {
        $this->schedaTipo = $tipo;
        $this->schedaId = $id;

        return $this;
    }

    /** Una persona di cui la riga contiene dati: `anagrafica`, `fornitore` o `utente`. */
    public function soggetto(string $tipo, ?int $id): self
    {
        if ($id !== null) {
            $this->soggetti[$tipo . ':' . $id] = [$tipo, $id];
        }

        return $this;
    }

    /** @param iterable<int> $ids */
    public function soggettiDi(string $tipo, iterable $ids): self
    {
        foreach ($ids as $id) {
            $this->soggetto($tipo, (int) $id);
        }

        return $this;
    }

    /** Dati in più del lotto (le prime e ultime sequenze di un ripristino, i conteggi letti prima di un'eliminazione). @param array<string, mixed> $dati */
    public function lotto(array $dati): self
    {
        $this->lottoAggiunto = array_replace($this->lottoAggiunto, $dati);

        return $this;
    }

    /** La scheda che dentro il gesto scrive lo stesso i suoi campi (la fattura modificata: i suoi dati nella sua riga, le scritture riscritte nel lotto). */
    public function conIlTrattoDi(Model $modello): self
    {
        $this->conIlTratto[] = $modello;

        return $this;
    }

    /** Una funzione `fn (Gesto $gesto, Lotto $lotto, mixed $esito)` chiamata alla fine del lavoro, prima di decidere se scrivere (i campi di dopo). */
    public function allaFine(Closure $funzione): self
    {
        $this->allaFine[] = $funzione;

        return $this;
    }

    public function chiudi(Lotto $lotto, mixed $esito): void
    {
        foreach ($this->allaFine as $funzione) {
            $funzione($this, $lotto, $esito);
        }
        $this->allaFine = [];
    }

    public function preparaIlLotto(Lotto $lotto): void
    {
        foreach ($this->conIlTratto as $modello) {
            $lotto->lascia($modello);
        }
    }

    /**
     * Un salvataggio che non cambia niente non scrive la riga: senza campi e senza dati in più il gesto non c'è, anche se il lotto ha contato righe
     * risalvate uguali (cambia solo chi ha salvato, P8).
     */
    public function soloSeCambia(): self
    {
        $this->soloSeCambia = true;

        return $this;
    }

    /** La riga si scrive solo se la funzione `fn (Lotto $lotto, mixed $esito): bool` dice di sì (un riallineamento senza rettifiche non scrive). */
    public function scriviSe(Closure $condizione): self
    {
        $this->scriviSe = $condizione;

        return $this;
    }

    public function daScrivere(Lotto $lotto, mixed $esito = null): bool
    {
        if ($this->scriviSe !== null && ! ($this->scriviSe)($lotto, $esito)) {
            return false;
        }

        return ! $this->soloSeCambia || $this->campi !== [] || $this->lottoAggiunto !== [];
    }

    public function avvenutaIl(?\DateTimeInterface $quando): self
    {
        $this->avvenutaIl = $quando;

        return $this;
    }

    public function oggettoTipo(): string
    {
        return $this->oggettoTipo;
    }

    public function oggettoId(): ?int
    {
        return $this->oggettoId;
    }

    /** L'oggetto, quando si conosce solo dopo il lavoro (il piano appena creato). */
    public function oggetto(?int $id, ?string $nome = null): self
    {
        $this->oggettoId = $id;
        if ($nome !== null) {
            $this->oggettoNome = $nome;
        }

        return $this;
    }

    /**
     * La riga da scrivere, con il lotto del lavoro e il suo esito.
     *
     * @return array<string, mixed>
     */
    public function perLaRiga(?Lotto $lotto = null, mixed $esito = null): array
    {
        $lotto ??= new Lotto();
        $riepilogo = $this->riepilogo instanceof Closure ? ($this->riepilogo)($lotto, $esito) : $this->riepilogo;
        $rimando = $this->rimando instanceof Closure ? ($this->rimando)($lotto, $esito) : $this->rimando;
        $datiDelLotto = array_replace($lotto->perLaRiga(), $this->lottoAggiunto);

        return [
            'area' => $this->area,
            'oggetto_tipo' => $this->oggettoTipo,
            'oggetto_id' => $this->oggettoId,
            'oggetto_nome' => $this->oggettoNome,
            'scheda_tipo' => $this->schedaTipo,
            'scheda_id' => $this->schedaId,
            'azione' => $this->azione,
            'riepilogo' => $riepilogo === null ? null : (string) $riepilogo,
            'perche' => $this->perche,
            'campi' => $this->campi === [] ? null : $this->campi,
            'rimando' => $rimando,
            'lotto' => $datiDelLotto === [] ? null : $datiDelLotto,
            'condomini' => $this->condomini,
            'soggetti' => array_values($this->soggetti),
            'avvenuta_il' => $this->avvenutaIl,
        ];
    }
}
