<?php
abstract class AccountDao {

	const SEQUENCE = '_shard_sequence';

	public $var = array();

	protected $fromdb = false;

	private $shardId = -1;

	private $serverAddress = '';

	/**
	 * 
	 * Enter description here ...
	 * @param unknown_type $id
	 */
	public final function __construct( $id=0 ) {
		global $dbconfig;
		$idColumn = $this->getIdColumnName();
		$domain = $this->getShardDomain();

		if ($id!=0) {
			global $dbconfig;
			$this->var[$idColumn] = $id;
			$shards_digit = $dbconfig[$domain]['shards_digit'];
			$this->shardId = $id%$shards_digit;
			$this->serverAddress = $this->getConfigServerAddress($domain, $this->shardId);

			$this->fromdb = $this->retrive();
		} else {
			$this->init();

			if ($this->isShardBaseObject()) {
				$this->setShardId();
			}
		}

		return $this;
	}

	/**
	 * 
	 * Saves the object to database, if primary key value already exists do update, if not do insert.
	 */
	public function save() {
		if ( $this->fromdb ) {
			$this->beforeUpdate();
			return $this->update();
		} else {
			$this->beforeInsert();
			$res = $this->insert();
			if ($res) {
				$this->fromdb = true;
			}

			return $res;
		}
	}

	/**
	 * 
	 * Retrive an object from database based on id
	 * @param $id - the database primary key id
	 */
	protected function retrive() {
		$idColumn = $this->getIdColumnName();

		$query = new QueryBuilder($this);
		$res = $query->select('*', $this->getTableName())
					 ->where($this->getIdColumnName(), $this->var[$idColumn])
					 ->find();

		$atReturn = false;
		if (isset($res) && $res) {
			$this->var = $res;
			$atReturn = true;
		} else {
			$id = $this->var[$idColumn];
			$this->init();
			$this->var[$idColumn] = $id;
		}

		return $atReturn;
	}

	/**
	 * 
	 * Insert an object to database
	 */
	private function insert()
	{
		// primary key id columnd name from abstract implementation of sub class
		//
		$idColumn = $this->getIdColumnName();

		$query = new QueryBuilder($this);
		$res = $query->insert($this->var, $this->getTableName())
					 ->query();

		if ($res==-1) { Logger::error($sql); }

		return $res!=-1;
	}

	/**
	 * 
	 * update the database row of the object
	 */
	private function update() {
		// primary key id columnd name from abstract implementation of sub class
		//
		$idColumn = $this->getIdColumnName();

		$set = $this->var;
		unset($set[$idColumn]);

		$builder = new QueryBuilder($this);
		$result = $builder->update($set, $this->getTableName())
						  ->where($idColumn, $this->var[$idColumn])
						  ->query();

		return $result;
	}

	public function setShardId($shardSequence=0) {
		global $dbconfig;

		$domain = $this->getShardDomain();

		if ($shardSequence==0) {
			$shardSequence = $this->getNextShardSequence();
		}

		$this->shardId = $shardSequence%$dbconfig[$domain]['total_shards'];

		$this->serverAddress = $this->getConfigServerAddress($domain, $this->shardId);

		// get the next sequence number for new object
		$nextObjectSequence = $this->getNextObjectSequence();

		$shards_digit = $dbconfig[$domain]['shards_digit'];

		// set the id for the new object
		$this->var[$this->getIdColumnName()] = ($nextObjectSequence*$shards_digit)+$this->shardId;
	}

	public function setServerAddress($shardSequence) {
		global $dbconfig;
		$domain = $this->getShardDomain();
		$this->shardId = $shardSequence%$dbconfig[$domain]['total_shards'];
		$this->serverAddress = $this->getConfigServerAddress($domain, $this->shardId);
	}

	public function getShardedDatabaseName() {
		return $this->getShardDomain().'_'.$this->shardId;
	}

	public function getServerAddress() {
		return $this->serverAddress;
	}

	public function isFromDatabase() {
		return $this->fromdb;
	}

    public static function makeObjectFromSelectResult($res, $class) {
		$object = null;
		if ($res) {
			$object = new $class;
			$object->fromdb = true;
			$object->var = $res;
		}

		return $object;
	}

	public static function makeObjectsFromSelectListResult($rows, $class) {
		$objects = array();
		if (isset($rows)) {
			foreach ($rows as $row) {
				$object = new $class;
				$object->fromdb = true;
				$object->var = $row;
				array_push($objects, $object);
			}
		}

		return $objects;
	}

	private function getNextObjectSequence() {
		$dbName = $this->getShardedDatabaseName();
		$tableName = $this->getTableName();

		$sequenceKey = $dbName.'.'.$tableName.ContentDao::SEQUENCE;

		$mem = CacheUtil::getInstance();

		$sequence = $mem->increment($sequenceKey);

		if ($sequence==FALSE) {
			$sequence = $this->getCurrentSequence();
			$mem->set($sequenceKey, $sequence);
		}

		return $sequence+1;
	}

	private function getNextShardSequence() {
		$sequenceKey = $this->getShardDomain().ContentDao::SEQUENCE;

		$mem = CacheUtil::getInstance();

		$sequence = $mem->increment($sequenceKey);

		if ($sequence==FALSE) {
			$sequence = 0;
			$mem->set($sequenceKey, $sequence);
		}

		return $sequence;
	}

	private function getConfigServerAddress($domain, $shardId) {
		global $dbconfig;
		$server_list = $dbconfig[$domain]['server_list'];
		foreach ($server_list as $key=>$val) {
			if ($shardId>=$val['min'] && $shardId<=$val['max']) {
				return $key;
			}
		}

		return null;
	}

    private function getCurrentSequence() {
    	global $dbconfig;

		$idColumn = $this->getIdColumnName();
		$table = $this->getTableName();

		$query = new QueryBuilder($this);
		$result = $query->select("MAX($idColumn) AS max", $table)->find();

		$shards_digit = $dbconfig[$this->getShardDomain()]['shards_digit'];

		$sequence = ceil($result['max']/$shards_digit);

		return $sequence;
    }

//========================================================= override functions =============================================================

    protected function beforeUpdate() {}

    protected function beforeInsert() {}

//========================================================= abstract functions =============================================================

	abstract public function getShardDomain();

	abstract protected function init();

	abstract protected function getTableName();

	abstract protected function getIdColumnName(); 

	abstract protected function isShardBaseObject();
}
?>