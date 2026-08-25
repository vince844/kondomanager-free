<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * Il dump SQL non rispetta il formato prodotto da MySqlDumper: statement
 * senza terminatore, stringa mai chiusa, direttiva DELIMITER malformata.
 * In un ripristino equivale a "archivio corrotto o troncato": si interrompe
 * PRIMA di eseguire altro SQL, mai a metà di uno statement.
 */
class MalformedDumpException extends RuntimeException {}
