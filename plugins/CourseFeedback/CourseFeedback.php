<?php
    class CourseFeedback extends \ls\pluginmanager\PluginBase
    {
        static protected $description = 'Implements additional visualizations in surveys.';
        static protected $name = 'Visualization';

        public function init()
        {
            //$this->subscribe('beforeActivate');
            $this->subscribe('beforeSurveyPage');
            $this->subscribe('beforeQuestionRender');
	    $this->subscribe('afterSurveyComplete');
        }

	public function afterSurveyComplete() {
	    $event = $this->getEvent();
	    //var_dump($event);
	    $id = $event->get('surveyId');
	    var_dump($id);
	    echo "<script>var doge = " . $id . "</script>";
	    include('chart.txt');
	}

        public function beforeQuestionRender() {
            $event = $this->getEvent();
            //$event->set('success', false);

            //$event->set('message', 'Test...');
            //var_dump($event);
            //echo "<script>var doge = 1</script>";
        }

        public function beforeSurveyPage() {
            $event = $this->getEvent();
            //$event->set('success', false);

            //$event->set('message', 'Test...');
            //var_dump($event);
            //echo "<script>var doge = 1</script>";
        }

        public function beforeActivate() {
            $event = $this->getEvent();
            $event->set('success', false);

            $event->set('message', 'Test...');
        }
    }
?>
