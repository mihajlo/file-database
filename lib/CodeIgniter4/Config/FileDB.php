<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Configuration holder for the FileDB library when used in CodeIgniter 4.
 */
class FileDB extends BaseConfig
{
    /**
     * Name of the logical database. This becomes the top-level directory name
     * inside the configured storage path.
     */
    public string $database = 'default';

    /**
     * Base path where FileDB should keep its data files.
     */
    public string $path = 'writable/filedb';

    public function __construct()
    {
        parent::__construct();

        if (defined('WRITEPATH')) {
            $this->path = rtrim(WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'filedb';
        }
    }
}
