<?php

namespace App\Traits;

/**
 * Trait for handling flash messages across controllers
 * 
 * Provides standardized methods for creating flash message arrays
 * that can be passed to Laravel's redirect `with()` method.
 * Ensures consistent flash message structure throughout the application.
 *
 * @package App\Traits
 */
trait HandleFlashMessages
{
    /**
     * Create a success flash message array
     *
     * @param string $message The success message to display
     * @return array Flash message array compatible with Laravel's `with()` method
     *
     * @example
     * // Usage in controller
     * return redirect()->route('users.index')
     *     ->with($this->flashSuccess('User created successfully'));
     *
     * // Inertia.js usage
     * return Inertia::render('Page')
     *     ->with($this->flashSuccess('Operation completed'));
     */
    protected function flashSuccess(string $message): array
    {
        return $this->flashMessage('success', $message);
    }

    /**
     * Create an info flash message array
     *
     * @param string $message The informational message to display
     * @return array Flash message array compatible with Laravel's `with()` method
     *
     * @example
     * return redirect()->back()
     *     ->with($this->flashInfo('Settings updated'));
     */
    protected function flashInfo(string $message): array
    {
        return $this->flashMessage('info', $message);
    }

    /**
     * Create an error flash message array
     *
     * @param string $message The error message to display
     * @return array Flash message array compatible with Laravel's `with()` method
     *
     * @example
     * try {
     *     // operation...
     * } catch (Exception $e) {
     *     return back()->with($this->flashError('Operation failed'));
     * }
     */
    protected function flashError(string $message): array
    {
        return $this->flashMessage('error', $message);
    }

    /**
     * Create a warning flash message array
     *
     * @param string $message The warning message to display
     * @return array Flash message array compatible with Laravel's `with()` method
     *
     * @example
     * return redirect()->route('dashboard')
     *     ->with($this->flashWarning('Please complete your profile'));
     */
    protected function flashWarning(string $message): array
    {
        return $this->flashMessage('warning', $message);
    }

    /**
     * Create a flash message array with custom type
     *
     * @param string $type The message type (success, error, warning, info)
     * @param string $message The message content
     * @return array Structured flash message array
     *
     * @example
     * // Custom type
     * return back()->with($this->flashMessage('custom', 'Custom message'));
     *
     * // Structure returned:
     * [
     *     'message' => [
     *         'type' => 'success',
     *         'message' => 'Your message here'
     *     ]
     * ]
     */
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