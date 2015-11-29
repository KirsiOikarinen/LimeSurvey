<?php
	//A class for handling mysql connections
	class DBConnection {
		const server 	= "localhost";
		const usern 	= "..";
		const passw 	= "..";
		const dbname 	= "Lime";

		private $dbCon;

		public function __construct() {
			$this->dbCon = new PDO("mysql:host=". self::server . ";dbname=" . self::dbname . ";charset=utf8", self::usern, self::passw);
			$this->dbCon->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$this->dbCon->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
		}

		public function getConnection() {
			return $this->dbCon;
		}

		public function executeQuery($query) {
			$statement = $this->dbCon->prepare($query);
			$statement->execute();
			if($statement->rowCount() == 0) {
				throw new Exception('No results');
			}			
			$rows = $statement->fetchAll(PDO::FETCH_ASSOC);
			return $rows;
		}

		public function executeQueryParams($query, array $arr) {
			$statement = $this->dbCon->prepare($query);
			$statement->execute($arr);
			if($statement->rowCount() == 0) {
				throw new Exception('No results');
			}						
			$rows = $statement->fetchAll(PDO::FETCH_ASSOC);
			return $rows;
		}
	}
?>
