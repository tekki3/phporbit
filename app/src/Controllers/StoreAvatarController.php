<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\DatabaseUserProvider;
use App\Auth\User;
use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Upload\UploadError;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Session\Session;
use RuntimeException;

/**
 * Stores an uploaded avatar.
 *
 * The order of checks here is the whole point of the exercise:
 *
 *  1. Was anything uploaded, and did it survive transport?
 *  2. Do the *actual bytes* match a type we accept? The filename and the
 *     browser's declared type are attacker-controlled and are never consulted.
 *  3. The stored name and extension are derived from what we detected, not
 *     from anything the client sent, so `photo.php` cannot keep its extension.
 *
 * Anything not moved is deleted when the request scope closes, so every early
 * return below is also a cleanup path without saying so.
 */
final class StoreAvatarController implements Handler
{
    /**
     * Detected media type => the extension we will store it under.
     *
     * SVG is absent deliberately: it is a document that can carry script, so
     * serving a user-supplied one from our own origin would be stored XSS.
     *
     * @var array<string, string>
     */
    public const ALLOWED_TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly Authenticator $auth,
        private readonly DatabaseUserProvider $users,
        private readonly Session $session,
        private readonly string $avatarDirectory,
        private readonly int $maxBytes,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $user = $this->auth->user();

        assert($user instanceof User, 'the route is behind RequireAuthentication');

        $upload = $request->file('avatar');

        if ($upload === null || $upload->error === UploadError::NoFile) {
            return $this->back('Choose an image first.');
        }

        if ($upload->error !== UploadError::None) {
            return $this->back($upload->error->message());
        }

        if ($upload->size > $this->maxBytes) {
            // Reported in whichever unit does not round to zero — a limit
            // shown as "0 KB" tells the user nothing about what would fit.
            return $this->back(sprintf(
                'Images must be %s or smaller.',
                $this->maxBytes >= 1024
                    ? intdiv($this->maxBytes, 1024) . ' KB'
                    : $this->maxBytes . ' bytes',
            ));
        }

        // The only opinion that counts: what the bytes actually are.
        $extension = $upload->extensionFromContents(self::ALLOWED_TYPES);

        if ($extension === null) {
            return $this->back(sprintf(
                'That file is a %s. Accepted types are: %s.',
                $upload->detectedType() ?? 'unknown type',
                implode(', ', array_keys(self::ALLOWED_TYPES)),
            ));
        }

        // A name we generate: no collisions, no traversal, no surprises from
        // whatever the client called it.
        $name = sprintf('user-%d-%s.%s', $user->id, bin2hex(random_bytes(8)), $extension);

        try {
            $upload->moveTo($this->avatarDirectory, $name);
        } catch (RuntimeException $e) {
            return $this->back('The upload could not be stored.');
        }

        $this->replacePrevious($user);
        $this->users->setAvatar($user, '/avatars/' . $name);

        $this->session->flash('notice', 'Avatar updated.');

        return Response::redirect('/avatar');
    }

    /**
     * Removes the file the previous avatar pointed at.
     *
     * Skipped unless the stored path looks like one we generated — the column
     * is data, and data that reaches `unlink()` deserves a second look.
     */
    private function replacePrevious(User $user): void
    {
        if ($user->avatarPath === null) {
            return;
        }

        $name = basename($user->avatarPath);

        if (preg_match('/^user-\d+-[a-f0-9]{16}\.[a-z]{3,4}$/', $name) !== 1) {
            return;
        }

        $path = $this->avatarDirectory . '/' . $name;

        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function back(string $error): Response
    {
        $this->session->flash('error', $error);

        return Response::redirect('/avatar');
    }
}
