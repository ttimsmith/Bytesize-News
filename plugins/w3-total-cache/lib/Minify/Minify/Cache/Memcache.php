<?php
/**
 * Class Minify_Cache_Memcache
 * @package Minify
 */

/**
 * Memcache-based cache class for Minify
 *
 * <code>
 * // fall back to disk caching if memcache can't connect
 * $memcache = new Memcache;
 * if ($memcache->connect('localhost', 11211)) {
 *     Minify::setCache(new Minify_Cache_Memcache($memcache));
 * } else {
 *     Minify::setCache();
 * }
 * </code>
 **/
class Minify_Cache_Memcache {

    /*
     * Blog id
     *
     * @var integer
     */
    private $_blog_id = 0;

    /**
    * Used for faster flushing
    *
    * @var integer $_key_postfix
    */
    private $_key_version = 0;

    /**
     * @var int current wp instance id
     */
    private $_instance_id = 0;

    /**
     * Create a Minify_Cache_Memcache object, to be passed to
     * Minify::setCache().
     *
     * @param Memcache $memcache already-connected instance
     *
     * @param int $expire seconds until expiration (default = 0
     * meaning the item will not get an expiration date)
     *
     * @param int $blog_id
     * @param int $instance_id current wp instance
     */
    public function __construct($memcache, $expire = 0, $blog_id = 0, $instance_id = 0) {
        $this->_mc = $memcache;
        $this->_exp = $expire;
        $this->_blog_id = $blog_id;
        $this->_instance_id = $instance_id;
    }

    /**
     * Write data to cache.
     *
     * @param string $id cache id
     *
     * @param string $data
     *
     * @return bool success
     */
    public function store($id, $data) {
        $v['key_version'] = $this->_get_key_version();
        $v['content'] = "{$_SERVER['REQUEST_TIME']}|{$data}";
        return $this->_mc->set($id . '_' . $this->_blog_id, $v, 0, $this->_exp);
    }


    /**
     * Get the size of a cache entry
     *
     * @param string $id cache id
     *
     * @return int size in bytes
     */
    public function getSize($id) {
        return $this->_fetch($id)
                ? strlen($this->_data)
                : false;
    }

    /**
     * Does a valid cache entry exist?
     *
     * @param string $id cache id
     *
     * @param int $srcMtime mtime of the original source file(s)
     *
     * @return bool exists
     */
    public function isValid($id, $srcMtime) {
        return ($this->_fetch($id) && ($this->_lm >= $srcMtime));
    }

    /**
     * Send the cached content to output
     *
     * @param string $id cache id
     */
    public function display($id) {
        echo $this->_fetch($id)
                ? $this->_data
                : '';
    }

    /**
     * Fetch the cached content
     *
     * @param string $id cache id
     *
     * @return string
     */
    public function fetch($id) {
        return $this->_fetch($id)
                ? $this->_data
                : '';
    }

    private $_mc = null;
    private $_exp = null;

    // cache of most recently fetched id
    private $_lm = null;
    private $_data = null;
    private $_id = null;

    /**
     * Fetch data and timestamp from memcache, store in instance
     *
     * @param string $id
     *
     * @return bool success
     */
    private function _fetch($id) {
        if ($this->_id === $id) {
            return true;
        }
        $v = $this->_mc->get($id . '_' . $this->_blog_id);

        if (!is_array($v)) {
            $this->_id = null;
            return false;
        }

        $key_version = $this->_get_key_version();
        if ($v['key_version'] == $key_version){
            list($this->_lm, $this->_data) = explode('|', $v['content'], 2);
            $this->_id = $id;
            return true;
        }

        if ($v['key_version'] > $key_version) {
            $this->_set_key_version($v['key_version']);
            list($this->_lm, $this->_data) = explode('|', $v['content'], 2);
            $this->_id = $id;
            return true;
        }

        // if we have expired data - update it for future use and let
        // current process recalculate it
        $expires_at = isset($v['expires_at']) ? $v['expires_at'] : null;
        if ($expires_at == null || time() > $expires_at) {
            $v['expires_at'] = time() + 30;
            $this->_mc->set($id . '_' . $this->_blog_id, $v, false, 0);
            $this->_id = null;
            return false;
        }
    }

    /**
     * Flushes all data
     *
     * @return boolean
     */
    function flush() {
        $this->_get_key_version();   // initialize $this->_key_postfix
        $this->_key_version++;
        $this->_set_key_version($this->_key_version);

        return true;
    }

    /**
     * Returns key version
     *
     * @return integer
     */
    private function _get_key_version() {
        if ($this->_key_version <= 0) {
            $v = @$this->_mc->get($this->_get_key_version_key());
            $v = intval($v);
            $this->_key_version = ($v > 0 ? $v : 1);
        }

        return $this->_key_version;
    }

    /**
     * Sets new key version
     * @param $v
     * @return boolean
     */
    private function _set_key_version($v) {
        @$this->_mc->set($this->_get_key_version_key(), $v, false, 0);
    }

    /**
     * Constructs key version key
     * @return string
     */
    private function _get_key_version_key() {
        return sprintf('w3tc_%d_%s_%d_key_version', $this->_blog_id, 'minify', $this->_instance_id);
    }
}
