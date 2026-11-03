<?php

declare(strict_types=1);

namespace PhpOrbit\Api;

use JsonException;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;

final class JsonBody
{
    /** @param array<string, mixed> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function fromRequest(ServerRequest $request, int $maxBytes = 16384): self
    {
        $type = strtolower(trim(explode(';', $request->headers->first('Content-Type') ?? '')[0]));
        if ($type !== 'application/json') {
            throw new ApiError(Status::UnsupportedMediaType, 'Use Content-Type: application/json.');
        }
        if (strlen($request->body) > $maxBytes) {
            throw new ApiError(Status::PayloadTooLarge, 'JSON body is too large.');
        }
        try {
            $values = json_decode($request->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiError(Status::BadRequest, 'Invalid JSON body.');
        }
        if (!is_array($values) || !str_starts_with(ltrim($request->body), '{')) {
            throw new ApiError(Status::BadRequest, 'The JSON body must be an object.');
        }
        $fields = [];
        foreach ($values as $key => $value) {
            $fields[(string) $key] = $value;
        }

        return new self($fields);
    }

    public function string(string $name, int $maxBytes = 1024, bool $required = true): string
    {
        $value = array_key_exists($name, $this->values) ? $this->values[$name] : ($required ? null : '');
        if (!is_string($value) || strlen($value) > $maxBytes || ($required && $value === '')) {
            throw new ApiError(Status::UnprocessableEntity, 'Invalid field: ' . $name . '.');
        }

        return $value;
    }
}
