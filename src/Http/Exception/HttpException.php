<?php

declare(strict_types=1);

namespace Gereq\Http\Exception;

class HttpException extends \RuntimeException implements HttpExceptionInterface
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $status,
        string $message = '',
        private readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : self::defaultMessage($status),
            $status,
            $previous,
        );
    }

    public function status(): int
    {
        return $this->status;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            401 => 'Pro pokračování se musíte přihlásit.',
            403 => 'Na tuhle akci nemáte oprávnění.',
            404 => 'Požadovaná adresa neexistuje.',
            405 => 'Tato HTTP metoda tu není povolená.',
            419 => 'Platnost formuláře vypršela. Obnovte stránku a zkuste to znovu.',
            429 => 'Příliš mnoho požadavků. Zkuste to za chvíli.',
            503 => 'Služba je dočasně nedostupná.',
            default => 'Došlo k neočekávané chybě.',
        };
    }
}
