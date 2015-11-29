<?php
	require_once('Dao.php');
	require_once('JSONFormatter.php');

	if(isset($_GET['id'])) {
		$id = $_GET['id'];
		if(!is_numeric($id)) {
			http_response_code(500);
		} else {
			try {
				$result = Dao::getSurveyById((int)$_GET['id']);
				if(is_array($result) && count($result) > 0) {
					echo JSONFormatter::parseSurveyAnswersArray($result);
					//var_dump($result);
				}
			} catch(PDOException $pe) {
				http_response_code(404);	
			}
		}
	} else {
		$result = Dao::getSurveys();
		if(is_array($result) && count($result) > 0) {
			echo json_encode($result);
		} else {
			echo "[]";
		}
	}	
?>