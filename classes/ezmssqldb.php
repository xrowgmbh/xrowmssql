<?php

/*
    eZ Publish MSSQL extension
    Copyright (C) 2011  xrow GbR, Hannover, Germany

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.
*/

class eZMSSQLDB extends eZDBInterface
{
    const VERSION_REVISION_STRING = '$Rev: 174 $';
    const SINGLEQUOTE = "'";
    
    private $functions;

    static function revision()
    {
        return preg_replace( "#\\\$Rev:\s+([0-9]+)\s+\\\$#", '$1', eZMSSQLDB::VERSION_REVISION_STRING );
    }

    /*!
      Create a new eZMSSQLDB object and connects to the database backend.
    */
    function eZMSSQLDB( $parameters )
    {
        
        $this->eZDBInterface( $parameters );
        
        if ( ! extension_loaded( 'sqlsrv' ) and ! extension_loaded( 'dblib' ) )
        {
            if ( function_exists( 'eZAppendWarningItem' ) )
            {
                eZAppendWarningItem( array( 
                    'error' => array( 
                        'type' => 'ezdb' , 
                        'number' => eZDBInterface::ERROR_MISSING_EXTENSION 
                    ) , 
                    'text' => 'The PHP SQL Server or FreeTSD extension not found.' 
                ) );
                $this->IsConnected = false;
                return;
            }
        }
        if ( extension_loaded( 'sqlsrv' ) and eZSys::osType() == 'win32' )
        {
            $this->functions = new eZMSSQLFunctions( eZMSSQLFunctions::SQLSRV );
        }
        elseif ( extension_loaded( 'dblib' ) and eZSys::osType() != 'win32' )
        {
            $this->functions = new eZMSSQLFunctions( eZMSSQLFunctions::FREETDS );
        }
        else
        {
            throw new Exception( "Please use db extension sqlsrv for windows and dblib for other operating systems." );
        
        }
        /// Connect to master server
        if ( $this->DBWriteConnection == false )
        {
            $connection = $this->connect( $this->Server, $this->DB, $this->User, $this->Password, $this->SocketPath, $this->Charset );
            if ( $this->IsConnected )
            {
                $this->DBWriteConnection = $connection;
            }
        }
        
        // Connect to slave
        if ( $this->DBConnection == false )
        {
            if ( $this->UseSlaveServer === true )
            {
                $connection = $this->connect( $this->SlaveServer, $this->SlaveDB, $this->SlaveUser, $this->SlavePassword, $this->SocketPath, $this->Charset );
            }
            else
            {
                $connection = & $this->DBWriteConnection;
            }
            
            if ( $connection and $this->DBWriteConnection )
            {
                $this->DBConnection = $connection;
                $this->IsConnected = true;
            }
        }
        
        if ( $this->Charset !== null )
        {
            $originalCharset = $this->Charset;
            $charset = eZCharsetInfo::realCharsetCode( $this->Charset );
            // Convert charset names into something MSSQL will understand
            $charsetMapping = array( 
                'iso-8859-1' => 'latin1' , 
                'iso-8859-2' => 'latin2' , 
                'iso-8859-8' => 'hebrew' , 
                'iso-8859-7' => 'greek' , 
                'iso-8859-9' => 'latin5' , 
                'iso-8859-13' => 'latin7' , 
                'windows-1250' => 'cp1250' , 
                'windows-1251' => 'cp1251' , 
                'windows-1252' => 'cp1252' , 
                'windows-1256' => 'cp1256' , 
                'windows-1257' => 'cp1257' , 
                'utf-8' => 'utf8' 
            );
            if ( isset( $charsetMapping[$this->Charset] ) )
                $charset = $charsetMapping[$this->Charset];
            else
                eZDebug::writeError( "The charset '" . $charset . "' might be not supported." );
        }
        eZDebug::createAccumulatorGroup( 'mssql_total', 'Mssql Total' );
    }

    function getIdentityFromTable( $tablename = false )
    {
        if ( ! isset( $this->idents ) )
        {
            $sql = "SELECT name = c.name, tablename = o.name FROM sysobjects o, syscolumns c WHERE COLUMNPROPERTY(o.id, c.name, 'IsIdentity')= 1 AND o.id = c.id";
            $this->arrayQuery( $sql );
        }
        if ( isset( $this->idents[$tablename] ) )
            return $this->idents[$tablename];
        else
            return false;
    }

    /*!
     \private
     Opens a new connection to a MSSQL database and returns the connection
    */
    
    function &connect( $server, $db, $user, $password, $socketPath, $charset = null )
    {
        $connection = false;
        $ini = eZINI::instance();
        $port = $ini->variable( 'DatabaseSettings', 'Port' );
        if ( $port and eZSys::osType() == 'win32' )
        {
            $server = $server . ',' . $port;
        }
        elseif ( $port )
        {
            $server = $server . ':' . $port;
        }
        eZDebug::accumulatorStart( 'mssql_connect', 'mssql_total', 'Connect in mssql' );
        $connection = $this->functions->connect( $server, $user, $password, $db );
        eZDebug::accumulatorStop( 'mssql_connect' );
        
        $maxAttempts = $this->connectRetryCount();
        $waitTime = $this->connectRetryWaitTime();
        $numAttempts = 1;
        while ( $connection == false and $numAttempts <= $maxAttempts )
        {
            sleep( $waitTime );
            eZDebug::accumulatorStart( 'mssql_connect', 'mssql_total', 'Connect in mssql' );
            $connection = $this->functions->connect( $server, $user, $password, $db );
            eZDebug::accumulatorStop( 'mssql_connect' );
            $numAttempts ++;
        }
        
        $this->IsConnected = true;
        
        if ( $connection == false )
        {
            eZDebug::writeError( "Connection error: Couldn't connect to database. Please try again later or inform the system administrator.\n" . $this->functions->error(), "eZMSSQLDB" );
            $this->IsConnected = false;
        }
        
        return $connection;
    }

    function isExpressEdition()
    {
        $info = $this->databaseServerVersion();
        if ( preg_match( "/Express(.*?)Edition/", $info['string'] ) )
            return true;
        return false;
    }

    /*!
     \reimp
    */
    function databaseName()
    {
        return 'mssql';
    }

    /*!
      \reimp
    */
    function bindingType()
    {
        return self::BINDING_NO;
    }

    /*!
      \reimp
    */
    function bindVariable( $value, $fieldDef = false )
    {
        return $value;
    }

    /*!
     Generate unique table name basing on the given pattern.
     If the pattern contains a (%) character then the character
     is replaced with a part providing uniqueness (e.g. random number).
    */
    function generateUniqueTempTableName( $pattern, $randomizeIndex = false, $server = self::SERVER_SLAVE )
    {
        
        return "#" . parent::generateUniqueTempTableName( $pattern, $randomizeIndex, $server );
    }

    /*!
     \reimp
    */
    private function _appendN( $sql )
    {
        
        $result = $sql;
        
        /// Check we have some single quote in the query. Exit ok.
        if ( strpos( $sql, self::SINGLEQUOTE ) === false )
        {
            return $sql;
        }
        
        /// Check we haven't an odd number of single quotes (this can cause problems below
        /// and should be considered one wrong SQL). Exit with debug info.
        if ( ( substr_count( $sql, self::SINGLEQUOTE ) & 1 ) )
        {
            return $sql;
        }
        
        /// Check we haven't any backslash + single quote combination. It should mean wrong
        /// backslashes use (bad magic_quotes_sybase?). Exit with debug info.
        $regexp = '/(\\\\' . self::SINGLEQUOTE . '[^' . self::SINGLEQUOTE . '])/';
        if ( preg_match( $regexp, $sql ) )
        {
            return $sql;
        }
        
        /// Remove pairs of single-quotes
        $pairs = array();
        $regexp = '/(' . self::SINGLEQUOTE . self::SINGLEQUOTE . ')/';
        preg_match_all( $regexp, $result, $list_of_pairs );
        if ( $list_of_pairs )
        {
            foreach ( array_unique( $list_of_pairs[0] ) as $key => $value )
            {
                $pairs['<@#@#@PAIR-' . $key . '@#@#@>'] = $value;
            }
            if ( ! empty( $pairs ) )
            {
                $result = str_replace( $pairs, array_keys( $pairs ), $result );
            }
        }
        
        /// Remove the rest of literals present in the query
        $literals = array();
        $regexp = '/(N?' . self::SINGLEQUOTE . '.*?' . self::SINGLEQUOTE . ')/is';
        preg_match_all( $regexp, $result, $list_of_literals );
        if ( $list_of_literals )
        {
            foreach ( array_unique( $list_of_literals[0] ) as $key => $value )
            {
                $literals['<#@#@#LITERAL-' . $key . '#@#@#>'] = $value;
            }
            if ( ! empty( $literals ) )
            {
                $result = str_replace( $literals, array_keys( $literals ), $result );
            }
        }
        
        /// Analyse literals to prepend the N char to them if their contents aren't numeric
        if ( ! empty( $literals ) )
        {
            foreach ( $literals as $key => $value )
            {
                if ( ! is_numeric( trim( $value, self::SINGLEQUOTE ) ) )
                {
                    /// Non numeric string, prepend our dear N
                    $literals[$key] = 'N' . trim( $value, 'N' ); //Trimming potentially existing previous "N"
                }
            }
        }
        
        /// Re-apply literals to the text
        if ( ! empty( $literals ) )
        {
            $result = str_replace( array_keys( $literals ), $literals, $result );
        }
        
        /// Re-apply pairs of single-quotes to the text
        if ( ! empty( $pairs ) )
        {
            $result = str_replace( array_keys( $pairs ), $pairs, $result );
        }
        
        return $result;
    }

    function query( $sql, $server = false )
    {
        if ( $this->IsConnected )
        {
            eZDebug::accumulatorStart( 'mssql_query', 'mssql_total', 'Mssql_queries' );
            $orig_sql = $sql;
            
            // The converted sql should not be output
            if ( $this->InputTextCodec )
            {
                eZDebug::accumulatorStart( 'mssql_conversion', 'mssql_total', 'String conversion in mssql' );
                $sql = $this->InputTextCodec->convertString( $sql );
                eZDebug::accumulatorStop( 'mssql_conversion' );
            }
            
            if ( $this->OutputSQL )
            {
                $this->startTimer();
            }
            // Check if it's a write or read sql query
            $sql = trim( $sql );
            
            $isWriteQuery = true;
            if ( stristr( $sql, "select" ) )
            {
                $isWriteQuery = false;
            }
            
            // Send temporary create queries to slave server
            if ( preg_match( "/create\s+temporary/i", $sql ) )
            {
                $isWriteQuery = false;
            }
            
            // fix sql for mssql
            $patterns = array();
            $replace = array();
            $patterns[] = "/^\s*CREATE\s+TEMPORARY\s+TABLE\s+(.*)/i";
            $replace[] = "CREATE TABLE \\1";
            $patterns[] = "/(.*)LENGTH\(([a-zA-Z\s]*)\)(.*)/i";
            $replace[] = "\\1LEN(\\2)\\3";
            $sql = preg_replace( $patterns, $replace, $sql );
            
            if ( preg_match( "/^INSERT\s+INTO\s+(\w+)/i", $sql, $matches ) )
            {
                $tablename = $matches[1];
                $isInsert = true;
            }
            else
                $isInsert = false;
            
            if ( $isWriteQuery )
            {
                $connection = $this->DBWriteConnection;
            }
            else
            {
                $connection = $this->DBConnection;
            }
            
            if ( $isInsert )
            {
                $tmp1sql = "DECLARE @indent int; SET @indent = ( SELECT OBJECTPROPERTY ( object_id('$tablename') , 'TableHasIdentity' ) ); IF ( @indent = 1 ) BEGIN SET IDENTITY_INSERT $tablename on; END";
                $tmp1 = $this->functions->query( $connection, $tmp1sql );
                if ( $tmp1 === false )
                {
                    $this->setError();
                    eZDebug::writeError( $this->ErrorMessage . $tmp1sql, "eZMSSQLDB::query()" );
                }
            }
            $result = $this->functions->query( $connection, $this->_appendN( $sql ) );
            
            if ( $this->OutputSQL )
            {
                $this->endTimer();
                
                if ( $this->timeTaken() > $this->SlowSQLTimeout )
                {
                    $this->reportQuery( 'eZMSSQLDB::query()', $sql, self::num_rows( $result ), $this->timeTaken() );
                }
            }
            if ( $result === false )
            {
                $this->setError();
                eZDebug::writeError( $this->ErrorMessage, "eZMSSQLDB::query()" );
            }
            if ( $isInsert )
            {
                $tmp2sql = "DECLARE @indent int; SET @indent = ( SELECT OBJECTPROPERTY ( object_id('$tablename') , 'TableHasIdentity' ) ); IF ( @indent = 1 ) BEGIN SET IDENTITY_INSERT $tablename off; END";
                
                $tmp2 = $this->functions->query( $connection, $tmp2sql );
                
                if ( $tmp2 === false )
                {
                    $this->setError();
                    eZDebug::writeError( $this->ErrorMessage . $tmp2sql, "eZMSSQLDB::query()" );
                }
            }
            
            eZDebug::accumulatorStop( 'mssql_query' );
            
            if ( is_resource( $result ) or $result === true )
            {
                return $result;
            }
            else
            {
                
                return false;
            }
        }
        else
        {
            eZDebug::writeError( "Trying to do a query without being connected to a database!", "eZMSSQLDB" );
            return false;
        }
    
    }

    function filterField( &$str )
    {
        if ( strlen( $str ) == 1 )
            $str = str_replace( ' ', '', $str );
        return $str;
    }

    /*!
     \reimp
    */
    function arrayQuery( $sql, $params = array(), $server = false )
    {
        
        $retArray = array();
        if ( $this->IsConnected )
        {
            $limit = false;
            $offset = 0;
            $column = false;
            // check for array parameters
            if ( is_array( $params ) )
            {
                if ( isset( $params["limit"] ) and is_numeric( $params["limit"] ) )
                    $limit = $params["limit"];
                
                if ( isset( $params["offset"] ) and is_numeric( $params["offset"] ) )
                    $offset = $params["offset"];
                
                if ( isset( $params["column"] ) and is_numeric( $params["column"] ) )
                    $column = $params["column"];
            }
            /** LIMIT can't be implemted at current point OW_NUMBER() OVER always expects a order BY this doesn't help us.
            if ( $limit !== false and is_numeric( $limit ) )
            {
                $offset = 1;
                $limit = $limit + 1;
                #$sql .= "\n--FAKE LIMIT $offset, $limit \n";
            }
            if ( $offset !== false and is_numeric( $offset ) )
            {
                $offset = $offset + 1;
                if ( $limit === false )
                    $limit = "18446744073709551615"; // 2^64-1
                #$sql .= "\n--FAKE LIMIT $offset, 18446744073709551615\n"; // 2^64-1
            }

             *)(ORDER\s+BY\s+(.*)(ASC|DESC|))$/ims", $sql, $m ) )
            {
                $old =$sql;
                $sql  = "WITH ordered AS ( SELECT ". $m[1] . " ROW_NUMBER() OVER (". $m[9] .") as ezmssqldbrownumber, " . $m[2];
             * FROM ordered WHERE ezmssqldbrownumber BETWEEN $offset and $limit ";
                eZDebug::writeDebug( $m, "ROW_NUMBER matches"); 
            }
            
             */
            /* disable conversion
            // The converted sql should not be output
            if ( $this->InputTextCodec )
            {
                eZDebug::accumulatorStart( 'mssql_conversion', 'mssql_total', 'String conversion in mssql' );
                $sql = $this->InputTextCodec->convertString( $sql );
                eZDebug::accumulatorStop( 'mssql_conversion' );
            }
            */
            if ( $this->OutputSQL )
            {
                $this->startTimer();
            }
            // Check if it's a write or read sql query
            $sql = trim( $sql );
            
            $options = array( 
                'Scrollable' => SQLSRV_CURSOR_STATIC 
            );
            
            $result = $this->functions->query( $this->DBConnection, $sql, array(), $options );
            
            if ( $this->OutputSQL )
            {
                $this->endTimer();
                
                if ( $this->timeTaken() > $this->SlowSQLTimeout )
                {
                    $this->reportQuery( 'eZMSSQLDB::arrayQuery()', $sql, self::num_rows( $result ), $this->timeTaken() );
                }
            }
            
            if ( $result === false )
            {
                $this->setError();
                eZDebug::writeError( $this->ErrorMessage, "eZMSSQLDB::arrayQuery()" );
                return false;
            }
            if ( $result === null )
                return array();
            
            $numRows = $this->functions->num_rows( $result );
            $numRows = $numRows - $offset;
            
            if ( $numRows > 0 )
            {
                if ( $limit == false )
                    $limit = 18446744073709551615;
                
                if ( ! is_string( $column ) )
                {
                    eZDebug::accumulatorStart( 'mssql_loop', 'mssql_total', 'Looping result' );
                    
                    for ( $i = 0; ( $i < $numRows ) and ( $i < $limit ); $i ++ )
                    {
                        
                        $tmp_row = $this->functions->fetch_array( $result );
                        unset( $conv_row );
                        $conv_row = array();
                        reset( $tmp_row );
                        while ( ( $key = key( $tmp_row ) ) !== null )
                        {
                            
                            $conv_row[$key] = $tmp_row[$key];
                            next( $tmp_row );
                        }
                        $retArray[$i + $offset] = $conv_row;
                    
                    }
                    eZDebug::accumulatorStop( 'mssql_loop' );
                
                }
                else
                {
                    eZDebug::accumulatorStart( 'mssql_loop', 'mssql_total', 'Looping result' );
                    for ( $i = 0; ( $i < $numRows ) and ( $i < $limit ); $i ++ )
                    {
                        $tmp_row = $this->functions->fetch_array( $result );
                        $retArray[$i + $offset] = $tmp_row[$column];
                    
                    }
                    eZDebug::accumulatorStop( 'mssql_loop' );
                }
            }
        }
        return $retArray;
    }

    /*!
     \private
    */
    function subString( $string, $from, $len = null )
    {
        if ( $len == null && ! is_numeric( $len ) )
        {
            return " substring( $string , $from, 8000 ) ";
        }
        else
        {
            return " substring( $string , $from , $len ) ";
        }
    }

    function concatString( $strings = array() )
    {
        $str = implode( " + ", $strings );
        return " $str ";
    }

    function md5( $str )
    {
        return " SUBSTRING( master.dbo.fn_varbintohexstr( HashBytes( 'md5'," . $str . ") ), 3, 32) ";
    }

    /*!
     \reimp
    */
    function supportedRelationTypeMask()
    {
        return self::RELATION_TABLE_BIT;
    }

    /*!
     \reimp
    */
    function supportedRelationTypes()
    {
        return array( 
            self::RELATION_TABLE 
        );
    }

    /*!
     \reimp
    */
    function relationCounts( $relationMask )
    {
        if ( $relationMask & self::RELATION_TABLE_BIT )
            return $this->relationCount();
        else
            return 0;
    }

    /*!
      \reimp
    */
    function relationCount( $relationType = self::RELATION_TABLE )
    {
        if ( $relationType != self::RELATION_TABLE )
        {
            eZDebug::writeError( "Unsupported relation type '$relationType'", 'eZMSSQLDB::relationCount' );
            return false;
        }
        $count = false;
        if ( $this->IsConnected )
        {
            $sql = "select name from sysobjects where type= 'U'";
            $result = $this->query( $sql );
            $count = sqlsrv_num_rows( $result );
            sqlsrv_free_stmt( $result );
        }
        return $count;
    }

    /*!
      \reimp
    */
    function relationList( $relationType = self::RELATION_TABLE )
    {
        if ( $relationType != self::RELATION_TABLE )
        {
            eZDebug::writeError( "Unsupported relation type '$relationType'", 'eZMSSQLDB::relationList' );
            return false;
        }
        $tables = array();
        if ( $this->IsConnected )
        {
            //$result =& mssql_list_tables( $this->DB, $this->DBConnection );
            $sql = "select name from sysobjects where type= 'U'";
            $result = $this->arrayQuery( $sql );
            //$count = mssql_num_rows( $result );
            while ( $row = array_shift( $result ) )
            {
                $tables[] = $row['name'];
            }
        }
        return $tables;
    }

    /*!
     \reimp
    */
    function eZTableList( $server = self::SERVER_MASTER )
    {
        $tables = array();
        if ( $this->IsConnected )
        {
            
            $sql = "select name from sysobjects where type= 'U'";
            $result = $this->arrayQuery( $sql );
            $i = 0;
            
            while ( $row = array_shift( $result ) )
            {
                $tableName = $row[$i];
                $i ++;
                if ( substr( $tableName, 0, 2 ) == 'ez' )
                {
                    $tables[$tableName] = self::RELATION_TABLE;
                }
            }
        }
        return $tables;
    }

    /*!
     \reimp
    */
    function relationMatchRegexp( $relationType )
    {
        return "#^ez#";
    }

    /*!
      \reimp
    */
    function removeRelation( $relationName, $relationType )
    {
        $relationTypeName = $this->relationName( $relationType );
        if ( ! $relationTypeName )
        {
            eZDebug::writeError( "Unknown relation type '$relationType'", 'eZMSSQLDB::removeRelation' );
            return false;
        }
        
        if ( $this->IsConnected )
        {
            $sql = "DROP $relationTypeName $relationName";
            return $this->query( $sql );
        }
        return false;
    }

    function beginQuery()
    {
        if ( $this->IsConnected )
        {
            $this->functions->begin( $this->DBConnection );
        }
    }

    function commitQuery()
    {
        if ( $this->IsConnected )
        {
            $this->functions->commit( $this->DBConnection );
        
        }
    }

    function rollbackQuery()
    {
        if ( $this->IsConnected )
        {
            $this->functions->rollback( $this->DBConnection );
        }
    }

    function lastSerialID( $table = false, $column = false )
    {
        if ( $this->IsConnected )
        {
            $oldRecordError = $this->RecordError;
            // Turn off error handling while we begin
            $this->RecordError = false;
            $id = $this->arrayQuery( "SELECT @@identity as computed" );
            $this->RecordError = $oldRecordError;
            
            while ( $row = array_shift( $id ) )
            {
                return $row['computed'];
            }
        }
        else
            return false;
    }

    /*!
     \reimp
    */
    function escapeString( $str )
    {
        $return = str_replace( "'", "''", $str );
        return $return;
    }

    /*!
     \reimp
    */
    function close()
    {
        if ( $this->IsConnected )
        {
        	$this->functions->close($this->DBConnection);
        	$this->functions->close($this->DBWriteConnection);
        }
    }

    /*!
     \reimp
    */
    function createDatabase( $dbName )
    {
        if ( $this->DBConnection != false )
        {
            $sql = "CREATE database $dbName";
            $result = $this->query( $sql );
        }
    }

    /*!
     \reimp
    */
    function setError()
    {
        $this->ErrorMessage = $this->functions->error();
        
        $oldRecordError = $this->RecordError;
        // Turn off error handling while we unlock
        $this->RecordError = false;
        $this->unlock();
        $this->RecordError = $oldRecordError;
        
        $this->reportError();
    
    }

    /*!
     \reimp
    */
    function availableDatabases()
    {
        
        throw new Exception( __FUNCTION__ . " not implemented" );
        
        if ( $this->errorNumber() != 0 )
        {
            return null;
        }
        
        $databases = array();
        $i = 0;
        $numRows = sqlsrv_num_rows( $databaseArray );
        if ( count( $numRows ) == 0 )
        {
            return false;
        }
        
        while ( $i < $numRows )
        {
            // we don't allow "mysql" database to be shown anywhere
            // geht nicht!! -->             $curDB = mssql_db_name( $databaseArray, $i );
            if ( strcasecmp( $curDB, 'mssql' ) != 0 )
                $databases[] = $curDB;
            ++ $i;
        }
        return $databases;
    }

    function databaseServerVersion()
    {
        $sql = "SELECT SERVERPROPERTY('productversion') as productversion, SERVERPROPERTY ('productlevel') as productlevel, SERVERPROPERTY ('edition') as edition";
        $versionInfo = $this->arrayQuery( $sql );
        $versionArray = explode( '.', $versionInfo[0]['productversion'] );
        return array( 
            'string' => $versionInfo[0]['productversion'] . ' ' . $versionInfo[0]['edition'] , 
            'values' => $versionArray 
        );
    }

    function databaseClientVersion()
    {
        return array( 
            'string' => null , 
            'values' => null 
        );
    }

    function isCharsetSupported( $charset )
    {
        # fix for uft8
        return true;
    }

}
