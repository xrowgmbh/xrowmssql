Quick Install of the PHP Odbtp Extension
========================================

PHP version    : 4.4.1

It is required that you download the complete odbtp package since
this archive contains only the PHP odbtp client extension for Win32:
http://odbtp.sourceforge.net/

Extract the files from this archive with full pathnames so that subdirs
are created; then just copy the extension to usually c:/php4/ext/.
If you need the mssql aliased version of odbtp then copy the suffixed
extension and rename it.
Finally edit c:/php4/php.ini and add this line to load the extension:
extension=php_odbtp.so

and add the section below to configure odbtp:
[odbtp]
odbtp.interface_file = "c:/php4/odbtp.conf"
odbtp.datetime_format = mdyhmsf
odbtp.detach_default_queries = yes

then reload PHP or reboot your server.
Then check with phpinfo() that the module is loaded and has correctly
parsed the config section.

Known bugs:
- none yet ...

