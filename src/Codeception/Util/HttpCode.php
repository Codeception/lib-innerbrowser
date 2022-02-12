<?php

declare(strict_types=1);

namespace Codeception\Util;

/**
 * Class containing constants of HTTP Status Codes
 * and method to print HTTP code with its description.
 *
 * Usage:
 *
 * ```php
 * <?php
 * use \Codeception\Util\HttpCode;
 *
 * // using REST, PhpBrowser, or any Framework module
 * $I->seeResponseCodeIs(HttpCode::OK);
 * $I->dontSeeResponseCodeIs(HttpCode::NOT_FOUND);
 * ```
 */
class HttpCode
{
    // const CONTINUE = 100;
    /**
     * @var int
     */
    public const SWITCHING_PROTOCOLS = 101;
    /**
     * @var int
     */
    public const PROCESSING = 102;            // RFC2518
    /**
     * @var int
     */
    public const EARLY_HINTS = 103;           // RFC8297
    /**
     * @var int
     */
    public const OK = 200;
    /**
     * @var int
     */
    public const CREATED = 201;
    /**
     * @var int
     */
    public const ACCEPTED = 202;
    /**
     * @var int
     */
    public const NON_AUTHORITATIVE_INFORMATION = 203;
    /**
     * @var int
     */
    public const NO_CONTENT = 204;
    /**
     * @var int
     */
    public const RESET_CONTENT = 205;
    /**
     * @var int
     */
    public const PARTIAL_CONTENT = 206;
    /**
     * @var int
     */
    public const MULTI_STATUS = 207;          // RFC4918
    /**
     * @var int
     */
    public const ALREADY_REPORTED = 208;      // RFC5842
    /**
     * @var int
     */
    public const IM_USED = 226;               // RFC3229
    /**
     * @var int
     */
    public const MULTIPLE_CHOICES = 300;
    /**
     * @var int
     */
    public const MOVED_PERMANENTLY = 301;
    /**
     * @var int
     */
    public const FOUND = 302;
    /**
     * @var int
     */
    public const SEE_OTHER = 303;
    /**
     * @var int
     */
    public const NOT_MODIFIED = 304;
    /**
     * @var int
     */
    public const USE_PROXY = 305;
    /**
     * @var int
     */
    public const RESERVED = 306;
    /**
     * @var int
     */
    public const TEMPORARY_REDIRECT = 307;
    /**
     * @var int
     */
    public const PERMANENTLY_REDIRECT = 308;  // RFC7238
    /**
     * @var int
     */
    public const BAD_REQUEST = 400;
    /**
     * @var int
     */
    public const UNAUTHORIZED = 401;
    /**
     * @var int
     */
    public const PAYMENT_REQUIRED = 402;
    /**
     * @var int
     */
    public const FORBIDDEN = 403;
    /**
     * @var int
     */
    public const NOT_FOUND = 404;
    /**
     * @var int
     */
    public const METHOD_NOT_ALLOWED = 405;
    /**
     * @var int
     */
    public const NOT_ACCEPTABLE = 406;
    /**
     * @var int
     */
    public const PROXY_AUTHENTICATION_REQUIRED = 407;
    /**
     * @var int
     */
    public const REQUEST_TIMEOUT = 408;
    /**
     * @var int
     */
    public const CONFLICT = 409;
    /**
     * @var int
     */
    public const GONE = 410;
    /**
     * @var int
     */
    public const LENGTH_REQUIRED = 411;
    /**
     * @var int
     */
    public const PRECONDITION_FAILED = 412;
    /**
     * @var int
     */
    public const REQUEST_ENTITY_TOO_LARGE = 413;
    /**
     * @var int
     */
    public const REQUEST_URI_TOO_LONG = 414;
    /**
     * @var int
     */
    public const UNSUPPORTED_MEDIA_TYPE = 415;
    /**
     * @var int
     */
    public const REQUESTED_RANGE_NOT_SATISFIABLE = 416;
    /**
     * @var int
     */
    public const EXPECTATION_FAILED = 417;
    /**
     * @var int
     */
    public const I_AM_A_TEAPOT = 418;                                               // RFC2324
    /**
     * @var int
     */
    public const MISDIRECTED_REQUEST = 421;                                         // RFC7540
    /**
     * @var int
     */
    public const UNPROCESSABLE_ENTITY = 422;                                        // RFC4918
    /**
     * @var int
     */
    public const LOCKED = 423;                                                      // RFC4918
    /**
     * @var int
     */
    public const FAILED_DEPENDENCY = 424;                                           // RFC4918
    /**
     * @var int
     */
    public const RESERVED_FOR_WEBDAV_ADVANCED_COLLECTIONS_EXPIRED_PROPOSAL = 425;   // RFC2817
    /**
     * @var int
     */
    public const UPGRADE_REQUIRED = 426;                                            // RFC2817
    /**
     * @var int
     */
    public const PRECONDITION_REQUIRED = 428;                                       // RFC6585
    /**
     * @var int
     */
    public const TOO_MANY_REQUESTS = 429;                                           // RFC6585
    /**
     * @var int
     */
    public const REQUEST_HEADER_FIELDS_TOO_LARGE = 431;                             // RFC6585
    /**
     * @var int
     */
    public const UNAVAILABLE_FOR_LEGAL_REASONS = 451;
    /**
     * @var int
     */
    public const INTERNAL_SERVER_ERROR = 500;
    /**
     * @var int
     */
    public const NOT_IMPLEMENTED = 501;
    /**
     * @var int
     */
    public const BAD_GATEWAY = 502;
    /**
     * @var int
     */
    public const SERVICE_UNAVAILABLE = 503;
    /**
     * @var int
     */
    public const GATEWAY_TIMEOUT = 504;
    /**
     * @var int
     */
    public const VERSION_NOT_SUPPORTED = 505;
    /**
     * @var int
     */
    public const VARIANT_ALSO_NEGOTIATES_EXPERIMENTAL = 506;                        // RFC2295
    /**
     * @var int
     */
    public const INSUFFICIENT_STORAGE = 507;                                        // RFC4918
    /**
     * @var int
     */
    public const LOOP_DETECTED = 508;                                               // RFC5842
    /**
     * @var int
     */
    public const NOT_EXTENDED = 510;                                                // RFC2774
    /**
     * @var int
     */
    public const NETWORK_AUTHENTICATION_REQUIRED = 511;                             // RFC6585
    /**
     * @var array<int, string>
     */
    private static array $codes = [
         100 => 'Continue',
         102 => 'Processing',
         103 => 'Early Hints',
         200 => 'OK',
         201 => 'Created',
         202 => 'Accepted',
         203 => 'Non-Authoritative Information',
         204 => 'No Content',
         205 => 'Reset Content',
         206 => 'Partial Content',
         207 => 'Multi-Status',
         208 => 'Already Reported',
         226 => 'IM Used',
         300 => 'Multiple Choices',
         301 => 'Moved Permanently',
         302 => 'Found',
         303 => 'See Other',
         304 => 'Not Modified',
         305 => 'Use Proxy',
         306 => 'Reserved',
         307 => 'Temporary Redirect',
         308 => 'Permanent Redirect',
         400 => 'Bad Request',
         401 => 'Unauthorized',
         402 => 'Payment Required',
         403 => 'Forbidden',
         404 => 'Not Found',
         405 => 'Method Not Allowed',
         406 => 'Not Acceptable',
         407 => 'Proxy Authentication Required',
         408 => 'Request Timeout',
         409 => 'Conflict',
         410 => 'Gone',
         411 => 'Length Required',
         412 => 'Precondition Failed',
         413 => 'Request Entity Too Large',
         414 => 'Request-URI Too Long',
         415 => 'Unsupported Media Type',
         416 => 'Requested Range Not Satisfiable',
         417 => 'Expectation Failed',
         418 => 'Unassigned',
         421 => 'Misdirected Request',
         422 => 'Unprocessable Entity',
         423 => 'Locked',
         424 => 'Failed Dependency',
         425 => 'Too Early',
         426 => 'Upgrade Required',
         428 => 'Precondition Required',
         429 => 'Too Many Requests',
         431 => 'Request Header Fields Too Large',
         451 => 'Unavailable For Legal Reasons',
         500 => 'Internal Server Error',
         501 => 'Not Implemented',
         502 => 'Bad Gateway',
         503 => 'Service Unavailable',
         504 => 'Gateway Timeout',
         505 => 'HTTP Version Not Supported',
         506 => 'Variant Also Negotiates',
         507 => 'Insufficient Storage',
         508 => 'Loop Detected',
         510 => 'Not Extended',
         511 => 'Network Authentication Required'
    ];

    /**
     * Returns string with HTTP code and its description
     *
     * ```php
     * <?php
     * HttpCode::getDescription(200); // '200 (OK)'
     * HttpCode::getDescription(401); // '401 (Unauthorized)'
     * ```
     */
    public static function getDescription(int $code): int|string
    {
        if (isset(self::$codes[$code])) {
            return sprintf('%d (%s)', $code, self::$codes[$code]);
        }
        return $code;
    }
}
