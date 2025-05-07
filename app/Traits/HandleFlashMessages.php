<?php

namespace App\Traits;

trait HandleFlashMessages
{
    protected function flashSuccess(string $message): array
    {
        return $this->flashMessage('success', $message);
    }

    protected function flashInfo(string $message): array
    {
        return $this->flashMessage('info', $message);
    }

    protected function flashError(string $message): array
    {
        return $this->flashMessage('error', $message);
    }

    protected function flashWarning(string $message): array
    {
        return $this->flashMessage('warning', $message);
    }

    protected function flashMessage(string $type, string $message): array
    {
        return [
            'message' => [
                'type' => $type,
                'message' => $message
            ]
        ];
    }
}