#!/usr/bin/env php
<?php
/*
    eZ Publish MSSQL extension
    Copyright (C) 2007  xrow GbR, Hannover, Germany

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.
*/

include_once( 'lib/ezutils/classes/ezcli.php' );
include_once( 'kernel/classes/ezscript.php' );

$cli =& eZCLI::instance();
$script =& eZScript::instance( array( 'description' => ( "eZ publish SQL Schema dump\n\n" .
                                                         "Dump sql schema to specified file or standard output\n".
                                                         "ezsqldumpschema.php --type=mysql --user=root stable33 schema.sql" ),
                                      'use-session' => false,
                                      'use-modules' => true,
                                      'use-extensions' => true ) );

$script->startup();

$options = $script->getOptions( "[type:][user:][database:][host:][password;][port:][socket:]",
                                "",
                                array( 'type' => ( "Which database type to use for source, can be one of:\n" .
                                                          "mysql, postgresql" ),
                                       'database' => "Source database name",
                                       'host' => "Connect to host source database",
                                       'user' => "User for login to source database",
                                       'password' => "Password to use when connecting to source database",
                                       'port' => 'Port to connect to source database',
                                       ) );
$script->initialize();

$type = $options['type'];
$host = $options['host'];
$database = $options['database'];
$user = $options['user'];
$port = $options['port'];
$socket = $options['socket'];
$password = $options['password'];

if ( !is_string( $password ) )
    $password = '';

if ( strlen( trim( $type ) ) == 0)
{
    $cli->error( "No database type chosen" );
    $script->shutdown( 1 );
}

// Creates a displayable string for the end-user explaining
// which database, host, user and password which were tried
function eZTriedDatabaseString( $database, $host, $user, $password, $socket )
{
    $msg = "'$database'";
    if ( strlen( $host ) > 0 )
    {
        $msg .= " at host '$host'";
    }
    else
    {
        $msg .= " locally";
    }
    if ( strlen( $user ) > 0 )
    {
        $msg .= " with user '$user'";
    }
    if ( strlen( $password ) > 0 )
        $msg .= " and with a password";
    if ( strlen( $socket ) > 0 )
        $msg .= " and with socket '$socket'";
    return $msg;
}


    if ( strlen( trim( $user ) ) == 0)
    {
        $cli->error( "No database user chosen" );
        $script->shutdown( 1 );
    }

    include_once( 'lib/ezdb/classes/ezdb.php' );
    $parameters = array( 'use_defaults' => false,
                         'server' => $host,
                         'user' => $user,
                         'password' => $password,
                         'database' => $database );
    if ( $socket )
        $parameters['socket'] = $socket;
    if ( $port )
        $parameters['port'] = $port;
    $db =& eZDB::instance( $type,
                           $parameters,
                           true );

    if ( !is_object( $db ) )
    {
        $cli->error( 'Could not initialize database:' );
        $cli->error( '* No database handler was found for $type' );
        $script->shutdown( 1 );
    }
    if ( !$db or !$db->isConnected() )
    {
        $cli->error( "Could not initialize database:" );
        $cli->error( "* Tried database " . eZTriedDatabaseString( $database, $host, $user, $password, $socket ) );

        // Fetch the database error message if there is one
        // It will give more feedback to the user what is wrong
        $msg = $db->errorMessage();
        if ( $msg )
        {
            $number = $db->errorNumber();
            if ( $number > 0 )
                $msg .= '(' . $number . ')';
            $cli->error( '* ' . $msg );
        }
        $script->shutdown( 1 );
    }

function microtime_float()
{
   list($usec, $sec) = explode(" ", microtime());
   return ((float)$usec + (float)$sec);
}

eZDB::setInstance( $db );
$dbversion = $db->databaseServerVersion();
$cli->output(  "Current Database" );
$cli->output(  $dbversion['string'] );
$cli->output( );
$turns = 1000;
$cli->output( "Fetching " . $turns . " times node 2.");
$time_start = microtime_float( );

include_once( 'kernel/classes/ezcontentobjecttreenode.php' );
for( $i = 0; $i < $turns; $i++ )
{
    $node = eZContentObjectTreeNode::fetch( 2 );
    unset( $node );
}
$time_end = microtime_float( );
$cli->output(  $time_end - $time_start . " seconds needed.");

$cli->output( "Updating " . $turns . " persisten objects.");
$node = eZContentObjectTreeNode::fetch( 2 );

$object = $node->object();
$time_start = microtime_float( );
for( $i = 0;$i < $turns;$i++ )
{
    $object->setAttribute("remote_id", uniqid("a") );
    $object->store();
}
$time_end = microtime_float( );
$cli->output(  $time_end - $time_start . " seconds needed.");

$cli->output( "Writing " . $turns . " sessions.");
$time_start = microtime_float( );
for( $i = 0;$i < $turns;$i++ )
{
    eZSessionWrite( uniqid('a'), uniqid('b') );
}
$time_end = microtime_float( );
$cli->output(  $time_end - $time_start . " seconds needed.");

eZSessionEmpty();
$script->shutdown();

?>
