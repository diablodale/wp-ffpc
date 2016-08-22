<?php

if (!class_exists('WP_FFPC_Backend_redis')):

class WP_FFPC_Backend_redis extends WP_FFPC_Backend {

	protected function _init () {
		/* Memcached class does not exist, Memcached extension is not available */
		if (!class_exists('Redis')) {
			$this->log (  __translate__('Redis extension missing, wp-ffpc will not be able to function correctly!', $this->plugin_constant ), LOG_WARNING );
			return false;
		}

		/* check for existing server list, otherwise we cannot add backends */
		if ( empty ( $this->options['servers'] ) && ! $this->alive ) {
			$this->log (  __translate__("Redis servers list is empty, init failed", $this->plugin_constant ), LOG_WARNING );
			return false;
		}

		/* check is there's no backend connection yet */
		if ( $this->connection === NULL ) {
			$this->connection = new Redis();
		}

		/* check if initialization was success or not */
		if ( $this->connection === NULL ) {
			$this->log (  __translate__( 'error initializing Redis PHP extension, exiting', $this->plugin_constant ) );
			return false;
		}

		/* check if we already have list of servers, only add server(s) if it's not already connected *
		$servers_alive = array();
		if ( !empty ( $this->status ) ) {
			$servers_alive = $this->connection->getServerList();
			/* create check array if backend servers are already connected *
			if ( !empty ( $servers ) ) {
				foreach ( $servers_alive as $skey => $server ) {
					$skey =  $server['host'] . ":" . $server['port'];
					$servers_alive[ $skey ] = true;
				}
			}
		}

		/* adding servers */
		foreach ( $this->options['servers'] as $server_id => $server ) {
			/* only add servers that does not exists already  in connection pool */
			if ( !@array_key_exists($server_id , $servers_alive ) ) {

				if ( $server['port'] != 0)
					$this->connection->connect($server['host'], $server['port'] );
				else
					$this->connection->connect($server['host'] );

				if ( isset($this->options['authpass']) && !empty($this->options['authpass']) )
					$this->connection->auth ( $this->options['authpass'] );

				$this->log ( sprintf( __translate__( '%s added', $this->plugin_constant ),  $server_id ) );
			}
		}

		/* backend is now alive */
		$this->alive = true;
		$this->_status();
	}

	/**
	 * sets current backend alive status for Memcached servers
	 *
	 */
	protected function _status () {
		/* server status will be calculated by getting server stats */

		$this->log (  __translate__("checking server status", $this->plugin_constant ));

		try {
			$info = $this->connection->ping();
		} catch ( Exception $e ) {
			$this->log (  __translate__("Exception occured: " . json_encode($e), $this->plugin_constant ));
		}

		$status = empty( $info ) ? 0 : 1;

		foreach ( $this->options['servers'] as $server_id => $server ) {
			/* reset server status to offline */
			$this->status[$server_id] = $status;
		}

	}

	/**
	 * get function for Memcached backend
	 *
	 * @param string $key Key to get values for
	 *
	*/
	protected function _get ( &$key ) {
		return $this->connection->get($key);
	}

	/**
	 * Set function for Memcached backend
	 *
	 * @param string $key Key to set with
	 * @param mixed $data Data to set
	 *
	 */
	protected function _set ( &$key, &$data, &$expire ) {
		$result = $this->connection->set ( $key, $data, $expire );

		/* if storing failed, log the error code */
		//if ( $result === false ) {
			$this->log ( sprintf( __translate__( 'set entry returned: %s', $this->plugin_constant ),  $result ) );
		//}

		return $result;
	}

	/**
	 *
	 * Flush memcached entries
	 */
	protected function _flush ( ) {
		try {
			$r = $this->connection->flushDB();
		} catch ( Exception $e ) {
			$this->log ( sprintf( __translate__( 'unable to flush, error: %s', $this->plugin_constant ),  json_encode($r) ) );
		}
		return $r;
	}


	/**
	 * Removes entry from Memcached or flushes Memcached storage
	 *
	 * @param mixed $keys String / array of string of keys to delete entries with
	*/
	protected function _clear ( &$keys ) {

		/* make an array if only one string is present, easier processing */
		if ( !is_array ( $keys ) )
			$keys = array ( $keys => true );

		try {
			$kresult = $this->connection->delete( $keys );
		} catch ( Exception $e ) {
			$this->log ( sprintf( __translate__( 'unable to delete entry(s): %s', $this->plugin_constant ),  json_encode($key) ) );
			$this->log ( sprintf( __translate__( 'Redis error: %s', $this->plugin_constant ),  json_encode($e) ) );
		}
		$this->log ( sprintf( __translate__( 'entry(s) deleted: %s', $this->plugin_constant ),  json_encode($keys) ) );
	}
}

endif;
