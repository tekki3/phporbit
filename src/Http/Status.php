<?php

declare(strict_types=1);

namespace PhpOrbit\Http;

enum Status: int
{
    case Ok = 200;
    case Created = 201;
    case NoContent = 204;
    case MovedPermanently = 301;
    case Found = 302;
    case NotModified = 304;
    case BadRequest = 400;
    case Unauthorized = 401;
    case Forbidden = 403;
    case NotFound = 404;
    case MethodNotAllowed = 405;
    case Conflict = 409;
    case PayloadTooLarge = 413;
    case UnprocessableEntity = 422;
    case TooManyRequests = 429;
    case InternalServerError = 500;
    case NotImplemented = 501;
    case ServiceUnavailable = 503;

    public function reasonPhrase(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::Created => 'Created',
            self::NoContent => 'No Content',
            self::MovedPermanently => 'Moved Permanently',
            self::Found => 'Found',
            self::NotModified => 'Not Modified',
            self::BadRequest => 'Bad Request',
            self::Unauthorized => 'Unauthorized',
            self::Forbidden => 'Forbidden',
            self::NotFound => 'Not Found',
            self::MethodNotAllowed => 'Method Not Allowed',
            self::Conflict => 'Conflict',
            self::PayloadTooLarge => 'Payload Too Large',
            self::UnprocessableEntity => 'Unprocessable Entity',
            self::TooManyRequests => 'Too Many Requests',
            self::InternalServerError => 'Internal Server Error',
            self::NotImplemented => 'Not Implemented',
            self::ServiceUnavailable => 'Service Unavailable',
        };
    }

    /**
     * Responses that must not carry a message body (RFC 9110 §6.4.1).
     */
    public function allowsBody(): bool
    {
        return $this !== self::NoContent && $this !== self::NotModified;
    }
}
