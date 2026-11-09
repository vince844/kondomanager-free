<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una carta dello studio: come esce la testata delle stampe del suo gruppo di documenti. A campi (lo studio e il condominio scritti dal
 * programma) o con la carta intestata caricata; il logo sì o no; il colore dei titoli e delle righe, nullo per i colori di oggi di ogni stampa.
 * Una sola è la predefinita. Le regole stanno in {@see \App\Services\PDF\CarteDelloStudio}.
 *
 * Una carta salvata o eliminata: la carta della stampa si rilegge ({@see \App\Services\PDF\CartaDiStampa}, che la legge una volta per richiesta).
 *
 * @property int $id
 * @property string $nome
 * @property string $aspetto
 * @property bool $mostra_logo
 * @property ?string $colore
 * @property ?string $carta_path il file della carta intestata, sul disco dei file pubblici
 * @property ?string $carta_tipo «pdf» o «immagine»
 * @property ?int $carta_pagine
 * @property ?float $margine_alto in mm, come gli altri tre
 * @property ?float $margine_basso
 * @property ?float $margine_sinistro
 * @property ?float $margine_destro
 * @property bool $predefinita
 */
class CartaStampa extends Model
{
    public const ASPETTO_CAMPI = 'campi';

    public const ASPETTO_CARTA = 'carta';

    protected $table = 'carte_stampa';

    protected $fillable = ['nome', 'aspetto', 'mostra_logo', 'colore', 'carta_path', 'carta_tipo', 'carta_pagine',
        'margine_alto', 'margine_basso', 'margine_sinistro', 'margine_destro', 'predefinita'];

    protected $casts = [
        'mostra_logo' => 'boolean',
        'predefinita' => 'boolean',
        'carta_pagine' => 'integer',
        'margine_alto' => 'float',
        'margine_basso' => 'float',
        'margine_sinistro' => 'float',
        'margine_destro' => 'float',
    ];

    protected static function booted(): void
    {
        $rileggi = fn () => app()->forgetInstance(\App\Services\PDF\CartaDiStampa::class);
        static::saved($rileggi);
        static::deleted($rileggi);
    }
}
