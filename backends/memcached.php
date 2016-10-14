<?php

if (!class_exists('WP_FFPC_Backend_memcached')):

class WP_FFPC_Backend_memcached extends WP_FFPC_Backend {

	protected function _init() {
		/* Memcached class does not exist, Memcached extension is not available */
		if (!class_exists('Memcached')) {
			$this->log( __translate__(' Memcached extension missing, wp-ffpc will not be able to function correctly!', 'wp-ffpc' ), self::LOG_WARNING );
			return false;
		}

		/* check for existing server list, otherwise we cannot add backends */
		if (empty($this->options['servers'])) {
			$this->log( __translate__('Memcached servers list is empty, init failed', 'wp-ffpc' ), self::LOG_WARNING );
			return false;
		}

		// create client cache object
		$this->connection = new Memcached();
		if (empty($this->connection)) {
			$this->log(__translate__('error initializing Memcached PHP extension, exiting', 'wp-ffpc'));
			return false;
		}

		/* use binary and not compressed format, good for nginx and still fast */
		$this->connection->setOption(Memcached::OPT_COMPRESSION, false);
		if ($this->options['memcached_binary']){
			$this->connection->setOption(Memcached::OPT_BINARY_PROTOCOL, true);
			// handle SASL authentication; only works with binary protocol
			if ( version_compare(phpversion('memcached'), '2.0.0', '>=') && (ini_get('memcached.use_sasl') == 1) &&
					isset($this->options['authuser']) && ("" !== $this->options['authuser']) &&
					isset($this->options['authpass']) && ("" !== $this->options['authpass']) ) {
				$this->connection->setSaslAuthData( $this->options['authuser'], $this->options['authpass']);
			}
		}

		// create array of servers already in cache server pool
		// BUGBUG if we don't have persistant connections or instances, we should not have any servers in the list; is this really needed?
		$serverpool = $this->connection->getServerList();
		if (is_array($serverpool)) {
			foreach ($serverpool as $skey => $server) {
				$poolkey = $server['host'] . ':' . $server['port'];
				$serverpool[$poolkey] = true;
			}
		}
		else {
			$serverpool = array();
		}

		/* add servers not already in the pool */
		foreach ($this->options['servers'] as $server_id => $server) {
			$newkey = $server['host'] . ':' . $server['port'];
			if (!array_key_exists($newkey, $serverpool)) {
				$this->connection->addServer($server['host'], $server['port']);
				$this->log(sprintf(__translate__('%s added', 'wp-ffpc'), $server_id));
			}
		}

		/* backend is now alive */
		$this->alive = true;
		$this->_status();
	}

	/**
	 * sets current backend alive status for Memcached servers
	 * BUGBUG this function does not work currectly when more than one server
	 */
	protected function _status() {
		/* server status will be calculated by getting server stats */
		$this->log(__translate__('checking server statuses', 'wp-ffpc'));
		/* get server list from connection */
		$servers = $this->connection->getServerList();

		foreach ( $servers as $server ) {
			$server_id = $server['host'] . ':' . $server['port'];
			/* reset server status to offline */
			$this->status[$server_id] = 0;
			if ($this->connection->set('wp-ffpc', time())) {
				$this->log(sprintf(__translate__('%s server is up & running', 'wp-ffpc'), $server_id));
				$this->status[$server_id] = 1;
			}
		}
	}

	/**
	 * get function for Memcached backend
	 *
	 * @param string $key Key to get values for
	 *
	*/
	protected function _get(&$key) {
		return $this->connection->get($key);
	}

	/**
	 * Set function for Memcached backend
	 *
	 * @param string $key Key to set with
	 * @param mixed $data Data to set
	 *
	 */
	protected function _set(&$key, &$data, $expire) {
		// convert to consistent expire TTL due to 30 day threshold http://php.net/manual/en/memcached.expiration.php
		if ($expire > 2592000) $expire = time() + $expire;
		$result = $this->connection->set($key, $data , $expire);

		/* if storing failed, log the error code */
		if ($result === false) {
			$code = $this->connection->getResultCode();
			$this->log(sprintf(__translate__('unable to set entry: %s', 'wp-ffpc'), $key));
			$this->log(sprintf(__translate__('Memcached error code: %s', 'wp-ffpc'), $code));
			//throw new Exception ( __translate__('Unable to store Memcached entry ', 'wp-ffpc' ) . $key . __translate__( ', error code: ', 'wp-ffpc' ) . $code );
		}
		return $result;
	}

	/**
	 *
	 * Flush memcached entries
	 */
	protected function _flush() {
		return $this->connection->flush();
	}


	/**
	 * Removes entry from Memcached or flushes Memcached storage
	 *
	 * @param mixed $keys String / array of string of keys to delete entries with
	*/
	protected function _clear(&$keys) {

		/* make an array if only one string is present, easier processing */
		if (!is_array($keys))
			$keys = array($keys => true);

		foreach ($keys as $key => $dummy) {
			$kresult = $this->connection->delete($key);

			if ($kresult === false) {
				$code = $this->connection->getResultCode();
				$this->log(sprintf(__translate__('unable to delete entry: %s', 'wp-ffpc'), $key));
				$this->log(sprintf(__translate__('Memcached error code: %s', 'wp-ffpc'), $code));
			}
			else {
				$this->log(sprintf(__translate__('entry deleted: %s', 'wp-ffpc'), $key));
			}
		}
	}
}

endif;
