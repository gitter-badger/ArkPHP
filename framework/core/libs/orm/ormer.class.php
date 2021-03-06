<?php

class Ormer {



	/**
	 * The name of the current model
	 * @var string
	 */
	public $class;

	/**
	 * @var \PDO
	 */
	public $connector;

	/**
	 * The name of the current table
	 * @var string
	 */
	public $tableName = "";

	private
	$_now_query_sql = "",
	$_idSelector = "id",
	$_isNew = false,
	$_rawQuery = null,
	$_distinct = false,
	$_resultSelector = array("*"),
	$_data = array(),
	$_dirty = array(),
	$_values = array(),
	$_join = array(),
	$_joinTables = array(),
	$_where = array(),
	$_limit = null,
	$_limitOffset = false,
	$_offset = null,
	$_order = null,
	$_orderBy = array(),
	$_groupBy = array();

	public static
	$log = array();

	/**
	 * Set the connector to database. Define the table name. Define the id column
	 */
	function __construct(){
		$this->connector = OrmConnector::getInstance();

		$this->parseTableName();
		$this->setIdName('id_'.trim($this->tableName, OrmConnector::$quoteSeparator));


	}

	/**
	 * if tableName Exist in the db.
	 * @return int
	 * by Ark 2015.3.19
	 */
	private function isExistTable($tableName){
		$tableName=trim($tableName, OrmConnector::$quoteSeparator);
		$sql ='SHOW TABLES LIKE "'.$tableName.'"';
		$rs = $this->connector->query($sql);

		return $rs->rowCount();

	}
	/**
	 * Get the current model name and parse to table name
	 * @return void
	 */
	private function parseTableName(){

		$this->class = get_called_class();
		$this->tableName = $this->setQuotes(strtolower(preg_replace('/(?!^)[[:upper:]]/', '_\0', $this->class)));

		//if($this->isExistTable($this->tableName)!=1){
		//	echo 'Error:table:'.$this->tableName. ' not exsit.';
		//	return ;
		//}


	}


	/**
	 * Lauch the current query for selection
	 * @param bool $noResult
	 * @return array/false
	 */
	public function run($noResult = false){
		if(!$this->connector){
			echo "fail";
			return false;
		}

		if(is_null($this->_rawQuery)){
			//			if($query==""){
			$query = $this->buildSelect();
			//			$this->_now_query_sql = $query;
			//			}else{
			//			$query = $this->_now_query_sql;
			//			}

		}else{

			$query = $this->_rawQuery;
		}
		self::$log[] = $query;
		//echo $query;
		try{
			$preparedQuery = $this->connector->prepare($query);
			$preparedQuery->execute($this->_values);
		}catch(Exception $e){
			echo $e->getMessage();
			self::logError($e, $query);
			return false;
		}

		if(!$noResult){
			$rows = array();
			while($row = $preparedQuery->fetch(PDO::FETCH_ASSOC)){
				$rows[] = $row;
			}

			return $rows;
		}else{
			return $preparedQuery;
		}
	}

	/**
	 * Create the query used by the run method
	 * @return string
	 */
	private function buildSelect(){
		return $this->joinIfNotEmpty(array(
		$this->buildSelectStart(),
		$this->buildJoin(),
		$this->buildWhere(),
		$this->buildGroupBy(),
		$this->buildOrderBy(),
		$this->buildLimit(),
		$this->buildOffset(),
		));
	}

	/**
	 * Create the query begining
	 * @return string
	 */
	private function buildSelectStart(){
		$resultColumns = join(', ', $this->_resultSelector);

		$table = $this->tableName;
		$resultColumns = $resultColumns != "*" ? $this->setQuotes($resultColumns) : $resultColumns;
		$joinTables = '';

		$resultColumns = $this->_distinct ?
            "DISTINCT $table.$resultColumns":
            "$table.$resultColumns";


		if(count($this->_join)){
			foreach($this->_joinTables as $joinTable){
				$joinTables .= ", $joinTable->tableName.*";
			}
		}

		$fragment = "SELECT $resultColumns $joinTables FROM $table";

		return $fragment;
	}

	/**
	 * Create the join query
	 * @return string
	 */
	private function buildJoin(){
		if(!count($this->_join)){
			return '';
		}

		return join(" ", $this->_join);
	}

	/**
	 * Create the where query
	 * @return string
	 */
	private function buildWhere(){
		if(!count($this->_where)){
			return '';
		}

		$return = array();

		foreach($this->_where as $where){
			if(count($return) == 0){
				$return[] = $where[1][0];
			}else{
				$return[] = $where[0]." ".$where[1][0];
			}

			$this->_values = array_merge($this->_values, $where[1][1]);

		}

		return "WHERE ".join(" ", $return);
	}

	/**
	 * Create the Group By query
	 * @return string
	 */
	private function buildGroupBy(){
		if(!count($this->_groupBy)){
			return '';
		}

		return "GROUP BY ".join(",", $this->_groupBy);
	}

	/**
	 * Create the Order By query
	 * @return string
	 */
	private function buildOrderBy(){
		if(!count($this->_orderBy) || is_null($this->_order)){
			return '';
		}

		return "ORDER BY ".join(",", $this->_orderBy).' '.$this->_order;
	}

	/**
	 * Create the Limit query
	 * @return string
	 */
	private function buildLimit(){
		if(is_null($this->_limit)){
			return '';
		}

		return "LIMIT ".($this->_limitOffset ? $this->_limitOffset."," : "")." ".$this->_limit;
	}

	/**
	 * Create the Offset query
	 * @return string
	 */
	private function buildOffset(){
		if(is_null($this->_offset)){
			return '';
		}

		return "OFFSET ".$this->_offset;
	}

	/**
	 * Create the Insert query
	 * @return string
	 */
	private function buildInsert(){
		$listFields = array_map(array($this, "setQuotes"), array_keys($this->_data));
		$values = $this->createPlaceholder($this->_data);

		$table = $this->tableName;
		$listFields = join(", ", $listFields);

		$query = "INSERT INTO $table ($listFields) VALUES ($values)";

		return $query;
	}

	/**
	 * Create the Update query
	 * @return string
	 */
	private function buildUpdate(){
		$listFields = array();

		foreach($this->_dirty as $field => $value){
			$listFields[] = $this->setQuotes($field)." = ?";
		}

		$table = $this->tableName;
		$join = $this->buildJoin();
		$listFields = join(", ", $listFields);
		$id = $this->setQuotes($this->_idSelector);

		$query = "UPDATE $table $join SET $listFields WHERE $table.$id = ?";

		return $query;
	}

	/**
	 * Create the Delete query
	 * @return string
	 */
	private function buildDelete(){
		$table = $this->tableName;
		$id = $this->setQuotes($this->_idSelector);

		$join = $this->buildJoin();

		$deleteSelector = $table.'.*';

		if(count($this->_joinTables)){
			foreach($this->_joinTables as $joinTable){
				$deleteSelector .=  ", $joinTable->tableName.*";
			}
		}

		$query = "DELETE $deleteSelector FROM $table $join WHERE $table.$id = ?";

		return $query;
	}

	/**
	 * Hydrate the current model with send data
	 * @param   array $data array of data
	 * @return  OrmWrapper
	 */
	private function hydrate($data = array()){
		$this->_data = $data;
		$this->_dirty = $data;

		return $this;
	}

	/**
	 * Join different element from array to a string
	 * @param   array $joinArray array of data
	 * @return  string
	 */
	private function joinIfNotEmpty($joinArray){
		$returnArray = null;

		foreach($joinArray as $select){
			if(!empty($select)){
				$returnArray[] = trim($select);
			}
		}

		return join(" ", $returnArray);
	}

	/**
	 * Add specific db quote to sent fragment
	 * @param   string $fragment string to be quoted
	 * @return  string
	 */
	private function setQuotes($fragment){
		$parts = explode('.', $fragment);

		foreach($parts as &$part){
			//////////////////////////////兼容性
			if(($part[0]==OrmConnector::$quoteSeparator) && ($part[strlen($part)-1]==OrmConnector::$quoteSeparator)){

				continue;

			}
			elseif (strstr($part,'SUM(')){
				//echo 'SUM matching';
				continue;
			}
			else{

				$part = OrmConnector::$quoteSeparator . $part . OrmConnector::$quoteSeparator;
			}
		}

		return join('.', $parts);
	}

	/**
	 * Create instance from current model with specified row
	 * @param   array $data  data to bound to new instance
	 * @return  OrmWrapper
	 */
	private function createInstance($data){
		$instance = clone $this;
		$instance->hydrate($data);
		return $instance;
	}

	/**
	 * Create placeholder for data used in query
	 * @param   array $dataArray
	 * @return  string
	 */
	private function createPlaceholder($dataArray){
		$number = count($dataArray);

		return join(",", array_fill(0, $number, "?"));
	}

	/**
	 * Get id of current model
	 * @return  int
	 */
	public function getId(){
		return $this->__get($this->_idSelector);
	}

	/**
	 * Set id of current model
	 * @param mixed $id
	 */
	public function setId($id){
		$this->__set($this->_idSelector, $id);
		$this->_isNew = true;
	}

	/**
	 * Set id column name for this model
	 * @param   String $name    id column name
	 * @return  OrmWrapper
	 */
	public function setIdName($name){
		$this->_idSelector = $name;

		return $this;
	}

	/**
	 * Get the current table id name
	 * @return  string
	 */
	public function getIdName(){
		return $this->_idSelector;
	}

	/**
	 * Set the selected column in request
	 * @param   string/array $rows
	 * @return  OrmWrapper
	 */
	public function select($rows){
		if(is_array($rows)){
			$rows = array_map(array($this, 'setQuotes'), $rows);
		}else{
			$rows = array($this->setQuotes($rows));
		}

		$this->_resultSelector = $rows;
		return $this;
	}

	/**
	 * Create a where condition
	 * @param   string $column          the column to be compared
	 * @param   string $statement       type of comparison
	 * @param   mixed  $value           value of comparison
	 * @param   boolean|string  $type   type of where
	 * @return  OrmWrapper
	 */
	public function where($column, $statement, $value, $type = false){
		if(!is_array($value)){
			$value = array($value);
		}

		if($type == false){
			$type = "AND";
		}

		$column = $this->setQuotes($column);

		if($statement=="IN"|| $statement=="NOT IN"){
				
			$sql_part=" $column $statement (?";
			$count =count($value)-1;
			while($count--){
				$sql_part.=",?";
			}
			$sql_part.=") ";

			$this->_where[] = array($type, array($sql_part, $value));
		}else{
			$this->_where[] = array($type, array(" $column $statement ? ", $value));
		}

		return $this;
	}

	/**
	 * Helper for where to make AND condition
	 * @param   string $column          the column to be compared
	 * @param   string $statement       type of comparison
	 * @param   mixed  $value           value of comparison
	 * @return OrmWrapper
	 */
	public function andWhere($column, $statement, $value){
		return $this->where($column, $statement, $value, 'AND');
	}

	/**
	 * Helper for where to make OR condition
	 * @param   string $column          the column to be compared
	 * @param   string $statement       type of comparison
	 * @param   mixed  $value           value of comparison
	 * @return OrmWrapper
	 */
	public function orWhere($column, $statement, $value){
		return $this->where($column, $statement, $value, 'OR');
	}

	/**
	 * Create a join query
	 * @param   string $type                type of join
	 * @param   \OrmWrapper $table          table to be join
	 * @param   array/string $condition     condition of the join
	 * @return  OrmWrapper
	 */
	public function join($type, OrmWrapper $table, $conditions = null){
		$type = trim(strtoupper($type)." JOIN");

		if(null === $conditions){
			$conditions = $this->tableName.'.'.$this->setQuotes($table->getIdName()).' = '.$table->tableName.'.'.$this->setQuotes($table->getIdName());
		}

		$tableName = $table->tableName;

		$this->_joinTables[] = $table;
		$this->_join[] = "$type $tableName ON ".$this->listJoinCondition($conditions);

		return $this;
	}

	/**
	 * @param   array/string $conditions
	 * @return  string
	 */
	private function listJoinCondition($conditions){
		if(is_array($conditions)){
			$returnedConditions = "";

			foreach($conditions as $key => $value){
				$key = is_int($key) ? "" : $key." ";
				$value = is_array($value) ? "(".$this->listJoinCondition($value).")" : $this->makeJoinCondition($value);

				$returnedConditions[] = "$key$value";
			}

			return join(" ", $returnedConditions);
		}

		return $conditions;
	}

	/**
	 * @param   array/string  $conditions
	 * @return  string
	 */
	private function makeJoinCondition($condition){
		if(is_array($condition)){

			$joinTable = array_pop($this->_joinTables);
			list($firstCol, $statement, $lastCol) = $condition;
			$firstCol = $this->setQuotes($this->tableName).".".$this->setQuotes($firstCol);
			$lastCol = $this->setQuotes($joinTable->tableName).".".$this->setQuotes($lastCol);

			return "$firstCol $statement $lastCol";
		}

		return $condition;
	}

	/**
	 * Set a limit to the query
	 * @param   int $offset
	 * @param   bool|int $line
	 * @return  \OrmWrapper
	 */
	public function limit($offset, $line = false){
		if(!$line){
			$this->_limit = (int)$offset;
		}else{
			$this->_limitOffset = (int)$offset;
			$this->_limit = (int)$line;
		}

		return $this;
	}

	/**
	 * Set a offset to the query
	 * @param   int $offset
	 * @return  \OrmWrapper
	 */
	public function offset($offset){
		$this->_offset = (int)$offset;
		return $this;
	}

	/**
	 * Set a distinct keyword to the query
	 * @return  \OrmWrapper
	 */
	public function distinct(){
		$this->_distinct = true;
		return $this;
	}

	/**
	 * Set the group for the query
	 * @param   string/array $rows
	 * @return  \OrmWrapper
	 */
	public function group($rows){
		if(is_array($rows)){
			foreach($rows as &$row){
				$row = $this->setQuotes($row);
			}
		}else{
			$rows = array($this->setQuotes($rows));
		}

		$this->_groupBy = $rows;
		return $this;
	}

	/**
	 * Set the rows used for order and direction
	 * @param   string/array $rows
	 * @param   string $direction
	 * @return  \OrmWrapper
	 */
	public function order($rows, $direction = 'ASC'){
		 
		if(is_array($rows)){
			foreach($rows as &$row){
				//$row = $this->setQuotes($row);
			}
		}else{
			//$rows = array($this->setQuotes($rows));
		}
		$this->_orderBy = is_array($rows) ? $rows : array($rows);
		$this->_order = (in_array($direction, array('ASC', 'DESC'))) ? $direction : null;
		return $this;
	}

	/**
	 * Set the rows used for order and direction
	 * @param   string/array $rows
	 * @param   string $direction
	 * @return  \OrmWrapper
	 * By Ark 15.10.28
 	 */
	public function custom_order($rows,$values, $direction = 'ASC'){
		$protocol_condition='';
		foreach ($values as $protocol){
			$protocol_condition .= (',\''.$protocol.'\'');
		}
		
		$order_condition = 'FIELD(`'.$rows.'`'.$protocol_condition.')';
		
		$rows = $order_condition;
		
		$this->_orderBy = is_array ( $rows ) ? $rows : array (
				$rows
		);
		
		$this->_order = (in_array ( $direction, array (
				'ASC',
				'DESC'
		) )) ? $direction : null;
		return $this;
	}
	/**
	 * Create a new model
	 * @param   array $data   data to be insert in the model
	 * @return  \OrmWrapper
	 */
	public function create($data = null){
		$this->_isNew = true;

		if(is_array($data)){
			$this->hydrate($data);
		}
		//print_r($data);
		return $this;
	}

	/**
	 * find the first elem of query
	 * @param   int $id   search id
	 * @return  OrmWrapper/false
	 */
	public function findOne($id = null){
		if(!is_null($id)){
			$this->where($this->_idSelector, "=", $id);
		}
		$this->limit(1);
		$row = $this->run();

		if(empty($row)){
			return false;
		}

		return $this->hydrate($row[0]);
	}
	/**
	 * find the next elem of query
	 * 2015.4.6 By Ark
	 * @param   int $id   search id
	 * @return  OrmWrapper/false
	 */
	public function findNextOne($id = null){
		if(!is_null($id)){
			$this->where($this->_idSelector, ">", $id);
		}

		$this->order($this->_idSelector);

		$this->limit(1);

		$row = $this->run();

		if(empty($row)){
			return false;
		}

		return $this->hydrate($row[0]);
	}
	public function findPrevOne($id = null){
		if(!is_null($id)){
			$this->where($this->_idSelector, "<", $id);
		}

		$this->order($this->_idSelector,'DESC');

		$this->limit(1);

		$row = $this->run();

		if(empty($row)){
			return false;
		}

		return $this->hydrate($row[0]);
	}
	/**
	 * find all elem of query
	 * @return  OrmWrapper[]/false
	 */
	public function findMany(){
		$rows = $this->run();

		return $rows ? array_map(array($this, 'createInstance'), $rows) : false;
	}

	/**
	 * Count the number of lines from the asked model
	 * @return Int|Boolean
	 */
	public function rowCount(){
		$result = $this->joinIfNotEmpty(array(
		$this->buildJoin(),
		$this->buildWhere(),
		$this->buildGroupBy(),
		$this->buildOrderBy(),
		$this->buildLimit(),
		$this->buildOffset(),
		));


		if(!$this->connector){
			return false;
		}

		$query = "SELECT ".OrmConnector::$quoteSeparator.$this->_idSelector.OrmConnector::$quoteSeparator." FROM ".$this->tableName." ".$result;
		self::$log[] = $query;

		try{
			$preparedQuery = $this->connector->prepare($query);
			$preparedQuery->execute($this->_values);
		}catch(Exception $e){
			self::logError($e, $query);
			return false;
		}

		return $preparedQuery->rowCount();
	}

	public function getQueryRowCount(){

		if(!$this->connector){
			echo "fail";
			return false;
		}
		if(is_null($this->_rawQuery)){

			$query = $this->buildSelect();

		}else{

			$query = $this->_rawQuery;
		}

		self::$log[] = $query;

		try{
			$preparedQuery = $this->connector->prepare($query);
			
			$preparedQuery->execute($this->_values);
		}catch(Exception $e){
			echo $e->getMessage();
			self::logError($e, $query);
			return false;
		}

		//echo 'count'.$preparedQuery->rowCount();
		return $preparedQuery->rowCount();


	}

	/**
	 * save the state of current OrmWrapper
	 * @return  boolean
	 */
	public function save(){
		if(!$this->connector){
			return false;
		}

		$values = array_values($this->_dirty);


		if($this->_isNew){
			$query = $this->buildInsert();

		}else{
			if(!count($values)){
				return true;
			}

			$query = $this->buildUpdate();

			$values[] = $this->getId();
		}

		self::$log[] = $query;
		self::$log[] = $values;

		try{
			$preparedQuery = $this->connector->prepare($query);
			$success = $preparedQuery->execute($values);
		}catch(Exception $e){
			self::logError($e, $query);
			return false;
		}

		if($this->_isNew){
			$this->_isNew = false;

			if(is_null($this->getId())){
				$this->set($this->_idSelector, $this->connector->lastInsertId());
			}
		}

		return $success;
	}

	/**
	 * Delete the current OrmWrapper
	 * @return  boolean
	 */
	public function delete(){

		$query = $this->buildDelete();
		$params = array($this->getId());

		self::$log[] = $query;

		try{
			$exec = $this->connector->prepare($query);
			$success = $exec->execute($params);
		}catch(Exception $e){
			self::logError($e);
			return false;
		}

		return $success;
	}

	/**
	 * Send a "manual" query to the orm
	 *
	 * @param   string $query   the query to be run
	 * @param   array  $values  values used in the query
	 * @return  \OrmWrapper
	 */
	public function rawQuery($query, $values = array()){

		$this->_rawQuery = $query;
		$this->_values = $values;

		return $this;
	}

	/**
	 * Get the rows name of table
	 * @return array
	 */
	public function getRowNames(){
		if(count($this->_data) === 0){
			$this->findOne();
		}

		return array_keys($this->_data);
	}
	/**
	 * Get the rows name of table
	 *
	 * @return array
	 */
	public function getRows() {
		if (count ( $this->_data ) === 0) {
			$this->findOne ();
		}
		
		return array_keys ( $this->_data );
	}
	/**
	 * Reset the model to is initial state
	 * @return OrmWrapper
	 */
	public function reset(){
		$this->_isNew = false;
		//$this->_rawQuery = null;
		//$this->_distinct = false;
		$this->_resultSelector = array("*");
		$this->_data = array();
		$this->_dirty = array();
		$this->_values = array();
		$this->_join = array();
		$this->_joinTables = array();
		//$this->_where = array();
		$this->_limit = null;
		$this->_limitOffset = false;
		$this->_offset = null;
		$this->_order = null;
		$this->_orderBy = array();
		$this->_groupBy = array();

		return $this;
	}

	/**
	 * Get all data.
	 * @return array
	 */
	public function getAll(){
		return $this->_data;
	}

	/**
	 * Get a data
	 * @param $name
	 * @return mixed|null
	 */
	public function __get($name){
		return isset($this->_data[$name]) ? $this->_data[$name] : null;
	}

	/**
	 * Set a data
	 * @param $name
	 * @param $value
	 */
	private function set($name, $value){
		$this->_data[$name] = $value;
		$this->_dirty[$name] = $value;
	}

	/**
	 * Set a data
	 * @param $name
	 * @param $value
	 */
	public function __set($name, $value){
		$this->set($name, $value);
	}

	/**
	 * Log an error to the Log class or the internal log array
	 * @static
	 * @param $error
	 * @param null $query
	 */
	public static function logError($error, $query = null){
		if(class_exists('Log')){
			if(null != $query){
				SimpleLogger::warn($query);
			}

			SimpleLogger::fatal($error);
		}else{
			if(null != $query){
				self::$log[] = $query;
			}
			self::$log[] = $error;
		}
	}


	/**
	 * Get all of the tables of the database
	 * @return array
	 */
	public function list_tables($database)

	{

		$rs = $this->connector->query ( "SHOW TABLES FROM " . $database );
		$tables = $rs->fetchAll ();

		return $tables;
	}

	public function getFields() {

		$stmt = $this->connector->prepare ( "DESC " . $this->tableName );
		$stmt->execute ();
		$table_fields = $stmt->fetchAll ( PDO::FETCH_COLUMN );
		return  $table_fields;

	}

	public function findSum($Row,$Where) {
		
		$sql = "SELECT SUM($Row) as $Row FROM $this->tableName  ".$Where."  LIMIT 1";
		
		return $this->connector->query ( $sql )->fetch ();
	}
}
