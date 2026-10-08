<?php
declare(strict_types=1);
namespace O8\Auth;

final class AccountEmailException extends \RuntimeException
{
    public function __construct(public readonly string $translationKey)
    {
        parent::__construct($translationKey);
    }
}
