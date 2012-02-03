<?php
require_once dirname(__FILE__) . '/Resque/Event.php';
require_once dirname(__FILE__) . '/Resque/Exception.php';

/**
 * Base Resque class.
 *
 * @package		Resque
 * @author		Chris Boulton <chris.boulton@interspire.com>
 * @copyright	(c) 2010 Chris Boulton
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class Resque
{
	const VERSION = '1.0';

	/**
	 * @var Resque_Redis Instance of Resque_Redis that talks to redis.
	 */
	public static $redis = null;

	/**
	 * @var redis backend server address
	 */
	public static $server = null;

	/**
	 * @var integer Db on which the Redis backend server is selected
	 */
	public static $database = null;

	/**
	 * @var bool use phpredis extension or fsockopen to connect to the redis server
	 */
	public static $phpredis = null;

	/**
	 * Given a host/port combination separated by a colon, set it as
	 * the redis server that Resque will talk to.
	 *
	 * @param mixed $server Host/port combination separated by a colon, or
	 * a nested array of servers with host/port pairs.
	 * @param integer $database the db to be selected
	 * @param bool $phpredis use phpredis extension or fsockopen to connect to the server
	 */
	public static function setBackend($server, $database = 0, $phpredis = true)
	{
	  //save the params for later use
	  self::$server = $server;
	  self::$database = $database;
	  self::$phpredis = $phpredis;

		if(is_array($server)) {
			require_once dirname(__FILE__) . '/Resque/RedisCluster.php';
			self::$redis = new Resque_RedisCluster($server, $database, $phpredis);
		}
		else {
			list($host, $port) = explode(':', $server);
			require_once dirname(__FILE__) . '/Resque/Redis.php';
			self::$redis = new Resque_Redis($host, $port, $database, $phpredis);
		}
    return self::$redis;
	}

	/**
	* Reconnect to the redis backend specified in during __construct
	* @return Resque_Redis Instance of Resque_Redis. (redis ressource handle)
	*/
	public static function ResetBackend()
	{
	  if(is_array(self::$server)) {
	    self::$redis = new Resque_RedisCluster(self::$server, self::database, self::phpredis);
	  }
	  else {
	    list($host, $port) = explode(':', self::$server);
	    self::$redis = new Resque_Redis($host, $port, self::$database, self::$phpredis);
	  }
	  return self::$redis;
	}

	/**
	 * Return an instance of the Resque_Redis class instantiated for Resque.
	 *
	 * @return Resque_Redis Instance of Resque_Redis.
	 */
	public static function redis()
	{
	  //try to reset the backend specified during __construct
		if(is_null(self::$redis) && is_null(self::ResetBackend())) {
			self::setBackend('localhost:6379', 0, true);
		}

		return self::$redis;
	}

	/**
	 * Push a job to the end of a specific queue. If the queue does not
	 * exist, then create it as well.
	 *
	 * @param string $queue The name of the queue to add the job to.
	 * @param array $item Job description as an array to be JSON encoded.
	 */
	public static function push($queue, $item)
	{
		self::redis()->sadd('queues', $queue);
		self::redis()->rpush('queue:' . $queue, json_encode($item));
	}

	/**
	 * Pop an item off the end of the specified queue, decode it and
	 * return it.
	 *
	 * @param string $queue The name of the queue to fetch an item from.
	 * @return array Decoded item from the queue.
	 */
	public static function pop($queue)
	{
		$item = self::redis()->lpop('queue:' . $queue);
		if(!$item) {
			return;
		}

		return json_decode($item, true);
	}

	/**
	 * Return the size (number of pending jobs) of the specified queue.
	 *
	 * @return int The size of the queue.
	 */
	public static function size($queue)
	{
		return self::redis()->llen('queue:' . $queue);
	}

	/**
	 * Create a new job and save it to the specified queue.
	 *
	 * @param string $queue The name of the queue to place the job in.
	 * @param string $class The name of the class that contains the code to execute the job.
	 * @param array $args Any optional arguments that should be passed when the job is executed.
	 * @param boolean $monitor Set to true to be able to monitor the status of a job.
	 */
	public static function enqueue($queue, $class, $args = null, $trackStatus = false)
	{
		require_once dirname(__FILE__) . '/Resque/Job.php';
		$result = Resque_Job::create($queue, $class, $args, $trackStatus);
		if ($result) {
			Resque_Event::trigger('afterEnqueue', array(
				'class' => $class,
				'args' => $args,
			));
		}

		return $result;
	}

	/**
	 * Reserve and return the next available job in the specified queue.
	 *
	 * @param string $queue Queue to fetch next available job from.
	 * @return Resque_Job Instance of Resque_Job to be processed, false if none or error.
	 */
	public static function reserve($queue)
	{
		require_once dirname(__FILE__) . '/Resque/Job.php';
		return Resque_Job::reserve($queue);
	}

	/**
	 * Get an array of all known queues.
	 *
	 * @return array Array of queues.
	 */
	public static function queues()
	{
		$queues = self::redis()->smembers('queues');
		if(!is_array($queues)) {
			$queues = array();
		}
		return $queues;
	}
}
