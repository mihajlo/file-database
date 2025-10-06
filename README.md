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

