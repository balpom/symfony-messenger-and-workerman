<?php

declare(strict_types=1);

namespace Balpom\SymfonyMessengerWorkerman;

class SmsNotification
{
    private $content;

    public function __construct(string $content)
    {
        $this->content = $content;
    }

    public function getContent(): string
    {
        return $this->content;
    }
}
