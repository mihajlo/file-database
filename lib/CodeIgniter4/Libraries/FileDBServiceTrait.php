<?php

declare(strict_types=1);

namespace FileDatabase\CodeIgniter4\Libraries;

use FileDatabase\FileDB;

/**
 * Reusable service definition for integrating FileDB with CodeIgniter 4.
 *
 * Usage:
 *
 * <code>
 * namespace Config;
 *
 * use CodeIgniter\Config\BaseService;
 * use FileDatabase\CodeIgniter4\Libraries\FileDBServiceTrait;
 *
 * class Services extends BaseService
 * {
 *     use FileDBServiceTrait;
 * }
 * </code>
 */
trait FileDBServiceTrait
{
    public static function filedb(?string $database = null, ?string $path = null, bool $getShared = true): FileDB
    {
        if ($getShared) {
            /** @var FileDB */
            return static::getSharedInstance('filedb', $database, $path);
        }

        $config = function_exists('config') ? config('FileDB') : null;

        $database = $database ?? self::readConfigValue($config, 'database', 'default');
        $path = $path ?? self::readConfigValue($config, 'path', self::defaultPath());

        return new FileDB($database, $path);
    }

    /**
     * @param object|array|null $config
     */
    private static function readConfigValue($config, string $key, string $fallback): string
    {
        if (is_array($config) && array_key_exists($key, $config) && is_string($config[$key]) && $config[$key] !== '') {
            return $config[$key];
        }

        if (is_object($config) && isset($config->$key) && is_string($config->$key) && $config->$key !== '') {
            return $config->$key;
        }

        return $fallback;
    }

    private static function defaultPath(): string
    {
        if (defined('WRITEPATH')) {
            return rtrim(WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'filedb';
        }

        return 'writable' . DIRECTORY_SEPARATOR . 'filedb';
    }
}
