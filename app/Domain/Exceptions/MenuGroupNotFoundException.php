<?php

namespace App\Domain\Exceptions;

/** error-catalog.md MENU_GROUP_NOT_FOUND. */
final class MenuGroupNotFoundException extends DomainException
{
    public static function forId(string $menuGroupId): self
    {
        return new self("Menu group \"{$menuGroupId}\" was not found.", ['menu_group_id' => $menuGroupId]);
    }

    public function errorCode(): string
    {
        return 'MENU_GROUP_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
