<?php

namespace App\Domain\Exceptions;

/**
 * error-catalog.md DISH_CATEGORY_NOT_FOUND, DISH_NOT_FOUND, MEAL_NOT_FOUND, MEAL_OPTION_GROUP_NOT_FOUND and
 * MEAL_OPTION_NOT_FOUND (ADR-016): the record does not exist, or belongs to another store -- the two are indistinguishable.
 */
final class MenuRecordNotFoundException extends DomainException
{
    private function __construct(private readonly string $errorCode, string $message, array $details)
    {
        parent::__construct($message, $details);
    }

    public static function dishCategory(string $id): self
    {
        return new self('DISH_CATEGORY_NOT_FOUND', "Dish category \"{$id}\" was not found.", ['dish_category_id' => $id]);
    }

    public static function dish(string $id): self
    {
        return new self('DISH_NOT_FOUND', "Dish \"{$id}\" was not found.", ['dish_id' => $id]);
    }

    public static function meal(string $id): self
    {
        return new self('MEAL_NOT_FOUND', "Meal \"{$id}\" was not found.", ['meal_id' => $id]);
    }

    public static function optionGroup(string $id): self
    {
        return new self('MEAL_OPTION_GROUP_NOT_FOUND', "Meal option group \"{$id}\" was not found.", ['meal_option_group_id' => $id]);
    }

    public static function option(string $id): self
    {
        return new self('MEAL_OPTION_NOT_FOUND', "Meal option \"{$id}\" was not found.", ['meal_option_id' => $id]);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
