<?php

class eZMSSQLFunctions
{
    const SQLSRV = 'sqlsrv';
    const FREETDS = 'freetds';

    function __construct( $type )
    {
        $this->mode = $type;
    }

    function &connect( $server, $user, $password, $db )
    {
        if ( $this->mode == self::SQLSRV )
        {
            $connection = sqlsrv_connect( $server, array( 
                "UID" => $user , 
                "PWD" => $password , 
                "Database" => $db 
            ) );
        }
        else
        {
            $connection = mssql_connect( $server, $user, $password );
            mssql_select_db( $db, $connection );
        }
        return $connection;
    }

    function num_rows( &$result )
    {
        if ( $this->mode == self::SQLSRV )
        {
            $result2 = sqlsrv_num_rows( $result );
        }
        else
        {
            $result2 = mssql_num_rows( $result );
        }
        return $result2;
    }

    function &query( $connection, $sql, $params = array(), $options = array() )
    {
        if ( $this->mode == self::SQLSRV )
        {
            $result = sqlsrv_query( $connection, $sql, $params, $options );
        }
        else
        {
            $result = mssql_query( $sql, $connection );
        }
        return $result;
    }

    public function begin( &$connection )
    {
        if ( $this->mode == self::SQLSRV )
        {
            $result = sqlsrv_begin_transaction( $connection );
        }
        else
        {
            if ( ! isset( $this->TransactionNAME ) )
                $this->TransactionNAME = 0;
            $this->TransactionNAME += 1;
            
            $result = $this->query( $connection, "BEGIN TRANSACTION COUNTER" . $this->TransactionNAME . " WITH MARK 'COUNTER" . $this->TransactionNAME . "'" );
        }
        return $result;
    }

    public function commit( $connection )
    {
        if ( $this->mode == self::SQLSRV )
        {
            $result = sqlsrv_commit( $connection );
        
        }
        else
        {
            $result = $this->query( $connection, "COMMIT TRANSACTION COUNTER" . $this->TransactionNAME );
            $this->TransactionNAME -= 1;
        }
        return $result;
    }
    public function rollback( $connection )
    {
        if ( $this->mode == self::SQLSRV )
        {
            $result = sqlsrv_rollback( $connection );
        
        }
        else
        {
            $result = $this->query( "ROLLBACK" );
        }
        return $result;
    }
    public function close( $connection )
    {
        if ( $this->mode == self::SQLSRV )
        {
            $result = sqlsrv_close( $connection );
        
        }
        else
        {
            $result = mssql_close( $connection );
        }
        return $result;
    }
    public function error( &$connection )
    {
        if ( $this->mode == self::SQLSRV )
        {
            if ( ( $errors = sqlsrv_errors( SQLSRV_ERR_ALL ) ) != null )
            {
                $str = '';
                foreach ( $errors as $error )
                {
                    $str .= "SQLSTATE: " . $error['SQLSTATE'] . "\n";
                    $str .= "code: " . $error['code'] . "\n";
                    $str .= "message: " . $error['message'] . "\n";
                }
                return $str;
            }
            return false;
        }
        else
        {
            if ( $error = mssql_get_last_message() )
            {
                return $error;
            }
            return false;
        }
    
    }

    public function &fetch_array( &$result )
    {
        if ( $this->mode == self::SQLSRV )
        {
            $result2 = sqlsrv_fetch_array( $result, SQLSRV_FETCH_ASSOC );
        }
        else
        {
            $result2 = mssql_fetch_array( $result, MSSQL_ASSOC );
        }
        return $result2;
    }
}