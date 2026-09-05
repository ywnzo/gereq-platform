<?php

declare(strict_types=1);

namespace Gereq\Validation;

use Gereq\Http\Exception\HttpException;

final class ValidationException extends HttpException
{
    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $input
     */
    public function __construct(
        private readonly array $errors,
        private readonly array $input = [],
        private readonly string $form = '',
        string $message = 'validation.form_failed',
        private readonly ?string $redirectTo = null,
    ) {
        parent::__construct(422, $message);
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> */
    public function input(): array
    {
        return $this->input;
    }

    public function form(): string
    {
        return $this->form;
    }

    public function redirectTo(): ?string
    {
        return $this->redirectTo;
    }

    public function firstError(): ?string
    {
        foreach ($this->errors as $message) {
            return $message;
        }

        return null;
    }
}
