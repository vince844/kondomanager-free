<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

/**
 * Il lotto di un gesto aperto con `durante`: le scritture dei modelli che il gesto ha fatto, contate per tipo e per azione, e fino a 500 id per tipo.
 * Un gesto dentro un altro si conta qui (P10), non scrive una riga sua.
 */
final class Lotto
{
    public const MASSIMO_ID = 500;

    /** @var array<string, int> «tipo.azione» => quante */
    private array $conteggi = [];

    /** @var array<string, list<int>> tipo => id */
    private array $id = [];

    /** @var array<string, true> */
    private array $idVisti = [];

    private int $gesti = 0;

    /** @var array<string, true> le schede che dentro il gesto scrivono lo stesso la loro riga («classe:id») */
    private array $lasciate = [];

    /** La scheda principale del gesto (la fattura modificata) scrive i suoi campi anche dentro il gesto; il resto si conta. */
    public function lascia(\Illuminate\Database\Eloquent\Model $modello): void
    {
        $this->lasciate[$modello::class . ':' . $modello->getKey()] = true;
    }

    public function lasciata(\Illuminate\Database\Eloquent\Model $modello): bool
    {
        return isset($this->lasciate[$modello::class . ':' . $modello->getKey()]);
    }

    public function conta(string $tipo, string $azione, ?int $id = null, int $quante = 1): void
    {
        $chiave = $tipo . '.' . $azione;
        $this->conteggi[$chiave] = ($this->conteggi[$chiave] ?? 0) + $quante;

        if ($id !== null && count($this->id[$tipo] ?? []) < self::MASSIMO_ID && ! isset($this->idVisti[$tipo . ':' . $id])) {
            $this->id[$tipo][] = $id;
            $this->idVisti[$tipo . ':' . $id] = true;
        }
    }

    /** Un gesto annidato: il suo lotto entra in questo, e conta come gesto. */
    public function assorbi(Gesto $gesto, ?Lotto $suo = null): void
    {
        $this->gesti++;
        $this->conta($gesto->oggettoTipo(), 'gesto', $gesto->oggettoId());
        if ($suo !== null) {
            foreach ($suo->conteggi as $chiave => $quante) {
                $this->conteggi[$chiave] = ($this->conteggi[$chiave] ?? 0) + $quante;
            }
            foreach ($suo->id as $tipo => $ids) {
                foreach ($ids as $id) {
                    if (count($this->id[$tipo] ?? []) < self::MASSIMO_ID && ! isset($this->idVisti[$tipo . ':' . $id])) {
                        $this->id[$tipo][] = $id;
                        $this->idVisti[$tipo . ':' . $id] = true;
                    }
                }
            }
        }
    }

    public function quante(string $tipo, ?string $azione = null): int
    {
        if ($azione !== null) {
            return $this->conteggi[$tipo . '.' . $azione] ?? 0;
        }

        $totale = 0;
        foreach ($this->conteggi as $chiave => $quante) {
            if (str_starts_with($chiave, $tipo . '.')) {
                $totale += $quante;
            }
        }

        return $totale;
    }

    public function vuoto(): bool
    {
        return $this->conteggi === [];
    }

    /** La forma nella riga: `{conteggi: {«tipo.azione»: n}, id: {tipo: [..]}}`, vuota se il gesto non ha toccato modelli. @return array<string, mixed> */
    public function perLaRiga(): array
    {
        if ($this->conteggi === []) {
            return [];
        }
        ksort($this->conteggi);
        ksort($this->id);

        return ['conteggi' => $this->conteggi, 'id' => $this->id];
    }
}
