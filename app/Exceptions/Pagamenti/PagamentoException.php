<?php

namespace App\Exceptions\Pagamenti;

/**
 * Classe base per tutte le eccezioni di dominio del modulo Pagamento Fatture.
 *
 * Estende DomainException (non RuntimeException) perché queste eccezioni
 * rappresentano violazioni di regole di business, non errori tecnici.
 *
 * Handler globale Laravel (app/Exceptions/Handler.php) dovrebbe catturare
 * PagamentoException e mappare al corretto HTTP status code.
 * Vedi mapping suggerito in guida v1.9.1 sezione 6.8.
 *
 * Uso nei test Pest:
 *   expect(fn() => $service->registraPagamento($data))
 *       ->toThrow(OverpaymentException::class);
 */
abstract class PagamentoException extends \DomainException {}