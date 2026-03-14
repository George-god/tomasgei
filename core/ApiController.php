<?php
declare(strict_types=1);

namespace Game\Core;

require_once __DIR__ . '/ApiResponse.php';
require_once __DIR__ . '/SessionHelper.php';
require_once __DIR__ . '/Validator.php';

use Game\Helper\ApiResponse;
use Game\Helper\SessionHelper;

final class ApiController
{
    public static function requireUserId(): int
    {
        return SessionHelper::requireUserIdForApi();
    }

    public static function requirePost(): void
    {
        Validator::requirePost();
    }

    /**
     * @param callable(): array<string, mixed> $resolver
     */
    public static function handle(callable $resolver, int $successCode = 200, int $errorCode = 400): void
    {
        ApiResponse::fromServiceResult($resolver(), $successCode, $errorCode);
    }
}
