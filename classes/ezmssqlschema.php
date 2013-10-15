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

class eZMssqlSchema extends eZDBSchemaInterface
{
    /*!
     \reimp
     Constructor

     \param db instance
    */
    function eZMssqlSchema( $params )
    {
        $this->eZDBSchemaInterface( $params );
    }
    /*!
     \reimp
    */
    function schema( $params = array() )
    {
        $params = array_merge( array( 'meta_data' => false,
                                      'format' => 'generic' ),
                               $params );
        $schema = array();

        if ( $this->Schema === false )
        {
            $sql = "sp_tables @table_name = 'ez%', @table_owner = 'dbo'";
           
            $tableArray = $this->DBInstance->arrayQuery( $sql );

            foreach( $tableArray as $tableNameArray )
            {
                $table_name = $tableNameArray['TABLE_NAME'];
                $schema_table['name'] = $table_name;
                $schema_table['fields'] = $this->fetchTableFields( $table_name, $params );
                $schema_table['indexes'] = $this->fetchTableIndexes( $table_name, $params );

                $schema[$table_name] = $schema_table;
            }
            $this->transformSchema( $schema, $params['format'] == 'local' );
            ksort( $schema );
            $this->Schema = $schema;
        }
        else
        {
            $this->transformSchema( $this->Schema, $params['format'] == 'local' );
            $schema = $this->Schema;
        }
        return $schema;
    }

	function parseType( &$info )
	{
		preg_match ( "@([a-z]*)( identity)?@", $info['TYPE_NAME'], $matches );
		if ( isset( $matches[2] ) )
		{
            $info['TYPE_NAME'] = $matches[1];
            $info['IDENTITY'] = true;
		}
		if ( $info['TYPE_NAME'] == "text" or $info['TYPE_NAME'] == "ntext" )
    		$info['TYPE_NAME'] = "longtext";
		return $info['TYPE_NAME'];
	}
	function parseDefault( $field )
	{
	    $default = $field['COLUMN_DEF'];
		preg_match ( "@\(\(([0-9]*)\)\)@", $default, $matches );
		if ( isset( $matches[1] ) )
		{
            return (int)$matches[1];
		}
		preg_match ( "@\('([\w\.]*)'\)@", $default, $matches );
		if ( isset( $matches[1] ) )
		{
            return (string)$matches[1];
		}
		return null;
	}
	/*!
	 \private

     \param table name
	 */
	function fetchTableFields( $table, $params )
	{
		$fields = array();
        $numericTypes = array( 'float', 'int', 'decimal' );
        $blobTypes = array( 'tinytext', 'text', 'mediumtext', 'longtext','shorttext' );
        $charTypes = array( 'varchar', 'char' );
        
        $sql = "sp_columns '$table'";
		$resultArray = $this->DBInstance->arrayQuery( $sql );

        foreach( $resultArray as $row )
        {
			$field = array();
			$field['type'] = $this->parseType( $row );

			if ( $row['LENGTH'] )
			{
			    if ( $field['type'] == 'int' )
			    {
			        $maxnumber = pow( 2, $row['LENGTH'] * 8 ) - 1;
                    // add +1 for signed storage range
			        $field['length'] = strlen( (string)$maxnumber ) + 1;
			    }
			    elseif (  in_array( $field['type'], array( 'decimal' ) ) )
			    {
			        $field['length'] = $row['PRECISION'] . "," . $row['SCALE'];
			    }
			    elseif (  in_array( $field['type'], array( 'float', 'longtext', 'text' ) ) )
			    {
			        //do nothing for a float
			    }
			    else
				    $field['length'] = $row['LENGTH'];
			}
            $field['not_null'] = 0;
			if ( $row['NULLABLE'] == '0' )
			{
				$field['not_null'] = '1';
			}

            $field['default'] = false;
            if ( !$field['not_null'] )
            {
                if ( $row['COLUMN_DEF'] === null )
                    $field['default'] = null;
                else
                    $field['default'] = $this->parseDefault( $row['COLUMN_DEF'] );
            }
            else
			{
				$field['default'] = $this->parseDefault( $row['COLUMN_DEF'] );
			}


            if ( in_array( $field['type'], $charTypes ) )
            {
                if ( !$field['not_null'] )
                {
                    if ( $field['default'] === null )
                    {
                        $field['default'] = null;
                    }
                    else if ( $field['default'] === false )
                    {
                        $field['default'] = '';
                    }
                }
            }
            else if ( in_array( $field['type'], $numericTypes ) )
            {
                if ( $field['default'] === false )
                {
                    if ( $field['not_null'] )
                    {
                        $field['default'] = 0;
                    }
                }
                else if ( $field['type'] == 'int' )
                {
                    if ( $field['not_null'] or
                         is_numeric( $field['default'] ) )
                        $field['default'] = (int)$field['default'];
                }
                else if ( $field['type'] == 'float' or
                          is_numeric( $field['default'] ) )
                {
                    if ( $field['not_null'] or
                         is_numeric( $field['default'] ) )
                        $field['default'] = (float)$field['default'];
                }
            }
            else if ( in_array( $field['type'], $blobTypes ) )
            {
                // We do not want default for blobs.
                $field['default'] = false;
            }

			if ( isset( $row['IDENTITY'] ) and $row['IDENTITY'] )
			{
				unset( $field['length'] );
				$field['not_null'] = 0;
				$field['default'] = false;
				$field['type'] = 'auto_increment';
			}

            if ( !$field['not_null'] )
                unset( $field['not_null'] );

			$fields[$row['COLUMN_NAME']] = $field;
		}
        ksort( $fields );

		return $fields;
	}
	function parseIndex( &$info )
	{
		preg_match ( "@([a-z]*)( identity)?@", $info['TYPE_NAME'], $matches );
		if ( isset( $matches[2] ) )
		{
            $info['TYPE_NAME'] = $matches[1];
            $info['IDENTITY'] = true;
		}
	}
	/*!
	 * \private
	 */
	function fetchTableIndexes( $table, $params )
	{
        $metaData = $params['meta_data'];
		$indexes = array();
		// make use of this structure when dealing in a clustered env with index an different servers
		// sp_indexes @table_server = @@SERVERNAME, @table_name = 'ezapprove_items'; 

		$sql = "sp_helpindex @objname = '$table';"; 
        $resultArray = $this->DBInstance->arrayQuery( $sql );

        foreach( $resultArray as $row )
		{
			$kn = $row['index_name'];

			if ( strpos( $row['index_description'], 'primary key' ) !== false )
			{
			    $kn = 'PRIMARY';
				$indexes[$kn]['type'] = 'primary';
			}
			elseif ( strpos( $row['index_description'], 'unique' ) !== false )
			{
				$indexes[$kn]['type'] = 'unique';
			}
			else
			{
                $indexes[$kn]['type'] = 'non-unique';
			}
            $indexFieldDef = explode( ', ', $row['index_keys'] );
			$indexes[$kn]['fields'] = $indexFieldDef;
		}
        ksort( $indexes );

		return $indexes;
	}

	/*!
	 * \private
	 */
	function generateAddIndexSql( $table_name, $index_name, $def, $params, $isEmbedded = false )
	{
        $diffFriendly = isset( $params['diff_friendly'] ) ? $params['diff_friendly'] : false;
        // If the output should compatible with existing MySQL dumps
        $mssqlCompatible = isset( $params['compatible_sql'] ) ? $params['compatible_sql'] : false;
        $sql = '';

        // Will be set to true when primary key is inside CREATE TABLE
        if ( !$isEmbedded and !in_array( $def['type'], array( 'non-unique', 'unique' ) ) )
        {
            $sql .= "ALTER TABLE $table_name ADD";
            $sql .= " ";
        }

		switch ( $def['type'] )
		{
            case 'primary':
            {
                $sql .= "constraint ".$table_name."_pk PRIMARY KEY";
                if ( $mssqlCompatible )
                    $sql .= " ";
            } break;

            case 'non-unique':
            {
                if ( $isEmbedded )
                {
					$sql .= "CREATE INDEX $index_name ON " . $table_name ." ";
                }
                else
                {
                    $sql .= "CREATE INDEX $index_name ON " . $table_name ." ";
                }
            } break;

            case 'unique':
            {
                if ( $isEmbedded )
                {
                    $sql .= "CREATE UNIQUE INDEX $index_name ON " . $table_name ." ";
                }
                else
                {
                    $sql .= "CREATE UNIQUE INDEX $index_name ON " . $table_name ." ";
                }
            } break;
		}

        $sql .= ( $diffFriendly ? " (\n    " : ( $mssqlCompatible ? " (" : " ( " ) );
        $fields = $def['fields'];
        $i = 0;
        foreach ( $fields as $fieldDef )
        {
            if ( $i > 0 )
            {
                $sql .= $diffFriendly ? ",\n    " : ( $mssqlCompatible ? ',' : ', ' );
            }
            if ( is_array( $fieldDef ) )
            {
                $sql .= $fieldDef['name'];
                if ( isset( $fieldDef['mssql:length'] ) )
                {
                    if ( $diffFriendly )
                    {
                        $sql .= "(\n";
                        $sql .= "    " . str_repeat( ' ', strlen( $fieldDef['name'] ) );
                    }
                    else
                    {
                        $sql .= $mssqlCompatible ? "(" : "( ";
                    }
                    $sql .= $fieldDef['mssql:length'];
                    if ( $diffFriendly )
                    {
                        $sql .= ")";
                    }
                    else
                    {
                        $sql .= $mssqlCompatible ? ")" : " )";
                    }
                }
            }
            else
            {
                $sql .= $fieldDef;
            }
            ++$i;
        }
        $sql .= ( $diffFriendly ? "\n)" : ( $mssqlCompatible ? ")" : " )" ) );

        if ( !$isEmbedded )
        {
            return $sql . ";\n";
        }
        return $sql;
	}

	/*!
	 * \private
	 */
	function generateDropIndexSql( $table_name, $index_name, $def, $params )
	{
        $sql = '';
		$sql .= "ALTER TABLE $table_name DROP ";

		if ( $def['type'] == 'primary' )
		{
			$sql .= 'PRIMARY KEY';
		}
		else
		{
			$sql .= "INDEX $index_name";
		}
		return $sql . ";\n";
	}

	/*!
	 * \private
	 */
	function generateFieldDef( $field_name, $def, &$skip_primary, $params = null )
	{
        $diffFriendly = isset( $params['diff_friendly'] ) ? $params['diff_friendly'] : false;
        // If the outputshould compatible with existing MySQL dumps
        $mssqlCompatible = isset( $params['compatible_sql'] ) ? $params['compatible_sql'] : false;

		$sql_def = $field_name . ' ';
        $defaultText = $mssqlCompatible ? "default" : "DEFAULT";


        if ( $def['type'] == 'longtext' || $def['type'] == 'tinytext' || $def['type'] == 'mediumtext')
		{
			$def['type'] = 'ntext';
		}

		if ( $def['type'] == 'varchar')
		{
			$def['type'] = 'nvarchar';
		}

		if ( $def['type'] == 'char')
		{
			$def['type'] = 'nchar';
		}

		if ( $def['type'] != 'auto_increment' )
		{
            $defList = array();
            $type = $def['type'];

			if ( isset( $def['length'] ) && $type != "int")
			{
				if ( $def['length'] > 4000 and ( $def['type'] == 'nvarchar' || $def['type'] == 'nchar' ) )
				{
					$type .= "(4000)";
				}
				else
				{
					$type .= "({$def['length']})";
				}
			}

			$defList[] = $type;


			if ( isset( $def['not_null'] ) && ( $def['not_null'] ) )
            {
				$defList[] = 'NOT NULL';
			}
			else
			{
			    $defList[] = 'NULL';
			}

            if ( array_key_exists( 'default', $def ) )
            {
                if ( $def['default'] === null )
                {
                    $defList[] = "$defaultText NULL";
                }
                else if ( $def['default'] !== false )
                {
					if($type <> "int")
					{
                       $defList[] = "$defaultText '{$def['default']}'";
					}
					else
					{
				       $defList[] = "$defaultText {$def['default']}";
					}
                }

			}

			else if ( $def['type'] == 'varchar')
			{
				$defList[] = $defList[] = "$defaultText ''";
			}

            $sql_def .= join( $diffFriendly ? "\n    " : " ", $defList );

			$skip_primary = false;
		}
		else
		{
            $incrementText = $mssqlCompatible ? "auto_increment" : "IDENTITY(0,1)";
            if ( $diffFriendly )
            {
                $sql_def .= "int\n    $incrementText\n NOT NULL";
            }
            else
            {
                $sql_def .= "int $incrementText NOT NULL";
            }
			$skip_primary = true;
		}
		return $sql_def;
	}
	/*!
	 * \private
	 */
	function generateAddFieldSql( $table_name, $field_name, $def, $params )
	{
		$sql = "ALTER TABLE $table_name ADD ";
		$sql .= eZMssqlSchema::generateFieldDef ( $field_name, $def, $dummy );

		return $sql . ";\n";
	}

	function generateAlterFieldSql( $table_name, $field_name, $def = array(), $params )
	{
		$sql = "ALTER TABLE $table_name alter COLUMN $field_name set ";
		$sql .= eZMssqlSchema::generateFieldDef ( $field_name, $def, $dummy );

		return $sql . ";\n";
	}

	/*!
     \reimp
     \note Calls generateTableSQL() with \a $asArray set to \c false
    */
	function generateTableSchema( $tableName, $table, $params )
	{
        return $this->generateTableSQL( $tableName, $table, $params, false, false );
    }

	/*!
	 \reimp
     \note Calls generateTableSQL() with \a $asArray set to \c true
    */
	function generateTableSQLList( $tableName, $table, $params, $separateTypes )
	{
        return $this->generateTableSQL( $tableName, $table, $params, true, $separateTypes );
    }

    function getIncrementKeys( $tableDef )
	{
	    $array = array();
	    foreach ( $tableDef['fields'] as $name => $fielddef )
		{
			if ( $fielddef['type'] == 'auto_increment' )
			    $array[]=$name;
		}
		return $array;
	}

    /*!
     \virtual
     \protected
    */
    function generateTableInsertSQLList( $tableName, $tableDef, $dataEntries, $params, $withClosure = true )
    {
        $diffFriendly = isset( $params['diff_friendly'] ) ? $params['diff_friendly'] : false;
        $multiInsert = ( isset( $params['allow_multi_insert'] ) and $params['allow_multi_insert'] ) ? $this->isMultiInsertSupported() : false;
		$withClosure = true;
        // Make sure we don't generate SQL when there are no rows
        if ( count( $dataEntries['rows'] ) == 0 )
            return '';

        $sqlList = array();
        $sql = '';
        $defText = '';
        $entryIndex = 0;
        foreach ( $dataEntries['fields'] as $fieldName )
        {
            if ( !isset( $tableDef['fields'][$fieldName] ) )
                continue;
            if ( $entryIndex == 0 )
            {
                if ( $diffFriendly )
                {
                    $defText .= "  ";
                }
            }
            else
            {
                if ( $diffFriendly )
                {
                    $defText .= ",\n  ";
                }
                else
                {
                    $defText .= ", ";
                }
            }
            $defText .= $fieldName;
            ++$entryIndex;
        }

        $insertIndex = 0;
        foreach ( $dataEntries['rows'] as $row )
        {
            if ( $multiInsert and $insertIndex > 0 )
            {
                if ( $diffFriendly )
                    $sql .= "\n,\n";
                else
                    $sql .= ", ";
            }
            $dataText = '';
            $entryIndex = 0;
            foreach ( $dataEntries['fields'] as $fieldName )
            {
                if ( !isset( $tableDef['fields'][$fieldName] ) )
                    continue;
                if ( $entryIndex == 0 )
                {
                    if ( $diffFriendly )
                    {
                        $dataText .= "  ";
                    }
                }
                else
                {
                    if ( $diffFriendly )
                    {
                        $dataText .= ",\n  ";
                    }
                    else
                    {
                        $dataText .= ",";
                    }
                }
                $dataText .= $this->generateDataValueTextSQL( $tableDef['fields'][$fieldName], $row[$entryIndex] );
				++$entryIndex;
            }
            if ( $multiInsert )
            {
                if ( $diffFriendly )
                {
                    $sql .= "(\n  $dataText\n)";
                }
                else
                {
                    $sql .= "($dataText)";
                }
                ++$insertIndex;
            }
            else
            {
                if ( $diffFriendly )
                {
                    if ( count( $this->getIncrementKeys( $tableDef ) ) > 0 )
                    	$sqlList[] = "SET identity_insert $tableName ON". ( $withClosure ? ";" : "" ) . "\nINSERT INTO $tableName (\n$defText\n) VALUES (\n$dataText\n)" . ( $withClosure ? ";" : "" ) . "\nSET identity_insert $tableName OFF" . ( $withClosure ? ";" : "" );
					else
					    $sqlList[] = "INSERT INTO $tableName (\n$defText\n) VALUES (\n$dataText\n)" . ( $withClosure ? ";" : "" );
				}
                else
                {
                    if ( count( $this->getIncrementKeys( $tableDef ) ) > 0 )
                    	$sqlList[] = "SET identity_insert $tableName ON" . ( $withClosure ? ";" : "" ) . "INSERT INTO $tableName ($defText) VALUES ($dataText)" . ( $withClosure ? ";" : "" ) . "SET identity_insert $tableName OFF" . ( $withClosure ? ";" : "" );
					else
					    $sqlList[] = "INSERT INTO $tableName ($defText) VALUES ($dataText)" . ( $withClosure ? ";" : "" );
				}
            }
        }
        if ( $multiInsert )
        {
            if ( $withClosure )
                $sql .= "\n;";
            $sqlList[] = $sql;
        }
        return $sqlList;
    }
    function generateAddTriggerSql( $tableName, $tableDef )
    {
        
 foreach ( $tableDef["fields"] as $key => $field )
 {  
       $namesAll[] = $key;
       
       if ( $field['type'] == 'auto_increment' )
        $increment =$key;
       else
        $namesExludedIncrement[] = $key;
 }
foreach ( $namesAll as $name )
{
    if ( $name != $increment )
    {
        $insertpieces[] = 'DEFAULT';
        $updatepieces[]= ''.$name.' = ( SELECT '.$name.' from inserted )';
    }
    else
        $insertpieces[] = '@increment'; 
        
}
   
                $trigger = "
CREATE TRIGGER [dbo].[" . $tableName . "_insert_tr] 
   ON  [dbo].[" . $tableName . "] 
   INSTEAD OF INSERT
AS 
BEGIN
--    BEGIN TRY
    DECLARE @counter int;
    DECLARE @counter_val int;
    DECLARE @increment int;
    SET @counter = (SELECT count( " . $increment . " ) FROM inserted WHERE inserted." . $increment . " = '0');
    SET @counter_val = ( SELECT " . $increment . " from inserted );
    SET @increment =  IDENT_CURRENT ( '" . $tableName . "' ) + IDENT_INCR('" . $tableName . "') ;

	IF ( @counter = 0 )
		BEGIN
			INSERT INTO " . $tableName . " ( ". implode( ",", $namesAll ) . " ) SELECT ". implode( ",", $namesAll ) . " FROM inserted
		END
	ELSE
		BEGIN
			INSERT INTO " . $tableName . " ( ". implode( ",", $namesAll ) . " ) VALUES ( ". implode( ",", $insertpieces ) . " );";
                 if ( isset($updatepieces) and count($updatepieces)!=0){
			$trigger .= "UPDATE " . $tableName . " SET  ". implode( ",", $updatepieces ) . " WHERE " . $increment . " = @increment;";
                 }
			$trigger .= "
		END

--    END TRY
--    BEGIN CATCH
--        RETURN EXEC eZ_RethrowError;
--    END CATCH;
END;
";
    
                return $trigger;
    }

	/*!
	 \private

     \param $asArray If \c true all SQLs are return in an array,
                     if not they are returned as a string.
     \note When returned as array the SQLs will not have a semi-colon to end the statement
    */
	function generateTableSQL( $tableName, $tableDef, $params, $asArray, $separateTypes = false )
	{
        $diffFriendly = isset( $params['diff_friendly'] ) ? $params['diff_friendly'] : false;
        $mssqlCompatible = isset( $params['compatible_sql'] ) ? $params['compatible_sql'] : false;

        if ( $asArray )
        {
            if ( $separateTypes )
            {
                $sqlList = array( 'tables' => array() );
            }
            else
            {
                $sqlList = array();
            }
        }

		$sql = '';
        $skip_pk = false;
        $sql_fields = array();
        $sql .= "CREATE TABLE $tableName (\n";

        $fields = $tableDef['fields'];

        foreach ( $fields as $field_name => $field_def )
        {
            $sql_fields[] = '  ' . eZMssqlSchema::generateFieldDef( $field_name, $field_def, $skip_pk_flag, $params );
            if ( $skip_pk_flag )
            {
                $skip_pk = true;
            }
        }
        

        // Make sure the order is as defined by 'offset'
        $indexes = $tableDef['indexes'];

        // We need to add all keys in table definition
        foreach ( $indexes as $index_name => $index_def )
        {
            // columns with lob types(lob, ntext, nchar, nvarchar ) can't have indexes
            if ( isset( $fields[$index_name] ) and in_array( $fields[$index_name]['type'] , array( 'longtext' ) ) )
                continue;
            if( $index_def['type'] == "primary" || $index_def['type'] =="id" )
            {
				$sql_fields[] = ( $diffFriendly ? '' : '  ' ) . eZMssqlSchema::generateAddIndexSql( $tableName, $index_name, $index_def, $params, true );
            }
		}
        $sql .= join( ",\n", $sql_fields );
        $sql .= "\n);\n";
        


        // Add some extra table options if they are required
        $extraOptions = array();

        if ( isset( $tableDef['options'] ) )
        {
            foreach( $tableDef['options'] as $optionType => $optionValue )
            {
                $optionText = $this->generateTableOption( $tableName, $tableDef, $optionType, $optionValue, $params );
                if ( $optionText )
                    $extraOptions[] = $optionText;
            }
        }

        if ( count( $extraOptions ) > 0 )
        {
            $sql .= " " . implode( $diffFriendly ? "\n" : " ", $extraOptions );
        }

        foreach ( $indexes as $index_name => $index_def )
        {
            if( $index_def['type'] == "non-unique" )
            {
            	$sql .= ( $diffFriendly ? '' : '  ' ) . eZMssqlSchema::generateAddIndexSql( $tableName, $index_name, $index_def, $params, false );
			}
			if( $index_def['type'] == "unique" )
            {
            	$sql .= ( $diffFriendly ? '' : '  ' ) . eZMssqlSchema::generateAddIndexSql( $tableName, $index_name, $index_def, $params, false );
			}
		}
        
        if ( $asArray )
        {
            if ( $separateTypes )
			{
                $sqlList['tables'][] = $sql;
                if ( $skip_pk )
                    $sqlList['trigger'][] = eZMssqlSchema::generateAddTriggerSql( $tableName, $tableDef );
            }
            else
            {
                $sqlList[] = $sql;
                if ( $skip_pk )
                    $sqlList[] = eZMssqlSchema::generateAddTriggerSql( $tableName, $tableDef );
            }
        }
        else
        {
            $sql .= ";\n";
            
            if ( $mssqlCompatible )
                $sql .= "GO\n";
            if ( $skip_pk )
            {
                $sql .= eZMssqlSchema::generateAddTriggerSql( $tableName, $tableDef );
                if ( $mssqlCompatible )
                    $sql .= "GO\n";
            }
        }

		return $asArray ? $sqlList : $sql;
	}

    /*!
     Detects known options and generates the MySQL SQL code for it.
     \return The SQL code as a string or \c false if not known.
     \param $optionType The type of option, the supported ones are:
                        - delay_key_write - If \a $optionValue is true then adds DELAY_KEY_WRITE=1
    */
    function generateTableOption( $tableName, $tableDef, $optionType, $optionValue, $params )
    {
        switch ( $optionType )
        {
            case 'mssql:delay_key_write';
            {
                if ( $optionValue )
                    return 'DELAY_KEY_WRITE=1';
            } break;
        }
        return false;
    }

    /*!
     * \private
     */
    function generateDropTable( $table, $params )
    {
        return "DROP TABLE $table;\n";
    }

    /*!
     \reimp
     MySQL 3.22.5 and higher support multi-insert queries so if the current
     database has sufficient version we return \c true.
     If no database is connected we return \true.
    */
    function isMultiInsertSupported()
    {
        return false;
    }

    /*!
     \reimp
    */
    function escapeSQLString( $value )
    {
        return eZMSSQLDB::escapeString( $value );
    }

    /*!
     \reimp
    */
    function schemaType()
    {
        return 'mssql';
    }

    /*!
     \reimp
    */
    function schemaName()
    {
        return 'MSSQL';
    }

}
?>
