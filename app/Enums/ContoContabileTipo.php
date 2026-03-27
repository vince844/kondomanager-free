<?php

namespace App\Enums;

enum ContoContabileTipo: string
{
    case ATTIVO  = 'attivo';
    case PASSIVO = 'passivo';
    case COSTO   = 'costo';
    case RICAVO  = 'ricavo';
}