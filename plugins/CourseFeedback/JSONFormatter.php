<?php
	class JSONFormatter {
		private static $nope = array('id', 'token', 'startlanguage', 'lastpage', 'startdate', 'datestamp');		

		public static function parseSurveyAnswersArray(array $arr) {
			$jres = array();
			foreach ($arr as $entry) {
				$parsed = array();
				foreach ($entry as $key => $value) {
					if(!in_array($key, self::$nope) && !is_null($value)) {
						$parsed[$key] = $value;
					}
				}
				if(count($parsed) > 0) {
					array_push($jres, $parsed);
				}
			}
			return json_encode($jres);
		}
	}
?>