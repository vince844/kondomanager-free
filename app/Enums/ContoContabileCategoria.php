<?php

namespace App\Enums;

enum ContoContabileCategoria: string
{
    case LIQUIDITA = 'liquidita';
    case CREDITI   = 'crediti';
    case DEBITI    = 'debiti';
    case FONDI     = 'fondi';
    case COSTI     = 'costi';
    case RICAVI    = 'ricavi';
}