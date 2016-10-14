<?php

if (!class_exists('WP_FFPC_Backend_memcache')):

class WP_FFPC_Backend_memcache extends WP_FFPC_Backend {
	/**
	 * init memcache backend
	 */
	protected function _init() {
		/* Memcache class does not exist, Memcache extension is not available */
		if (!class_exists('Memcache')) {
			$this->log(__translate__('PHP Memcache extension missing', 'wp-ffpc'), self::LOG_WARNING);
			return false;
		}

		/* check for existing server list, otherwise we cannot add backends */
		if (empty($this->options['servers'])) {
			$this->log(__translate__('servers list is empty, init failed', 'wp-ffpc'), self::LOG_WARNING);
			return false;
		}

		// create client cache object
		$this->connection = new Memcache();
		if (empty($this->connection)) {
			$this->log(__translate__('error initializing Memcache PHP extension, exiting', 'wp-ffpc'));
			return false;
		}

		/* adding servers */
		// BUGBUG multiple connect() calls does not create a pool and instead replaces any previously setup connection; the below is very problematic
		foreach ($this->options['servers'] as $server_id => $server) {
			$this->status[$server_id] = false;
			if (0 === $server['port']) /* in case of unix socket */
				$this->status[$server_id] = @$this->connection->connect('unix://' . $server['host'], 0);
			else
				$this->status[$server_id] = @$this->connection->connect($server['host'], $server['port']);
			if (0 == $this->status[$server_id])
				$this->log(sprintf(__translate__('%s server is down', 'wp-ffpc'), $server_id));
			else
				$this->log(sprintf(__translate__('%s added', 'wp-ffpc'), $server_id));
		}

		/* backend is now alive */
		$this->alive = true;
	}

	/**
	 * check current backend alive status for Memcache
	 * BUGBUG this status check is not effective in combination with the above init bugs; the status is not updated with the below methods, see http://php.net/manual/en/memcache.getserverstatus.php
	 */
	protected function _status() {
		/* get servers statistic from connection */
		foreach ($this->options['servers'] as $server_id => $server) {
			if (0 === $server['port']) /* in case of unix socket */
				$this->status[$server_id] = $this->connection->getServerStatus('unix://' . $server['host'], 0);
			else
				$this->status[$server_id] = $this->connection->getServerStatus($server['host'], $server['port']);
			if (0 == $this->status[$server_id])
				$this->log(sprintf(__translate__('%s server is down', 'wp-ffpc'), $server_id));
			else
				$this->log(sprintf(__translate__('%s server is up & running', 'wp-ffpc'), $server_id));
		}
	}

	/**
	 * get function for Memcache backend
	 *
	 * @param string $key Key to get values for
	 *
	*/
	protected function _get(&$key) {
		return $this->connection->get($key);
	}

	/**
	 * Set function for Memcache backend
	 *
	 * @param string $key Key to set with
	 * @param mixed $data Data to set
	 *
	 */
	protected function _set(&$key, &$data, $expire) {
		// convert to consistent expire TTL due to 30 day threshold http://php.net/manual/en/memcache.set.php
		if ($expire > 2592000) $expire = time() + $expire;
		$result = $this->connection->set($key, $data, 0, $expire);
		return $result;
	}

	/**
	 *
	 * Flush memcache entries
	 */
	protected function _flush() {
		return $this->connection->flush();
	}

	/**
	 * Removes entry from Memcache or flushes Memcache storage
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
				$this->log(sprintf(__translate__('unable to delete entry: %s', 'wp-ffpc'), $key));
			}
			else {
				$this->log(sprintf(__translate__('entry deleted: %s', 'wp-ffpc'), $key));
			}
		}
	}
}

endif;
