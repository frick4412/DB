# DB

php database querying for sqlsrv, mssql, mysql, sqlite

## DBX.1 
  * Deprecated paging queries.
  * Deleted autoload code not being used.
  * Added support for JSONp.

## DB5.4
  * Consolidation of database versions (sqlsrv, odbc(mssql), mysql, sqlite).
  * Additional comments in file including connection file format
(Note: Directories holding Sqlite databases must be writable by the web server.)

## DB6.0
 * Adds second parameter (boolean) to constructor for display of error messages.
 * Removed old functions: queryPage, recordCount, pageCount

## DB7.0
 * Changed parameters for functions from 3 parameters to named array
 * Added auth_msint function to return current domain login name
