<?php
	require_once('DBConnection.php');

	//A class for accessing Limesurvey database
	class Dao {
		public static function getSurveys() {
			$con = new DBConnection();
			$rs = $con->executeQuery("SELECT sid FROM lime_surveys WHERE active = 'Y'");			
			return $rs;
		}

		public static function getSurveyById($id) {
			if(!is_int($id)) {
				throw new Exception('Given ID is not integer');
			}
			$table = "lime_survey_" . $id;
			$con = new DBConnection();
			$rs = $con->executeQuery("SELECT * FROM " . $table);			
			return $rs;
		}
	}
?>