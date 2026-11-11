<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Authenticator;
use App\Framework\Controller\ControllerAbstract;
use App\Framework\Template\RendererInterface;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The logged-in user's account: change password.
 */
class AccountController extends ControllerAbstract
{
    /**
     * Create the controller.
     */
    public function __construct(
        RendererInterface $renderer,
        StreamFactoryInterface $stream,
        ResponseFactoryInterface $factory,
        EntityManagerInterface $em,
        private readonly Authenticator $auth,
    ) {
        parent::__construct($renderer, $stream, $factory, $em);
    }

    /**
     * Show the change password form.
     */
    public function passwordForm(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->auth->user() === null) {
            return $this->redirect('/login');
        }

        $updated = ($request->getQueryParams()['done'] ?? null) === 'updated';

        return $this->renderForm([], $updated ? 'Your password has been changed.' : null);
    }

    /**
     * Change the password after checking the current one; 422 with field errors when invalid.
     * The session gets a new ID so the user stays logged in on this browser.
     */
    public function updatePassword(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->auth->user();

        if ($user === null) {
            return $this->redirect('/login');
        }

        $current = $this->field($request, 'current_password');
        $new = $this->field($request, 'new_password');
        $confirm = $this->field($request, 'confirm_password');
        $errors = [];

        if (!$this->auth->verifyPassword($user, $current)) {
            $errors['current_password'] = $current === ''
                ? 'Enter your current password.'
                : 'Your current password is incorrect.';
        }

        if (mb_strlen($new) < Authenticator::MIN_PASSWORD_LENGTH) {
            $errors['new_password'] = sprintf(
                'Use at least %d characters.',
                Authenticator::MIN_PASSWORD_LENGTH
            );
        } elseif (!isset($errors['current_password']) && $new === $current) {
            $errors['new_password'] = 'Choose a password different from your current one.';
        }

        if (!isset($errors['new_password']) && $confirm !== $new) {
            $errors['confirm_password'] = 'The passwords do not match.';
        }

        if ($errors !== []) {
            return $this->renderForm($errors);
        }

        try {
            $this->auth->setPassword($user, $new);
        } catch (InvalidArgumentException $e) {
            return $this->renderForm(['new_password' => $e->getMessage()]);
        }

        $this->em->flush();
        $this->auth->regenerateSession();

        return $this->redirect('/account/password?done=updated');
    }

    /**
     * Render the form; 422 when there are errors. Passwords are never echoed back.
     *
     * @param array<string, string> $errors Messages keyed by field name.
     */
    private function renderForm(array $errors, ?string $notice = null): ResponseInterface
    {
        return $this->render('account/password', [
            'errors' => $errors,
            'notice' => $notice,
            'minLength' => Authenticator::MIN_PASSWORD_LENGTH,
        ], $errors === [] ? 200 : 422)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * A form field as sent (passwords are not trimmed), or '' when missing or not a string.
     */
    private function field(ServerRequestInterface $request, string $name): string
    {
        $body = $request->getParsedBody();

        return is_array($body) && is_string($body[$name] ?? null) ? $body[$name] : '';
    }
}
