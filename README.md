# file-database
Non-sql database on filesystem

Non-sql database based on file system. With this class you can add storage on filesystem and access data on simplest way ever.

## Improvements

The `filedb` class now offers a more robust feature set:

* Automatic creation of missing directories with helpful error messages instead of silencing failures.
* Helper methods such as `getOne()` and `listTables()` to simplify common lookups.
* Safer JSON reading and writing utilities that gracefully handle corrupt data.
* Storage helpers that avoid duplicated logic when working with partitions.

Refer to `lib/filedb.php` for the full API surface.

## Using with CodeIgniter 4

The library now ships with helper files that make it easy to register FileDB as
an injectable service in CodeIgniter 4 applications:

1. Copy `lib/CodeIgniter4/Config/FileDB.php` into your project's `app/Config`
   folder. Adjust the `$database` and `$path` properties as needed.
2. Copy `lib/CodeIgniter4/Libraries/FileDBServiceTrait.php` somewhere that can
   be autoloaded (for example `app/Libraries`).
3. Update your application's `app/Config/Services.php` file to pull in the
   trait:

   ```php
   namespace Config;

   use CodeIgniter\Config\BaseService;
   use FileDatabase\CodeIgniter4\Libraries\FileDBServiceTrait;

   class Services extends BaseService
   {
       use FileDBServiceTrait;
   }
   ```

You can then resolve the FileDB service anywhere in your CodeIgniter 4 project
via `service('filedb')` or by injecting `\FileDatabase\FileDB` using your
preferred method.

