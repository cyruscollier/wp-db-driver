<?php

/**
 * Database driver, using the PDO extension.
 *
 * @link http://php.net/manual/en/book.pdo.php
 *
 * @package WordPress
 * @subpackage Database
 * @since 3.6.0
 */
class wpdb_driver_pdo_sqlite extends wpdb_driver {

	/**
	 * Database link
	 * @var PDO
	 */
	private $dbh = null;

	/**
	 * Result set
	 * @var PDOStatement
	 */
	private $result = null;

	/**
	 * Cached column info
	 * @var array|null
	 */
	private $col_info = null;

	/**
	 * Array of fetched rows.
	 * PDO doesn't have a "count rows" feature, so we have to fetch the rows
	 * up front, and cache them here
	 * @var array
	 */
	private $fetched_rows = array();


	public static function get_name() {
		return 'PDO - MySQL';
	}

	public static function is_supported() {
		return extension_loaded( 'pdo_sqlite' );
	}


	/**
	 * Escape with mysql_real_escape_string()
	 *
	 * @see PDO::quote()
	 *
	 * @param  string $string to escape
	 * @return string escaped
	 */
	public function escape( $string ) {
		return substr( $this->dbh->quote( $string ), 1, -1 );
	}

	/**
	 * Get the latest error message from the DB driver
	 *
	 * @return string
	 */
	public function get_error_message() {
		$error = $this->dbh->errorInfo();
		if ( isset( $error[2] ) ) {
			return $error[2];
		}
		return '';
	}

	/**
	 * Free memory associated with the resultset
	 *
	 * @return void
	 */
	public function flush() {
		if ( $this->result instanceof PDOStatement ) {
			$this->result->closeCursor();
		}
		$this->result = null;
		$this->col_info = null;
		$this->fetched_rows = array();
	}

	/**
	 * Check if server is still connected
	 * @return bool
	 */
	public function is_connected() {
		if ( ! $this->dbh || 2006 == $this->dbh->errorCode() ) {
			return false;
		}

		return true;
	}

	/**
	 * Connect to database
	 * @return bool
	 */
	public function connect( $host, $user, $pass, $port = 3306, $options = array() ) {
		//$dsn = 'sqlite:memory';
        $dsn = 'sqlite:' . WP_CONTENT_DIR . '/db.sq3';
		try {
			$pdo_options = array();

			$this->dbh = new PDO( $dsn, null, null, $pdo_options );
			$this->dbh->setAttribute ( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		}
		catch ( Exception $e ) {
			return false;
		}

		return true;
	}

	/**
	 * Closes the current database connection.
	 *
	 * @since 4.5.0
	 * @access public
	 *
	 * @return bool True if the connection was successfully closed, false if it wasn't,
	 *              or the connection doesn't exist.
	 */
	public function close() {
		if ( ! $this->dbh ) {
			return false;
		}

		$this->dbh = null;

		return true;
	}

	/**
	 * Ping a server connection or reconnect if there is no connection
	 * @return bool
	 */
	public function ping() {
		return (bool) $this->query('SELECT 1');
	}

	/**
	 * Sets the connection's character set.
	 *
	 * @param resource $dbh     The resource given by the driver
	 * @param string   $charset Optional. The character set. Default null.
	 * @param string   $collate Optional. The collation. Default null.
	 */
	public function set_charset( $charset = null, $collate = null ) {
		if ( $this->has_cap( 'collation' ) && ! empty( $charset ) ) {
			if ( $this->has_cap( 'set_charset' ) ) {
				$this->dbh->exec( "set names " . $charset );

				return true;
			}
		}

		return false;
	}

	/**
	 * Get the name of the current character set.
	 *
	 * @return string Returns the name of the character set
	 */
	public function connection_charset() {
		$result = $this->dbh->query("SHOW VARIABLES LIKE 'character_set_connection'");
		return $result->fetchColumn(1);
	}

	/**
	 * Select database
	 * @return void
	 */
	public function select( $db ) {
//		try {
//			$this->dbh->exec( sprintf( 'USE `%s`', $db ) );
//		} catch ( Exception $e ) {
//			return false;
//		}

		return true;
	}

	/**
	 * Perform a MySQL database query, using current database connection.
	 * @param string $query Database query
	 * @return int|false Number of rows affected/selected or false on error
	 */
	public function query( $query ) {
		$return_val = 0;

		if ( ! $this->dbh ) {
			return false;
		}

        if ( preg_match( '/^set\s/i', $query ) ) {
            return true;
        }

        $query = str_replace('`', '', $query );
		$this->result = $this->dbh->query( $query );

		if ( preg_match( '/^\s*(create|alter|truncate|drop)\s/i', $query ) ) {
			$return_val = $this->result;
		}
		elseif ( preg_match( '/^\s*(insert|delete|update|replace)\s/i', $query ) ) {
			$return_val = $this->affected_rows();
		}
		elseif ( preg_match( '/^\s*select\s/i', $query ) ) {
			$this->get_results();
			return count( $this->fetched_rows );
		}

		return $return_val;
	}

	/**
	 * Get result data.
	 * @param int The row number from the result that's being retrieved. Row numbers start at 0.
	 * @param int The offset of the field being retrieved.
	 * @return array|false The contents of one cell from a MySQL result set on success, or false on failure.
	 */
	public function query_result( $row, $field = 0 ) {
		if( $row > 1 ) {
			$this->result->fetch( PDO::FETCH_ASSOC,PDO::FETCH_ORI_NEXT, $row );
		}

		return $this->result->fetchColumn( $field );
	}

	/**
	 * Get number of rows affected
	 * @return int
	 */
	public function affected_rows() {
		if ( $this->result instanceof PDOStatement ) {
			return $this->result->rowCount();
		}
		return 0;
	}

	/**
	 * Get last insert id
	 * @return int
	 */
	public function insert_id() {
		return $this->dbh->lastInsertId();
	}

	/**
	 * Get results
	 * @return array
	 */
	public function get_results() {
		if ( !empty( $this->fetched_rows ) ) {
			return $this->fetched_rows;
		}
		$this->fetched_rows = array();

		if ( !empty( $this->result ) && $this->result->rowCount() > 0 ) {
			try {
				while ( $row = $this->result->fetchObject() ) {
					$this->fetched_rows[] = $row;
				}
			} catch ( Exception $e ) {
			}
		}

		return $this->fetched_rows;
	}

	/**
	 * Load the column metadata from the last query.
	 * @return array
	 */
	public function load_col_info() {
		if ( $this->col_info ) {
			return $this->col_info;
		}

		$num_fields = $this->result->columnCount();

		for ( $i = 0; $i < $num_fields; $i++ ) {
			$this->col_info[ $i ] = (object) $this->result->getColumnMeta( $i );
		}

		return $this->col_info;
	}

	/**
	 * Retrieves the MySQL server version.
	 * @return false|string false on failure, version number on success
	 */
	public function db_version() {
		return $this->dbh ? '5.5.3' : false;
	}


	/**
	 * Determine if a database supports a particular feature.
	 */
	public function has_cap( $db_cap ) {
		$db_cap = strtolower( $db_cap );
        $version = $this->dbh->getAttribute( PDO::ATTR_CLIENT_VERSION );
        switch ( strtolower( $db_cap ) ) {
            case 'collation' :
            case 'group_concat' :
            case 'subqueries' :
                return version_compare( $version, '2.0', '>=' );
//            case 'set_charset' :
//            case 'utf8mb4' :      // @since 4.1.0
//                return version_compare( $version, '3.0', '>=' );
        }

		return false;
	}


	/**
	 * Don't save any state.  The db wrapper should call connect() again.
	 * @return array
	 */
	public function __sleep() {
		return array();
	}
}
