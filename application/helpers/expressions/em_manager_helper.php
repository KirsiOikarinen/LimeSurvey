<?php
    /**
    * LimeSurvey
    * Copyright (C) 2007-2015 The LimeSurvey Project Team / Carsten Schmitz
    * All rights reserved.
    * License: GNU/GPL License v2 or later, see LICENSE.php
    * LimeSurvey is free software. This version may have been modified pursuant
    * to the GNU General Public License, and as distributed it includes or
    * is derivative of works licensed under the GNU General Public License or
    * other free or open source software licenses.
    * See COPYRIGHT.php for copyright notices and details.
    *
    */
    /**
    * LimeExpressionManager
    * This is a wrapper class around ExpressionManager that implements a Singleton and eases
    * passing of LimeSurvey variable values into ExpressionManager
    *
    * @author LimeSurvey Team (limesurvey.org)
    * @author Thomas M. White (TMSWhite)
    * @author Denis Chenu <http://sondages.pro>
    */
    include_once('em_core_helper.php');
    Yii::app()->loadHelper('database');
    Yii::app()->loadHelper('frontend');
    Yii::app()->loadHelper('surveytranslator');
    Yii::import("application.libraries.Date_Time_Converter");
    define('LEM_DEBUG_VALIDATION_SUMMARY',2);   // also includes  SQL error messages
    define('LEM_DEBUG_VALIDATION_DETAIL',4);
    define('LEM_PRETTY_PRINT_ALL_SYNTAX',32);

    define('LEM_DEFAULT_PRECISION',12);

    class LimeExpressionManager
    {
        /**
         * LimeExpressionManager is a singleton.  $instance is its storage location.
         * @var LimeExpressionManager
         */
        private static $instance;
        /**
         * Implements the recursive descent parser that processes expressions
         * @var ExpressionManager
         */
        private $em;

        /**
         * sum of LEM_DEBUG constants - use bitwise AND comparisons to identify which parts to use
         * @var type
         */
        private $debugLevel = 0;
        /**
         * sPreviewMode used for relevance equation force to 1 in preview mode
         * Maybe we can set it public
         * @var string
         */
        private $sPreviewMode = false;
        /**
         * Collection of variable attributes, indexed by SGQA code
         *

         /**
         * variables temporarily set for substitution purposes
         *
         * These are typically the LimeReplacement Fields passed in via templatereplace()
         * Each has the following structure:  array(
         * 'code' => // the static value of the variable
         * 'jsName_on' => // ''
         * 'jsName' => // ''
         * 'readWrite'  => // 'N'
         * );
         *
         * @var type
         */
        private $tempVars;
        /**
         * Array of relevance information for each page (gseq), indexed by gseq.
         * Within a page, it contains a sequential list of the results of each relevance equation processed
         * array(
         * 'qid' => // question id -- e.g. 154
         * 'gseq' => // 0-based group sequence -- e.g. 2
         * 'eqn' => // the raw relevance equation parsed -- e.g. "!is_empty(p2_sex)"
         * 'result' => // the Boolean result of parsing that equation in the current context -- e.g. 0
         * 'numJsVars' => // the number of dynamic JavaScript variables used in that equation -- e.g. 1
         * 'relevancejs' => // the actual JavaScript to insert for that relevance equation -- e.g. "LEMif(LEManyNA('p2_sex'),'',( ! LEMempty(LEMval('p2_sex') )))"
         * 'relevanceVars' => // a pipe-delimited list of JavaScript variables upon which that equation depends -- e.g. "java38612X12X153"
         * 'jsResultVar' => // the JavaScript variable in which that result will be stored -- e.g. "java38612X12X154"
         * 'type' => // the single character type of the question -- e.g. 'S'
         * 'hidden' => // 1 if the question should always be hidden
         * 'hasErrors' => // 1 if there were parsing errors processing that relevance equation
         * @var type
         */
        private $pageRelevanceInfo;


        /**
         * last result of NavigateForwards, NavigateBackwards, or JumpTo
         * Array of status information about last movement, whether at question, group, or survey level
         *
         * @example = array(
         * 'finished' => 0  // 1 if the survey has been completed and needs to be finalized
         * 'message' => ''  // any error message that needs to be displayed
         * 'seq' => 1   // the sequence count, using gseq, or qseq units if in 'group' or 'question' mode, respectively
         * 'mandViolation' => 0 // whether there was any violation of mandatory constraints in the last movement
         * 'valid' => 0 // 1 if the last movement passed all validation constraints.  0 if there were any validation errors
         * 'unansweredSQs' => // pipe-separated list of any sub-questions that were not answered
         * 'invalidSQs' => // pipe-separated list of any sub-questions that failed validation constraints
         * );
         *
         * @var type
         */
        private $lastMoveResult = null;

        /**
         * array of information needed to generate navigation index in group-by-group mode
         * One entry for each group, indexed by gseq
         *
         * @example [0] = array(
         * 'gtext' => // the description for the group
         * 'gname' => 'G1' // the group title
         * 'gid' => '34' // the group id
         * 'anyUnanswered' => 0 // 1 if any questions within the group are unanswered
         * 'anyErrors' => 0 // 1 if any of the questions within the group fail either validity or mandatory constraints
         * 'valid' => 1 // 1 if at least question in the group is relevant and non-hidden
         * 'mandViolation' => 0 // 1 if at least one relevant, non-hidden question in the group fails mandatory constraints
         * 'show' => 1 // 1 if there is at least one relevant, non-hidden question within the group
         * );
         *
         * @var type
         */
        private $indexGseq;
        /**
         * array of group sequence number to static info
         * One entry per group, indexed on gseq
         *
         * @example [0] = array(
         * 'group_order' => 0   // gseq
         * 'gid' => "34" // group id
         * 'group_name' => 'G2' // the group title
         * 'description' => // the description of the group (e.g. gtitle)
         * 'grelevance' => '' // the group-level relevance
         * );
         *
         * @var type
         */
        private $gseq2info;


        /**
         * /**
         * mapping of questions to information about their subquestions.
         * One entry per question, indexed on qid
         *
         * @example [702] = array(
         * 'qid' => 702 // the question id
         * 'qseq' => 6 // the question sequence
         * 'gseq' => 0 // the group sequence
         * 'sgqa' => '26626X34X702' // the root of the SGQA code (reallly just the SGQ)
         * 'varName' => 'afSrcFilter_sq1' // the full qcode variable name - note, if there are sub-questions, don't use this one.
         * 'type' => 'M' // the one-letter question type
         * 'fieldname' => '26626X34X702sq1' // the fieldname (used as JavaScript variable name, and also as database column name
         * 'rootVarName' => 'afDS'  // the root variable name
         * 'preg' => '/[A-Z]+/' // regular expression validation equation, if any
         * 'subqs' => array() of sub-questions, where each contains:
         *     'rowdivid' => '26626X34X702sq1' // the javascript id identifying the question row (so array_filter can hide rows)
         *     'varName' => 'afSrcFilter_sq1' // the full variable name for the sub-question
         *     'jsVarName_on' => 'java26626X34X702sq1' // the JavaScript variable name if the variable is defined on the current page
         *     'jsVarName' => 'java26626X34X702sq1' // the JavaScript variable name to use if the variable is defined on a different page
         *     'csuffix' => 'sq1' // the SGQ suffix to use for a fieldname
         *     'sqsuffix' => '_sq1' // the suffix to use for a qcode variable name
         *  );
         *
         * @var type
         */
        private $q2subqInfo;
        /**
         * array of advanced question attributes for each question
         * Indexed by qid; available for all quetions
         *
         * @example [784] = array(
         * 'array_filter_exclude' => 'afSrcFilter'
         * 'exclude_all_others' => 'sq5'
         * 'max_answers' => '3'
         * 'min_answers' => '1'
         * 'other_replace_text' => '{afSrcFilter_other}'
         * );
         *
         * @var type
         */
        private $qattr;
        /**
         * list of needed sub-question relevance (e.g. array_filter)
         * Indexed by qid then sgqa; only generated for current group of questions
         *
         * @example [708][26626X37X708sq2] = array(
         * 'qid' => '708' // the question id
         * 'eqn' => "((26626X34X702sq2 != ''))" // the auto-generated sub-question-level relevance equation
         * 'prettyPrintEqn' => '' // only generated if there errors - shows syntax highlighting of them
         * 'result' => 0 // result of processing the sub-question-level relevance equation in the current context
         * 'numJsVars' => 1 // the number of on-page javascript variables in 'eqn'
         * 'relevancejs' => // the generated javascript from 'eqn' -- e.g. "LEMif(LEManyNA('26626X34X702sq2'),'',(((LEMval('26626X34X702sq2')  != ""))))"
         * 'relevanceVars' => "java26626X34X702sq2" // the pipe-separated list of on-page javascript variables in 'eqn'
         * 'rowdivid' => "26626X37X708sq2" // the javascript id of the question row (so can apply array_filter)
         * 'type' => 'array_filter' // semicolon delimited list of types of subquestion relevance filters applied
         * 'qtype' => 'A' // the single character question type
         * 'sgqa' => "26626X37X708" // the SGQ portion of the fieldname
         * 'hasErrors' => 0 // 1 if there are any parse errors in the sub-question validation equations
         * );
         *
         * @var type
         */
        private $subQrelInfo = array();
        /**
         * array of Group-level relevance status
         * Indexed by gseq; only shows groups that have been visited
         *
         * @example [1] = array(
         * 'gseq' => 1 // group sequence
         * 'eqn' => '' // the group-level relevance
         * 'result' => 1 // result of processing the group-level relevance
         * 'numJsVars' => 0 // the number of on-page javascript variables in the group-level relevance equation
         * 'relevancejs' => '' // the javascript version of the relevance equation
         * 'relevanceVars' => '' // the pipe-delimited list of on-page javascript variable names used within the group-level relevance equation
         * 'prettyPrint' => '' // a pretty-print version of the group-level relevance equation, only if there are errors
         * );
         *
         * @var type
         */
        private $gRelInfo = array();


        /**
         * True (1) if calling LimeExpressionManager functions between StartSurvey and FinishProcessingPage
         * Used (mostly deprecated) to detect calls to LEM which happen outside of the normal processing scope
         * @var Boolean
         */
        private $initialized = false;
        /**
         * True (1) if have already processed the relevance equations (so don't need to do it again)
         *
         * @var Boolean
         */
        private $processedRelevance = false;
        /**
         * temporary variable to reduce need to parse same equation multiple times.  Used for relevance and validation
         * Array, indexed on equation, providing the following information:
         *
         * @example ['!is_empty(num)'] = array(
         * 'result' => 1 // result of processing the equation in the current scope
         * 'prettyPrint' => '' // syntax-highlighted version of equation if there are any errors
         * 'hasErrors' => 0 // 1 if there are any syntax errors
         * );
         *
         * @var type
         */
        private $ParseResultCache;
        /**
         * array of 2nd scale answer lists for types ':' and ';' -- needed for convenient print of logic file
         * Indexed on qid; available for all questions
         *
         * @example [706] = array(
         * '1~1' => '1|Never',
         * '1~2' => '2|Sometimes',
         * '1~3' => '3|Always'
         * );
         *
         * @var type
         */
        private $multiflexiAnswers;

        /**
         * used to specify whether to  generate equations using SGQA codes or qcodes
         * Default is to convert all qcode naming to sgqa naming when generating javascript, as that provides the greatest backwards compatibility
         * TSV export of survey structure sets this to false so as to force use of qcode naming
         *
         * @var Boolean
         */
        private $sgqaNaming = true;



        /**
         * Linked list of array filters
         * @var array
         */
        private $qrootVarName2arrayFilter = array();
        /**
         * Array, keyed on qid, to JavaScript and list of variables needed to implement exclude_all_others_auto
         * @var type
         */
        private $qid2exclusiveAuto = array();

        /**
         * A private constructor; prevents direct creation of object
         */
        private function __construct()
        {
            self::$instance =& $this;

            $this->em = new ExpressionManager([$this, 'GetVarAttribute']);
        }

        /**
         * Ensures there is only one instances of LEM.  Note, if switch between surveys, have to clear this cache
         * @return LimeExpressionManager
         */
        public static function &singleton()
        {
            if (!isset(self::$instance)) {
                bP();
                self::$instance = new static();
                eP();
            }

            return self::$instance;
        }



        /**
         * Prevent users to clone the instance
         */
        public function __clone()
        {
            throw new \Exception('Clone is not allowed.');
        }

        /**
         * Set the previewmode
         */
        public static function SetPreviewMode($previewmode = false)
        {
            $LEM =& LimeExpressionManager::singleton();
            $LEM->sPreviewMode = $previewmode;
            //$_SESSION[$LEM->sessid]['previewmode']=$previewmode;
        }

        /**
         * Tells Expression Manager that something has changed enough that needs to eliminate internal caching
         */
        public static function SetDirtyFlag()
        {
            $_SESSION['LEMdirtyFlag'] = true;// For fieldmap and other. question help {HELP} is taken from fieldmap
            $_SESSION['LEMforceRefresh'] = true;// For Expression manager string
        }


        /**
         * Do bulk-update/save of Condition to Relevance
         * @param <integer> $surveyId - if NULL, processes the entire database, otherwise just the specified survey
         * @param <integer> $qid - if specified, just updates that one question
         * @return array of query strings
         */
        public static function UpgradeConditionsToRelevance($surveyId = null, $qid = null)
        {
            LimeExpressionManager::SetDirtyFlag();  // set dirty flag even if not conditions, since must have had a DB change
            // Cheat and upgrade question attributes here too.
            self::UpgradeQuestionAttributes(true, $surveyId, $qid);

            if (is_null($surveyId)) {
                $sQuery = 'SELECT sid FROM {{surveys}}';
                $aSurveyIDs = Yii::app()->db->createCommand($sQuery)->queryColumn();
            } else {
                $aSurveyIDs = array($surveyId);
            }
            foreach ($aSurveyIDs as $surveyId) {
                // echo $surveyId.'<br>';flush();@ob_flush();
                $releqns = self::ConvertConditionsToRelevance($surveyId, $qid);
                if (!empty($releqns)) {
                    foreach ($releqns as $key => $value) {
                        $sQuery = "UPDATE {{questions}} SET relevance=" . Yii::app()->db->quoteValue($value) . " WHERE qid=" . $key;
                        Yii::app()->db->createCommand($sQuery)->execute();
                    }
                }
            }

            LimeExpressionManager::SetDirtyFlag();
        }

        /**
         * This reverses UpgradeConditionsToRelevance().  It removes Relevance for questions that have Condition
         * @param <integer> $surveyId
         * @param <integer> $qid
         */
        public static function RevertUpgradeConditionsToRelevance($surveyId = null, $qid = null)
        {
            LimeExpressionManager::SetDirtyFlag();  // set dirty flag even if not conditions, since must have had a DB change
            $releqns = self::ConvertConditionsToRelevance($surveyId, $qid);
            $num = count($releqns);
            if ($num == 0) {
                return null;
            }

            foreach ($releqns as $key => $value) {
                $query = "UPDATE {{questions}} SET relevance=1 WHERE qid=" . $key;
                dbExecuteAssoc($query);
            }

            return count($releqns);
        }

        /**
         * Return array database name as key, LEM name as value
         * @example (['gender'] => '38612X10X145')
         * @param <integer> $surveyId
         **/
        public static function getLEMqcode2sgqa($iSurveyId)
        {
            $LEM =& LimeExpressionManager::singleton();

            $LEM->SetEMLanguage(Survey::model()->findByPk($iSurveyId)->language);
            $LEM->StartProcessingPage(true);

            return $LEM->qcode2sgqa;
        }

        /**
         * If $qid is set, returns the relevance equation generated from conditions (or NULL if there are no conditions for that $qid)
         * If $qid is NULL, returns an array of relevance equations generated from Condition, keyed on the question ID
         * @param <integer> $surveyId
         * @param <integer> $qid - if passed, only generates relevance equation for that question - otherwise genereates for all questions with conditions
         * @return array of generated relevance strings, indexed by $qid
         */
        public static function ConvertConditionsToRelevance($surveyId = null, $qid = null)
        {
            $query = LimeExpressionManager::getConditionsForEM($surveyId, $qid);

            $_qid = -1;
            $relevanceEqns = array();
            $scenarios = array();
            $relAndList = array();
            $relOrList = array();
            foreach ($query->readAll() as $row) {
                $row['method'] = trim($row['method']); //For Postgres
                if ($row['qid'] != $_qid) {
                    // output the values for prior question is there was one
                    if ($_qid != -1) {
                        if (count($relOrList) > 0) {
                            $relAndList[] = '(' . implode(' or ', $relOrList) . ')';
                        }
                        if (count($relAndList) > 0) {
                            $scenarios[] = '(' . implode(' and ', $relAndList) . ')';
                        }
                        $relevanceEqn = implode(' or ', $scenarios);
                        $relevanceEqns[$_qid] = $relevanceEqn;
                    }

                    // clear for next question
                    $_qid = $row['qid'];
                    $_scenario = $row['scenario'];
                    $_cqid = $row['cqid'];
                    $_subqid = -1;
                    $relAndList = array();
                    $relOrList = array();
                    $scenarios = array();
                    $releqn = '';
                }
                if ($row['scenario'] != $_scenario) {
                    if (count($relOrList) > 0) {
                        $relAndList[] = '(' . implode(' or ', $relOrList) . ')';
                    }
                    $scenarios[] = '(' . implode(' and ', $relAndList) . ')';
                    $relAndList = array();
                    $relOrList = array();
                    $_scenario = $row['scenario'];
                    $_cqid = $row['cqid'];
                    $_subqid = -1;
                }
                if ($row['cqid'] != $_cqid) {
                    $relAndList[] = '(' . implode(' or ', $relOrList) . ')';
                    $relOrList = array();
                    $_cqid = $row['cqid'];
                    $_subqid = -1;
                }

                // fix fieldnames
                if ($row['type'] == '' && preg_match('/^{.+}$/', $row['cfieldname'])) {
                    $fieldname = substr($row['cfieldname'], 1, -1);    // {TOKEN:xxxx}
                    $subqid = $fieldname;
                    $value = $row['value'];
                } else {
                    if ($row['type'] == 'M' || $row['type'] == 'P') {
                        if (substr($row['cfieldname'], 0, 1) == '+') {
                            // if prefixed with +, then a fully resolved name
                            $fieldname = substr($row['cfieldname'], 1) . '.NAOK';
                            $subqid = $fieldname;
                            $value = $row['value'];
                        } else {
                            // else create name by concatenating two parts together
                            $fieldname = $row['cfieldname'] . $row['value'] . '.NAOK';
                            $subqid = $row['cfieldname'];
                            $value = 'Y';
                        }
                    } else {
                        $fieldname = $row['cfieldname'] . '.NAOK';
                        $subqid = $fieldname;
                        $value = $row['value'];
                    }
                }
                if ($_subqid != -1 && $_subqid != $subqid) {
                    $relAndList[] = '(' . implode(' or ', $relOrList) . ')';
                    $relOrList = array();
                }
                $_subqid = $subqid;

                // fix values
                if (preg_match('/^@\d+X\d+X\d+.*@$/', $value)) {
                    $value = substr($value, 1, -1);
                } else {
                    if (preg_match('/^{.+}$/', $value)) {
                        $value = substr($value, 1, -1);
                    } else {
                        if ($row['method'] == 'RX') {
                            if (!preg_match('#^/.*/$#', $value)) {
                                $value = '"/' . $value . '/"';  // if not surrounded by slashes, add them.
                            }
                        } else {
                            $value = '"' . $value . '"';
                        }
                    }
                }

                // add equation
                if ($row['method'] == 'RX') {
                    $relOrList[] = "regexMatch(" . $value . "," . $fieldname . ")";
                } else {
                    // Condition uses ' ' to mean not answered, but internally it is really stored as ''.  Fix this
                    if ($value === '" "' || $value == '""') {
                        if ($row['method'] == '==') {
                            $relOrList[] = "is_empty(" . $fieldname . ")";
                        } else {
                            if ($row['method'] == '!=') {
                                $relOrList[] = "!is_empty(" . $fieldname . ")";
                            } else {
                                $relOrList[] = $fieldname . " " . $row['method'] . " " . $value;
                            }
                        }
                    } else {
                        if ($value == '"0"' || !preg_match('/^".+"$/', $value)) {
                            switch ($row['method']) {
                                case '==':
                                case '<':
                                case '<=':
                                case '>=':
                                    $relOrList[] = '(!is_empty(' . $fieldname . ') && (' . $fieldname . " " . $row['method'] . " " . $value . '))';
                                    break;
                                case '!=':
                                    $relOrList[] = '(is_empty(' . $fieldname . ') || (' . $fieldname . " != " . $value . '))';
                                    break;
                                default:
                                    $relOrList[] = $fieldname . " " . $row['method'] . " " . $value;
                                    break;
                            }
                        } else {
                            switch ($row['method']) {
                                case '<':
                                case '<=':
                                    $relOrList[] = '(!is_empty(' . $fieldname . ') && (' . $fieldname . " " . $row['method'] . " " . $value . '))';
                                    break;
                                default:
                                    $relOrList[] = $fieldname . " " . $row['method'] . " " . $value;
                                    break;
                            }
                        }
                    }
                }

                if (($row['cqid'] == 0 && !preg_match('/^{TOKEN:([^}]*)}$/',
                            $row['cfieldname'])) || substr($row['cfieldname'], 0, 1) == '+'
                ) {
                    $_cqid = -1;    // forces this statement to be ANDed instead of being part of a cqid OR group (except for TOKEN fields)
                }
            }
            // output last one
            if ($_qid != -1) {
                if (count($relOrList) > 0) {
                    $relAndList[] = '(' . implode(' or ', $relOrList) . ')';
                }
                if (count($relAndList) > 0) {
                    $scenarios[] = '(' . implode(' and ', $relAndList) . ')';
                }
                $relevanceEqn = implode(' or ', $scenarios);
                $relevanceEqns[$_qid] = $relevanceEqn;
            }
            if (is_null($qid)) {
                return $relevanceEqns;
            } else {
                if (isset($relevanceEqns[$qid])) {
                    $result = array();
                    $result[$qid] = $relevanceEqns[$qid];

                    return $result;
                } else {
                    return null;
                }
            }
        }

        /**
         * Return list of relevance equations generated from conditions
         * @param <integer> $surveyId
         * @param <integer> $qid
         * @return array of relevance equations, indexed by $qid
         */
        public static function UnitTestConvertConditionsToRelevance($surveyId = null, $qid = null)
        {
            $LEM =& LimeExpressionManager::singleton();

            return $LEM->ConvertConditionsToRelevance($surveyId, $qid);
        }


        /**
         * Process all question attributes that apply to EM
         * (1) Sub-question-level relevance:  e.g. array_filter, array_filter_exclude, relevance equations entered in SQ-mask
         * (2) Validations: e.g. min/max number of answers; min/max/eq sum of answers
         * @param Question $question The base question
         */
        public function _CreateSubQLevelRelevanceAndValidationEqns(Question $question)
        {
            /**
             * @todo Implement this.
             */
            $session = App()->surveySessionManager->current;
            $subQrels = array();    // array of sub-question-level relevance equations
            $validationEqn = array();
            $validationTips = array();    // array of visible tips for validation criteria, indexed by $qid

            $knownVars = $this->getKnownVars();
            // Associate these with $qid so that can be nested under appropriate question-level relevance

            $questionNum = $question->primaryKey;
            $hasSubqs = (isset($qinfo['subqs']) && count($qinfo['subqs'] > 0));
            $input_boxes = isset($question->bool_input_boxes) && $question->bool_input_boxes;

            $value_range_allows_missing = isset($question->bool_value_range_allows_missing) && $question->bool_value_range_allows_missing;

            // array_filter
            // If want to filter question Q2 on Q1, where each have subquestions SQ1-SQ3, this is equivalent to relevance equations of:
            // relevance for Q2_SQ1 is Q1_SQ1!=''
            $array_filter = null;
            if (isset($question->array_filter) && trim($question->array_filter) != '') {
                $array_filter = $question->array_filter;
                $this->qrootVarName2arrayFilter[$qinfo['rootVarName']]['array_filter'] = $array_filter;
            }

        // array_filter_exclude
        // If want to filter question Q2 on Q1, where each have subquestions SQ1-SQ3, this is equivalent to relevance equations of:
        // relevance for Q2_SQ1 is Q1_SQ1==''
        $array_filter_exclude = null;
        if (isset($question->array_filter_exclude) && trim($question->array_filter_exclude) != '') {
            $array_filter_exclude = $question->array_filter_exclude;
            $this->qrootVarName2arrayFilter[$qinfo['rootVarName']]['array_filter_exclude'] = $array_filter_exclude;
        }

        // array_filter and array_filter_exclude get processed together
        if (!is_null($array_filter) || !is_null($array_filter_exclude)) {
            if ($hasSubqs) {
                $cascadedAF = array();
                $cascadedAFE = array();

                list($cascadedAF, $cascadedAFE) = $this->_recursivelyFindAntecdentArrayFilters($qinfo['rootVarName'],
                    array(), array());

                $cascadedAF = array_reverse($cascadedAF);
                $cascadedAFE = array_reverse($cascadedAFE);

                $subqs = $qinfo['subqs'];
                if ($question->type == Question::TYPE_RANKING) {
                    $subqs = array();
                    foreach ($this->qans[$question->primaryKey] as $k => $v) {
                        $_code = explode('~', $k);
                        $subqs[] = array(
                            'rowdivid' => $question->sgqa . $_code[1],
                            'sqsuffix' => '_' . $_code[1],
                        );
                    }
                }
                $last_rowdivid = '--';
                foreach ($subqs as $sq) {
                    if ($sq['rowdivid'] == $last_rowdivid) {
                        continue;
                    }
                    $last_rowdivid = $sq['rowdivid'];
                    $af_names = array();
                    $afe_names = array();
                    switch ($question->type) {
                        case '1':   //Array (Flexible Labels) dual scale
                        case ':': //ARRAY (Multi Flexi) 1 to 10
                        case ';': //ARRAY (Multi Flexi) Text
                        case 'A': //ARRAY (5 POINT CHOICE) radio-buttons
                        case 'B': //ARRAY (10 POINT CHOICE) radio-buttons
                        case 'C': //ARRAY (YES/UNCERTAIN/NO) radio-buttons
                        case 'E': //ARRAY (Increase/Same/Decrease) radio-buttons
                        case 'F': //ARRAY (Flexible) - Row Format
                        case 'L': //LIST drop-down/radio-button list
                        case 'M': //Multiple choice checkbox
                        case 'P': //Multiple choice with comments checkbox + text
                        case 'K': //MULTIPLE NUMERICAL QUESTION
                        case 'Q': //MULTIPLE SHORT TEXT
                        case 'R': //Ranking
//                                    if ($this->sgqaNaming)
//                                    {
                            foreach ($cascadedAF as $_caf) {
                                $sgq = ((isset($this->qcode2sgq[$_caf])) ? $this->qcode2sgq[$_caf] : $_caf);
                                $fqid = explode('X', $sgq);
                                if (!isset($fqid[2])) {
                                    continue;
                                }
                                $fqid = $fqid[2];
                                if ($this->q2subqInfo[$fqid]['type'] == 'R') {
                                    $rankables = array();
                                    foreach ($this->qans[$fqid] as $k => $v) {
                                        $rankable = explode('~', $k);
                                        $rankables[] = '_' . $rankable[1];
                                    }
                                    if (array_search($sq['sqsuffix'], $rankables) === false) {
                                        continue;
                                    }
                                }
                                $fsqs = array();
                                foreach ($this->q2subqInfo[$fqid]['subqs'] as $fsq) {
                                    if (!isset($fsq['csuffix'])) {
                                        $fsq['csuffix'] = '';
                                    }
                                    if ($this->q2subqInfo[$fqid]['type'] == 'R') {
                                        // we know the suffix exists
                                        $fsqs[] = '(' . $sgq . $fsq['csuffix'] . ".NAOK == '" . substr($sq['sqsuffix'],
                                                1) . "')";
                                    } else {
                                        if ($this->q2subqInfo[$fqid]['type'] == ':' && isset($this->qattr[$fqid]['multiflexible_checkbox']) && $this->qattr[$fqid]['multiflexible_checkbox'] == '1') {
                                            if ($fsq['sqsuffix'] == $sq['sqsuffix']) {
                                                $fsqs[] = $sgq . $fsq['csuffix'] . '.NAOK=="1"';
                                            }
                                        } else {
                                            if ($fsq['sqsuffix'] == $sq['sqsuffix']) {
                                                $fsqs[] = '!is_empty(' . $sgq . $fsq['csuffix'] . '.NAOK)';
                                            }
                                        }
                                    }
                                }
                                if (count($fsqs) > 0) {
                                    $af_names[] = '(' . implode(' or ', $fsqs) . ')';
                                }
                            }
                            foreach ($cascadedAFE as $_cafe) {
                                $sgq = ((isset($this->qcode2sgq[$_cafe])) ? $this->qcode2sgq[$_cafe] : $_cafe);
                                $fqid = explode('X', $sgq);
                                if (!isset($fqid[2])) {
                                    continue;
                                }
                                $fqid = $fqid[2];
                                if ($this->q2subqInfo[$fqid]['type'] == 'R') {
                                    $rankables = array();
                                    foreach ($this->qans[$fqid] as $k => $v) {
                                        $rankable = explode('~', $k);
                                        $rankables[] = '_' . $rankable[1];
                                    }
                                    if (array_search($sq['sqsuffix'], $rankables) === false) {
                                        continue;
                                    }
                                }
                                $fsqs = array();
                                foreach ($this->q2subqInfo[$fqid]['subqs'] as $fsq) {
                                    if ($this->q2subqInfo[$fqid]['type'] == 'R') {
                                        // we know the suffix exists
                                        $fsqs[] = '(' . $sgq . $fsq['csuffix'] . ".NAOK != '" . substr($sq['sqsuffix'],
                                                1) . "')";
                                    } else {
                                        if ($this->q2subqInfo[$fqid]['type'] == ':' && isset($this->qattr[$fqid]['multiflexible_checkbox']) && $this->qattr[$fqid]['multiflexible_checkbox'] == '1') {
                                            if ($fsq['sqsuffix'] == $sq['sqsuffix']) {
                                                $fsqs[] = $sgq . $fsq['csuffix'] . '.NAOK!="1"';
                                            }
                                        } else {
                                            if ($fsq['sqsuffix'] == $sq['sqsuffix']) {
                                                $fsqs[] = 'is_empty(' . $sgq . $fsq['csuffix'] . '.NAOK)';
                                            }
                                        }
                                    }
                                }
                                if (count($fsqs) > 0) {
                                    $afe_names[] = '(' . implode(' and ', $fsqs) . ')';
                                }
                            }
                            break;
                        default:
                            break;
                    }
                    $af_names = array_unique($af_names);
                    $afe_names = array_unique($afe_names);

                    if (count($af_names) > 0 || count($afe_names) > 0) {
                        $afs_eqn = '';
                        if (count($af_names) > 0) {
                            $afs_eqn .= implode(' && ', $af_names);
                        }
                        if (count($afe_names) > 0) {
                            if ($afs_eqn != '') {
                                $afs_eqn .= ' && ';
                            }
                            $afs_eqn .= implode(' && ', $afe_names);
                        }

                        $subQrels[] = array(
                            'qtype' => $question->type,
                            'type' => 'array_filter',
                            'rowdivid' => $sq['rowdivid'],
                            'eqn' => '(' . $afs_eqn . ')',
                            'qid' => $questionNum,
                            'sgqa' => $qinfo['sgqa'],
                        );
                    }
                }
            }
        }

        // individual subquestion relevance
        if ($hasSubqs &&
            $question->type != '|' && $question->type != '!' && $question->type != 'L' && $question->type != 'O'
        ) {
            $subqs = $qinfo['subqs'];
            $last_rowdivid = '--';
            foreach ($subqs as $sq) {
                if ($sq['rowdivid'] == $last_rowdivid) {
                    continue;
                }
                $last_rowdivid = $sq['rowdivid'];
                $rowdivid = null;
                $rowdivid = $sq['rowdivid'];
                switch ($question->type) {
                    case '1': //Array (Flexible Labels) dual scale
                        $rowdivid = $rowdivid . '#0';
                        break;
                    case ':': //ARRAY Numbers
                    case ';': //ARRAY Text
                        $aCsuffix = (explode('_', $sq['csuffix']));
                        $rowdivid = $rowdivid . '_' . $aCsuffix[1];
                        break;
                    case 'A': //ARRAY (5 POINT CHOICE) radio-buttons
                    case 'B': //ARRAY (10 POINT CHOICE) radio-buttons
                    case 'C': //ARRAY (YES/UNCERTAIN/NO) radio-buttons
                    case 'E': //ARRAY (Increase/Same/Decrease) radio-buttons
                    case 'F': //ARRAY (Flexible) - Row Format
                    case 'M': //Multiple choice checkbox
                    case 'P': //Multiple choice with comments checkbox + text
                    case 'K': //MULTIPLE NUMERICAL QUESTION
                    case 'Q': //MULTIPLE SHORT TEXT
                        break;
                    default:
                        break;
                }
                if (isset($knownVars[$rowdivid]['SQrelevance']) & $knownVars[$rowdivid]['SQrelevance'] != '') {
                    $subQrels[] = array(
                        'qtype' => $question->type,
                        'type' => 'SQ_relevance',
                        'rowdivid' => $sq['rowdivid'],
                        'eqn' => $knownVars[$rowdivid]['SQrelevance'],
                        'qid' => $questionNum,
                        'sgqa' => $qinfo['sgqa'],
                    );
                }
            }
        }

         // Default validation for question type
        switch ($question->type) {
            case Question::TYPE_MULTIPLE_NUMERICAL_INPUT: //MULTI NUMERICAL QUESTION TYPE
                if ($hasSubqs) {
                    $subqs = $qinfo['subqs'];
                    $sq_equs = array();
                    $subqValidEqns = array();
                    foreach ($subqs as $sq) {
                        $sq_name = ($this->sgqaNaming) ? $sq['rowdivid'] . ".NAOK" : $sq['varName'] . ".NAOK";
                        $sq_equ = '( is_numeric(' . $sq_name . ') || is_empty(' . $sq_name . ') )';// Leave mandatory to mandatory attribute
                        $subqValidSelector = $sq['jsVarName_on'];
                        if (!is_null($sq_name)) {
                            $sq_equs[] = $sq_equ;
                            $subqValidEqns[$subqValidSelector] = array(
                                'subqValidEqn' => $sq_equ,
                                'subqValidSelector' => $subqValidSelector,
                            );
                        }
                    }
                    if (!isset($validationEqn[$questionNum])) {
                        $validationEqn[$questionNum] = array();
                    }
                    $validationEqn[$questionNum][] = array(
                        'qtype' => $question->type,
                        'type' => 'default',
                        'class' => 'default',
                        'eqn' => implode(' and ', $sq_equs),
                        'qid' => $questionNum,
                        'subqValidEqns' => $subqValidEqns,
                    );
                }
                break;
            default:
                break;
        }

        // date_min
        // Maximum date allowed in date question
        if (isset($qattr['date_min']) && trim($qattr['date_min']) != '') {
            $date_min = $qattr['date_min'];
            if ($hasSubqs) {
                $subqs = $qinfo['subqs'];
                $sq_names = array();
                $subqValidEqns = array();
                foreach ($subqs as $sq) {
                    $sq_name = null;
                    switch ($question->type) {
                        case 'D': //DATE QUESTION TYPE
                            // date_min: Determine whether we have an expression, a full date (YYYY-MM-DD) or only a year(YYYY)
                            if (trim($qattr['date_min']) != '') {
                                $mindate = $qattr['date_min'];
                                if ((strlen($mindate) == 4) && ($mindate >= 1900) && ($mindate <= 2099)) {
                                    // backward compatibility: if only a year is given, add month and day
                                    $date_min = '\'' . $mindate . '-01-01' . ' 00:00\'';
                                } elseif (preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])/",
                                    $mindate)) {
                                    $date_min = '\'' . $mindate . ' 00:00\'';
                                } elseif (array_key_exists($date_min,
                                    $this->qcode2sgqa))  // refers to another question
                                {
                                    $date_min = $date_min . '.NAOK';
                                }
                            }

                            $sq_name = ($this->sgqaNaming) ? $sq['rowdivid'] . ".NAOK" : $sq['varName'] . ".NAOK";
                            $sq_name = '(is_empty(' . $sq_name . ') || (' . $sq_name . ' >= date("Y-m-d H:i", strtotime(' . $date_min . ')) ))';
                            $subqValidSelector = '';
                            break;
                        default:
                            break;
                    }
                    if (!is_null($sq_name)) {
                        $sq_names[] = $sq_name;
                        $subqValidEqns[$subqValidSelector] = array(
                            'subqValidEqn' => $sq_name,
                            'subqValidSelector' => $subqValidSelector,
                        );
                    }
                }
                if (count($sq_names) > 0) {
                    if (!isset($validationEqn[$questionNum])) {
                        $validationEqn[$questionNum] = array();
                    }
                    $validationEqn[$questionNum][] = array(
                        'qtype' => $question->type,
                        'type' => 'date_min',
                        'class' => 'value_range',
                        'eqn' => implode(' && ', $sq_names),
                        'qid' => $questionNum,
                        'subqValidEqns' => $subqValidEqns,
                    );
                }
            }
        } else {
            $date_min = '';
        }

        // date_max
        // Maximum date allowed in date question
        if (isset($qattr['date_max']) && trim($qattr['date_max']) != '') {
            $date_max = $qattr['date_max'];
            if ($hasSubqs) {
                $subqs = $qinfo['subqs'];
                $sq_names = array();
                $subqValidEqns = array();
                foreach ($subqs as $sq) {
                    $sq_name = null;
                    switch ($question->type) {
                        case 'D': //DATE QUESTION TYPE
                            // date_max: Determine whether we have an expression, a full date (YYYY-MM-DD) or only a year(YYYY)
                            if (trim($qattr['date_max']) != '') {
                                $maxdate = $qattr['date_max'];
                                if ((strlen($maxdate) == 4) && ($maxdate >= 1900) && ($maxdate <= 2099)) {
                                    // backward compatibility: if only a year is given, add month and day
                                    $date_max = '\'' . $maxdate . '-12-31 23:59' . '\'';
                                } elseif (preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])/",
                                    $maxdate)) {
                                    $date_max = '\'' . $maxdate . ' 23:59\'';
                                } elseif (array_key_exists($date_max,
                                    $this->qcode2sgqa))  // refers to another question
                                {
                                    $date_max = $date_max . '.NAOK';
                                }
                            }

                            $sq_name = ($this->sgqaNaming) ? $sq['rowdivid'] . ".NAOK" : $sq['varName'] . ".NAOK";
                            $sq_name = '(is_empty(' . $sq_name . ') || is_empty(' . $date_max . ') || (' . $sq_name . ' <= date("Y-m-d H:i", strtotime(' . $date_max . ')) ))';
                            $subqValidSelector = '';
                            break;
                        default:
                            break;
                    }
                    if (!is_null($sq_name)) {
                        $sq_names[] = $sq_name;
                        $subqValidEqns[$subqValidSelector] = array(
                            'subqValidEqn' => $sq_name,
                            'subqValidSelector' => $subqValidSelector,
                        );
                    }
                }
                if (count($sq_names) > 0) {
                    if (!isset($validationEqn[$questionNum])) {
                        $validationEqn[$questionNum] = array();
                    }
                    $validationEqn[$questionNum][] = array(
                        'qtype' => $question->type,
                        'type' => 'date_max',
                        'class' => 'value_range',
                        'eqn' => implode(' && ', $sq_names),
                        'qid' => $questionNum,
                        'subqValidEqns' => $subqValidEqns,
                    );
                }
            }
        } else {
            $date_max = '';
        }

        // equals_num_value
        // Validation:= sum(sq1,...,sqN) == value (which could be an expression).
        if (isset($qattr['equals_num_value']) && trim($qattr['equals_num_value']) != '') {
            $equals_num_value = $qattr['equals_num_value'];
            if ($hasSubqs) {
                $subqs = $qinfo['subqs'];
                $sq_names = array();
                foreach ($subqs as $sq) {
                    $sq_name = null;
                    switch ($question->type) {
                        case 'K': //MULTIPLE NUMERICAL QUESTION
                            if ($this->sgqaNaming) {
                                $sq_name = $sq['rowdivid'] . '.NAOK';
                            } else {
                                $sq_name = $sq['varName'] . '.NAOK';
                            }
                            break;
                        default:
                            break;
                    }
                    if (!is_null($sq_name)) {
                        $sq_names[] = $sq_name;
                    }
                }
                if (count($sq_names) > 0) {
                    if (!isset($validationEqn[$questionNum])) {
                        $validationEqn[$questionNum] = array();
                    }
                    // sumEqn and sumRemainingEqn may need to be rounded if using sliders
                    $precision = LEM_DEFAULT_PRECISION;    // default is not to round
                    if (isset($qattr['slider_layout']) && $qattr['slider_layout'] == '1') {
                        $precision = 0;   // default is to round to whole numbers
                        if (isset($qattr['slider_accuracy']) && trim($qattr['slider_accuracy']) != '') {
                            $slider_accuracy = $qattr['slider_accuracy'];
                            $_parts = explode('.', $slider_accuracy);
                            if (isset($_parts[1])) {
                                $precision = strlen($_parts[1]);    // number of digits after mantissa
                            }
                        }
                    }
                    $sumEqn = 'sum(' . implode(', ', $sq_names) . ')';
                    $sumRemainingEqn = '(' . $equals_num_value . ' - sum(' . implode(', ', $sq_names) . '))';
                    $mainEqn = 'sum(' . implode(', ', $sq_names) . ')';

                    if (!is_null($precision)) {
                        $sumEqn = 'round(' . $sumEqn . ', ' . $precision . ')';
                        $sumRemainingEqn = 'round(' . $sumRemainingEqn . ', ' . $precision . ')';
                        $mainEqn = 'round(' . $mainEqn . ', ' . $precision . ')';
                    }

                    $noanswer_option = '';
                    if ($value_range_allows_missing) {
                        $noanswer_option = ' || count(' . implode(', ', $sq_names) . ') == 0';
                    }

                    $validationEqn[$questionNum][] = array(
                        'qtype' => $question->type,
                        'type' => 'equals_num_value',
                        'class' => 'sum_range',
                        'eqn' => ($question->bool_mandatory) ? '(' . $mainEqn . ' == (' . $equals_num_value . '))' : '(' . $mainEqn . ' == (' . $equals_num_value . ')' . $noanswer_option . ')',
                        'qid' => $questionNum,
                        'sumEqn' => $sumEqn,
                        'sumRemainingEqn' => $sumRemainingEqn,
                    );
                }
            }
        } else {
            $equals_num_value = '';
        }

        // exclude_all_others
        // If any excluded options are true (and relevant), then disable all other input elements for that question
        if (isset($qattr['exclude_all_others']) && trim($qattr['exclude_all_others']) != '') {
            $exclusive_options = explode(';', $qattr['exclude_all_others']);
            if ($hasSubqs) {
                foreach ($exclusive_options as $exclusive_option) {
                    $exclusive_option = trim($exclusive_option);
                    if ($exclusive_option == '') {
                        continue;
                    }
                    $subqs = $qinfo['subqs'];
                    $sq_names = array();
                    foreach ($subqs as $sq) {
                        $sq_name = null;
                        if ($sq['csuffix'] == $exclusive_option) {
                            continue;   // so don't make the excluded option irrelevant
                        }
                        switch ($question->type) {
                            case ':': //ARRAY (Multi Flexi) 1 to 10
                            case 'A': //ARRAY (5 POINT CHOICE) radio-buttons
                            case 'B': //ARRAY (10 POINT CHOICE) radio-buttons
                            case 'C': //ARRAY (YES/UNCERTAIN/NO) radio-buttons
                            case 'E': //ARRAY (Increase/Same/Decrease) radio-buttons
                            case 'F': //ARRAY (Flexible) - Row Format
                            case 'M': //Multiple choice checkbox
                            case 'P': //Multiple choice with comments checkbox + text
                            case 'K': //MULTIPLE NUMERICAL QUESTION
                            case 'Q': //MULTIPLE SHORT TEXT
                                if ($this->sgqaNaming) {
                                    $sq_name = $qinfo['sgqa'] . trim($exclusive_option) . '.NAOK';
                                } else {
                                    $sq_name = $qinfo['sgqa'] . trim($exclusive_option) . '.NAOK';
                                }
                                break;
                            default:
                                break;
                        }
                        if (!is_null($sq_name)) {
                            $subQrels[] = array(
                                'qtype' => $question->type,
                                'type' => 'exclude_all_others',
                                'rowdivid' => $sq['rowdivid'],
                                'eqn' => 'is_empty(' . $sq_name . ')',
                                'qid' => $questionNum,
                                'sgqa' => $qinfo['sgqa'],
                            );
                        }
                    }
                }
            }
        } else {
            $exclusive_option = '';
        }

        // exclude_all_others_auto
        // if (count(this.relevanceStatus) == count(this)) { set exclusive option value to "Y" and call checkconditions() }
        // However, note that would need to blank the values, not use relevance, otherwise can't unclick the _auto option without having it re-enable itself
        if (isset($qattr['exclude_all_others_auto']) && trim($qattr['exclude_all_others_auto']) == '1'
            && isset($qattr['exclude_all_others']) && trim($qattr['exclude_all_others']) != '' && count(explode(';',
                trim($qattr['exclude_all_others']))) == 1
        ) {
            $exclusive_option = trim($qattr['exclude_all_others']);
            if ($hasSubqs) {
                $subqs = $qinfo['subqs'];
                $sq_names = array();
                foreach ($subqs as $sq) {
                    $sq_name = null;
                    switch ($question->type) {
                        case 'M': //Multiple choice checkbox
                        case 'P': //Multiple choice with comments checkbox + text
                            if ($this->sgqaNaming) {
                                $sq_name = substr($sq['jsVarName'], 4);
                            } else {
                                $sq_name = $sq['varName'];
                            }
                            break;
                        default:
                            break;
                    }
                    if (!is_null($sq_name)) {
                        if ($sq['csuffix'] == $exclusive_option) {
                            $eoVarName = substr($sq['jsVarName'], 4);
                        } else {
                            $sq_names[] = $sq_name;
                        }
                    }
                }
                if (count($sq_names) > 0) {
                    $relpart = "sum(" . implode(".relevanceStatus, ", $sq_names) . ".relevanceStatus)";
                    $checkedpart = "count(" . implode(".NAOK, ", $sq_names) . ".NAOK)";
                    $eoRelevantAndUnchecked = "(" . $eoVarName . ".relevanceStatus && is_empty(" . $eoVarName . "))";
                    $eoEqn = "(" . $eoRelevantAndUnchecked . " && (" . $relpart . " == " . $checkedpart . "))";

                    $this->em->ProcessBooleanExpression($eoEqn, $session->getGroupIndex($question->gid), $session->getQuestionIndex($question->primaryKey));

                    $relevanceVars = implode('|', $this->em->GetJSVarsUsed());
                    $relevanceJS = $this->em->GetJavaScriptEquivalentOfExpression();

                    // Unset all checkboxes and hidden values for this question (irregardless of whether they are array filtered)
                    $eosaJS = "if (" . $relevanceJS . ") {\n";
                    $eosaJS .= "  $('#question" . $questionNum . " [type=checkbox]').attr('checked',false);\n";
                    $eosaJS .= "  $('#java" . $qinfo['sgqa'] . "other').val('');\n";
                    $eosaJS .= "  $('#answer" . $qinfo['sgqa'] . "other').val('');\n";
                    $eosaJS .= "  $('[id^=java" . $qinfo['sgqa'] . "]').val('');\n";
                    $eosaJS .= "  $('#answer" . $eoVarName . "').attr('checked',true);\n";
                    $eosaJS .= "  $('#java" . $eoVarName . "').val('Y');\n";
                    $eosaJS .= "  LEMrel" . $questionNum . "();\n";
                    $eosaJS .= "  relChange" . $questionNum . "=true;\n";
                    $eosaJS .= "}\n";

                    $this->qid2exclusiveAuto[$questionNum] = array(
                        'js' => $eosaJS,
                        'relevanceVars' => $relevanceVars,
                        // so that EM knows which variables to declare
                        'rowdivid' => $eoVarName,
                        // to ensure that EM creates a hidden relevanceSGQA input for the exclusive option
                    );
                }
            }
        }
        // input_boxes
        if (isset($qattr['input_boxes']) && $qattr['input_boxes'] == 1) {
            $input_boxes = 1;
            switch ($question->type) {
                case ':': //Array Numbers
                    if ($hasSubqs) {
                        $subqs = $qinfo['subqs'];
                        $sq_equs = array();
                        $subqValidEqns = array();
                        foreach ($subqs as $sq) {
                            $sq_name = ($this->sgqaNaming) ? substr($sq['jsVarName'],
                                    4) . ".NAOK" : $sq['varName'] . ".NAOK";
                            $sq_equ = '( is_numeric(' . $sq_name . ') || is_empty(' . $sq_name . ') )';// Leave mandatory to mandatory attribute (see #08665)
                            $subqValidSelector = $sq['jsVarName_on'];
                            if (!is_null($sq_name)) {
                                $sq_equs[] = $sq_equ;
                                $subqValidEqns[$subqValidSelector] = array(
                                    'subqValidEqn' => $sq_equ,
                                    'subqValidSelector' => $subqValidSelector,
                                );
                            }
                        }
                        if (!isset($validationEqn[$questionNum])) {
                            $validationEqn[$questionNum] = array();
                        }
                        $validationEqn[$questionNum][] = array(
                            'qtype' => $question->type,
                            'type' => 'input_boxes',
                            'class' => 'input_boxes',
                            'eqn' => implode(' and ', $sq_equs),
                            'qid' => $questionNum,
                            'subqValidEqns' => $subqValidEqns,
                        );
                    }
                    break;
                default:
                    break;
            }
        } else {
            $input_boxes = "";
        }

        // min_answers
        // Validation:= count(sq1,...,sqN) >= value (which could be an expression).
        if (isset($qattr['min_answers']) && trim($qattr['min_answers']) != '' && trim($qattr['min_answers']) != '0') {
            $min_answers = $qattr['min_answers'];
            if ($hasSubqs) {
                $subqs = $qinfo['subqs'];
                $sq_names = array();
                foreach ($subqs as $sq) {
                    $sq_name = null;
                    switch ($question->type) {
                        case '1':   //Array (Flexible Labels) dual scale
                            if (substr($sq['varName'], -1, 1) == '0') {
                                if ($this->sgqaNaming) {
                                    $base = $sq['rowdivid'] . "#";
                                    $sq_name = "if(count(" . $base . "0.NAOK," . $base . "1.NAOK)==2,1,'')";
                                } else {
                                    $base = substr($sq['varName'], 0, -1);
                                    $sq_name = "if(count(" . $base . "0.NAOK," . $base . "1.NAOK)==2,1,'')";
                                }
                            }
                            break;
                        case ':': //ARRAY (Multi Flexi) 1 to 10
                        case ';': //ARRAY (Multi Flexi) Text
                        case 'A': //ARRAY (5 POINT CHOICE) radio-buttons
                        case 'B': //ARRAY (10 POINT CHOICE) radio-buttons
                        case 'C': //ARRAY (YES/UNCERTAIN/NO) radio-buttons
                        case 'E': //ARRAY (Increase/Same/Decrease) radio-buttons
                        case 'F': //ARRAY (Flexible) - Row Format
                        case 'K': //MULTIPLE NUMERICAL QUESTION
                        case 'Q': //MULTIPLE SHORT TEXT
                        case 'M': //Multiple choice checkbox
                        case 'R': //RANKING STYLE
                            if ($this->sgqaNaming) {
                                $sq_name = substr($sq['jsVarName'], 4) . '.NAOK';
                            } else {
                                $sq_name = $sq['varName'] . '.NAOK';
                            }
                            break;
                        case 'P': //Multiple choice with comments checkbox + text
                            if (!preg_match('/comment$/', $sq['varName'])) {
                                if ($this->sgqaNaming) {
                                    $sq_name = $sq['rowdivid'] . '.NAOK';
                                } else {
                                    $sq_name = $sq['rowdivid'] . '.NAOK';
                                }
                            }
                            break;
                        default:
                            break;
                    }
                    if (!is_null($sq_name)) {
                        $sq_names[] = $sq_name;
                    }
                }
                if (count($sq_names) > 0) {
                    if (!isset($validationEqn[$questionNum])) {
                        $validationEqn[$questionNum] = array();
                    }
                    $validationEqn[$questionNum][] = array(
                        'qtype' => $question->type,
                        'type' => 'min_answers',
                        'class' => 'num_answers',
                        'eqn' => 'if(is_empty(' . $min_answers . '),1,(count(' . implode(', ',
                                $sq_names) . ') >= (' . $min_answers . ')))',
                        'qid' => $questionNum,
                    );
                }
            }
        } else {
            $min_answers = '';
        }

        // max_answers
        // Validation:= count(sq1,...,sqN) <= value (which could be an expression).
        if (isset($qattr['max_answers']) && trim($qattr['max_answers']) != '') {
            $max_answers = $qattr['max_answers'];
            if ($hasSubqs) {
                $subqs = $qinfo['subqs'];
                $sq_names = array();
                foreach ($subqs as $sq) {
                    $sq_name = null;
                    switch ($question->type) {
                        case '1':   //Array (Flexible Labels) dual scale
                            if (substr($sq['varName'], -1, 1) == '0') {
                                if ($this->sgqaNaming) {
                                    $base = $sq['rowdivid'] . "#";
                                    $sq_name = "if(count(" . $base . "0.NAOK," . $base . "1.NAOK)==2,1,'')";
                                } else {
                                    $base = substr($sq['varName'], 0, -1);
                                    $sq_name = "if(count(" . $base . "0.NAOK," . $base . "1.NAOK)==2,1,'')";
                                }
                            }
                            break;
                        case ':': //ARRAY (Multi Flexi) 1 to 10
                        case ';': //ARRAY (Multi Flexi) Text
                        case 'A': //ARRAY (5 POINT CHOICE) radio-buttons
                        case 'B': //ARRAY (10 POINT CHOICE) radio-buttons
                        case 'C': //ARRAY (YES/UNCERTAIN/NO) radio-buttons
                        case 'E': //ARRAY (Increase/Same/Decrease) radio-buttons
                        case 'F': //ARRAY (Flexible) - Row Format
                        case 'K': //MULTIPLE NUMERICAL QUESTION
                        case 'Q': //MULTIPLE SHORT TEXT
                        case 'M': //Multiple choice checkbox
                        case 'R': //RANKING STYLE
                            if ($this->sgqaNaming) {
                                $sq_name = substr($sq['jsVarName'], 4) . '.NAOK';
                            } else {
                                $sq_name = $sq['varName'] . '.NAOK';
                            }
                            break;
                        case 'P': //Multiple choice with comments checkbox + text
                            if (!preg_match('/comment$/', $sq['varName'])) {
                                if ($this->sgqaNaming) {
                                    $sq_name = $sq['rowdivid'] . '.NAOK';
                                } else {
                                    $sq_name = $sq['varName'] . '.NAOK';
                                }
                            }
                            break;
                        default:
                            break;
                    }
                    if (!is_null($sq_name)) {
                        $sq_names[] = $sq_name;
                    }
                }
                if (count($sq_names) > 0) {
                    if (!isset($validationEqn[$questionNum])) {
                        $validationEqn[$questionNum] = array();
                    }
                    $validationEqn[$questionNum][] = array(
                        'qtype' => $question->type,
                        'type' => 'max_answers',
                        'class' => 'num_answers',
                        'eqn' => '(if(is_empty(' . $max_answers . '),1,count(' . implode(', ',
                                $sq_names) . ') <= (' . $max_answers . ')))',
                        'qid' => $questionNum,
                    );
                }
            }
        } else {
            $max_answers = '';
        }

        // Fix min_num_value_n and max_num_value_n for multinumeric with slider: see bug #7798
        if ($question->type == "K" && isset($qattr['slider_min']) && (!isset($qattr['min_num_value_n']) || trim($qattr['min_num_value_n']) == '')) {
            $qattr['min_num_value_n'] = $qattr['slider_min'];
        }
        // min_num_value_n
        // Validation:= N >= value (which could be an expression).
        if (isset($qattr['min_num_value_n']) && trim($qattr['min_num_value_n']) != '') {
            $min_num_value_n = $qattr['min_num_value_n'];
            if ($hasSubqs) {
                $subqs = $qinfo['subqs'];
                $sq_names = array();
                $subqValidEqns = array();
                foreach ($subqs as $sq) {
                    $sq_name = null;
                    switch ($question->type) {
                        case 'K': //MULTIPLE NUMERICAL QUESTION
                            if ($this->sgqaNaming) {
                                $sq_name = '(is_empty(' . $sq['rowdivid'] . '.NAOK) || ' . $sq['rowdivid'] . '.NAOK >= (' . $min_num_value_n . '))';
                            } else {
                                $sq_name = '(is_empty(' . $sq['varName'] . '.NAOK) || ' . $sq['varName'] . '.NAOK >= (' . $min_num_value_n . '))';
                            }
                            $subqValidSelector = $sq['jsVarName_on'];
                            break;
                        case 'N': //NUMERICAL QUESTION TYPE
                            if ($this->sgqaNaming) {
                                $sq_name = '(is_empty(' . $sq['rowdivid'] . '.NAOK) || ' . $sq['rowdivid'] . '.NAOK >= (' . $min_num_value_n . '))';
                            } else {
                                $sq_name = '(is_empty(' . $sq['varName'] . '.NAOK) || ' . $sq['varName'] . '.NAOK >= (' . $min_num_value_n . '))';
                            }
                            $subqValidSelector = '';
                            break;
                        default:
                            break;
                    }
                    if (!is_null($sq_name)) {
                        $sq_names[] = $sq_name;
                        $subqValidEqns[$subqValidSelector] = array(
                            'subqValidEqn' => $sq_name,
                            'subqValidSelector' => $subqValidSelector,
                        );
                    }
                }
                if (count($sq_names) > 0) {
                    if (!isset($validationEqn[$questionNum])) {
                        $validationEqn[$questionNum] = array();
                    }
                    $validationEqn[$questionNum][] = array(
                        'qtype' => $question->type,
                        'type' => 'min_num_value_n',
                        'class' => 'value_range',
                        'eqn' => implode(' && ', $sq_names),
                        'qid' => $questionNum,
                        'subqValidEqns' => $subqValidEqns,
                    );
                }
            }
        } else {
            $min_num_value_n = '';
        }

        // Fix min_num_value_n and max_num_value_n for multinumeric with slider: see bug #7798
        if ($question->type == "K" && isset($qattr['slider_max']) && (!isset($qattr['max_num_value_n']) || trim($qattr['max_num_value_n']) == '')) {
            $qattr['max_num_value_n'] = $qattr['slider_max'];
        }
        // max_num_value_n
        // Validation:= N <= value (which could be an expression).
        if (isset($qattr['max_num_value_n']) && trim($qattr['max_num_value_n']) != '') {
            $max_num_value_n = $qattr['max_num_value_n'];
            if ($hasSubqs) {
                $subqs = $qinfo['subqs'];
                $sq_names = array();
                $subqValidEqns = array();
                foreach ($subqs as $sq) {
                    $sq_name = null;
                    switch ($question->type) {
                        case 'K': //MULTIPLE NUMERICAL QUESTION
                            if ($this->sgqaNaming) {
                                $sq_name = '(is_empty(' . $sq['rowdivid'] . '.NAOK) || ' . $sq['rowdivid'] . '.NAOK <= (' . $max_num_value_n . '))';
                            } else {
                                $sq_name = '(is_empty(' . $sq['varName'] . '.NAOK) || ' . $sq['varName'] . '.NAOK <= (' . $max_num_value_n . '))';
                            }
                            $subqValidSelector = $sq['jsVarName_on'];
                            break;
                        case 'N': //NUMERICAL QUESTION TYPE
                            if ($this->sgqaNaming) {
                                $sq_name = '(is_empty(' . $sq['rowdivid'] . '.NAOK) || ' . $sq['rowdivid'] . '.NAOK <= (' . $max_num_value_n . '))';
                            } else {
                                $sq_name = '(is_empty(' . $sq['varName'] . '.NAOK) || ' . $sq['varName'] . '.NAOK <= (' . $max_num_value_n . '))';
                            }
                            $subqValidSelector = '';
                            break;
                        default:
                            break;
                    }
                    if (!is_null($sq_name)) {
                        $sq_names[] = $sq_name;
                        $subqValidEqns[$subqValidSelector] = array(
                            'subqValidEqn' => $sq_name,
                            'subqValidSelector' => $subqValidSelector,
                        );
                    }
                }
                if (count($sq_names) > 0) {
                    if (!isset($validationEqn[$questionNum])) {
                        $validationEqn[$questionNum] = array();
                    }
                    $validationEqn[$questionNum][] = array(
                        'qtype' => $question->type,
                        'type' => 'max_num_value_n',
                        'class' => 'value_range',
                        'eqn' => implode(' && ', $sq_names),
                        'qid' => $questionNum,
                        'subqValidEqns' => $subqValidEqns,
                    );
                }
            }
        } else {
            $max_num_value_n = '';
        }

        // min_num_value
        // Validation:= sum(sq1,...,sqN) >= value (which could be an expression).
        if (isset($qattr['min_num_value']) && trim($qattr['min_num_value']) != '') {
            $min_num_value = $qattr['min_num_value'];
            if ($hasSubqs) {
                $subqs = $qinfo['subqs'];
                $sq_names = array();
                foreach ($subqs as $sq) {
                    $sq_name = null;
                    switch ($question->type) {
                        case 'K': //MULTIPLE NUMERICAL QUESTION
                            if ($this->sgqaNaming) {
                                $sq_name = $sq['rowdivid'] . '.NAOK';
                            } else {
                                $sq_name = $sq['varName'] . '.NAOK';
                            }
                            break;
                        default:
                            break;
                    }
                    if (!is_null($sq_name)) {
                        $sq_names[] = $sq_name;
                    }
                }
                if (count($sq_names) > 0) {
                    if (!isset($validationEqn[$questionNum])) {
                        $validationEqn[$questionNum] = array();
                    }

                    $sumEqn = 'sum(' . implode(', ', $sq_names) . ')';
                    $precision = LEM_DEFAULT_PRECISION;
                    if (!is_null($precision)) {
                        $sumEqn = 'round(' . $sumEqn . ', ' . $precision . ')';
                    }

                    $noanswer_option = '';
                    if ($value_range_allows_missing) {
                        $noanswer_option = ' || count(' . implode(', ', $sq_names) . ') == 0';
                    }

                    $validationEqn[$questionNum][] = array(
                        'qtype' => $question->type,
                        'type' => 'min_num_value',
                        'class' => 'sum_range',
                        'eqn' => '(sum(' . implode(', ',
                                $sq_names) . ') >= (' . $min_num_value . ')' . $noanswer_option . ')',
                        'qid' => $questionNum,
                        'sumEqn' => $sumEqn,
                    );
                }
            }
        } else {
            $min_num_value = '';
        }

        // max_num_value
        // Validation:= sum(sq1,...,sqN) <= value (which could be an expression).
        if (isset($qattr['max_num_value']) && trim($qattr['max_num_value']) != '') {
            $max_num_value = $qattr['max_num_value'];
            if ($hasSubqs) {
                $subqs = $qinfo['subqs'];
                $sq_names = array();
                foreach ($subqs as $sq) {
                    $sq_name = null;
                    switch ($question->type) {
                        case 'K': //MULTIPLE NUMERICAL QUESTION
                            if ($this->sgqaNaming) {
                                $sq_name = $sq['rowdivid'] . '.NAOK';
                            } else {
                                $sq_name = $sq['varName'] . '.NAOK';
                            }
                            break;
                        default:
                            break;
                    }
                    if (!is_null($sq_name)) {
                        $sq_names[] = $sq_name;
                    }
                }
                if (count($sq_names) > 0) {
                    if (!isset($validationEqn[$questionNum])) {
                        $validationEqn[$questionNum] = array();
                    }

                    $sumEqn = 'sum(' . implode(', ', $sq_names) . ')';
                    $precision = LEM_DEFAULT_PRECISION;
                    if (!is_null($precision)) {
                        $sumEqn = 'round(' . $sumEqn . ', ' . $precision . ')';
                    }

                    $noanswer_option = '';
                    if ($value_range_allows_missing) {
                        $noanswer_option = ' || count(' . implode(', ', $sq_names) . ') == 0';
                    }

                    $validationEqn[$questionNum][] = array(
                        'qtype' => $question->type,
                        'type' => 'max_num_value',
                        'class' => 'sum_range',
                        'eqn' => '(sum(' . implode(', ',
                                $sq_names) . ') <= (' . $max_num_value . ')' . $noanswer_option . ')',
                        'qid' => $questionNum,
                        'sumEqn' => $sumEqn,
                    );
                }
            }
        } else {
            $max_num_value = '';
        }

        // multiflexible_min
        // Validation:= sqN >= value (which could be an expression).
        if (isset($qattr['multiflexible_min']) && trim($qattr['multiflexible_min']) != '' && $input_boxes) {
            $multiflexible_min = $qattr['multiflexible_min'];
            if ($hasSubqs) {
                $subqs = $qinfo['subqs'];
                $sq_names = array();
                $subqValidEqns = array();
                foreach ($subqs as $sq) {
                    $sq_name = null;
                    switch ($question->type) {
                        case ':': //MULTIPLE NUMERICAL QUESTION
                            if ($this->sgqaNaming) {
                                $sgqa = substr($sq['jsVarName'], 4);
                                $sq_name = '(is_empty(' . $sgqa . '.NAOK) || ' . $sgqa . '.NAOK >= (' . $multiflexible_min . '))';
                            } else {
                                $sq_name = '(is_empty(' . $sq['varName'] . '.NAOK) || ' . $sq['varName'] . '.NAOK >= (' . $multiflexible_min . '))';
                            }
                            $subqValidSelector = $sq['jsVarName_on'];
                            break;
                        default:
                            break;
                    }
                    if (!is_null($sq_name)) {
                        $sq_names[] = $sq_name;
                        $subqValidEqns[$subqValidSelector] = array(
                            'subqValidEqn' => $sq_name,
                            'subqValidSelector' => $subqValidSelector,
                        );
                    }
                }
                if (count($sq_names) > 0) {
                    if (!isset($validationEqn[$questionNum])) {
                        $validationEqn[$questionNum] = array();
                    }
                    $validationEqn[$questionNum][] = array(
                        'qtype' => $question->type,
                        'type' => 'multiflexible_min',
                        'class' => 'value_range',
                        'eqn' => implode(' && ', $sq_names),
                        'qid' => $questionNum,
                        'subqValidEqns' => $subqValidEqns,
                    );
                }
            }
        } else {
            $multiflexible_min = '';
        }

        // multiflexible_max
        // Validation:= sqN <= value (which could be an expression).
        if (isset($qattr['multiflexible_max']) && trim($qattr['multiflexible_max']) != '' && $input_boxes) {
            $multiflexible_max = $qattr['multiflexible_max'];
            if ($hasSubqs) {
                $subqs = $qinfo['subqs'];
                $sq_names = array();
                $subqValidEqns = array();
                foreach ($subqs as $sq) {
                    $sq_name = null;
                    switch ($question->type) {
                        case ':': //MULTIPLE NUMERICAL QUESTION
                            if ($this->sgqaNaming) {
                                $sgqa = substr($sq['jsVarName'], 4);
                                $sq_name = '(is_empty(' . $sgqa . '.NAOK) || ' . $sgqa . '.NAOK <= (' . $multiflexible_max . '))';
                            } else {
                                $sq_name = '(is_empty(' . $sq['varName'] . '.NAOK) || ' . $sq['varName'] . '.NAOK <= (' . $multiflexible_max . '))';
                            }
                            $subqValidSelector = $sq['jsVarName_on'];
                            break;
                        default:
                            break;
                    }
                    if (!is_null($sq_name)) {
                        $sq_names[] = $sq_name;
                        $subqValidEqns[$subqValidSelector] = array(
                            'subqValidEqn' => $sq_name,
                            'subqValidSelector' => $subqValidSelector,
                        );
                    }
                }
                if (count($sq_names) > 0) {
                    if (!isset($validationEqn[$questionNum])) {
                        $validationEqn[$questionNum] = array();
                    }
                    $validationEqn[$questionNum][] = array(
                        'qtype' => $question->type,
                        'type' => 'multiflexible_max',
                        'class' => 'value_range',
                        'eqn' => implode(' && ', $sq_names),
                        'qid' => $questionNum,
                        'subqValidEqns' => $subqValidEqns,
                    );
                }
            }
        } else {
            $multiflexible_max = '';
        }

        // min_num_of_files
        // Validation:= sq_filecount >= value (which could be an expression).
        if (isset($qattr['min_num_of_files']) && trim($qattr['min_num_of_files']) != '' && trim($qattr['min_num_of_files']) != '0') {
            $min_num_of_files = $qattr['min_num_of_files'];

            $eqn = '';
            $sgqa = $qinfo['sgqa'];
            switch ($question->type) {
                case '|': //List - dropdown
                    $eqn = "(" . $sgqa . "_filecount >= (" . $min_num_of_files . "))";
                    break;
                default:
                    break;
            }
            if ($eqn != '') {
                if (!isset($validationEqn[$questionNum])) {
                    $validationEqn[$questionNum] = array();
                }
                $validationEqn[$questionNum][] = array(
                    'qtype' => $question->type,
                    'type' => 'min_num_of_files',
                    'class' => 'num_answers',
                    'eqn' => $eqn,
                    'qid' => $questionNum,
                );
            }
        } else {
            $min_num_of_files = '';
        }
        // max_num_of_files
        // Validation:= sq_filecount <= value (which could be an expression).
        if (isset($qattr['max_num_of_files']) && trim($qattr['max_num_of_files']) != '') {
            $max_num_of_files = $qattr['max_num_of_files'];
            $eqn = '';
            $sgqa = $qinfo['sgqa'];
            switch ($question->type) {
                case '|': //List - dropdown
                    $eqn = "(" . $sgqa . "_filecount <= (" . $max_num_of_files . "))";
                    break;
                default:
                    break;
            }
            if ($eqn != '') {
                if (!isset($validationEqn[$questionNum])) {
                    $validationEqn[$questionNum] = array();
                }
                $validationEqn[$questionNum][] = array(
                    'qtype' => $question->type,
                    'type' => 'max_num_of_files',
                    'class' => 'num_answers',
                    'eqn' => $eqn,
                    'qid' => $questionNum,
                );
            }
        } else {
            $max_num_of_files = '';
        }

        // num_value_int_only
        // Validation fixnum(sqN)==int(fixnum(sqN)) : fixnum or not fix num ..... 10.00 == 10
        if (isset($qattr['num_value_int_only']) && trim($qattr['num_value_int_only']) == "1") {
            $num_value_int_only = "1";
            if ($hasSubqs) {
                $subqs = $qinfo['subqs'];
                $sq_eqns = array();
                $subqValidEqns = array();
                foreach ($subqs as $sq) {
                    $sq_eqn = null;
                    $subqValidSelector = '';
                    switch ($question->type) {
                        case 'K': //MULTI NUMERICAL QUESTION TYPE (Need a attribute, not set in 131014)
                            $subqValidSelector = $sq['jsVarName_on'];
                        case 'N': //NUMERICAL QUESTION TYPE
                            $sq_name = ($this->sgqaNaming) ? $sq['rowdivid'] . ".NAOK" : $sq['varName'] . ".NAOK";
                            $sq_eqn = 'is_int(' . $sq_name . ') || is_empty(' . $sq_name . ')';
                            break;
                        default:
                            break;
                    }
                    if (!is_null($sq_eqn)) {
                        $sq_eqns[] = $sq_eqn;
                        $subqValidEqns[$subqValidSelector] = array(
                            'subqValidEqn' => $sq_eqn,
                            'subqValidSelector' => $subqValidSelector,
                        );
                    }
                }
                if (count($sq_eqns) > 0) {
                    if (!isset($validationEqn[$questionNum])) {
                        $validationEqn[$questionNum] = array();
                    }
                    $validationEqn[$questionNum][] = array(
                        'qtype' => $question->type,
                        'type' => 'num_value_int_only',
                        'class' => 'value_integer',
                        'eqn' => implode(' and ', $sq_eqns),
                        'qid' => $questionNum,
                        'subqValidEqns' => $subqValidEqns,
                    );
                }
            }
        } else {
            $num_value_int_only = '';
        }

        // num_value_int_only
        // Validation is_numeric(sqN)
        if (isset($qattr['numbers_only']) && trim($qattr['numbers_only']) == "1") {
            $numbers_only = 1;
            switch ($question->type) {
                case 'S': // Short text
                    if ($hasSubqs) {
                        $subqs = $qinfo['subqs'];
                        $sq_equs = array();
                        foreach ($subqs as $sq) {
                            $sq_name = ($this->sgqaNaming) ? $sq['rowdivid'] . ".NAOK" : $sq['varName'] . ".NAOK";
                            $sq_equs[] = '( is_numeric(' . $sq_name . ') || is_empty(' . $sq_name . ') )';
                        }
                        if (!isset($validationEqn[$questionNum])) {
                            $validationEqn[$questionNum] = array();
                        }
                        $validationEqn[$questionNum][] = array(
                            'qtype' => $question->type,
                            'type' => 'numbers_only',
                            'class' => 'numbers_only',
                            'eqn' => implode(' and ', $sq_equs),
                            'qid' => $questionNum,
                        );
                    }
                    break;
                case 'Q': // multi text
                    if ($hasSubqs) {
                        $subqs = $qinfo['subqs'];
                        $sq_equs = array();
                        $subqValidEqns = array();
                        foreach ($subqs as $sq) {
                            $sq_name = ($this->sgqaNaming) ? $sq['rowdivid'] . ".NAOK" : $sq['varName'] . ".NAOK";
                            $sq_equ = '( is_numeric(' . $sq_name . ') || is_empty(' . $sq_name . ') )';// Leave mandatory to mandatory attribute
                            $subqValidSelector = $sq['jsVarName_on'];
                            if (!is_null($sq_name)) {
                                $sq_equs[] = $sq_equ;
                                $subqValidEqns[$subqValidSelector] = array(
                                    'subqValidEqn' => $sq_equ,
                                    'subqValidSelector' => $subqValidSelector,
                                );
                            }
                        }
                        if (!isset($validationEqn[$questionNum])) {
                            $validationEqn[$questionNum] = array();
                        }
                        $validationEqn[$questionNum][] = array(
                            'qtype' => $question->type,
                            'type' => 'numbers_only',
                            'class' => 'numbers_only',
                            'eqn' => implode(' and ', $sq_equs),
                            'qid' => $questionNum,
                            'subqValidEqns' => $subqValidEqns,
                        );
                    }
                    break;
                case ';': // Array of text
                    if ($hasSubqs) {
                        $subqs = $qinfo['subqs'];
                        $sq_equs = array();
                        $subqValidEqns = array();
                        foreach ($subqs as $sq) {
                            $sq_name = ($this->sgqaNaming) ? substr($sq['jsVarName'],
                                    4) . ".NAOK" : $sq['varName'] . ".NAOK";
                            $sq_equ = '( is_numeric(' . $sq_name . ') || is_empty(' . $sq_name . ') )';// Leave mandatory to mandatory attribute
                            $subqValidSelector = $sq['jsVarName_on'];
                            if (!is_null($sq_name)) {
                                $sq_equs[] = $sq_equ;
                                $subqValidEqns[$subqValidSelector] = array(
                                    'subqValidEqn' => $sq_equ,
                                    'subqValidSelector' => $subqValidSelector,
                                );
                            }
                        }
                        if (!isset($validationEqn[$questionNum])) {
                            $validationEqn[$questionNum] = array();
                        }
                        $validationEqn[$questionNum][] = array(
                            'qtype' => $question->type,
                            'type' => 'numbers_only',
                            'class' => 'numbers_only',
                            'eqn' => implode(' and ', $sq_equs),
                            'qid' => $questionNum,
                            'subqValidEqns' => $subqValidEqns,
                        );
                    }
                    break;
                case '*': // Don't think we need equation ?
                default:
                    break;
            }
        } else {
            $numbers_only = "";
        }

        // other_comment_mandatory
        // Validation:= sqN <= value (which could be an expression).
        if (isset($qattr['other_comment_mandatory']) && trim($qattr['other_comment_mandatory']) == '1') {
            $other_comment_mandatory = $qattr['other_comment_mandatory'];
            $eqn = '';
            if ($other_comment_mandatory == '1' && $this->questionSeq2relevance[$session->getQuestionIndex($question->primaryKey)]['other'] == 'Y') {
                $sgqa = $qinfo['sgqa'];
                switch ($question->type) {
                    case '!': //List - dropdown
                    case 'L': //LIST drop-down/radio-button list
                        $eqn = "(" . $sgqa . ".NAOK!='-oth-' || (" . $sgqa . ".NAOK=='-oth-' && !is_empty(trim(" . $sgqa . "other.NAOK))))";
                        break;
                    case 'P': //Multiple choice with comments
                        $eqn = "(is_empty(trim(" . $sgqa . "other.NAOK)) || (!is_empty(trim(" . $sgqa . "other.NAOK)) && !is_empty(trim(" . $sgqa . "othercomment.NAOK))))";
                        break;
                    default:
                        break;
                }
            }
            if ($eqn != '') {
                if (!isset($validationEqn[$questionNum])) {
                    $validationEqn[$questionNum] = array();
                }
                $validationEqn[$questionNum][] = array(
                    'qtype' => $question->type,
                    'type' => 'other_comment_mandatory',
                    'class' => 'other_comment_mandatory',
                    'eqn' => $eqn,
                    'qid' => $questionNum,
                );
            }
        } else {
            $other_comment_mandatory = '';
        }

        // other_numbers_only
        // Validation:= is_numeric(sqN).
        if (isset($qattr['other_numbers_only']) && trim($qattr['other_numbers_only']) == '1') {
            $other_numbers_only = 1;
            $eqn = '';
            if ($this->questionSeq2relevance[$session->getQuestionIndex($question->primaryKey)]['other'] == 'Y') {
                $sgqa = $qinfo['sgqa'];
                switch ($question->type) {
                    //case '!': //List - dropdown
                    case 'L': //LIST drop-down/radio-button list
                    case 'M': //Multiple choice
                    case 'P': //Multiple choice with
                        $eqn = "(is_empty(trim(" . $sgqa . "other.NAOK)) ||is_numeric(" . $sgqa . "other.NAOK))";
                        break;
                    default:
                        break;
                }
            }
            if ($eqn != '') {
                if (!isset($validationEqn[$questionNum])) {
                    $validationEqn[$questionNum] = array();
                }
                $validationEqn[$questionNum][] = array(
                    'qtype' => $question->type,
                    'type' => 'other_numbers_only',
                    'class' => 'other_numbers_only',
                    'eqn' => $eqn,
                    'qid' => $questionNum,
                );
            }
        } else {
            $other_numbers_only = '';
        }


        // show_totals
        // TODO - create equations for these?

        // assessment_value
        // TODO?  How does it work?
        // The assessment value (referenced how?) = count(sq1,...,sqN) * assessment_value
        // Since there are easy work-arounds to this, skipping it for now

        // preg - a PHP Regular Expression to validate text input fields
        if (isset($qinfo['preg']) && !is_null($qinfo['preg'])) {
            $preg = $qinfo['preg'];
            if ($hasSubqs) {
                $subqs = $qinfo['subqs'];
                $sq_names = array();
                $subqValidEqns = array();
                foreach ($subqs as $sq) {
                    $sq_name = null;
                    $subqValidSelector = null;
                    $sgqa = substr($sq['jsVarName'], 4);
                    switch ($question->type) {
                        case 'N': //NUMERICAL QUESTION TYPE
                        case 'K': //MULTIPLE NUMERICAL QUESTION
                        case 'Q': //MULTIPLE SHORT TEXT
                        case ';': //ARRAY (Multi Flexi) Text
                        case ':': //ARRAY (Multi Flexi) 1 to 10
                        case 'S': //SHORT FREE TEXT
                        case 'T': //LONG FREE TEXT
                        case 'U': //HUGE FREE TEXT
                            if ($this->sgqaNaming) {
                                $sq_name = '(if(is_empty(' . $sgqa . '.NAOK),0,!regexMatch("' . $preg . '", ' . $sgqa . '.NAOK)))';
                            } else {
                                $sq_name = '(if(is_empty(' . $sq['varName'] . '.NAOK),0,!regexMatch("' . $preg . '", ' . $sq['varName'] . '.NAOK)))';
                            }
                            break;
                        default:
                            break;
                    }
                    switch ($question->type) {
                        case 'K': //MULTIPLE NUMERICAL QUESTION
                        case 'Q': //MULTIPLE SHORT TEXT
                        case ';': //ARRAY (Multi Flexi) Text
                        case ':': //ARRAY (Multi Flexi) 1 to 10
                            if ($this->sgqaNaming) {
                                $subqValidEqn = '(is_empty(' . $sgqa . '.NAOK) || regexMatch("' . $preg . '", ' . $sgqa . '.NAOK))';
                            } else {
                                $subqValidEqn = '(is_empty(' . $sq['varName'] . '.NAOK) || regexMatch("' . $preg . '", ' . $sq['varName'] . '.NAOK))';
                            }
                            $subqValidSelector = $sq['jsVarName_on'];
                            break;
                        default:
                            break;
                    }
                    if (!is_null($sq_name)) {
                        $sq_names[] = $sq_name;
                        if (isset($subqValidSelector)) {
                            $subqValidEqns[$subqValidSelector] = array(
                                'subqValidEqn' => $subqValidEqn,
                                'subqValidSelector' => $subqValidSelector,
                            );
                        }
                    }
                }
                if (count($sq_names) > 0) {
                    if (!isset($validationEqn[$questionNum])) {
                        $validationEqn[$questionNum] = array();
                    }
                    $validationEqn[$questionNum][] = array(
                        'qtype' => $question->type,
                        'type' => 'preg',
                        'class' => 'regex_validation',
                        'eqn' => '(sum(' . implode(', ', $sq_names) . ') == 0)',
                        'qid' => $questionNum,
                        'subqValidEqns' => $subqValidEqns,
                    );
                }
            }
        } else {
            $preg = '';
        }

        // em_validation_q_tip - a description of the EM validation equation that must be satisfied for the whole question.
        if (isset($qattr['em_validation_q_tip']) && !is_null($qattr['em_validation_q_tip']) && trim($qattr['em_validation_q_tip']) != '') {
            $em_validation_q_tip = trim($qattr['em_validation_q_tip']);
        } else {
            $em_validation_q_tip = '';
        }


        // em_validation_q - an EM validation equation that must be satisfied for the whole question.  Uses 'this' in the equation
        if (isset($qattr['em_validation_q']) && !is_null($qattr['em_validation_q']) && trim($qattr['em_validation_q']) != '') {
            $em_validation_q = $qattr['em_validation_q'];
            if ($hasSubqs) {
                $subqs = $qinfo['subqs'];
                $sq_names = array();
                foreach ($subqs as $sq) {
                    $sq_name = null;
                    switch ($question->type) {
                        case 'A': //ARRAY (5 POINT CHOICE) radio-buttons
                        case 'B': //ARRAY (10 POINT CHOICE) radio-buttons
                        case 'C': //ARRAY (YES/UNCERTAIN/NO) radio-buttons
                        case 'E': //ARRAY (Increase/Same/Decrease) radio-buttons
                        case 'F': //ARRAY (Flexible) - Row Format
                        case 'K': //MULTIPLE NUMERICAL QUESTION
                        case 'Q': //MULTIPLE SHORT TEXT
                        case ';': //ARRAY (Multi Flexi) Text
                        case ':': //ARRAY (Multi Flexi) 1 to 10
                        case 'M': //Multiple choice checkbox
                        case 'N': //NUMERICAL QUESTION TYPE
                        case 'O':
                        case 'P': //Multiple choice with comments checkbox + text
                        case 'R': //RANKING STYLE
                        case 'S': //SHORT FREE TEXT
                        case 'T': //LONG FREE TEXT
                        case 'U': //HUGE FREE TEXT
                        case 'D': //DATE
                            if ($this->sgqaNaming) {
                                $sq_name = '!(' . preg_replace('/\bthis\b/', substr($sq['jsVarName'], 4),
                                        $em_validation_q) . ')';
                            } else {
                                $sq_name = '!(' . preg_replace('/\bthis\b/', $sq['varName'],
                                        $em_validation_q) . ')';
                            }
                            break;
                        default:
                            break;
                    }
                    if (!is_null($sq_name)) {
                        $sq_names[] = $sq_name;
                    }
                }
                if (count($sq_names) > 0) {
                    if (!isset($validationEqn[$questionNum])) {
                        $validationEqn[$questionNum] = array();
                    }
                    $validationEqn[$questionNum][] = array(
                        'qtype' => $question->type,
                        'type' => 'em_validation_q',
                        'class' => 'q_fn_validation',
                        'eqn' => '(sum(' . implode(', ', array_unique($sq_names)) . ') == 0)',
                        'qid' => $questionNum,
                    );
                }
            }
        } else {
            $em_validation_q = '';
        }

        // em_validation_sq_tip - a description of the EM validation equation that must be satisfied for each subquestion.
        if (isset($qattr['em_validation_sq_tip']) && !is_null($qattr['em_validation_sq_tip']) && trim($qattr['em_validation_sq']) != '') {
            $em_validation_sq_tip = trim($qattr['em_validation_sq_tip']);
        } else {
            $em_validation_sq_tip = '';
        }


        // em_validation_sq - an EM validation equation that must be satisfied for each subquestion.  Uses 'this' in the equation
        if (isset($qattr['em_validation_sq']) && !is_null($qattr['em_validation_sq']) && trim($qattr['em_validation_sq']) != '') {
            $em_validation_sq = $qattr['em_validation_sq'];
            if ($hasSubqs) {
                $subqs = $qinfo['subqs'];
                $sq_names = array();
                $subqValidEqns = array();
                foreach ($subqs as $sq) {
                    $sq_name = null;
                    switch ($question->type) {
                        case 'K': //MULTIPLE NUMERICAL QUESTION
                        case 'Q': //MULTIPLE SHORT TEXT
                        case ';': //ARRAY (Multi Flexi) Text
                        case ':': //ARRAY (Multi Flexi) 1 to 10
                        case 'N': //NUMERICAL QUESTION TYPE
                        case 'S': //SHORT FREE TEXT
                        case 'T': //LONG FREE TEXT
                        case 'U': //HUGE FREE TEXT
                            if ($this->sgqaNaming) {
                                $sq_name = '!(' . preg_replace('/\bthis\b/', substr($sq['jsVarName'], 4),
                                        $em_validation_sq) . ')';
                            } else {
                                $sq_name = '!(' . preg_replace('/\bthis\b/', $sq['varName'],
                                        $em_validation_sq) . ')';
                            }
                            break;
                        default:
                            break;
                    }
                    switch ($question->type) {
                        case 'K': //MULTIPLE NUMERICAL QUESTION
                        case 'Q': //MULTIPLE SHORT TEXT
                        case ';': //ARRAY (Multi Flexi) Text
                        case ':': //ARRAY (Multi Flexi) 1 to 10
                        case 'N': //NUMERICAL QUESTION TYPE
                        case 'S': //SHORT FREE TEXT
                        case 'T': //LONG FREE TEXT
                        case 'U': //HUGE FREE TEXT
                            if ($this->sgqaNaming) {
                                $subqValidEqn = '(' . preg_replace('/\bthis\b/', substr($sq['jsVarName'], 4),
                                        $em_validation_sq) . ')';
                            } else {
                                $subqValidEqn = '(' . preg_replace('/\bthis\b/', $sq['varName'],
                                        $em_validation_sq) . ')';
                            }
                            $subqValidSelector = $sq['jsVarName_on'];
                            break;
                        default:
                            break;
                    }
                    if (!is_null($sq_name)) {
                        $sq_names[] = $sq_name;
                        if (isset($subqValidSelector)) {
                            $subqValidEqns[$subqValidSelector] = array(
                                'subqValidEqn' => $subqValidEqn,
                                'subqValidSelector' => $subqValidSelector,
                            );
                        }
                    }
                }
                if (count($sq_names) > 0) {
                    if (!isset($validationEqn[$questionNum])) {
                        $validationEqn[$questionNum] = array();
                    }
                    $validationEqn[$questionNum][] = array(
                        'qtype' => $question->type,
                        'type' => 'em_validation_sq',
                        'class' => 'sq_fn_validation',
                        'eqn' => '(sum(' . implode(', ', $sq_names) . ') == 0)',
                        'qid' => $questionNum,
                        'subqValidEqns' => $subqValidEqns,
                    );
                }
            }
        } else {
            $em_validation_sq = '';
        }

        ////////////////////////////////////////////
        // COMPOSE USER FRIENDLY MIN/MAX MESSAGES //
        ////////////////////////////////////////////

        // Put these in the order you with them to appear in messages.
        $qtips = array();

        // Default validation qtip without attribute
        switch ($question->type) {
            case 'N':
                $qtips['default'] = gT("Only numbers may be entered in this field.");
                break;
            case 'K':
                $qtips['default'] = gT("Only numbers may be entered in these fields.");
                break;
            case 'R':
                $qtips['default'] = gT("All your answers must be different and you must rank in order.");
                break;
// Helptext is added in qanda_help.php
            /*                  case 'D':
                $qtips['default']=gT("Please complete all parts of the date.");
                break;
*/
            default:
                break;
        }

        if (isset($question->commented_checkbox)) {
            switch ($question->commented_checkbox) {
                case 'checked':
                    $qtips['commented_checkbox'] = gT("Comment only when you choose an answer.");
                    break;
                case 'unchecked':
                    $qtips['commented_checkbox'] = gT("Comment only when you don't choose an answer.");
                    break;
                case 'allways':
                default:
                    $qtips['commented_checkbox'] = gT("Comment your answers.");
                    break;
            }
        }

        // equals_num_value
        if ($equals_num_value != '') {
            $qtips['sum_range'] = sprintf(gT("The sum must equal %s."),
                '{fixnum(' . $equals_num_value . ')}');
        }

        if ($input_boxes && $question->type == Question::TYPE_ARRAY_NUMBERS) {
            $qtips['input_boxes'] = gT("Only numbers may be entered in these fields.");
        }

        // min/max answers
        if ($min_answers != '' || $max_answers != '') {
            $_minA = (($min_answers == '') ? "''" : $min_answers);
            $_maxA = (($max_answers == '') ? "''" : $max_answers);
            /* different messages for text and checkbox questions */
            if ($question->type == 'Q' || $question->type == 'K' || $question->type == ';' || $question->type == ':') {
                $_msgs = array(
                    'atleast_m' => gT("Please fill in at least %s answers"),
                    'atleast_1' => gT("Please fill in at least one answer"),
                    'atmost_m' => gT("Please fill in at most %s answers"),
                    'atmost_1' => gT("Please fill in at most one answer"),
                    '1' => gT("Please fill in at most one answer"),
                    'n' => gT("Please fill in %s answers"),
                    'between' => gT("Please fill in between %s and %s answers")
                );
            } else {
                $_msgs = array(
                    'atleast_m' => gT("Please select at least %s answers"),
                    'atleast_1' => gT("Please select at least one answer"),
                    'atmost_m' => gT("Please select at most %s answers"),
                    'atmost_1' => gT("Please select at most one answer"),
                    '1' => gT("Please select one answer"),
                    'n' => gT("Please select %s answers"),
                    'between' => gT("Please select between %s and %s answers")
                );
            }
            $qtips['num_answers'] =
                "{if(!is_empty($_minA) && is_empty($_maxA) && ($_minA)!=1,sprintf('" . $_msgs['atleast_m'] . "',fixnum($_minA)),'')}" .
                "{if(!is_empty($_minA) && is_empty($_maxA) && ($_minA)==1,sprintf('" . $_msgs['atleast_1'] . "',fixnum($_minA)),'')}" .
                "{if(is_empty($_minA) && !is_empty($_maxA) && ($_maxA)!=1,sprintf('" . $_msgs['atmost_m'] . "',fixnum($_maxA)),'')}" .
                "{if(is_empty($_minA) && !is_empty($_maxA) && ($_maxA)==1,sprintf('" . $_msgs['atmost_1'] . "',fixnum($_maxA)),'')}" .
                "{if(!is_empty($_minA) && !is_empty($_maxA) && ($_minA) == ($_maxA) && ($_minA) == 1,'" . $_msgs['1'] . "','')}" .
                "{if(!is_empty($_minA) && !is_empty($_maxA) && ($_minA) == ($_maxA) && ($_minA) != 1,sprintf('" . $_msgs['n'] . "',fixnum($_minA)),'')}" .
                "{if(!is_empty($_minA) && !is_empty($_maxA) && ($_minA) != ($_maxA),sprintf('" . $_msgs['between'] . "',fixnum($_minA),fixnum($_maxA)),'')}";
        }

        // min/max value for each numeric entry
        if ($min_num_value_n != '' || $max_num_value_n != '') {
            $_minV = (($min_num_value_n == '') ? "''" : $min_num_value_n);
            $_maxV = (($max_num_value_n == '') ? "''" : $max_num_value_n);
            if ($question->type != 'N') {
                $qtips['value_range'] =
                    "{if(!is_empty($_minV) && is_empty($_maxV), sprintf('" . gT("Each answer must be at least %s") . "',fixnum($_minV)), '')}" .
                    "{if(is_empty($_minV) && !is_empty($_maxV), sprintf('" . gT("Each answer must be at most %s") . "',fixnum($_maxV)), '')}" .
                    "{if(!is_empty($_minV) && ($_minV) == ($_maxV),sprintf('" . gT("Each answer must be %s") . "', fixnum($_minV)), '')}" .
                    "{if(!is_empty($_minV) && !is_empty($_maxV) && ($_minV) != ($_maxV), sprintf('" . gT("Each answer must be between %s and %s") . "', fixnum($_minV), fixnum($_maxV)), '')}";
            } else {
                $qtips['value_range'] =
                    "{if(!is_empty($_minV) && is_empty($_maxV), sprintf('" . gT("Your answer must be at least %s") . "',fixnum($_minV)), '')}" .
                    "{if(is_empty($_minV) && !is_empty($_maxV), sprintf('" . gT("Your answer must be at most %s") . "',fixnum($_maxV)), '')}" .
                    "{if(!is_empty($_minV) && ($_minV) == ($_maxV),sprintf('" . gT("Your answer must be %s") . "', fixnum($_minV)), '')}" .
                    "{if(!is_empty($_minV) && !is_empty($_maxV) && ($_minV) != ($_maxV), sprintf('" . gT("Your answer must be between %s and %s") . "', fixnum($_minV), fixnum($_maxV)), '')}";
            }
        }

        // min/max value for dates
        if ($date_min != '' || $date_max != '') {
            //Get date format of current question and convert date in help text accordingly
            $LEM =& LimeExpressionManager::singleton();
            $aAttributes = $session->getQuestion($questionNum)->questionAttributes;
            $aDateFormatData = getDateFormatDataForQID($aAttributes[$questionNum], $LEM->surveyOptions);
            $_minV = (($date_min == '') ? "''" : "if((strtotime(" . $date_min . ")), date('" . $aDateFormatData['phpdate'] . "', strtotime(" . $date_min . ")),'')");
            $_maxV = (($date_max == '') ? "''" : "if((strtotime(" . $date_max . ")), date('" . $aDateFormatData['phpdate'] . "', strtotime(" . $date_max . ")),'')");
            $qtips['value_range'] =
                "{if(!is_empty($_minV) && is_empty($_maxV), sprintf('" . gT("Answer must be greater or equal to %s") . "',$_minV), '')}" .
                "{if(is_empty($_minV) && !is_empty($_maxV), sprintf('" . gT("Answer must be less or equal to %s") . "',$_maxV), '')}" .
                "{if(!is_empty($_minV) && ($_minV) == ($_maxV),sprintf('" . gT("Answer must be %s") . "', $_minV), '')}" .
                "{if(!is_empty($_minV) && !is_empty($_maxV) && ($_minV) != ($_maxV), sprintf('" . gT("Answer must be between %s and %s") . "', ($_minV), ($_maxV)), '')}";
        }

        // min/max value for each numeric entry - for multi-flexible question type
        if ($multiflexible_min != '' || $multiflexible_max != '') {
            $_minV = (($multiflexible_min == '') ? "''" : $multiflexible_min);
            $_maxV = (($multiflexible_max == '') ? "''" : $multiflexible_max);
            $qtips['value_range'] =
                "{if(!is_empty($_minV) && is_empty($_maxV), sprintf('" . gT("Each answer must be at least %s") . "',fixnum($_minV)), '')}" .
                "{if(is_empty($_minV) && !is_empty($_maxV), sprintf('" . gT("Each answer must be at most %s") . "',fixnum($_maxV)), '')}" .
                "{if(!is_empty($_minV) && ($_minV) == ($_maxV),sprintf('" . gT("Each answer must be %s") . "', fixnum($_minV)), '')}" .
                "{if(!is_empty($_minV) && !is_empty($_maxV) && ($_minV) != ($_maxV), sprintf('" . gT("Each answer must be between %s and %s") . "', fixnum($_minV), fixnum($_maxV)), '')}";
        }

        // min/max sum value
        if ($min_num_value != '' || $max_num_value != '') {
            $_minV = (($min_num_value == '') ? "''" : $min_num_value);
            $_maxV = (($max_num_value == '') ? "''" : $max_num_value);
            $qtips['sum_range'] =
                "{if(!is_empty($_minV) && is_empty($_maxV), sprintf('" . gT("The sum must be at least %s") . "',fixnum($_minV)), '')}" .
                "{if(is_empty($_minV) && !is_empty($_maxV), sprintf('" . gT("The sum must be at most %s") . "',fixnum($_maxV)), '')}" .
                "{if(!is_empty($_minV) && ($_minV) == ($_maxV),sprintf('" . gT("The sum must equal %s") . "', fixnum($_minV)), '')}" .
                "{if(!is_empty($_minV) && !is_empty($_maxV) && ($_minV) != ($_maxV), sprintf('" . gT("The sum must be between %s and %s") . "', fixnum($_minV), fixnum($_maxV)), '')}";
        }

        // min/max num files
        if ($min_num_of_files != '' || $max_num_of_files != '') {
            $_minA = (($min_num_of_files == '') ? "''" : $min_num_of_files);
            $_maxA = (($max_num_of_files == '') ? "''" : $max_num_of_files);
            // TODO - create em_num_files class so can sepately style num_files vs. num_answers
            $qtips['num_answers'] =
                "{if(!is_empty($_minA) && is_empty($_maxA) && ($_minA)!=1,sprintf('" . gT("Please upload at least %s files") . "',fixnum($_minA)),'')}" .
                "{if(!is_empty($_minA) && is_empty($_maxA) && ($_minA)==1,sprintf('" . gT("Please upload at least one file") . "',fixnum($_minA)),'')}" .
                "{if(is_empty($_minA) && !is_empty($_maxA) && ($_maxA)!=1,sprintf('" . gT("Please upload at most %s files") . "',fixnum($_maxA)),'')}" .
                "{if(is_empty($_minA) && !is_empty($_maxA) && ($_maxA)==1,sprintf('" . gT("Please upload at most one file") . "',fixnum($_maxA)),'')}" .
                "{if(!is_empty($_minA) && !is_empty($_maxA) && ($_minA) == ($_maxA) && ($_minA) == 1,'" . gT("Please upload one file") . "','')}" .
                "{if(!is_empty($_minA) && !is_empty($_maxA) && ($_minA) == ($_maxA) && ($_minA) != 1,sprintf('" . gT("Please upload %s files") . "',fixnum($_minA)),'')}" .
                "{if(!is_empty($_minA) && !is_empty($_maxA) && ($_minA) != ($_maxA),sprintf('" . gT("Please upload between %s and %s files") . "',fixnum($_minA),fixnum($_maxA)),'')}";
        }


        // integer for numeric
        if ($num_value_int_only != '') {
            switch ($question->type) {
                case 'N':
                    $qtips['default'] = '';
                    $qtips['value_integer'] = gT("Only an integer value may be entered in this field.");
                    break;
                case 'K':
                    $qtips['default'] = '';
                    $qtips['value_integer'] = gT("Only integer values may be entered in these fields.");
                    break;
                default:
                    break;
            }
        }

        // numbers only
        if ($numbers_only) {
            switch ($question->type) {
                case 'S':
                    $qtips['numbers_only'] = gT("Only numbers may be entered in this field.");
                    break;
                case 'Q':
                case ';':
                    $qtips['numbers_only'] = gT("Only numbers may be entered in these fields.");
                    break;
                default:
                    break;
            }
        }

        // other comment mandatory
        if ($other_comment_mandatory != '') {
            if (isset($qattr['other_replace_text']) && trim($qattr['other_replace_text']) != '') {
                $othertext = trim($qattr['other_replace_text']);
            } else {
                $othertext = gT('Other:');
            }
            $qtips['other_comment_mandatory'] = sprintf(gT("If you choose '%s' please also specify your choice in the accompanying text field."),
                $othertext);
        }

        // other comment mandatory
        if ($other_numbers_only != '') {
            if (isset($qattr['other_replace_text']) && trim($qattr['other_replace_text']) != '') {
                $othertext = trim($qattr['other_replace_text']);
            } else {
                $othertext = gT('Other:');
            }
            $qtips['other_numbers_only'] = sprintf(gT("Only numbers may be entered in '%s' accompanying text field."),
                $othertext);
        }

        // regular expression validation
        if ($preg != '') {
            // do string replacement here so that curly braces within the regular expression don't trigger an EM error
            //                $qtips['regex_validation']=sprintf(gT('Each answer must conform to this regular expression: %s'), str_replace(array('{','}'),array('{ ',' }'), $preg));
            $qtips['regex_validation'] = gT('Please check the format of your answer.');
        }

        if ($em_validation_sq != '') {
            if ($em_validation_sq_tip == '') {
                //                    $stringToParse = htmlspecialchars_decode($em_validation_sq,ENT_QUOTES);
                //                    $gseq = $this->questionId2groupSeq[$question->primaryKey];
                //                    $result = $this->em->ProcessBooleanExpression($stringToParse,$gseq,  $session->getQuestionIndex($question->primaryKey));
                //                    $_validation_tip = $this->em->GetPrettyPrintString();
                //                    $qtips['sq_fn_validation']=sprintf(gT('Each answer must conform to this expression: %s'),$_validation_tip);
            } else {
                $qtips['sq_fn_validation'] = $em_validation_sq_tip;
            }

            }

            // em_validation_q - whole-question validation equation
            if ($em_validation_q != '') {
                if ($em_validation_q_tip == '') {
                    //                    $stringToParse = htmlspecialchars_decode($em_validation_q,ENT_QUOTES);
                    //                    $gseq = $this->questionId2groupSeq[$question->primaryKey];
                    //                    $result = $this->em->ProcessBooleanExpression($stringToParse,$gseq,  $session->getQuestionIndex($question->primaryKey));
                    //                    $_validation_tip = $this->em->GetPrettyPrintString();
                    //                    $qtips['q_fn_validation']=sprintf(gT('The question must conform to this expression: %s'), $_validation_tip);
                } else {
                    $qtips['q_fn_validation'] = $em_validation_q_tip;
                }
            }

            if (count($qtips) > 0) {
                $validationTips[$questionNum] = $qtips;
            }


            // Consolidate logic across array filters
            $rowdivids = array();
            $order = 0;
            foreach ($subQrels as $sq) {
                $oldeqn = (isset($rowdivids[$sq['rowdivid']]['eqns']) ? $rowdivids[$sq['rowdivid']]['eqns'] : array());
                $oldtype = (isset($rowdivids[$sq['rowdivid']]['type']) ? $rowdivids[$sq['rowdivid']]['type'] : '');
                $neweqn = (($sq['type'] == 'exclude_all_others') ? array() : array($sq['eqn']));
                $oldeo = (isset($rowdivids[$sq['rowdivid']]['exclusive_options']) ? $rowdivids[$sq['rowdivid']]['exclusive_options'] : array());
                $neweo = (($sq['type'] == 'exclude_all_others') ? array($sq['eqn']) : array());
                $rowdivids[$sq['rowdivid']] = array(
                    'order' => $order++,
                    'qid' => $sq['qid'],
                    'rowdivid' => $sq['rowdivid'],
                    'type' => $sq['type'] . ';' . $oldtype,
                    'qtype' => $sq['qtype'],
                    'sgqa' => $sq['sgqa'],
                    'eqns' => array_merge($oldeqn, $neweqn),
                    'exclusive_options' => array_merge($oldeo, $neweo),
                );
            }

            foreach ($rowdivids as $sq) {
                $sq['eqn'] = implode(' and ', array_unique(array_merge($sq['eqns'],
                    $sq['exclusive_options'])));   // without array_unique, get duplicate of filters for question types 1, :, and ;
                $eos = array_unique($sq['exclusive_options']);
                $isExclusive = '';
                $irrelevantAndExclusive = '';
                if (count($eos) > 0) {
                    $isExclusive = '!(' . implode(' and ', $eos) . ')';
                    $noneos = array_unique($sq['eqns']);
                    if (count($noneos) > 0) {
                        $irrelevantAndExclusive = '(' . implode(' and ', $noneos) . ') and ' . $isExclusive;
                    }
                }
                $this->_ProcessSubQRelevance($sq['eqn'], $sq['qid'], $sq['rowdivid'], $sq['type'], $sq['qtype'],
                    $sq['sgqa'], $isExclusive, $irrelevantAndExclusive);
            }

            foreach ($validationEqn as $qid => $eqns) {
                $parts = array();
                $tips = (isset($validationTips[$qid]) ? $validationTips[$qid] : array());
                $subqValidEqns = array();
                $sumEqn = '';
                $sumRemainingEqn = '';
                foreach ($eqns as $v) {
                    if (!isset($parts[$v['class']])) {
                        $parts[$v['class']] = array();
                    }
                    $parts[$v['class']][] = $v['eqn'];
                    // even if there are min/max/preg, the count or total will always be the same
                    $sumEqn = (isset($v['sumEqn'])) ? $v['sumEqn'] : $sumEqn;
                    $sumRemainingEqn = (isset($v['sumRemainingEqn'])) ? $v['sumRemainingEqn'] : $sumRemainingEqn;
                    if (isset($v['subqValidEqns'])) {
                        $subqValidEqns[] = $v['subqValidEqns'];
                    }
                }
                // combine the sub-question level validation equations into a single validation equation per sub-question
                $subqValidComposite = array();
                foreach ($subqValidEqns as $sqs) {
                    foreach ($sqs as $sq) {
                        if (!isset($subqValidComposite[$sq['subqValidSelector']])) {
                            $subqValidComposite[$sq['subqValidSelector']] = array(
                                'subqValidSelector' => $sq['subqValidSelector'],
                                'subqValidEqns' => array(),
                            );
                        }
                        $subqValidComposite[$sq['subqValidSelector']]['subqValidEqns'][] = $sq['subqValidEqn'];
                    }
                }
                $csubqValidEqns = array();
                foreach ($subqValidComposite as $csq) {
                    $csubqValidEqns[$csq['subqValidSelector']] = array(
                        'subqValidSelector' => $csq['subqValidSelector'],
                        'subqValidEqn' => implode(' && ', $csq['subqValidEqns']),
                    );
                }
                // now combine all classes of validation equations
                $veqns = [];
                foreach ($parts as $vclass => $eqns) {
                    $veqns[$vclass] = '(' . implode(' and ', $eqns) . ')';
                }
                $this->qid2validationEqn[$qid] = array(
                    'eqn' => $veqns,
                    'tips' => $tips,
                    'subqValidEqns' => $csubqValidEqns,
                    'sumEqn' => $sumEqn,
                    'sumRemainingEqn' => $sumRemainingEqn,
                );
            }


        }

        /**
         * Recursively find all questions that logically preceded the current array_filter or array_filter_exclude request
         * Note, must support:
         * (a) semicolon-separated list of $qroot codes for either array_filter or array_filter_exclude
         * (b) mixed history of array_filter and array_filter_exclude values
         * @param type $qroot - the question root variable name
         * @param type $aflist - the list of array_filter $qroot codes
         * @param type $afelist - the list of array_filter_exclude $qroot codes
         * @return type
         */
        private function _recursivelyFindAntecdentArrayFilters($qroot, $aflist, $afelist)
        {
            if (isset($this->qrootVarName2arrayFilter[$qroot])) {
                if (isset($this->qrootVarName2arrayFilter[$qroot]['array_filter'])) {
                    $_afs = explode(';', $this->qrootVarName2arrayFilter[$qroot]['array_filter']);
                    foreach ($_afs as $_af) {
                        if (in_array($_af, $aflist)) {
                            continue;
                        }
                        $aflist[] = $_af;
                        list($aflist, $afelist) = $this->_recursivelyFindAntecdentArrayFilters($_af, $aflist, $afelist);
                    }
                }
                if (isset($this->qrootVarName2arrayFilter[$qroot]['array_filter_exclude'])) {
                    $_afes = explode(';', $this->qrootVarName2arrayFilter[$qroot]['array_filter_exclude']);
                    foreach ($_afes as $_afe) {
                        if (in_array($_afe, $afelist)) {
                            continue;
                        }
                        $afelist[] = $_afe;
                        list($aflist, $afelist) = $this->_recursivelyFindAntecdentArrayFilters($_afe, $aflist,
                            $afelist);
                    }
                }
            }

            return array($aflist, $afelist);
        }

        /**
         * Return whether a sub-question is relevant
         * @param <type> $sgqa
         * @return <boolean>
         */
        public static function SubQuestionIsRelevant($sgqa)
        {
            $LEM =& LimeExpressionManager::singleton();
            $knownVars = $LEM->getKnownVars();
            if (!isset($knownVars[$sgqa])) {
                return false;
            }
            $var = $knownVars[$sgqa];
            $sqrel = 1;
            if (isset($var['rowdivid']) && $var['rowdivid'] != '') {
                $sqrel = (isset($_SESSION[$LEM->sessid]['relevanceStatus'][$var['rowdivid']]) ? $_SESSION[$LEM->sessid]['relevanceStatus'][$var['rowdivid']] : 1);
            }
            $qid = $var['qid'];
            $qrel = (isset($_SESSION[$LEM->sessid]['relevanceStatus'][$qid]) ? $_SESSION[$LEM->sessid]['relevanceStatus'][$qid] : 1);
            $gseq = $var['gseq'];
            $grel = (isset($_SESSION[$LEM->sessid]['relevanceStatus']['G' . $gseq]) ? $_SESSION[$LEM->sessid]['relevanceStatus']['G' . $gseq] : 1);   // group-level relevance based upon grelevance equation
            return ($grel && $qrel && $sqrel);
        }

        /**
         * Return whether question $qid is relevanct
         * @param <type> $qid
         * @return boolean
         */
        static function QuestionIsRelevant($qid)
        {
            $session = App()->surveySessionManager->current;
            $question = $session->getQuestion($qid);
            return $question->isRelevant($session->response);
        }

        /**
         * Returns true if the group is relevant and should be shown
         *
         * @param int $gid
         * @return boolean
         */
        static function GroupIsRelevant($gid)
        {
            return true;
            \Yii::beginProfile(__CLASS__ . '::'  .__FUNCTION__);
            $LEM =& LimeExpressionManager::singleton();
            $session = App()->surveySessionManager->current;
            $gseq = $session->getGroupIndex($gid);

            $result = !$LEM->GroupIsIrrelevantOrHidden($gseq);
            \Yii::beginProfile(__CLASS__ . '::'  .__FUNCTION__);
            return $result;
        }

        /**
         * Return whether group $gseq is relevant
         * @param <type> $gseq
         * @return boolean
         */
        static function GroupIsIrrelevantOrHidden($gseq)
        {
            return false;
        }

        /**
         * Translate all Expressions, Macros, registered variables, etc. in $string
         * @param string $string - the string to be replaced
         * @param integer $questionNum - the $qid of question being replaced - needed for properly alignment of question-level relevance and tailoring
         * @param array $replacementFields - optional replacement values
         * @param boolean $debug - deprecated
         * @param integer $numRecursionLevels - the number of times to recursively subtitute values in this string
         * @param integer $whichPrettyPrintIteration - if want to pretty-print the source string, which recursion  level should be pretty-printed
         * @param boolean $noReplacements - true if we already know that no replacements are needed (e.g. there are no curly braces)
         * @param boolean $timeit
         * @param boolean $staticReplacement - return HTML string without the system to update by javascript
         * @return string - the original $string with all replacements done.
         */

        static function ProcessString(
            $string,
            $questionNum = null,
            $replacementFields = array(),
            $debug = false,
            $numRecursionLevels = 1,
            $whichPrettyPrintIteration = 1,
            $noReplacements = false,
            $timeit = true,
            $staticReplacement = false
        ) {
            $session = App()->surveySessionManager->current;
            $LEM =& LimeExpressionManager::singleton();

            if ($noReplacements) {
                $LEM->em->SetPrettyPrintSource($string);

                return $string;
            }

            if (isset($replacementFields) && is_array($replacementFields) && count($replacementFields) > 0) {
                $replaceArray = array();
                foreach ($replacementFields as $key => $value) {
                    $replaceArray[$key] = array(
                        'code' => $value,
                        'jsName_on' => '',
                        'jsName' => '',
                        'readWrite' => 'N',
                    );
                }
                $LEM->tempVars = $replaceArray;
            }
            $questionSeq = -1;
            $groupSeq = -1;
            if (!is_null($questionNum)) {
                $questionSeq = $session->getQuestionIndex($questionNum);
                $groupSeq = isset($LEM->questionId2groupSeq[$questionNum]) ? $LEM->questionId2groupSeq[$questionNum] : -1;
            }
            $stringToParse = $string;   // decode called later htmlspecialchars_decode($string,ENT_QUOTES);
            $qnum = is_null($questionNum) ? 0 : $questionNum;
            $result = $LEM->em->sProcessStringContainingExpressions($stringToParse, $qnum, $numRecursionLevels,
                $whichPrettyPrintIteration, $groupSeq, $questionSeq, $staticReplacement);

            return $result;
        }


        /**
         * Compute Relevance, processing $eqn to get a boolean value.  If there are syntax errors, return false.
         * @param <type> $eqn - the relevance equation
         * @param <type> $questionNum - needed to align question-level relevance and tailoring
         * @param <type> $jsResultVar - this variable determines whether irrelevant questions are hidden
         * @param <type> $question->type - question type
         * @param <type> $hidden - whether question should always be hidden
         * @return <type>
         */
        static function ProcessRelevance($eqn, $questionNum = null, $jsResultVar = null, $type = null, $hidden = 0)
        {
            $LEM =& LimeExpressionManager::singleton();

            return $LEM->_ProcessRelevance($eqn, $questionNum, null, $jsResultVar, $type, $hidden);
        }

        /**
         * Compute Relevance, processing $eqn to get a boolean value.  If there are syntax errors, return false.
         * @param <type> $eqn - the relevance equation
         * @param <type> $questionNum - needed to align question-level relevance and tailoring
         * @param <type> $jsResultVar - this variable determines whether irrelevant questions are hidden
         * @param <type> $question->type - question type
         * @param <type> $hidden - whether question should always be hidden
         * @return <type>
         */
        private function _ProcessRelevance(
            $eqn,
            $questionNum = null,
            $gseq = null,
            $jsResultVar = null,
            $type = null,
            $hidden = 0
        ) {
            $session = App()->surveySessionManager->current;
            // These will be called in the order that questions are supposed to be asked
            // TODO - cache results and generated JavaScript equations?

            $questionSeq = -1;
            $groupSeq = -1;
            if (!is_null($questionNum)) {
                $questionSeq = $session->getQuestionIndex($questionNum);
                $groupSeq = isset($this->questionId2groupSeq[$questionNum]) ? $this->questionId2groupSeq[$questionNum] : -1;
            }

            $stringToParse = htmlspecialchars_decode($eqn, ENT_QUOTES);
            $result = $this->em->ProcessBooleanExpression($stringToParse, $groupSeq, $questionSeq);
            $hasErrors = $this->em->HasErrors();

            if (!is_null($questionNum) && !is_null($jsResultVar)) { // so if missing either, don't generate JavaScript for this - means off-page relevance.
                $jsVars = $this->em->GetJSVarsUsed();
                $relevanceVars = implode('|', $this->em->GetJSVarsUsed());
                $relevanceJS = $this->em->GetJavaScriptEquivalentOfExpression();
                $this->groupRelevanceInfo[] = array(
                    'qid' => $questionNum,
                    'gseq' => $gseq,
                    'eqn' => $eqn,
                    'result' => $result,
                    'numJsVars' => count($jsVars),
                    'relevancejs' => $relevanceJS,
                    'relevanceVars' => $relevanceVars,
                    'jsResultVar' => $jsResultVar,
                    'type' => $type,
                    'hidden' => $hidden,
                    'hasErrors' => $hasErrors,
                );
            }

            return $result;
        }

        /**
         * Create JavaScript needed to process sub-question-level relevance (e.g. for array_filter and  _exclude)
         * @param <type> $eqn - the equation to parse
         * @param <type> $questionNum - the question number - needed to align relavance and tailoring blocks
         * @param <type> $rowdivid - the javascript ID that needs to be shown/hidden in order to control array_filter visibility
         * @param <type> $question->type - the type of sub-question relevance (e.g. 'array_filter', 'array_filter_exclude')
         * @return <type>
         */
        private function _ProcessSubQRelevance(
            $eqn,
            $questionNum = null,
            $rowdivid = null,
            $type = null,
            $qtype = null,
            $sgqa = null,
            $isExclusive = '',
            $irrelevantAndExclusive = ''
        ) {
            // These will be called in the order that questions are supposed to be asked
            if (!isset($eqn) || trim($eqn == '') || trim($eqn) == '1') {
                return true;
            }
            $questionSeq = -1;
            $groupSeq = -1;
            if (!is_null($questionNum)) {
                $questionSeq = $session->getQuestionIndex($questionNum);
                $groupSeq = isset($this->questionId2groupSeq[$questionNum]) ? $this->questionId2groupSeq[$questionNum] : -1;
            }

            $stringToParse = htmlspecialchars_decode($eqn, ENT_QUOTES);
            $result = $this->em->ProcessBooleanExpression($stringToParse, $groupSeq, $questionSeq);
            $hasErrors = $this->em->HasErrors();
            $prettyPrint = '';
            if (($this->debugLevel & LEM_PRETTY_PRINT_ALL_SYNTAX) == LEM_PRETTY_PRINT_ALL_SYNTAX) {
                $prettyPrint = $this->em->GetPrettyPrintString();
            }

            if (!is_null($questionNum)) {
                // make sure subquestions with errors in relevance equations are always shown and answers recorded  #7703
                if ($hasErrors) {
                    $result = true;
                    $relevanceJS = 1;
                } else {
                    $relevanceJS = $this->em->GetJavaScriptEquivalentOfExpression();
                }
                $jsVars = $this->em->GetJSVarsUsed();
                $relevanceVars = implode('|', $this->em->GetJSVarsUsed());
                $isExclusiveJS = '';
                $irrelevantAndExclusiveJS = '';
                // Only need to extract JS, since will already have Vars and error counts from main equation
                if ($isExclusive != '') {
                    $this->em->ProcessBooleanExpression($isExclusive, $groupSeq, $questionSeq);
                    $isExclusiveJS = $this->em->GetJavaScriptEquivalentOfExpression();
                }
                if ($irrelevantAndExclusive != '') {
                    $this->em->ProcessBooleanExpression($irrelevantAndExclusive, $groupSeq, $questionSeq);
                    $irrelevantAndExclusiveJS = $this->em->GetJavaScriptEquivalentOfExpression();
                }

                $this->subQrelInfo[$questionNum][$rowdivid] = array(
                    'qid' => $questionNum,
                    'eqn' => $eqn,
                    'prettyPrintEqn' => $prettyPrint,
                    'result' => $result,
                    'numJsVars' => count($jsVars),
                    'relevancejs' => $relevanceJS,
                    'relevanceVars' => $relevanceVars,
                    'rowdivid' => $rowdivid,
                    'type' => $type,
                    'qtype' => $qtype,
                    'sgqa' => $sgqa,
                    'hasErrors' => $hasErrors,
                    'isExclusiveJS' => $isExclusiveJS,
                    'irrelevantAndExclusiveJS' => $irrelevantAndExclusiveJS,
                );
            }

            return $result;
        }

        /**
         * @param Question $question
         *
         * @throws Exception
         * @return array(
         * 'relevance' => "!is_empty(num)"  // the question-level relevance equation
         * 'grelevance' => ""   // the group-level relevance equation
         * 'qid' => "699" // the question id
         * 'qseq' => 3  // the 0-index question sequence
         * 'gseq' => 0  // the 0-index group sequence
         * 'jsResultVar_on' => 'answer26626X34X699' // the javascript variable holding the input value
         * 'jsResultVar' => 'java26226X34X699'  // the javascript variable (often hidden) holding the value to be submitted
         * 'type' => 'N'    // the one character question type
         * 'hidden' => 0    // 1 if it should be always_hidden
         * 'gid' => "34"    // group id
         * 'mandatory' => 'N'   // 'Y' if mandatory
         * 'eqn' => ""  // TODO ??
         * 'help' => "" // the help text
         * 'qtext' => "Enter a larger number than {num}"    // the question text
         * 'code' => 'afDS_sq5_1' // the full variable name
         * 'other' => 'N'   // whether the question supports the 'other' option - 'Y' if true
         * 'rowdivid' => '2626X37X705sq5'   // the javascript id for the row - in this case, the 5th sub-question
         * 'aid' => 'sq5'   // the answer id
         * 'sqid' => '791' // the sub-question's qid (only populated for some question types)
         * );
         */
        public function getQuestionRelevanceInfo(Question $question)
        {
            static $requestCache = [];
            bP();
            $session = App()->surveySessionManager->current;

            $questionCounter = 0;
            foreach ($question->fields as $sgqa => $details) {


                // Set $jsVarName_on (for on-page variables - e.g. answerSGQA) and $jsVarName (for off-page  variables; the primary name - e.g. javaSGQA)
                $jsVarName = 'java' . $sgqa;
                $jsVarName_on = 'answer' . $sgqa;
                switch ($question->type) {
                    case '!': //List - dropdown
                        if (preg_match("/other$/", $sgqa)) {
                            $jsVarName_on = 'othertext' . substr($sgqa, 0, -5);
                        } else {
                            $jsVarName_on = $jsVarName;
                        }
                        break;
                    case 'L': //LIST drop-down/radio-button list
                        if (preg_match("/other$/", $sgqa)) {
                            $jsVarName_on = 'answer' . $sgqa . "text";
                        } else {
                            $jsVarName_on = $jsVarName;
                        }
                        break;
                    case Question::TYPE_LIST_WITH_COMMENT: //LIST WITH COMMENT drop-down/radio-button list + textarea
                            $jsVarName_on = 'java' . $sgqa;
                        break;
                    case '1': //Array (Flexible Labels) dual scale
                        $jsVarName = 'java' . str_replace('#', '_', $sgqa);
                        $jsVarName_on = $jsVarName;
                        break;
                    case '|': //File Upload
                        $jsVarName_on = $jsVarName;
                        break;
                    case 'P': //Multiple choice with comments checkbox + text
                        if (preg_match("/(other|comment)$/", $sgqa)) {
                            $jsVarName_on = 'answer' . $sgqa;  // is this true for survey.php and not for group.php?
                        } else {
                            $jsVarName_on = $jsVarName;
                        }
                        break;
                }
                // Hidden question are never on same page (except for equation)
                if ($question->bool_hidden && $question->type != Question::TYPE_EQUATION) {
                    $jsVarName_on = '';
                }

                $result = [
                    'jsResultVar_on' => $jsVarName_on,
                    'jsResultVar' => $jsVarName,

                ];

            }
            eP();
            return $result;
        }

        public function getGroupRelevanceInfo(QuestionGroup $group)
        {
            bP();
            $session = App()->surveySessionManager->current;

            $eqn = $group->grelevance;
            if (is_null($eqn) || trim($eqn == '') || trim($eqn) == '1') {
                $result = [
                    'eqn' => '',
                    'result' => 1,
                    'numJsVars' => 0,
                    'relevancejs' => '',
                    'relevanceVars' => '',
                    'prettyprint' => '',
                ];
            } else {
                $stringToParse = htmlspecialchars_decode($eqn, ENT_QUOTES);
                $parseResult = $this->em->ProcessBooleanExpression($stringToParse, $groupSequence);
                $hasErrors = $this->em->HasErrors();

                $jsVars = $this->em->GetJSVarsUsed();
                $relevanceVars = implode('|', $this->em->GetJSVarsUsed());
                $relevanceJS = $this->em->GetJavaScriptEquivalentOfExpression();
                $prettyPrint = $this->em->GetPrettyPrintString();

                $result = [
                    'eqn' => $stringToParse,
                    'result' => $parseResult,
                    'numJsVars' => count($jsVars),
                    'relevancejs' => $relevanceJS,
                    'relevanceVars' => $relevanceVars,
                    'prettyprint' => $prettyPrint,
                    'hasErrors' => $hasErrors,
                ];
            }

            $result['gid'] = $group->primaryKey;
            eP();
            return $result;
        }


        /**
         * Used to show potential syntax errors of processing Relevance or Equations.
         * @return <type>
         */
        public static function GetLastPrettyPrintExpression()
        {
            $LEM =& LimeExpressionManager::singleton();

            return $LEM->em->GetLastPrettyPrintExpression();
        }

        /**
         * Expand "self.suffix" and "that.qcode.suffix" into canonical list of variable names
         * @param type $qseq
         * @param type $varname
         */
        static function GetAllVarNamesForQ($qseq, $varname)
        {
            $LEM =& LimeExpressionManager::singleton();

            $parts = explode('.', $varname);
            $qroot = '';
            $suffix = '';
            $sqpatts = array();
            $nosqpatts = array();
            $sqpatt = '';
            $nosqpatt = '';
            $comments = '';

            if ($parts[0] == 'self') {
                $question->type = 'self';
            } else {
                $question->type = 'that';
                array_shift($parts);
                if (isset($parts[0])) {
                    $qroot = $parts[0];
                } else {
                    return $varname;
                }
            }
            array_shift($parts);

            if (count($parts) > 0) {
                if (preg_match('/^' . ExpressionManager::$RDP_regex_var_attr . '$/', $parts[count($parts) - 1])) {
                    $suffix = '.' . $parts[count($parts) - 1];
                    array_pop($parts);
                }
            }

            foreach ($parts as $part) {
                if ($part == 'nocomments') {
                    $comments = 'N';
                } else {
                    if ($part == 'comments') {
                        $comments = 'Y';
                    } else {
                        if (preg_match('/^sq_.+$/', $part)) {
                            $sqpatts[] = substr($part, 3);
                        } else {
                            if (preg_match('/^nosq_.+$/', $part)) {
                                $nosqpatts[] = substr($part, 5);
                            } else {
                                return $varname;    // invalid
                            }
                        }
                    }
                }
            }
            $sqpatt = implode('|', $sqpatts);
            $nosqpatt = implode('|', $nosqpatts);
            $vars = array();
            if (isset($LEM->knownVars)) {
                foreach ($LEM->knownVars as $kv) {
                    if ($question->type == 'self') {
                        if (!isset($kv['qseq']) || $kv['qseq'] != $qseq || trim($kv['sgqa']) == '') {
                            continue;
                        }
                    } else {
                        if (!isset($kv['rootVarName']) || $kv['rootVarName'] != $qroot) {
                            continue;
                        }
                    }
                    if ($comments != '') {
                        if ($comments == 'Y' && !preg_match('/comment$/', $kv['sgqa'])) {
                            continue;
                        }
                        if ($comments == 'N' && preg_match('/comment$/', $kv['sgqa'])) {
                            continue;
                        }
                    }
                    $sgq = $LEM->sid . 'X' . $kv['gid'] . 'X' . $kv['qid'];
                    $ext = substr($kv['sgqa'], strlen($sgq));

                    if ($sqpatt != '') {
                        if (!preg_match('/' . $sqpatt . '/', $ext)) {
                            continue;
                        }
                    }
                    if ($nosqpatt != '') {
                        if (preg_match('/' . $nosqpatt . '/', $ext)) {
                            continue;
                        }
                    }

                    $vars[] = $kv['sgqa'] . $suffix;
                }
            }
            if (count($vars) > 0) {
                return implode(',', $vars);
            }

            return $varname;    // invalid
        }

        /**
         * Should be first function called on each page - sets/clears internally needed variables
         * @param <boolean> $initializeVars - if true, initializes the replacement variables to enable syntax highlighting on admin pages
         */
        public static function StartProcessingPage($initializeVars = false)
        {
            $LEM =& LimeExpressionManager::singleton();
            $session = App()->surveySessionManager->current;
            $LEM->processedRelevance = false;
            $LEM->surveyOptions['hyperlinkSyntaxHighlighting'] = true;    // this will be temporary - should be reset in running survey
            $LEM->qid2exclusiveAuto = array();

            $surveyinfo = (isset($LEM->sid) ? getSurveyInfo($LEM->sid) : null);
            if (isset($surveyinfo['assessments']) && $surveyinfo['assessments'] == 'Y') {
                $LEM->surveyOptions['assessments'] = true;
            }

            $LEM->initialized = true;

            if ($initializeVars) {
                $LEM->em->StartProcessingGroup(
                    $session->surveyId,
                    '',
                    true
                );
            }

        }

        /**
         * Initialize a survey so can use EM to manage navigation
         * @param int $surveyid
         * @param string $surveyMode
         * @param array $aSurveyOptions
         * @param bool $forceRefresh
         * @param int $debugLevel
         */
        static function StartSurvey(
            $surveyid,
            $aSurveyOptions = null,
            $forceRefresh = false,
            $debugLevel = 0
        ) {
            $LEM =& LimeExpressionManager::singleton();
            $LEM->em->StartProcessingGroup($surveyid);
            if (is_null($aSurveyOptions)) {
                $aSurveyOptions = array();
            }
            $LEM->surveyOptions['active'] = (isset($aSurveyOptions['active']) ? $aSurveyOptions['active'] : false);
            $LEM->surveyOptions['allowsave'] = (isset($aSurveyOptions['allowsave']) ? $aSurveyOptions['allowsave'] : false);
            $LEM->surveyOptions['anonymized'] = (isset($aSurveyOptions['anonymized']) ? $aSurveyOptions['anonymized'] : false);
            $LEM->surveyOptions['assessments'] = (isset($aSurveyOptions['assessments']) ? $aSurveyOptions['assessments'] : false);
            $LEM->surveyOptions['datestamp'] = (isset($aSurveyOptions['datestamp']) ? $aSurveyOptions['datestamp'] : false);
            $LEM->surveyOptions['deletenonvalues'] = (isset($aSurveyOptions['deletenonvalues']) ? ($aSurveyOptions['deletenonvalues'] == '1') : true);
            $LEM->surveyOptions['hyperlinkSyntaxHighlighting'] = (isset($aSurveyOptions['hyperlinkSyntaxHighlighting']) ? $aSurveyOptions['hyperlinkSyntaxHighlighting'] : false);
            $LEM->surveyOptions['ipaddr'] = (isset($aSurveyOptions['ipaddr']) ? $aSurveyOptions['ipaddr'] : false);
            $LEM->surveyOptions['radix'] = (isset($aSurveyOptions['radix']) ? $aSurveyOptions['radix'] : '.');
            $LEM->surveyOptions['refurl'] = (isset($aSurveyOptions['refurl']) ? $aSurveyOptions['refurl'] : null);
            $LEM->surveyOptions['savetimings'] = (isset($aSurveyOptions['savetimings']) ? $aSurveyOptions['savetimings'] : '');
            $LEM->sgqaNaming = (isset($aSurveyOptions['sgqaNaming']) ? ($aSurveyOptions['sgqaNaming'] == "Y") : true); // TODO default should eventually be false
            $LEM->surveyOptions['startlanguage'] = (isset($aSurveyOptions['startlanguage']) ? $aSurveyOptions['startlanguage'] : 'en');
            $LEM->surveyOptions['surveyls_dateformat'] = (isset($aSurveyOptions['surveyls_dateformat']) ? $aSurveyOptions['surveyls_dateformat'] : 1);
            $LEM->surveyOptions['tablename_timings'] = ((isset($aSurveyOptions['savetimings']) && $aSurveyOptions['savetimings'] == 'Y') ? '{{survey_' . $surveyid . '_timings}}' : '');
            $LEM->surveyOptions['target'] = (isset($aSurveyOptions['target']) ? $aSurveyOptions['target'] : '/temp/files/');
            $LEM->surveyOptions['timeadjust'] = (isset($aSurveyOptions['timeadjust']) ? $aSurveyOptions['timeadjust'] : 0);
            $LEM->surveyOptions['tempdir'] = (isset($aSurveyOptions['tempdir']) ? $aSurveyOptions['tempdir'] : '/temp/');
            $LEM->surveyOptions['token'] = (isset($aSurveyOptions['token']) ? $aSurveyOptions['token'] : null);

            $LEM->debugLevel = $debugLevel;
            $LEM->currentGroupSeq = -1;
            $LEM->indexGseq = array();
            $LEM->qrootVarName2arrayFilter = array();
            templatereplace("{}"); // Needed for coreReplacements in relevance equation (in all mode)
//            if (isset($_SESSION[$LEM->sessid]['startingValues']) && is_array($_SESSION[$surveyid]['startingValues']) && count($_SESSION[$surveyid]['startingValues']) > 0) {
//                $startingValues = array();
//                foreach ($_SESSION[$LEM->sessid]['startingValues'] as $k => $value) {
//                    if (isset($LEM->knownVars[$k])) {
//                        $knownVar = $LEM->knownVars[$k];
//                    } else {
//                        if (isset($LEM->qcode2sgqa[$k])) {
//                            $knownVar = $LEM->knownVars[$LEM->qcode2sgqa[$k]];
//                        } else {
//                            if (isset($LEM->tempVars[$k])) {
//                                $knownVar = $LEM->tempVar[$k];
//                            } else {
//                                continue;
//                            }
//                        }
//                    }
//                    if (!isset($knownVar['jsName'])) {
//                        continue;
//                    }
//                    switch ($knownVar['type']) {
//                        case 'D': //DATE
//                            if (trim($value) == "" | $value=='INVALID') {
//                                $value = null;
//                            } else {
//                                $dateformatdatat = getDateFormatData($LEM->surveyOptions['surveyls_dateformat']);
//                                $datetimeobj = new Date_Time_Converter($value, $dateformatdatat['phpdate']);
//                                $value = $datetimeobj->convert("Y-m-d H:i");
//                            }
//                            break;
//                        case 'N': //NUMERICAL QUESTION TYPE
//                        case 'K': //MULTIPLE NUMERICAL QUESTION
//                            if (trim($value) == "") {
//                                $value = null;
//                            } else {
//                                $value = sanitize_float($value);
//                            }
//                            break;
//                        case '|': //File Upload
//                            $value = null;  // can't upload a file via GET
//                            break;
//                    }
//                    $LEM->updatedValues[$knownVar['sgqa']] = array(
//                        'type' => $knownVar['type'],
//                        'value' => $value,
//                    );
//                }
//                $LEM->updateValuesInDatabase(null);
//            }

            return array(
                'hasNext' => true,
                'hasPrevious' => false,
            );
        }

        static function NavigateBackwards()
        {
            $LEM =& LimeExpressionManager::singleton();
            $session = App()->surveySessionManager->current;
            $LEM->ParseResultCache = array();    // to avoid running same test more than once for a given group
            $LEM->updatedValues = [];

            switch ($session->format) {
                case Survey::FORMAT_ALL_IN_ONE:
                    throw new \Exception("Can not move backwards in all in one mode");
                    break;
                case Survey::FORMAT_GROUP:
                    // First validate the current group
                    $LEM->StartProcessingPage();
                    $updatedValues = $LEM->ProcessCurrentResponses();
                    $message = '';
                    while (true) {
                        $LEM->currentQset = [];    // reset active list of questions
                        if (is_null($LEM->currentGroupSeq)) {
                            $LEM->currentGroupSeq = 0;
                        } // If moving backwards in preview mode and a question was removed then $LEM->currentGroupSeq is NULL and an endless loop occurs.
                        if (--$LEM->currentGroupSeq < 0) // Stop at start
                        {
                            $message .= $LEM->updateValuesInDatabase(false);
                            $LEM->lastMoveResult = array(
                                'at_start' => true,
                                'finished' => false,
                                'message' => $message,
                                'unansweredSQs' => (isset($result['unansweredSQs']) ? $result['unansweredSQs'] : ''),
                                'invalidSQs' => (isset($result['invalidSQs']) ? $result['invalidSQs'] : ''),
                            );

                            return $LEM->lastMoveResult;
                        }

                        $result = $LEM->validateGroup($LEM->currentGroupSeq);
                        if (is_null($result)) {
                            continue;   // this is an invalid group - skip it
                        }
                        $message .= $result['message'];
                        if (!$result['relevant'] || $result['hidden']) {
                            // then skip this group - assume already saved?
                            continue;
                        } else {
                            // display new group
                            $message .= $LEM->updateValuesInDatabase(false);
                            $LEM->lastMoveResult = array(
                                'at_start' => false,
                                'finished' => false,
                                'message' => $message,
                                'gseq' => $LEM->currentGroupSeq,
                                'seq' => $LEM->currentGroupSeq,
                                'mandViolation' => $result['mandViolation'],
                                'valid' => $result['valid'],
                                'unansweredSQs' => $result['unansweredSQs'],
                                'invalidSQs' => $result['invalidSQs'],
                            );

                            return $LEM->lastMoveResult;
                        }
                    }
                    break;
                case Survey::FORMAT_QUESTION:
                    $LEM->StartProcessingPage();
                    $updatedValues = $LEM->ProcessCurrentResponses();
                    $message = '';
                    while (true) {
                        if (--$LEM->currentQuestionSeq < 0) // Stop at start : can be a question
                        {
                            $message .= $LEM->updateValuesInDatabase(false);
                            $LEM->lastMoveResult = array(
                                'at_start' => true,
                                'finished' => false,
                                'message' => $message,
                                'unansweredSQs' => (isset($result['unansweredSQs']) ? $result['unansweredSQs'] : ''),
                                'invalidSQs' => (isset($result['invalidSQs']) ? $result['invalidSQs'] : ''),
                            );

                            return $LEM->lastMoveResult;
                        }

                        $LEM->_CreateSubQLevelRelevanceAndValidationEqns($session->getQuestion($session->step));
                        $result = $LEM->validateQuestion($LEM->currentQuestionSeq);
                        $message .= $result['message'];
                        $gRelInfo = $LEM->getGroupRelevanceInfo();
                        $grel = $gRelInfo['result'];

                        if (!$grel || !$result['relevant'] || $result['hidden']) {
                            // then skip this question - assume already saved?
                            continue;
                        } else {
                            // display new question : Ging backward : maxQuestionSeq>currentQuestionSeq is always true.
                            $message .= $LEM->updateValuesInDatabase(false);

                            return array(
                                'at_start' => false,
                                'finished' => false,
                                'message' => $message,
                                'gseq' => $LEM->currentGroupSeq,
                                'seq' => $LEM->currentQuestionSeq,
                                'qseq' => $LEM->currentQuestionSeq,
                                'mandViolation' => $result['mandViolation'],
                                'valid' => $result['valid'],
                                'unansweredSQs' => $result['unansweredSQs'],
                                'invalidSQs' => $result['invalidSQs'],
                            );
                        }
                    }
                    break;
            }
        }

        private function navigateNextGroup($force) {
            // First validate the current group
            $this->StartProcessingPage();
            $session = App()->surveySessionManager->current;
            $this->processData($session->response, $_POST);
            $group = $session->getCurrentGroup();
            $message = '';
            if (!$force) {
                $validationResults = $this->validateGroup($group);
                $message .= $validationResults->getMessagesAsString();
                if ($group->isRelevant($session->response) && !$validationResults->getSuccess()) {
                    // redisplay the current group
                    $message .= $this->updateValuesInDatabase(false);
                    $result = [
                        'finished' => false,
                        'message' => $message,
                        'gseq' => $session->step,
                        'seq' => $session->step,
                        'validationResults' => $validationResults
                    ];
                }
            }
            if ($force || !isset($result)) {
                $step = $session->step;
                $stepCount = $session->stepCount;
                for ($step = $session->step + 1; $step <= $stepCount; $step++) {
                    if ($step >= $session->stepCount) {// Move next with finished, but without submit.
                        $message .= $this->updateValuesInDatabase(true);
                        $result = [
                            'finished' => true,
                            'message' => $message,
                            'seq' => $step,
                            'validationResults' => $validationResults,
                        ];
                        break;
                    }
                    $group = $session->getGroupByIndex($step);
                    if ($group->isRelevant($session->response)) {
                        // then skip this group
                        continue;
                    } else {

                        $validationResults = $this->validateGroup($group);
                        $message .= $validationResults->getMessagesAsString();
                        // display new group
                        $message .= $this->updateValuesInDatabase(false);
                        $result = [
                            'finished' => false,
                            'message' => $message,
                            'seq' => $step,
                            'validationResults' => $validationResults
                        ];
                        break;
                    }


                }
            }
            return $result;
        }

        private function navigateNextQuestion($force) {
            $this->StartProcessingPage();
            $session = App()->surveySessionManager->current;
            $this->processData($session->response, $_POST);
            $question = $session->getQuestionByIndex($session->step);
            $message = '';
            if (!$force) {
                // Validate current page.
                $validateResult = $this->validateQuestion($question);
                $message .= $validateResult->getMessagesAsString();
                if ($question->isRelevant($session->response) && !$validateResult->getSuccess()) {
                    // redisplay the current question with all error
                    $message .= $this->updateValuesInDatabase(false);
                    $result = [
                        'finished' => false,
                        'message' => $message,
                        'qseq' => $session->step,
                        'gseq' => $session->getGroupIndex($question->gid),
                        'seq' => $session->step,
                        'validationResult' => $validateResult
                    ];
                }
            }
            if ($force || !isset($result)) {
                $step = $session->step;
                $stepCount = $session->stepCount;

                for ($step = $session->step + 1; $step <= $stepCount; $step++) {
                    if ($step >= $session->stepCount) // Move next with finished, but without submit.
                    {
                        $message .= $this->updateValuesInDatabase(true);
                        $result = [
                            'finished' => true,
                            'message' => $message,
                            'qseq' => $step,
                            'gseq' => $session->getGroupIndex($session->currentGroup->primaryKey),
                            'seq' => $step,
                            'mandViolation' => (($session->maxStep > $step) ? $result['mandViolation'] : false),
                            'valid' => (($session->maxStep > $step) ? $result['valid'] : true),
                            'unansweredSQs' => (isset($result['unansweredSQs']) ? $result['unansweredSQs'] : ''),
                            'invalidSQs' => (isset($result['invalidSQs']) ? $result['invalidSQs'] : ''),
                        ];

                        break;
                    }

                    // Set certain variables normally set by StartProcessingGroup()
                    $question = $session->getQuestionByIndex($step);
                    $this->_CreateSubQLevelRelevanceAndValidationEqns($question);
                    $validateResult = $this->validateQuestion($question);
                    $message .= $validateResult->getMessagesAsString();
                    $gRelInfo = $this->getGroupRelevanceInfo($question->group);
                    $grel = $gRelInfo['result'];

                    if ($question->bool_hidden || !$question->isRelevant($session->response)) {
                        // then skip this question, $this->updatedValues updated in _ValidateQuestion
                        continue;
                    } else {
                        // Display new question
                        // Show error only if this question are not viewed before (question hidden by condition before <= maxQuestionSeq>currentQuestionSeq)
                        $message .= $this->updateValuesInDatabase(false);
                        $result = [
                            'finished' => false,
                            'message' => $message,
                            'qseq' => $step,
                            'gseq' => $this->currentGroupSeq,
                            'seq' => $step,
                            'mandViolation' => (($session->maxStep >= $step) ? $validateResult['mandViolation'] : false),
                            'valid' => (($session->maxStep >= $step) ? $validateResult['valid'] : false),
                        ];
                        break;

                    }
                }
            }
            if (!isset($result) || !array_key_exists('finished', $result)) {
                throw new \UnexpectedValueException("Result should not be null, and should contain proper keys");
            }
            return $result;
        }
        /**
         *
         * @param <type> $force - if true, continue to go forward even if there are violations to the mandatory and/or validity rules
         */
        static function NavigateForwards($force = false)
        {
            $LEM =& LimeExpressionManager::singleton();
            $session = App()->surveySessionManager->current;
            $LEM->ParseResultCache = array();    // to avoid running same test more than once for a given group
            $LEM->updatedValues = [];

            switch ($session->format) {
                case Survey::FORMAT_ALL_IN_ONE:
                    $LEM->StartProcessingPage(true);
                    $updatedValues = $LEM->ProcessCurrentResponses();
                    $message = '';
                    $result = $LEM->_ValidateSurvey();
                    $message .= $result['message'];
                    $updatedValues = array_merge($updatedValues, $result['updatedValues']);
                    if (!$force && !is_null($result) && ($result['mandViolation'] || !$result['valid'] || $startingGroup == -1)) {
                        $finished = false;
                    } else {
                        $finished = true;
                    }
                    $message .= $LEM->updateValuesInDatabase($finished);
                    $LEM->lastMoveResult = array(
                        'finished' => $finished,
                        'message' => $message,
                        'gseq' => 1,
                        'seq' => 1,
                        'mandViolation' => $result['mandViolation'],
                        'valid' => $result['valid'],
                        'unansweredSQs' => $result['unansweredSQs'],
                        'invalidSQs' => $result['invalidSQs'],
                    );

                    return $LEM->lastMoveResult;
                    break;
                case Survey::FORMAT_GROUP:
                    $result = $LEM->navigateNextGroup($force);
                    break;
                case Survey::FORMAT_QUESTION:
                    $result = $LEM->navigateNextQuestion($force);
                    break;
                default: throw new \Exception("Unknown survey format");
            }
            if ($result === null) {
                throw new \UnexpectedValueException("Result should not be null");
            }
            return $result;
        }

        /**
         * Write values to database.
         * @param <type> $updatedValues
         * @param <boolean> $finished - true if the survey needs to be finalized
         */
        private function updateValuesInDatabase($finished = false)
        {
            $session = App()->surveySessionManager->current;
            $response = $session->response;
            if ($finished) {
                $setter = array();
                $thisstep = $session->step;
                $response->lastpage = $session->step;


                if ($session->survey->bool_savetimings) {
                    Yii::import("application.libraries.Save");
                    $cSave = new Save();
                    $cSave->set_answer_time();
                }

                // Delete the save control record if successfully finalize the submission
                $query = "DELETE FROM {{saved_control}} where `srid` = '{$session->responseId}' and `sid` = '{$session->surveyId}'";
                Yii::app()->db->createCommand($query)->execute();

                // Check Quotas
                $aQuotas = checkCompletedQuota('return');
                if ($aQuotas && !empty($aQuotas)) {
                    checkCompletedQuota($this->sid);  // will create a page and quit: why not use it directly ?
                } elseif ($finished) {
                    $session->response->markAsFinished();
                    $session->response->save();
                }

            }
        }

        /**
         * Get last move information, optionally clearing the substitution cache
         * @param type $clearSubstitutionInfo
         * @return type
         */
        static function GetLastMoveResult($clearSubstitutionInfo = false)
        {
            $LEM =& LimeExpressionManager::singleton();
            if ($clearSubstitutionInfo) {
                $LEM->em->ClearSubstitutionInfo();  // need to avoid double-generation of tailoring info
            }

            return (isset($LEM->lastMoveResult) ? $LEM->lastMoveResult : null);
        }

        private function processData(Response $response, array $data) {
            $response->setAttributes($data);
            $response->save();
        }

        private function jumpToGroup($seq, $preview, $processPOST, $force) {
            // First validate the current group
            $this->StartProcessingPage();
            $session = App()->surveySessionManager->current;
            if ($processPOST) {
                $this->processData($session->response, $_POST);
            } else {
                $updatedValues = array();
            }

            $message = '';
            // Validate if moving forward.
            if (!$force && $seq > $session->step) {
                $validationResults = $this->validateGroup($session->getCurrentGroup());
                $message .= $result['message'];
                $updatedValues = array_merge($updatedValues, $result['updatedValues']);
                if (!is_null($result) && ($result['mandViolation'] || !$result['valid'])) {
                    // redisplay the current group, showing error
                    $message .= $LEM->updateValuesInDatabase(false);
                    $LEM->lastMoveResult = array(
                        'finished' => false,
                        'message' => $message,
                        'gseq' => $LEM->currentGroupSeq,
                        'seq' => $LEM->currentGroupSeq,
                        'mandViolation' => $result['mandViolation'],
                        'valid' => $result['valid'],
                        'unansweredSQs' => $result['unansweredSQs'],
                        'invalidSQs' => $result['invalidSQs'],
                    );

                    return $LEM->lastMoveResult;
                }
            }

            $stepCount = $session->stepCount;
            for ($step = $seq; $step < $stepCount; $step++) {
                $group = $session->getGroupByIndex($step);
                $validationResults = $this->validateGroup($group);
                $message .= $validationResults->getMessagesAsString();
                if (!$preview && !$group->isRelevant($session->response)) {
                    // then skip this group
                    continue;
                } elseif (!$preview && !$validationResults->getSuccess() && $step < $seq) {
                    // if there is a violation while moving forward, need to stop and ask that set of questions
                    // if there are no violations, can skip this group as long as changed values are saved.
                    die('skip2');
                    continue;
                } else {
                    // Display new group
                    // Showing error if question are before the maxstep
                    $message .= $this->updateValuesInDatabase(false);
                    $result = [
                        'finished' => false,
                        'message' => $message,
                        'gseq' => $step,
                        'seq' => $step,
                        'mandViolation' => (($session->maxStep > $step) ? $validateResult['mandViolation'] : false),
                        'valid' => (($session->maxStep > $step) ? $validateResult['vaslid'] : true),
                    ];
                    break;
                }

                if ($step >= $session->stepCount) {
                    die('noo finished?');
                    $message .= $this->updateValuesInDatabase(true);
                    $result = [
                        'finished' => true,
                        'message' => $message,
                        'gseq' => $step,
                        'seq' => $step,
                        'mandViolation' => (isset($result['mandViolation']) ? $result['mandViolation'] : false),
                        'valid' => (isset($result['valid']) ? $result['valid'] : false),
                        'unansweredSQs' => (isset($result['unansweredSQs']) ? $result['unansweredSQs'] : ''),
                        'invalidSQs' => (isset($result['invalidSQs']) ? $result['invalidSQs'] : ''),
                    ];
                }

            }
            return $result;
        }

        private function jumpToQuestion($seq, $preview, $processPOST, $force) {
            $this->StartProcessingPage();
            $session = App()->surveySessionManager->current;
            if ($processPOST) {
                $this->processData($session->response, $_POST);
            } else {
                $updatedValues = array();
            }
            $message = '';
            // Validate if moving forward.
            if (!$force && $seq > $session->step) {
                $validateResult = $this->validateQuestion($session->step, $force);
                $message .= $validateResult['message'];
                $gRelInfo = $this->getGroupRelevanceInfo($session->getQuestion($session->step)->group);
                $grel = $gRelInfo['result'];
                if ($grel && ($validateResult['mandViolation'] || !$validateResult['valid'])) {
                    // Redisplay the current question, qhowning error
                    $message .= $this->updateValuesInDatabase(false);
                    $result = $this->lastMoveResult = [
                        'finished' => false,
                        'message' => $message,
                        'mandViolation' => (($session->maxStep > $session->step) ? $validateResult['mandViolation'] : false),
                        'valid' => (($session->maxStep > $session->step) ? $validateResult['valid'] : true),
                        'unansweredSQs' => $validateResult['unansweredSQs'],
                        'invalidSQs' => $validateResult['invalidSQs'],
                    ];
                }
            }
            $stepCount = $session->stepCount;
            for ($step = $seq; $step < $stepCount; $step++) {
                $question = $session->getQuestionByIndex($step);
                /** @var QuestionValidationResult $validationResult */
                $validationResult = $this->validateQuestion($question, $force);
                $message .= $validationResult->getMessagesAsString();
                $gRelInfo = $this->getGroupRelevanceInfo($session->getQuestionByIndex($step)->group);
                $grel = $gRelInfo['result'];

                if (!$preview && ($question->bool_hidden || !$question->isRelevant($session->response))) {
                    // then skip this question
                    continue;
                } elseif (!$preview && !$validationResult->getSuccess() && $step < $seq) {
                    // if there is a violation while moving forward, need to stop and ask that set of questions
                    // if there are no violations, can skip this group as long as changed values are saved.
                    die('skip2');
                    continue;
                } else {
//                    die('break');
                    // Display new question
                    // Showing error if question are before the maxstep
                    $message .= $this->updateValuesInDatabase(false);
                    $result = [
                        'finished' => false,
                        'message' => $message,
                        'qseq' => $step,
                        'gseq' => $session->getGroupIndex($session->getQuestionByIndex($step)->gid),
                        'seq' => $step,
                        'mandViolation' => (($session->maxStep > $step) ? $validateResult['mandViolation'] : false),
                        'valid' => (($session->maxStep > $step) ? $validateResult['vaslid'] : true),
                    ];
                    break;
                }
            }
            if ($step >= $session->stepCount) {
                die('noo finished?');
                $message .= $this->updateValuesInDatabase(true);
                $result = [
                    'finished' => true,
                    'message' => $message,
                    'qseq' => $step,
                    'gseq' => $this->currentGroupSeq,
                    'seq' => $step,
                    'mandViolation' => (isset($result['mandViolation']) ? $result['mandViolation'] : false),
                    'valid' => (isset($result['valid']) ? $result['valid'] : false),
                    'unansweredSQs' => (isset($result['unansweredSQs']) ? $result['unansweredSQs'] : ''),
                    'invalidSQs' => (isset($result['invalidSQs']) ? $result['invalidSQs'] : ''),
                ];



            }
            return $result;
        }
        /**
         * Jump to a specific question or group sequence.  If jumping forward, it re-validates everything in between
         * @param <type> $seq
         * @param <type> $force - if true, then skip validation of current group (e.g. will jump even if there are errors)
         * @param <type> $preview - if true, then treat this group/question as relevant, even if it is not, so that it can be displayed
         * @return <type>
         */
        static function JumpTo($seq, $preview = false, $processPOST = true, $force = false, $changeLang = false)
        {
            bP();
            if ($seq < 0) {
                throw new \InvalidArgumentException("Sequence must be >= 0");
            }
            $session = App()->surveySessionManager->current;
            $LEM =& LimeExpressionManager::singleton();
            if (!$preview) {
                $preview = $LEM->sPreviewMode;
            }
            if (!$LEM->sPreviewMode && $preview) {
                $LEM->sPreviewMode = $preview;
            }

            $LEM->ParseResultCache = [];    // to avoid running same test more than once for a given group
            $LEM->updatedValues = [];
            switch ($session->format) {
                case Survey::FORMAT_ALL_IN_ONE:
                    // This only happens if saving data so far, so don't want to submit it, just validate and return
                    $startingGroup = $LEM->currentGroupSeq;
                    $LEM->StartProcessingPage(true);
                    if ($processPOST) {
                        $updatedValues = $LEM->ProcessCurrentResponses();
                    } else {
                        $updatedValues = array();
                    }
                    $validationResults = $LEM->_ValidateSurvey($force);
                    $LEM->lastMoveResult = array(
                        'finished' => false,
                        'message' => 'TODO',
                        'gseq' => 1,
                        'seq' => 1,
                        'mandViolation' => $validationResults->getPassedMandatory(),
                        'valid' => $validationResults->getSuccess(),
                    );

                    $result = $LEM->lastMoveResult;
                    break;
                case Survey::FORMAT_GROUP:
                    $result = $LEM->jumpToGroup($seq, $preview, $processPOST, $force);
                    break;
                case Survey::FORMAT_QUESTION:
                    $result = $LEM->jumpToQuestion($seq, $preview, $processPOST, $force);
                    break;
                default:
                    throw new \Exception("Unknown survey mode: " . $session->format);
            }


            eP();
            if ($result === null) {
                throw new \UnexpectedValueException("Result should not be null");
            }
            return $result;
        }

        /**
         * Check the entire survey
         * @param boolean $force : force validation to true, even if there are error, used at survey start to fill EM
         * @return QuestionValidationResultCollection with information on validated question
         */
        private function _ValidateSurvey($force = false)
        {
            $LEM =& $this;

            $message = '';
            $srel = false;
            $shidden = true;
            $smandViolation = false;
            $svalid = true;
            $unansweredSQs = array();
            $invalidSQs = array();
            $updatedValues = array();
            $sanyUnanswered = false;

            ///////////////////////////////////////////////////////
            // CHECK EACH GROUP, AND SET SURVEY-LEVEL PROPERTIES //
            ///////////////////////////////////////////////////////
            $session = App()->surveySessionManager->current;
            $validationResults = new QuestionValidationResultCollection();
            foreach($session->getGroups() as $group) {
                if (!$group->isRelevant($session->response)) {
                    continue;
                }

                $validationResults->mergeWith($LEM->validateGroup($group, $force));
                // Skip group if it has no questions that are relevant AND visible.
                if (count($validationResults) == 0) {
                    continue;
                }


            }
            return $validationResults;
        }

        /**
         * Check a group and all of the questions it contains
         * @param QuestionGroup $group The group to be validated.
         * @param boolean $force : force validation to true, even if there are error
         * @return QuestionValidationResultCollection Validation result for all relevant and visible questions.
         */
        public function validateGroup(QuestionGroup $group, $force = false)
        {
            $session = App()->surveySessionManager->current;
            $validationResults = new QuestionValidationResultCollection();

            foreach ($session->getQuestions($group) as $question) {
                if (!$question->bool_hidden && $question->isRelevant($session->response)) {
                    $validationResults->add($this->validateQuestion($question, $force));
                }
            }

            return $validationResults;
        }


        /**
         * For the current set of questions (whether in survey, gtoup, or question-by-question mode), assesses the following:
         * (a) mandatory - if so, then all relevant sub-questions must be answered (e.g. pay attention to array_filter and array_filter_exclude)
         * (b) always-hidden
         * (c) relevance status - including sub-question-level relevance
         * (d) answered - if $_SESSION[$LEM->sessid][sgqa]=='' or NULL, then it is not answered
         * (e) validity - whether relevant questions pass their validity tests
         * @param integer $questionSeq - the 0-index sequence number for this question
         * @param boolean $force : force validation to true, even if there are error, this allow to save in DB even with error
         * @return QuestionValidationResult
         */

        private function validateQuestion(\Question $question, $force = false)
        {
            $session = App()->surveySessionManager->current;
            $result =  $question->validateResponse($session->response);
            return $result;
            $LEM =& $this;
            $knownVars = $this->getKnownVars();

            $qrel = true;   // assume relevant unless discover otherwise
            $prettyPrintRelEqn = '';    //  assume no relevance eqn by default
            $qid = $question->qid;
            $gid = $question->gid;
            $gseq = $session->getGroupIndex($gid);
            $debug_qmessage = '';

            $gRelInfo = $LEM->getGroupRelevanceInfo($question->group);
            $grel = $gRelInfo['result'];

            ///////////////////////////
            // IS QUESTION RELEVANT? //
            ///////////////////////////
            $relevanceEqn = isset($question->relevance) && !empty($question->relevance) ? $question->relevance : 1;
            $relevanceEqn = htmlspecialchars_decode($relevanceEqn, ENT_QUOTES);  // TODO is this needed?
            // assumes safer to re-process relevance and not trust POST values
            $qrel = $LEM->em->ProcessBooleanExpression($relevanceEqn, $gseq, $session->getQuestionIndex($question->primaryKey));

            $hasErrors = $LEM->em->HasErrors();
            if (($LEM->debugLevel & LEM_PRETTY_PRINT_ALL_SYNTAX) == LEM_PRETTY_PRINT_ALL_SYNTAX) {
                $prettyPrintRelEqn = $LEM->em->GetPrettyPrintString();
            }

            //////////////////////////////////////
            // ARE ANY SUB-QUESTION IRRELEVANT? //
            //////////////////////////////////////
            // identify the relevant subquestions (array_filter and array_filter_exclude may make some irrelevant)
            $relevantSQs = array();
            $irrelevantSQs = array();
            $prettyPrintSQRelEqns = array();
            $prettyPrintSQRelEqn = '';
             $prettyPrintValidTip = '';
            $anyUnanswered = false;

            if (!$qrel) {
                // All sub-questions are irrelevant

                $irrelevantSQs = array_keys($question->getFields());
            } else {
                foreach ($question->getFields() as $fieldName => $details) {
                    // for each subq, see if it is part of an array_filter or array_filter_exclude
                    if (!isset($LEM->subQrelInfo[$question->primaryKey])) {
                        $relevantSQs[] = $question->sgqa;
                        continue;
                    }
                    $foundSQrelevance = false;
                    if ($question->type == Question::TYPE_RANKING) {
                        // Relevance of subquestion for ranking question depend of the count of relevance of answers.
                        $iCountRank = (isset($iCountRank) ? $iCountRank + 1 : 1);
                        $iCountRelevant = isset($iCountRelevant) ? $iCountRelevant : count(array_filter($LEM->subQrelInfo[$qid],
                            function ($sqRankAnwsers) {
                                return $sqRankAnwsers['result'];
                            }));
                        if ($iCountRank > $iCountRelevant) {
                            $foundSQrelevance = true;
                            $irrelevantSQs[] = $question->sgqa;
                        } else {
                            $relevantSQs[] = $question->sgqa;
                        }
                        continue;
                    }

                    foreach ($LEM->subQrelInfo[$qid] as $sq) {
                        switch ($sq['qtype']) {
                            case '1':   //Array (Flexible Labels) dual scale
                                if ($sgqa == ($sq['rowdivid'] . '#0') || $sgqa == ($sq['rowdivid'] . '#1')) {
                                    $foundSQrelevance = true;
                                    if (isset($LEM->ParseResultCache[$sq['eqn']])) {
                                        $sqrel = $LEM->ParseResultCache[$sq['eqn']]['result'];
                                        if (($LEM->debugLevel & LEM_PRETTY_PRINT_ALL_SYNTAX) == LEM_PRETTY_PRINT_ALL_SYNTAX) {
                                            $prettyPrintSQRelEqns[$sq['rowdivid']] = $LEM->ParseResultCache[$sq['eqn']]['prettyprint'];
                                        }
                                    } else {
                                        $stringToParse = htmlspecialchars_decode($sq['eqn'],
                                            ENT_QUOTES);  // TODO is this needed?
                                        $sqrel = $LEM->em->ProcessBooleanExpression($stringToParse, $session->getGroupIndex($question->gid),
                                            $session->getQuestionIndex($question->primaryKey));
                                        $hasErrors = $LEM->em->HasErrors();
                                        // make sure subquestions with errors in relevance equations are always shown and answers recorded  #7703
                                        if ($hasErrors) {
                                            $sqrel = true;
                                        }
                                        if (($LEM->debugLevel & LEM_PRETTY_PRINT_ALL_SYNTAX) == LEM_PRETTY_PRINT_ALL_SYNTAX) {
                                            $prettyPrintSQRelEqn = $LEM->em->GetPrettyPrintString();
                                            $prettyPrintSQRelEqns[$sq['rowdivid']] = $prettyPrintSQRelEqn;
                                        }
                                        $LEM->ParseResultCache[$sq['eqn']] = array(
                                            'result' => $sqrel,
                                            'prettyprint' => $prettyPrintSQRelEqn,
                                            'hasErrors' => $hasErrors,
                                        );
                                    }
                                    if ($sqrel) {
                                        $relevantSQs[] = $sgqa;
                                        $_SESSION[$LEM->sessid]['relevanceStatus'][$sq['rowdivid']] = true;
                                    } else {
                                        $irrelevantSQs[] = $sgqa;
                                        $_SESSION[$LEM->sessid]['relevanceStatus'][$sq['rowdivid']] = false;
                                    }
                                }
                                break;
                            case ':': //ARRAY (Multi Flexi) 1 to 10
                            case ';': //ARRAY (Multi Flexi) Text
                                if (preg_match('/^' . $sq['rowdivid'] . '_/', $sgqa)) {
                                    $foundSQrelevance = true;
                                    if (isset($LEM->ParseResultCache[$sq['eqn']])) {
                                        $sqrel = $LEM->ParseResultCache[$sq['eqn']]['result'];
                                        if (($LEM->debugLevel & LEM_PRETTY_PRINT_ALL_SYNTAX) == LEM_PRETTY_PRINT_ALL_SYNTAX) {
                                            $prettyPrintSQRelEqns[$sq['rowdivid']] = $LEM->ParseResultCache[$sq['eqn']]['prettyprint'];
                                        }
                                    } else {
                                        $stringToParse = htmlspecialchars_decode($sq['eqn'],
                                            ENT_QUOTES);  // TODO is this needed?
                                        $sqrel = $LEM->em->ProcessBooleanExpression($stringToParse, $session->getGroupIndex($question->gid),
                                            $session->getQuestionIndex($question->primaryKey));
                                        $hasErrors = $LEM->em->HasErrors();
                                        // make sure subquestions with errors in relevance equations are always shown and answers recorded  #7703
                                        if ($hasErrors) {
                                            $sqrel = true;
                                        }
                                        if (($LEM->debugLevel & LEM_PRETTY_PRINT_ALL_SYNTAX) == LEM_PRETTY_PRINT_ALL_SYNTAX) {
                                            $prettyPrintSQRelEqn = $LEM->em->GetPrettyPrintString();
                                            $prettyPrintSQRelEqns[$sq['rowdivid']] = $prettyPrintSQRelEqn;
                                        }
                                        $LEM->ParseResultCache[$sq['eqn']] = array(
                                            'result' => $sqrel,
                                            'prettyprint' => $prettyPrintSQRelEqn,
                                            'hasErrors' => $hasErrors,
                                        );
                                    }
                                    if ($sqrel) {
                                        $relevantSQs[] = $sgqa;
                                        $_SESSION[$LEM->sessid]['relevanceStatus'][$sq['rowdivid']] = true;
                                    } else {
                                        $irrelevantSQs[] = $sgqa;
                                        $_SESSION[$LEM->sessid]['relevanceStatus'][$sq['rowdivid']] = false;
                                    }
                                }
                            case 'A': //ARRAY (5 POINT CHOICE) radio-buttons
                            case 'B': //ARRAY (10 POINT CHOICE) radio-buttons
                            case 'C': //ARRAY (YES/UNCERTAIN/NO) radio-buttons
                            case 'E': //ARRAY (Increase/Same/Decrease) radio-buttons
                            case 'F': //ARRAY (Flexible) - Row Format
                            case 'M': //Multiple choice checkbox
                            case 'P': //Multiple choice with comments checkbox + text
                                // Note, for M and P, Mandatory should mean that at least one answer was picked - not that all were checked
                            case 'K': //MULTIPLE NUMERICAL QUESTION
                            case 'Q': //MULTIPLE SHORT TEXT
                                if ($sgqa == $sq['rowdivid'] || $sgqa == ($sq['rowdivid'] . 'comment'))     // to catch case 'P'
                                {
                                    $foundSQrelevance = true;
                                    if (isset($LEM->ParseResultCache[$sq['eqn']])) {
                                        $sqrel = $LEM->ParseResultCache[$sq['eqn']]['result'];
                                        if (($LEM->debugLevel & LEM_PRETTY_PRINT_ALL_SYNTAX) == LEM_PRETTY_PRINT_ALL_SYNTAX) {
                                            $prettyPrintSQRelEqns[$sq['rowdivid']] = $LEM->ParseResultCache[$sq['eqn']]['prettyprint'];
                                        }
                                    } else {
                                        $stringToParse = htmlspecialchars_decode($sq['eqn'],
                                            ENT_QUOTES);  // TODO is this needed?
                                        $sqrel = $LEM->em->ProcessBooleanExpression($stringToParse, $session->getGroupIndex($question->gid),
                                            $session->getQuestionIndex($question->primaryKey));
                                        $hasErrors = $LEM->em->HasErrors();
                                        // make sure subquestions with errors in relevance equations are always shown and answers recorded  #7703
                                        if ($hasErrors) {
                                            $sqrel = true;
                                        }
                                        if (($LEM->debugLevel & LEM_PRETTY_PRINT_ALL_SYNTAX) == LEM_PRETTY_PRINT_ALL_SYNTAX) {
                                            $prettyPrintSQRelEqn = $LEM->em->GetPrettyPrintString();
                                            $prettyPrintSQRelEqns[$sq['rowdivid']] = $prettyPrintSQRelEqn;
                                        }
                                        $LEM->ParseResultCache[$sq['eqn']] = array(
                                            'result' => $sqrel,
                                            'prettyprint' => $prettyPrintSQRelEqn,
                                            'hasErrors' => $hasErrors,
                                        );
                                    }
                                    if ($sqrel) {
                                        $relevantSQs[] = $sgqa;
                                        $_SESSION[$LEM->sessid]['relevanceStatus'][$sq['rowdivid']] = true;
                                    } else {
                                        $irrelevantSQs[] = $sgqa;
                                        $_SESSION[$LEM->sessid]['relevanceStatus'][$sq['rowdivid']] = false;
                                    }
                                }
                                break;
                            case 'L': //LIST drop-down/radio-button list
                                if ($sgqa == ($sq['sgqa'] . 'other') && $sgqa == $sq['rowdivid'])   // don't do sub-q level validition to main question, just to other option
                                {
                                    $foundSQrelevance = true;
                                    if (isset($LEM->ParseResultCache[$sq['eqn']])) {
                                        $sqrel = $LEM->ParseResultCache[$sq['eqn']]['result'];
                                        if (($LEM->debugLevel & LEM_PRETTY_PRINT_ALL_SYNTAX) == LEM_PRETTY_PRINT_ALL_SYNTAX) {
                                            $prettyPrintSQRelEqns[$sq['rowdivid']] = $LEM->ParseResultCache[$sq['eqn']]['prettyprint'];
                                        }
                                    } else {
                                        $stringToParse = htmlspecialchars_decode($sq['eqn'],
                                            ENT_QUOTES);  // TODO is this needed?
                                        $sqrel = $LEM->em->ProcessBooleanExpression($stringToParse, $session->getGroupIndex($question->gid),
                                            $session->getQuestionIndex($question->primaryKey));
                                        $hasErrors = $LEM->em->HasErrors();
                                        // make sure subquestions with errors in relevance equations are always shown and answers recorded  #7703
                                        if ($hasErrors) {
                                            $sqrel = true;
                                        }
                                        if (($LEM->debugLevel & LEM_PRETTY_PRINT_ALL_SYNTAX) == LEM_PRETTY_PRINT_ALL_SYNTAX) {
                                            $prettyPrintSQRelEqn = $LEM->em->GetPrettyPrintString();
                                            $prettyPrintSQRelEqns[$sq['rowdivid']] = $prettyPrintSQRelEqn;
                                        }
                                        $LEM->ParseResultCache[$sq['eqn']] = array(
                                            'result' => $sqrel,
                                            'prettyprint' => $prettyPrintSQRelEqn,
                                            'hasErrors' => $hasErrors,
                                        );
                                    }
                                    if ($sqrel) {
                                        $relevantSQs[] = $sgqa;
                                    } else {
                                        $irrelevantSQs[] = $sgqa;
                                    }
                                }
                                break;
                            default:
                                break;
                        }
                    }   // end foreach($LEM->subQrelInfo) [checking array-filters]
                    if (!$foundSQrelevance) {
                        // then this question is relevant
                        $relevantSQs[] = $sgqa; // TODO - check this
                    }
                }
            } // end of processing relevant question for sub-questions
            // These array_unique only apply to array_filter of type L (list)
            $relevantSQs = array_unique($relevantSQs);
            $irrelevantSQs = array_unique($irrelevantSQs);

            //////////////////////////////////////////////
            // DETECT ANY VIOLATIONS OF MANDATORY RULES //
            //////////////////////////////////////////////

            $qmandViolation = !$question->validateResponse($session->response)->getPassedMandatory();
            $mandatoryTip = '';
            if ($qrel && !$question->bool_hidden && $qmandViolation) {
                $mandatoryTip = "<strong><br /><span class='errormandatory'>" . gT('This question is mandatory') . '.  ';
                switch ($question->type) {
                    case Question::TYPE_MULTIPLE_CHOICE:
                    case Question::TYPE_MULTIPLE_CHOICE_WITH_COMMENT:
                        $mandatoryTip .= gT('Please check at least one item.');
                    case Question::TYPE_DROPDOWN_LIST:
                    case Question::TYPE_RADIO_LIST:

                        // If at least one checkbox is checked, we're OK
                        if ($question->bool_other) {
                            $qattr = isset($LEM->qattr[$qid]) ? $LEM->qattr[$qid] : array();
                            if (isset($qattr['other_replace_text']) && trim($qattr['other_replace_text']) != '') {
                                $othertext = trim($qattr['other_replace_text']);
                            } else {
                                $othertext = gT('Other:');
                            }
                            $mandatoryTip .= "<br />\n" . sprintf(gT("If you choose '%s' please also specify your choice in the accompanying text field."),
                                    $othertext);
                        }
                        break;
                    case Question::TYPE_DISPLAY:   // Boilerplate can never be mandatory
                    case Question::TYPE_EQUATION:   // Equation is auto-computed, so can't violate mandatory rules
                        break;
                    case 'A':
                    case 'B':
                    case 'C':
                    case 'Q':
                    case 'K':
                    case 'E':
                    case 'F':
                    case 'J':
                    case 'H':
                    case ';':
                    case '1':
                        $mandatoryTip .= gT('Please complete all parts') . '.';
                        break;
                    case Question::TYPE_ARRAY_NUMBERS:
                        if ($question->multiflexible_checkbox) {
                            $mandatoryTip .= gT('Please check at least one box per row') . '.';
                        } else {
                            $mandatoryTip .= gT('Please complete all parts') . '.';
                        }
                        break;
                    case 'R':
                        $mandatoryTip .= gT('Please rank all items') . '.';
                        break;
                }
                $mandatoryTip .= "</span></strong>\n";
            }

            ///////////////////////////////////////////////
            // DETECT ANY VIOLATIONS OF VALIDATION RULES //
            ///////////////////////////////////////////////
            $qvalid = true;   // assume valid unless discover otherwise
            $hasValidationEqn = false;
            $prettyPrintValidEqn = '';    //  assume no validation eqn by default
            $validationEqn = '';
            $validationJS = '';       // assume can't generate JavaScript to validate equation
            $hasValidationEqn = true;
            if (!$question->bool_hidden)  // do this even is starts irrelevant, else will never show this information.
            {
                $validationEqns = $question->getValidationExpressions();
                $validationEqn = implode(' and ', $validationEqns);
                $qvalid = $LEM->em->ProcessBooleanExpression($validationEqn, $gseq, $session->getQuestionIndex($question->primaryKey));
                $hasErrors = $LEM->em->HasErrors();
                if (!$hasErrors) {
                    $validationJS = $LEM->em->GetJavaScriptEquivalentOfExpression();
                }
                $prettyPrintValidEqn = $validationEqn;
                if ((($this->debugLevel & LEM_PRETTY_PRINT_ALL_SYNTAX) == LEM_PRETTY_PRINT_ALL_SYNTAX)) {
                    $prettyPrintValidEqn = $LEM->em->GetPrettyPrintString();
                }

                $stringToParse = '';
                $tips = []; // $LEM->qid2validationEqn[$qid]['tips']
                foreach ($tips as $vclass => $vtip) {
                    $stringToParse .= "<div id='vmsg_" . $qid . '_' . $vclass . "' class='em_" . $vclass . " emtip'>" . $vtip . "</div>\n";
                }
                $prettyPrintValidTip = $stringToParse;
                $validTip = $LEM->ProcessString($stringToParse, $qid, null, false, 1, 1, false, false);
                // TODO check for errors?
                if ((($this->debugLevel & LEM_PRETTY_PRINT_ALL_SYNTAX) == LEM_PRETTY_PRINT_ALL_SYNTAX)) {
                    $prettyPrintValidTip = $LEM->GetLastPrettyPrintExpression();
                }
//                $sumEqn = $LEM->qid2validationEqn[$qid]['sumEqn'];
//                $sumRemainingEqn = $LEM->qid2validationEqn[$qid]['sumRemainingEqn'];
                //                $countEqn = $LEM->qid2validationEqn[$qid]['countEqn'];
                //                $countRemainingEqn = $LEM->qid2validationEqn[$qid]['countRemainingEqn'];

            }

            if (!$qvalid) {
                $invalidSQs = $question->title; // TODO - currently invalidates all - should only invalidate those that truly fail validation rules.
            }
            /////////////////////////////////////////////////////////
            // OPTIONALLY DISPLAY (DETAILED) DEBUGGING INFORMATION //
            /////////////////////////////////////////////////////////
            if (($LEM->debugLevel & LEM_DEBUG_VALIDATION_SUMMARY) == LEM_DEBUG_VALIDATION_SUMMARY) {
                $editlink = Yii::app()->getController()->createUrl('admin/survey/sa/view/surveyid/' . $LEM->sid . '/gid/' . $gid . '/qid/' . $qid);
                $debug_qmessage .= '--[Q#' . $session->getQuestionIndex($question->primaryKey) . ']'
                    . "[<a href='$editlink'>"
                    . 'QID:' . $qid . '</a>][' . $question->type . ']: '
                    . ($qrel ? 'relevant' : " <span style='color:red'>irrelevant</span> ")
                    . ($question->bool_hidden ? " <span style='color:red'>always-hidden</span> " : ' ')
                    . (($question->bool_mandatory) ? ' mandatory' : ' ')
                    . (($hasValidationEqn) ? (!$qvalid ? " <span style='color:red'>(fails validation rule)</span> " : ' valid') : '')
                    . ($qmandViolation ? " <span style='color:red'>(missing a relevant mandatory)</span> " : ' ')
                    . $prettyPrintRelEqn
                    . "<br />\n";

            }


            /////////////////////////////////////////////////////////////
            // CREATE ARRAY OF VALUES THAT NEED TO BE SILENTLY UPDATED //
            /////////////////////////////////////////////////////////////
            $updatedValues = array();
            if ((!$qrel || !$grel) && SettingGlobal::get('deletenonvalues')) {
                // If not relevant, then always NULL it in the database
                $sgqas = explode('|', $LEM->qid2code[$qid]);
                foreach ($sgqas as $sgqa) {
                    $session->response->$sgqa = null;
                    if ($sgqa == '') {
                        throw new \Exception("Invalid sgqa: ''");
                    }
                }
            } elseif ($question->type == Question::TYPE_EQUATION) {
                // Process relevant equations, even if hidden, and write the result to the database
                $textToParse = $question->question;
                $result = flattenText($LEM->ProcessString($textToParse, $question->primaryKey,NULL,false,1,1,false,false,true));// More numRecursionLevels ?
                $sgqa = $question->sgqa;
                $redata = array();
                $result = flattenText(templatereplace( // Why flattenText ? htmlspecialchars($string,ENT_NOQUOTES) seem better ?
                    $textToParse,
                    array('QID' => $question->primaryKey, 'GID' => $question->gid, 'SGQ' => $sgqa),
                    // Some date for replacement, other are only for "view"
                    $redata,
                    '',
                    false,
                    $question->primaryKey,
                    array(),
                    true // Static replace
                ));
                if ($LEM->getKnownVars()[$sgqa]['onlynum']) {
                    $result = (is_numeric($result) ? $result : "");
                }
                // Store the result of the Equation in the SESSION
                App()->surveySessionManager->current->response->$sgqa = $result;
                die('ok")');
                $_update = array(
                    'type' => '*',
                    'value' => $result,
                );
                $updatedValues[$sgqa] = $_update;
                $LEM->updatedValues[$sgqa] = $_update;

                if (($LEM->debugLevel & LEM_DEBUG_VALIDATION_DETAIL) == LEM_DEBUG_VALIDATION_DETAIL) {
                    $prettyPrintEqn = $LEM->em->GetPrettyPrintString();
                    $debug_qmessage .= '** Process Hidden but Relevant Equation [' . $sgqa . '](' . $prettyPrintEqn . ') => ' . $result . "<br />\n";
                }
            }

            if (SettingGlobal::get('deletenonvalues')) {
                foreach ($irrelevantSQs as $sq) {
                    // NULL irrelevant sub-questions
                    $session->response->$sq = null;
                }
            }

            //////////////////////////////////////////////////////////////////////////
            // STORE METADATA NEEDED FOR SUBSEQUENT PROCESSING AND DISPLAY PURPOSES //
            //////////////////////////////////////////////////////////////////////////

            $qStatus = array(
                // collect all questions within the group - includes mandatory and always-hiddden status
                'relevant' => $qrel,
                'hidden' => $question->bool_hidden,
                'relEqn' => $prettyPrintRelEqn,
                'valid' => $force || $qvalid,
                'validEqn' => $validationEqn,
                'prettyValidEqn' => $prettyPrintValidEqn,
                'validTip' => $validTip,
                'prettyValidTip' => $prettyPrintValidTip,
                'validJS' => $validationJS,
                'invalidSQs' => (isset($invalidSQs) && !$force) ? $invalidSQs : '',
                'relevantSQs' => implode('|', $relevantSQs),
                'irrelevantSQs' => implode('|', $irrelevantSQs),
                'subQrelEqn' => implode('<br />', $prettyPrintSQRelEqns),
                'mandViolation' => (!$force) ? $qmandViolation : false,
                'anyUnanswered' => $anyUnanswered,
                'mandTip' => (!$force) ? $mandatoryTip : '',
                'message' => $debug_qmessage,
                'updatedValues' => $updatedValues,
                'sumEqn' => (isset($sumEqn) ? $sumEqn : ''),
                'sumRemainingEqn' => (isset($sumRemainingEqn) ? $sumRemainingEqn : ''),
                //            'countEqn' => (isset($countEqn) ? $countEqn : ''),
                //            'countRemainingEqn' => (isset($countRemainingEqn) ? $countRemainingEqn : ''),

            );

            return $qStatus;
        }

        public function getQuestionNavIndex($questionSeq) {
            bP();
            $session = App()->surveySessionManager->current;
            $question = $session->getQuestionByIndex($questionSeq);
            $validationEqn = implode(' and ', $question->getValidationExpressions());
            $result = [
                'qid' => $question->primaryKey,
                'qtext' => $question->question,
                'qcode' => $question->title,
                'qhelp' => $question->help,
                'gid' => $question->gid,
                'mandViolation' => $question->validateResponse($session->response)->getPassedMandatory(),
                'valid' => $this->em->ProcessBooleanExpression($validationEqn, $session->getGroupIndex($question->gid), $questionSeq)
            ];
            eP();
            return $result;
        }

        static function GetQuestionStatus($qid)
        {
            if (isset(LimeExpressionManager::singleton()->currentQset[$qid])) {
                return LimeExpressionManager::singleton()->currentQset[$qid];
            }
        }

        /**
         * Get array of info needed to display the Group Index
         * @return <type>
         */
        static function GetGroupIndexInfo($gseq = null)
        {
            if (is_null($gseq)) {
                return LimeExpressionManager::singleton()->indexGseq;
            } else {
                return LimeExpressionManager::singleton()->indexGseq[$gseq];
            }
        }




        /**
         * Get array of info needed to display the Question Index
         * @return <type>
         */
        static function GetQuestionIndexInfo()
        {
            $LEM =& LimeExpressionManager::singleton();

            return $LEM->indexQseq;
        }

        /**
         * Return entries needed to build the navigation index
         * @param int $step - return a single value, otherwise return entire array
         * @return array  - will be either question or group-level, depending upon $surveyMode
         */
        static function GetStepIndexInfo($step)
        {
            bP();
            if (!is_int($step)) {
                throw new \InvalidArgumentException("Step argument must be an integer");
            }
            $LEM =& LimeExpressionManager::singleton();
            $session = App()->surveySessionManager->current;
            switch ($session->format) {
                case Survey::FORMAT_ALL_IN_ONE:
                    $result = $LEM->lastMoveResult;
                    break;
                case Survey::FORMAT_GROUP:
                    $result = null;
                    break;
                case Survey::FORMAT_QUESTION:
                    $result = $LEM->getQuestionNavIndex($step);
                    break;
            }
            eP();
            return $result;
        }

        /**
         * This should be called each time a new group is started, whether on same or different pages. Sets/Clears needed internal parameters.
         * @param <type> $gseq - the group sequence
         * @param <type> $anonymized - whether anonymized
         * @param <type> $forceRefresh - whether to force refresh of setting variable and token mappings (should be done rarely)
         */
        public static function StartProcessingGroup($gseq, $anonymized = false, $surveyid = null, $forceRefresh = false)
        {
            $session = App()->surveySessionManager->current;
            self::singleton()->em->StartProcessingGroup(
                $session->surveyId,
                '',
                isset($LEM->surveyOptions['hyperlinkSyntaxHighlighting']) ? $LEM->surveyOptions['hyperlinkSyntaxHighlighting'] : false
            );
        }

        /**
         * Returns an array of string parts, splitting out expressions
         * @param type $src
         * @return type
         */
        static function SplitStringOnExpressions($src)
        {
            $LEM =& LimeExpressionManager::singleton();

            return $LEM->em->asSplitStringOnExpressions($src);
        }

        /**
         * Should be called at end of each page
         */
        static function FinishProcessingPage()
        {
            $LEM =& LimeExpressionManager::singleton();

            $LEM->initialized = false;    // so detect calls after done
            $LEM->ParseResultCache = array(); // don't need to persist it in session
        }


        /*
        * Generate JavaScript needed to do dynamic relevance and tailoring
        * Also create list of variables that need to be declared
        */
        public static function GetRelevanceAndTailoringJavaScript(Question $question)
        {
            $session = App()->surveySessionManager->current;
            $LEM = LimeExpressionManager::singleton();
            /** @var CClientScript $clientScript */
            $clientScript = App()->getClientScript();
            $clientScript->registerScriptFile(SettingGlobal::get('generalscripts', '/scripts') . "/expressions/em_javascript.js");
            $fields = [];
            foreach($session->getGroups() as $group) {
                foreach($session->getQuestions($group) as $question) {
                    foreach($question->getFields() as $field) {
                        $field->value = $session->response->{$field->name};
                        $fields[$field->code] = $field;
                    }
                }
            }
            $clientScript->registerScript('EM', 'var EM = new ExpressionManager(' . json_encode($fields) . ');', CClientScript::POS_END);
            return $question->getRelevanceScript();




            $jsParts = array();
            $allJsVarsUsed = [];
            $rowdividList = array();   // list of subquestions needing relevance entries

            $jsParts[] = "\n<script type='text/javascript'>\n<!--\n";
            $jsParts[] = "var LEMmode='" . $session->format . "';\n";

            // flatten relevance array, keeping proper order

            $qidList = []; // list of questions used in relevance and tailoring
            $gidList = []; // list of gseqs on this page
            $gid_qidList = []; // list of qids using relevance/tailoring within each group


            $valEqns = array();
            $relEqns = array();
            $relChangeVars = array();

            $dynamicQinG = array(); // array of questions, per group, that might affect group-level visibility in all-in-one mode
            $GalwaysRelevant = array(); // checks whether a group is always relevant (e.g. has at least one question that is always shown)
            foreach ($LEM->getPageRelevanceInfo() as $arg) {
                $gidList[$arg['gid']] = $arg['gid'];
                // First check if there is any tailoring  and construct the tailoring JavaScript if needed
                $tailorParts = array();
                $relParts = array();    // relevance equation
                $valParts = array();    // validation
                $relJsVarsUsed = array();   // vars used in relevance and tailoring
                $valJsVarsUsed = array();   // vars used in validations
                foreach ($LEM->getPageTailorInfo() as $sub) {
                    if ($sub['questionNum'] == $arg['qid']) {
                        $tailorParts[] = $sub['js'];
                        foreach(explode('|', $sub['vars']) as $val) {
                            $allJsVarsUsed[$val] = $val;
                            $relJsVarsUsed[$val] = $val;
                        }
                    }
                }

                // Now check whether there is sub-question relevance to perform for this question
                $subqParts = array();
                if (isset($LEM->subQrelInfo[$arg['qid']])) {
                    foreach ($LEM->subQrelInfo[$arg['qid']] as $subq) {
                        $subqParts[$subq['rowdivid']] = $subq;
                    }
                }

                $qidList[$arg['qid']] = $arg['qid'];
                if (!isset($gid_qidList[$arg['gid']])) {
                    $gid_qidList[$arg['gid']] = [];
                }
                $gid_qidList[$arg['gid']][$arg['qid']] = '0';   // means the qid is within this gseq, but may not have a relevance equation

                // Now check whether any sub-question validation needs to be performed
                $subqValidations = array();
                $validationEqns = array();
                if (isset($LEM->qid2validationEqn[$arg['qid']])) {
                    if (isset($LEM->qid2validationEqn[$arg['qid']]['subqValidEqns'])) {
                        $_veqs = $LEM->qid2validationEqn[$arg['qid']]['subqValidEqns'];
                        foreach ($_veqs as $_veq) {
                            // generate JavaScript for each - tests whether invalid.
                            if (strlen(trim($_veq['subqValidEqn'])) == 0) {
                                continue;
                            }
                            $subqValidations[] = array(
                                'subqValidEqn' => $_veq['subqValidEqn'],
                                'subqValidSelector' => $_veq['subqValidSelector'],
                            );
                        }
                    }
                    $validationEqns = $LEM->qid2validationEqn[$arg['qid']]['eqn'];
                }

                // Process relevance for question $arg['qid'];
                $relevance = $arg['relevancejs'];

                $relChangeVars[] = "  relChange" . $arg['qid'] . "=false;\n"; // detect change in relevance status

                if (!isset($arg['numJsVars'])) {
                    vdd($arg);
                }
                if (($relevance == '' || $relevance == '1' || ($arg['result'] == true && $arg['numJsVars'] == 0)) && count($tailorParts) == 0 && count($subqParts) == 0 && count($subqValidations) == 0 && count($validationEqns) == 0) {
                    // Only show constitutively true relevances if there is tailoring that should be done.
                    // After we can assign var with EM and change again relevance : then doing it second time (see bug #08315).
                    $relParts[] = "$('#relevance" . $arg['qid'] . "').val('1');  // always true\n";
                    $GalwaysRelevant[$arg['gseq']] = true;
                    continue;
                }
                $relevance = ($relevance == '' || ($arg['result'] == true && $arg['numJsVars'] == 0)) ? '1' : $relevance;
                $relParts[] = "\nif (" . $relevance . ")\n{\n";
                ////////////////////////////////////////////////////////////////////////
                // DO ALL ARRAY FILTERING FIRST - MAY AFFECT VALIDATION AND TAILORING //
                ////////////////////////////////////////////////////////////////////////

                // Do all sub-question filtering (e..g array_filter)
                /**
                 * $afHide - if true, then use jQuery.show().  If false, then disable/enable the row
                 */
                $afHide = (isset($LEM->qattr[$arg['qid']]['array_filter_style']) ? ($LEM->qattr[$arg['qid']]['array_filter_style'] == '0') : true);
                $inputSelector = (($arg['type'] == 'R') ? '' : ' :input:not(:hidden)');
                foreach ($subqParts as $sq) {
                    $rowdividList[$sq['rowdivid']] = $sq['result'];
                    // make sure to update headings and colors for filtered questions (array filter and individual SQ relevance)
                    if (!empty($sq['type'])) {
                        // js to fix colors
                        $relParts[] = "updateColors($('#question" . $arg['qid'] . "').find('table.question'));\n";
                        // js to fix headings
                        $repeatheadings = Yii::app()->getConfig("repeatheadings");
                        if (isset($LEM->qattr[$arg['qid']]['repeat_headings']) && $LEM->qattr[$arg['qid']]['repeat_headings'] !== "") {
                            $repeatheadings = $LEM->qattr[$arg['qid']]['repeat_headings'];
                        }
                        if ($repeatheadings > 0) {
                            $relParts[] = "updateHeadings($('#question" . $arg['qid'] . "').find('table.question'), "
                                . $repeatheadings . ");\n";
                        }
                    }
                    // end
                    //this change is optional....changes to array should prevent "if( )"
                    $relParts[] = "  if ( " . (empty($sq['relevancejs']) ? '1' : $sq['relevancejs']) . " ) {\n";
                    if ($afHide) {
                        $relParts[] = "    $('#javatbd" . $sq['rowdivid'] . "').show();\n";
                    } else {
                        $relParts[] = "    $('#javatbd" . $sq['rowdivid'] . "$inputSelector').removeAttr('disabled');\n";
                    }
                    if ($sq['isExclusiveJS'] != '') {
                        $relParts[] = "    if ( " . $sq['isExclusiveJS'] . " ) {\n";
                        $relParts[] = "      $('#javatbd" . $sq['rowdivid'] . "$inputSelector').attr('disabled','disabled');\n";
                        $relParts[] = "    }\n";
                        $relParts[] = "    else {\n";
                        $relParts[] = "      $('#javatbd" . $sq['rowdivid'] . "$inputSelector').removeAttr('disabled');\n";
                        $relParts[] = "    }\n";
                    }
                    $relParts[] = "    relChange" . $arg['qid'] . "=true;\n";
                    if ($arg['type'] != 'R') // Ranking: rowdivid are subquestion, but array filter apply to answers and not SQ.
                    {
                        $relParts[] = "    $('#relevance" . $sq['rowdivid'] . "').val('1');\n";
                    }
                    $relParts[] = "  }\n  else {\n";
                    if ($sq['isExclusiveJS'] != '') {
                        if ($sq['irrelevantAndExclusiveJS'] != '') {
                            $relParts[] = "    if ( " . $sq['irrelevantAndExclusiveJS'] . " ) {\n";
                            $relParts[] = "      $('#javatbd" . $sq['rowdivid'] . "$inputSelector').attr('disabled','disabled');\n";
                            $relParts[] = "    }\n";
                            $relParts[] = "    else {\n";
                            $relParts[] = "      $('#javatbd" . $sq['rowdivid'] . "$inputSelector').removeAttr('disabled');\n";
                            if ($afHide) {
                                $relParts[] = "     $('#javatbd" . $sq['rowdivid'] . "').hide();\n";
                            } else {
                                $relParts[] = "     $('#javatbd" . $sq['rowdivid'] . "$inputSelector').attr('disabled','disabled');\n";
                            }
                            $relParts[] = "    }\n";
                        } else {
                            $relParts[] = "      $('#javatbd" . $sq['rowdivid'] . "$inputSelector').attr('disabled','disabled');\n";
                        }
                    } else {
                        if ($afHide) {
                            $relParts[] = "    $('#javatbd" . $sq['rowdivid'] . "').hide();\n";
                        } else {
                            $relParts[] = "    $('#javatbd" . $sq['rowdivid'] . "$inputSelector').attr('disabled','disabled');\n";
                        }
                    }
                    $relParts[] = "    relChange" . $arg['qid'] . "=true;\n";
                    if ($arg['type'] != 'R') // Ranking: rowdivid are subquestion, but array filter apply to answers and not SQ.
                    {
                        $relParts[] = "    $('#relevance" . $sq['rowdivid'] . "').val('');\n";
                    }
                    switch ($sq['qtype']) {
                        case 'L': //LIST drop-down/radio-button list
                            $listItem = substr($sq['rowdivid'],
                                strlen($sq['sgqa']));    // gets the part of the rowdiv id past the end of the sgqa code.
                            $relParts[] = "    if (($('#java" . $sq['sgqa'] . "').val() == '" . $listItem . "')";
                            if ($listItem == 'other') {
                                $relParts[] = " || ($('#java" . $sq['sgqa'] . "').val() == '-oth-')";
                            }
                            else
                            {
                                $relParts[] = "    $('#javatbd" . $sq['rowdivid'] . "$inputSelector').attr('disabled','disabled');\n";
                            }
                        }
                        $relParts[] = "    relChange" . $arg['qid'] . "=true;\n";
                        if($arg['type']!='R') // Ranking: rowdivid are subquestion, but array filter apply to answers and not SQ.
                            $relParts[] = "    $('#relevance" . $sq['rowdivid'] . "').val('');\n";
                        switch ($sq['qtype'])
                        {
                            case 'L': //LIST drop-down/radio-button list
                                $listItem = substr($sq['rowdivid'],strlen($sq['sgqa']));    // gets the part of the rowdiv id past the end of the sgqa code.
                                $relParts[] = "    if (($('#java" . $sq['sgqa'] ."').val() == '" . $listItem . "')";
                                if ($listItem == 'other') {
                                    $relParts[] = " || ($('#java" . $sq['sgqa'] ."').val() == '-oth-')";
                                }
                                $relParts[] = "){\n";
                                $relParts[] = "      $('#java" . $sq['sgqa'] . "').val('');\n";
                                $relParts[] = "      $('#answer" . $sq['sgqa'] . "NANS').attr('checked',true);\n";
                                $relParts[] = "    }\n";
                                break;
                            default:
                                break;
                        }
                        $relParts[] = "  }\n";

                    foreach(explode('|', $sq['relevanceVars']) as $val) {
                        $allJsVarsUsed[$val] = $val;
                        $relJsVarsUsed[$val] = $val;
                    }
                }

                // Do all tailoring
                $relParts[] = implode("\n", $tailorParts);

                // Do custom validation
                foreach ($subqValidations as $_veq) {
                    if ($_veq['subqValidSelector'] == '') {
                        continue;
                    }
                    $isValid = $LEM->em->ProcessBooleanExpression($_veq['subqValidEqn'], $arg['gseq'],
                        $session->getQuestionIndex($arg['qid']));
                    foreach($LEM->em->GetJSVarsUsed() as $var) {
                        $allJsVarsUsed[$var] = $var;
                        $valJsVarsUsed[$var] = $var;
                    }
                    $validationJS = $LEM->em->GetJavaScriptEquivalentOfExpression();
                    if ($validationJS != '') {
                        $valParts[] = "\n  if(" . $validationJS . "){\n";
                        $valParts[] = "    $('#" . $_veq['subqValidSelector'] . "').addClass('em_sq_validation').removeClass('error').addClass('good');;\n";
                        $valParts[] = "  }\n  else {\n";
                        $valParts[] = "    $('#" . $_veq['subqValidSelector'] . "').addClass('em_sq_validation').removeClass('good').addClass('error');\n";
                        $valParts[] = "  }\n";
                    }
                }

                // Set color-coding for validation equations
                if (count($validationEqns) > 0) {
                    $_hasSumRange = false;
                    $_hasOtherValidation = false;
                    $_hasOther2Validation = false;
                    $valParts[] = "  isValidSum" . $arg['qid'] . "=true;\n";    // assume valid until proven otherwise
                    $valParts[] = "  isValidOther" . $arg['qid'] . "=true;\n";    // assume valid until proven otherwise
                    $valParts[] = "  isValidOtherComment" . $arg['qid'] . "=true;\n";    // assume valid until proven otherwise
                    foreach ($validationEqns as $vclass => $validationEqn) {
                        if ($validationEqn == '') {
                            continue;
                        }
                        if ($vclass == 'sum_range') {
                            $_hasSumRange = true;
                        } else {
                            if ($vclass == 'other_comment_mandatory') {
                                $_hasOther2Validation = true;
                            } else {
                                $_hasOtherValidation = true;
                            }
                        }

                        $_isValid = $LEM->em->ProcessBooleanExpression($validationEqn, $arg['gseq'],
                            $session->getQuestionIndex($arg['qid']));
                        foreach($LEM->em->GetJSVarsUsed() as $var) {
                            $allJsVarsUsed[$var] = $var;
                            $valJsVarsUsed[$var] = $var;
                        }
                        $_validationJS = $LEM->em->GetJavaScriptEquivalentOfExpression();
                        if ($_validationJS != '') {
                            $valParts[] = "\n  if(" . $_validationJS . "){\n";
                            $valParts[] = "    $('#vmsg_" . $arg['qid'] . '_' . $vclass . "').removeClass('error').addClass('good');\n";
                            $valParts[] = "  }\n  else {\n";
                            $valParts[] = "    $('#vmsg_" . $arg['qid'] . '_' . $vclass . "').removeClass('good').addClass('error');\n";
                            switch ($vclass) {
                                case 'sum_range':
                                    $valParts[] = "    isValidSum" . $arg['qid'] . "=false;\n";
                                    break;
                                case 'other_comment_mandatory':
                                    $valParts[] = "    isValidOtherComment" . $arg['qid'] . "=false;\n";
                                    break;
                                //                            case 'num_answers':
                                //                            case 'value_range':
                                //                            case 'sq_fn_validation':
                                //                            case 'q_fn_validation':
                                //                            case 'regex_validation':
                                default:
                                    $valParts[] = "    isValidOther" . $arg['qid'] . "=false;\n";
                                    break;

                            }
                            $valParts[] = "  }\n";
                        }
                    }

                    $valParts[] = "\n  if(isValidSum" . $arg['qid'] . "){\n";
                    $valParts[] = "    $('#totalvalue_" . $arg['qid'] . "').removeClass('error').addClass('good');\n";
                    $valParts[] = "  }\n  else {\n";
                    $valParts[] = "    $('#totalvalue_" . $arg['qid'] . "').removeClass('good').addClass('error');\n";
                    $valParts[] = "  }\n";

                    // color-code single-entry fields as needed
                    switch ($arg['type']) {
                        case 'N':
                        case 'S':
                        case 'D':
                        case 'T':
                        case 'U':
                            $valParts[] = "\n  if(isValidOther" . $arg['qid'] . "){\n";
                            $valParts[] = "    $('#question" . $arg['qid'] . " :input').addClass('em_sq_validation').removeClass('error').addClass('good');\n";
                            $valParts[] = "  }\n  else {\n";
                            $valParts[] = "    $('#question" . $arg['qid'] . " :input').addClass('em_sq_validation').removeClass('good').addClass('error');\n";
                            $valParts[] = "  }\n";
                            break;
                        default:
                            break;
                    }

                    // color-code mandatory other comment fields
                    switch ($arg['type']) {
                        case '!':
                        case 'L':
                        case 'P':
                            switch ($arg['type']) {
                                case '!':
                                    $othervar = 'othertext' . substr($arg['jsResultVar'], 4, -5);
                                    break;
                                case 'L':
                                    $othervar = 'answer' . substr($arg['jsResultVar'], 4) . 'text';
                                    break;
                                case 'P':
                                    $othervar = 'answer' . substr($arg['jsResultVar'], 4);
                                    break;
                            }
                            $valParts[] = "\n  if(isValidOtherComment" . $arg['qid'] . "){\n";
                            $valParts[] = "    $('#" . $othervar . "').addClass('em_sq_validation').removeClass('error').addClass('good');\n";
                            $valParts[] = "  }\n  else {\n";
                            $valParts[] = "    $('#" . $othervar . "').addClass('em_sq_validation').removeClass('good').addClass('error');\n";
                            $valParts[] = "  }\n";
                            break;
                        default:
                            break;
                    }
                }

                if (count($valParts) > 0) {
                    $valJsVarsUsed = array_unique($valJsVarsUsed);
                    $qvalJS = "function LEMval" . $arg['qid'] . "(sgqa){\n";
                    //                    $qvalJS .= "  var UsesVars = ' " . implode(' ', $valJsVarsUsed) . " ';\n";
                    //                    $qvalJS .= "  if (typeof sgqa !== 'undefined' && !LEMregexMatch('/ java' + sgqa + ' /', UsesVars)) {\n return;\n }\n";
                    $qvalJS .= implode("", $valParts);
                    $qvalJS .= "}\n";
                    $valEqns[] = $qvalJS;

                    $relParts[] = "  LEMval" . $arg['qid'] . "(sgqa);\n";
                }

                if ($arg['hidden']) {
                    $relParts[] = "  // This question should always be hidden\n";
                    $relParts[] = "  $('#question" . $arg['qid'] . "').hide();\n";
                } else {
                    if (!($relevance == '' || $relevance == '1' || ($arg['result'] == true && $arg['numJsVars'] == 0))) {
                        // In such cases, PHP will make the question visible by default.  By not forcing a re-show(), template.js can hide questions with impunity
                        $relParts[] = "  $('#question" . $arg['qid'] . "').show();\n";
                        if ($arg['type'] == 'S') {
                            $relParts[] = "  if($('#question" . $arg['qid'] . " div[id^=\"gmap_canvas\"]').length > 0)\n";
                            $relParts[] = "  {\n";
                            $relParts[] = "      resetMap(" . $arg['qid'] . ");\n";
                            $relParts[] = "  }\n";
                        }
                    }
                }
                // If it is an equation, and relevance is true, then write the value from the question to the answer field storing the result
                if ($arg['type'] == '*') {
                    $relParts[] = "  // Write value from the question into the answer field\n";
                    $jsResultVar = $LEM->em->GetJsVarFor($arg['jsResultVar']);
                    // Note, this will destroy embedded HTML in the equation (e.g. if it is a report, can use {QCODE.question} for this purpose)
                    // This make same than flattenText to be same in JS and in PHP
                    $relParts[] = "  $('#" . substr($jsResultVar, 1,
                            -1) . "').val($.trim($('#question" . $arg['qid'] . " .em_equation').text()));\n";
                }
                $relParts[] = "  relChange" . $arg['qid'] . "=true;\n"; // any change to this value should trigger a propagation of changess
                $relParts[] = "  $('#relevance" . $arg['qid'] . "').val('1');\n";

                $relParts[] = "}\n";
                if (!($relevance == '' || $relevance == '1' || ($arg['result'] == true && $arg['numJsVars'] == 0))) {
                    if (!isset($dynamicQinG[$arg['gseq']])) {
                        $dynamicQinG[$arg['gseq']] = array();
                    }
                    if (!($arg['hidden'] && $arg['type'] == "*"))// Equation question type don't update visibility of group if hidden ( child of bug #08315).
                    {
                        $dynamicQinG[$arg['gseq']][$arg['qid']] = true;
                    }
                    $relParts[] = "else {\n";
                    $relParts[] = "  $('#question" . $arg['qid'] . "').hide();\n";
                    $relParts[] = "  if ($('#relevance" . $arg['qid'] . "').val()=='1') { relChange" . $arg['qid'] . "=true; }\n";  // only propagate changes if changing from relevant to irrelevant
                    $relParts[] = "  $('#relevance" . $arg['qid'] . "').val('0');\n";
                    $relParts[] = "}\n";
                } else {
                    // Second time : now if relevance is true: Group is allways visible (see bug #08315).
                    $relParts[] = "$('#relevance" . $arg['qid'] . "').val('1');  // always true\n";
                    if (!($arg['hidden'] && $arg['type'] == "*"))// Equation question type don't update visibility of group if hidden ( child of bug #08315).
                    {
                        $GalwaysRelevant[$arg['gseq']] = true;
                    }
                }

                foreach (explode('|', $arg['relevanceVars']) as $var) {
                    $allJsVarsUsed[$var] = $var;
                    $relJsVarsUsed[$var] = $var;
                }

                $relJsVarsUsed = array_merge($relJsVarsUsed, $valJsVarsUsed);
                $relJsVarsUsed = array_unique($relJsVarsUsed);
                $qrelQIDs = array();
                $qrelgseqs = array();    // so that any group-level change is also propagated
                foreach ($relJsVarsUsed as $jsVar) {
                    if ($jsVar != '' && isset($LEM->knownVars[substr($jsVar, 4)]['qid'])) {
                        $knownVar = $LEM->knownVars[substr($jsVar, 4)];
                        if ($session->format == Survey::FORMAT_GROUP && $knownVar['gseq'] != $LEM->currentGroupSeq) {
                            continue;   // don't make dependent upon off-page variables
                        }
                        $_qid = $knownVar['qid'];
                        if ($_qid == $arg['qid']) {
                            continue;   // don't make dependent upon itself
                        }
                        $qrelQIDs[] = 'relChange' . $_qid;
                        $qrelgseqs[] = 'relChangeG' . $knownVar['gseq'];
                    }
                }
                $qrelgseqs[] = 'relChangeG' . $arg['gseq'];   // so if current group changes visibility, re-tailor it.
                $qrelQIDs = array_unique($qrelQIDs);
                $qrelgseqs = array_unique($qrelgseqs);
                if ($session->format == Survey::FORMAT_QUESTION) {
                    $qrelQIDs = array();  // in question-by-questin mode, should never test for dependencies on self or other questions.
                    $qrelgseqs = array();
                }

                $qrelJS = "function LEMrel" . $arg['qid'] . "(sgqa){\n";
                $qrelJS .= "  var UsesVars = ' " . implode(' ', $relJsVarsUsed) . " ';\n";
                if (count($qrelQIDs) > 0) {
                    $qrelJS .= "  if(" . implode(' || ', $qrelQIDs) . "){\n    ;\n  }\n  else";
                }
                if (count($qrelgseqs) > 0) {
                    $qrelJS .= "  if(" . implode(' || ', $qrelgseqs) . "){\n    ;\n  }\n  else";
                }
                $qrelJS .= "  if (typeof sgqa !== 'undefined' && !LEMregexMatch('/ java' + sgqa + ' /', UsesVars)) {\n";
                $qrelJS .= "  return;\n }\n";
                $qrelJS .= implode("", $relParts);
                $qrelJS .= "}\n";
                $relEqns[] = $qrelJS;

                $gid_qidList[$arg['gid']][$arg['qid']] = '1';   // means has an explicit LEMrel() function
            }

            $jsParts[] = implode("", array_map(function($gid) use ($session) {
                return "  relChangeG" . $session->getGroupIndex($gid) . "=false;\n";
            }, array_keys($gid_qidList)));

            // Process relevance for each group; and if group is relevant, process each contained question in order
            foreach ($session->groups as $group) {
                $gr = $LEM->getGroupRelevanceInfo($group);
                if (!array_key_exists($group->primaryKey, $gidList)) {
                    continue;
                }
                if ($gr['relevancejs'] != '') {
                    //                $jsParts[] = "\n// Process Relevance for Group " . $gr['gid'];
                    //                $jsParts[] = ": { " . $gr['eqn'] . " }";
                    $jsParts[] = "\nif (" . $gr['relevancejs'] . ") {\n";
                    $jsParts[] = "  $('#group-" . $gr['gseq'] . "').show();\n";
                    $jsParts[] = "  relChangeG" . $gr['gseq'] . "=true;\n";
                    $jsParts[] = "  $('#relevanceG" . $gr['gseq'] . "').val(1);\n";

                    $qids = $gid_qidList[$group->primaryKey];
                    vdd($qids);
                    foreach ($qids as $_qid => $_val) {
                        $qid2exclusiveAuto = (isset($LEM->qid2exclusiveAuto[$_qid]) ? $LEM->qid2exclusiveAuto[$_qid] : array());
                        if ($_val == 1) {
                            $jsParts[] = "  LEMrel" . $_qid . "(sgqa);\n";
                            if (isset($LEM->qattr[$_qid]['exclude_all_others_auto']) && $LEM->qattr[$_qid]['exclude_all_others_auto'] == '1'
                                && isset($qid2exclusiveAuto['js']) && strlen($qid2exclusiveAuto['js']) > 0
                            ) {
                                $jsParts[] = $qid2exclusiveAuto['js'];
                                foreach(explode('|', $qid2exclusiveAuto['relevanceVars']) as $var) {
                                    $allJsVarsUsed[$var] = $var;
                                }
                                if (!isset($rowdividList[$qid2exclusiveAuto['rowdivid']])) {
                                    $rowdividList[$qid2exclusiveAuto['rowdivid']] = true;
                                }
                            }
                            if (isset($LEM->qattr[$_qid]['exclude_all_others'])) {
                                foreach (explode(';', trim($LEM->qattr[$_qid]['exclude_all_others'])) as $eo) {
                                    // then need to call the function twice so that cascading of array filter onto an excluded option works
                                    $jsParts[] = "  LEMrel" . $_qid . "(sgqa);\n";
                                }
                            }
                        }
                    }

                    $jsParts[] = "}\nelse {\n";
                    $jsParts[] = "  $('#group-" . $gr['gseq'] . "').hide();\n";
                    $jsParts[] = "  if ($('#relevanceG" . $gr['gseq'] . "').val()=='1') { relChangeG" . $gr['gseq'] . "=true; }\n";
                    $jsParts[] = "  $('#relevanceG" . $gr['gseq'] . "').val(0);\n";
                    $jsParts[] = "}\n";
                } else {
                    $qids = $gid_qidList[$group->primaryKey];
                    foreach ($qids as $_qid => $_val) {
                        $qid2exclusiveAuto = (isset($LEM->qid2exclusiveAuto[$_qid]) ? $LEM->qid2exclusiveAuto[$_qid] : array());
                        if ($_val == 1) {
                            $jsParts[] = "  LEMrel" . $_qid . "(sgqa);\n";
                            if (isset($LEM->qattr[$_qid]['exclude_all_others_auto']) && $LEM->qattr[$_qid]['exclude_all_others_auto'] == '1'
                                && isset($qid2exclusiveAuto['js']) && strlen($qid2exclusiveAuto['js']) > 0
                            ) {
                                $jsParts[] = $qid2exclusiveAuto['js'];
                                foreach(explode('|', $qid2exclusiveAuto['relevanceVars']) as $var) {
                                    $allJsVarsUsed[$var] = $var;
                                }
                                if (!isset($rowdividList[$qid2exclusiveAuto['rowdivid']])) {
                                    $rowdividList[$qid2exclusiveAuto['rowdivid']] = true;
                                }
                            }
                            if (isset($LEM->qattr[$_qid]['exclude_all_others'])) {
                                foreach (explode(';', trim($LEM->qattr[$_qid]['exclude_all_others'])) as $eo) {
                                    // then need to call the function twice so that cascading of array filter onto an excluded option works
                                    $jsParts[] = "  LEMrel" . $_qid . "(sgqa);\n";
                                }
                            }
                        }
                    }
                }

                // Add logic for all-in-one mode to show/hide groups as long as at there is at least one relevant question within the group
                // Only do this if there is no explicit group-level relevance equation, else may override group-level relevance
                $gseq = $session->getGroupIndex($group->primaryKey);
                $dynamicQidsInG = (isset($dynamicQinG[$gseq]) ? $dynamicQinG[$gseq] : array());
                $GalwaysVisible = (isset($GalwaysRelevant[$gseq]) ? $GalwaysRelevant[$gseq] : false);
                if ($session->format == Survey::FORMAT_ALL_IN_ONE && !$GalwaysVisible && count($dynamicQidsInG) > 0 && strlen(trim($gr['relevancejs'])) == 0) {
                    // check whether any dependent questions  have changed
                    $relStatusTest = "($('#relevance" . implode("').val()=='1' || $('#relevance",
                            array_keys($dynamicQidsInG)) . "').val()=='1')";

                    $jsParts[] = "\nif (" . $relStatusTest . ") {\n";
                    $jsParts[] = "  $('#group-" . $gr['gseq'] . "').show();\n";
                    $jsParts[] = "  if ($('#relevanceG" . $gr['gseq'] . "').val()=='0') { relChangeG" . $gr['gseq'] . "=true; }\n";
                    $jsParts[] = "  $('#relevanceG" . $gr['gseq'] . "').val(1);\n";
                    $jsParts[] = "}\nelse {\n";
                    $jsParts[] = "  $('#group-" . $gr['gseq'] . "').hide();\n";
                    $jsParts[] = "  if ($('#relevanceG" . $gr['gseq'] . "').val()=='1') { relChangeG" . $gr['gseq'] . "=true; }\n";
                    $jsParts[] = "  $('#relevanceG" . $gr['gseq'] . "').val(0);\n";
                    $jsParts[] = "}\n";
                }

                // now make sure any needed variables are accessible
                foreach(explode('|', $gr['relevanceVars']) as $var) {
                    $allJsVarsUsed[$var] = $var;
                }
            }

            $jsParts[] = implode("\n", $relEqns);
            $jsParts[] = implode("\n", $valEqns);


            $allJsVarsUsed = array_filter($allJsVarsUsed);

            // Add JavaScript Mapping Arrays
            $alias2varName = $LEM->getAliases();
            if (!empty($alias2varName)) {
                $neededCanonical = [];
                $neededCanonicalAttr = [];
                foreach ($allJsVarsUsed as $jsVar) {
                    if (preg_match("/^.*\.NAOK$/", $jsVar)) {
                        $jsVar = preg_replace("/\.NAOK$/", "", $jsVar);
                    }
                    $neededCanonical[] = $jsVar;
                }
                // Always define the variable.
                $jsParts[] = "var LEMalias2varName = "  . json_encode($alias2varName, JSON_PRETTY_PRINT) . ';';
                $varNameAttr = [];
                $jsParts[] = "var LEMvarNameAttr = " . json_encode($varNameAttr, JSON_PRETTY_PRINT) . ';';
            }

            $jsParts[] = "//-->\n</script>\n";

            // Now figure out which variables have not been declared (those not on the current page)
            $undeclaredJsVars = array();
            $undeclaredVal = array();
            if ($session->format != Survey::FORMAT_ALL_IN_ONE) {
                foreach ($LEM->getKnownVars() as $key => $knownVar) {
                    if (!is_numeric($key[0])) {
                        continue;
                    }
                    if ($knownVar['jsName'] == '') {
                        continue;
                    }
                    foreach ($allJsVarsUsed as $jsVar) {
                        if ($jsVar == $knownVar['jsName']) {
                            if ($session->format == Survey::FORMAT_GROUP && $knownVar['gseq'] == $LEM->currentGroupSeq) {
                                if ($knownVar['hidden'] && $knownVar['type'] != '*') {
                                    ;   // need to  declare a hidden variable for non-equation hidden variables so can do dynamic lookup.
                                } else {
                                    continue;
                                }
                            }
                            if ($session->format == Survey::FORMAT_QUESTION && $knownVar['qid'] == $LEM->currentQID) {
                                continue;
                            }
                            $undeclaredJsVars[] = $jsVar;
                            $sgqa = $knownVar['sgqa'];
                            $codeValue = (isset($session->response->$sgqa)) ? $session->response->$sgqa : '';
                            $undeclaredVal[$jsVar] = $codeValue;

                            if (isset($LEM->jsVar2qid[$jsVar])) {
                                $qidList[$LEM->jsVar2qid[$jsVar]] = $LEM->jsVar2qid[$jsVar];
                            }
                        }
                    }
                }
                $undeclaredJsVars = array_unique($undeclaredJsVars);
                foreach ($undeclaredJsVars as $jsVar) {
                    // TODO - is different type needed for text?  Or process value to striphtml?
                    if ($jsVar == '') {
                        continue;
                    }
                    $jsParts[] = "<input type='hidden' id='" . $jsVar . "' name='" . substr($jsVar,
                            4) . "' value='" . htmlspecialchars($undeclaredVal[$jsVar], ENT_QUOTES) . "'/>\n";
                }
            } else {
                // For all-in-one mode, declare the always-hidden variables, since qanda will not be called for them.
                foreach ($LEM->knownVars as $key => $knownVar) {
                    if (!is_numeric($key[0])) {
                        continue;
                    }
                    if ($knownVar['jsName'] == '') {
                        continue;
                    }
                    if ($knownVar['hidden']) {
                        $jsVar = $knownVar['jsName'];
                        $undeclaredJsVars[] = $jsVar;
                        $sgqa = $knownVar['sgqa'];
                        $codeValue = (isset($session->response->$sgqa)) ? $session->response->$sgqa : '';
                        $undeclaredVal[$jsVar] = $codeValue;
                    }
                }

                $undeclaredJsVars = array_unique($undeclaredJsVars);
                foreach ($undeclaredJsVars as $jsVar) {
                    if ($jsVar == '') {
                        continue;
                    }
                    $jsParts[] = "<input type='hidden' id='" . $jsVar . "' name='" . $jsVar . "' value='" . htmlspecialchars($undeclaredVal[$jsVar],
                            ENT_QUOTES) . "'/>\n";
                }
            }
            foreach ($qidList as $qid) {
//                if (isset($_SESSION[$LEM->sessid]['relevanceStatus'])) {
//                    $relStatus = (isset($_SESSION[$LEM->sessid]['relevanceStatus'][$qid]) ? $_SESSION[$LEM->sessid]['relevanceStatus'][$qid] : 1);
//                } else {
                    $relStatus = 1;
//                }
                $jsParts[] = "<input type='hidden' id='relevance" . $qid . "' name='relevance" . $qid . "' value='" . $relStatus . "'/>\n";
            }

            foreach ($gidList as $gid) {
//                if (isset($_SESSION['relevanceStatus'])) {
//                    $relStatus = (isset($_SESSION['relevanceStatus']['G' . $gseq]) ? $_SESSION['relevanceStatus']['G' . $gseq] : 1);
//                } else {
                    $relStatus = 1;
//                }
                $jsParts[] = "<input type='hidden' id='relevanceG" . $gseq . "' name='relevanceG" . $gseq . "' value='" . $relStatus . "'/>\n";
            }
            foreach ($rowdividList as $key => $val) {
                $jsParts[] = "<input type='hidden' id='relevance" . $key . "' name='relevance" . $key . "' value='" . $val . "'/>\n";
            }
//            throw new \Exception(htmlentities(implode("\n", $jsParts)));
            return implode('', $jsParts);
        }

        static function setTempVars($vars)
        {
            $LEM =& LimeExpressionManager::singleton();
            $LEM->tempVars = $vars;
        }



        /**
         * Set the 'this' variable as an alias for SGQA within the code.
         * @param <type> $sgqa
         */
        public static function SetThisAsAliasForSGQA($sgqa)
        {
            $LEM =& LimeExpressionManager::singleton();
            if (isset($LEM->knownVars[$sgqa])) {
                $LEM->qcode2sgqa['this'] = $sgqa;
            }
        }

        public static function ShowStackTrace($msg = null, &$args = null)
        {
            $LEM =& LimeExpressionManager::singleton();

            $msg = array("**Stack Trace**" . (is_null($msg) ? '' : ' - ' . $msg));

            $count = 0;
            foreach (debug_backtrace(false) as $log) {
                if ($count++ == 0) {
                    continue;   // skip this call
                }
                $LEM->debugStack = array();

                $subargs = array();
                if (!is_null($args) && $log['function'] == 'templatereplace') {
                    foreach ($args as $arg) {
                        if (isset($log['args'][2][$arg])) {
                            $subargs[$arg] = $log['args'][2][$arg];
                        }
                    }
                    if (count($subargs) > 0) {
                        $arglist = print_r($subargs, true);
                    } else {
                        $arglist = '';
                    }
                } else {
                    $arglist = '';
                }
                $msg[] = '  '
                    . (isset($log['file']) ? '[' . basename($log['file']) . ']' : '')
                    . (isset($log['class']) ? $log['class'] : '')
                    . (isset($log['type']) ? $log['type'] : '')
                    . (isset($log['function']) ? $log['function'] : '')
                    . (isset($log['line']) ? '[' . $log['line'] . ']' : '')
                    . $arglist;
            }
        }

        
        /**
         * Returns true if the survey is using comma as the radix
         * @return type
         */
        public static function usingCommaAsRadix()
        {
            $LEM =& LimeExpressionManager::singleton();
            $usingCommaAsRadix = (($LEM->surveyOptions['radix'] == ',') ? true : false);

            return $usingCommaAsRadix;
        }

        private static function getConditionsForEM($surveyid = null, $qid = null)
        {
            if (!is_null($qid)) {
                $where = " c.qid = " . $qid . " AND ";
            } else {
                if (!is_null($surveyid)) {
                    $where = " qa.sid = {$surveyid} AND ";
                } else {
                    $where = "";
                }
            }

            $query = "SELECT DISTINCT c.*, q.sid, q.type
                FROM {{conditions}} AS c
                LEFT JOIN {{questions}} q ON c.cqid=q.qid
                LEFT JOIN {{questions}} qa ON c.qid=qa.qid
                WHERE {$where} 1=1
                UNION
                SELECT DISTINCT c.*, q.sid, NULL AS TYPE
                FROM {{conditions}} AS c
                LEFT JOIN {{questions}} q ON c.cqid=q.qid
                LEFT JOIN {{questions}} qa ON c.qid=qa.qid
                WHERE {$where} c.cqid = 0";

            $databasetype = Yii::app()->db->getDriverName();
            if ($databasetype == 'mssql' || $databasetype == 'dblib') {
                $query .= " order by c.qid, sid, scenario, cqid, cfieldname, value";
            } else {
                $query .= " order by qid, sid, scenario, cqid, cfieldname, value";
            }

            return Yii::app()->db->createCommand($query)->query();
        }

        /**
         * Deprecate obsolete question attributes.
         * @param boolean $changedb - if true, updates parameters and deletes old ones
         * @param type $iSureyID - if set, then only for that survey
         * @param type $onlythisqid - if set, then only for this question ID
         */
        public static function UpgradeQuestionAttributes($changeDB = false, $iSurveyID = null, $onlythisqid = null)
        {
            $LEM =& LimeExpressionManager::singleton();
            if (is_null($iSurveyID)) {
                $sQuery = 'SELECT sid FROM {{surveys}}';
                $aSurveyIDs = Yii::app()->db->createCommand($sQuery)->queryColumn();
            } else {
                $aSurveyIDs = array($iSurveyID);
            }

            $attibutemap = array(
                'max_num_value_sgqa' => 'max_num_value',
                'min_num_value_sgqa' => 'min_num_value',
                'num_value_equals_sgqa' => 'equals_num_value',
            );
            $reverseAttributeMap = array_flip($attibutemap);
            foreach ($aSurveyIDs as $iSurveyID) {
                $qattrs = $LEM->getQuestionAttributesForEM($iSurveyID, $onlythisqid, $LEM->lang);
                foreach ($qattrs as $qid => $qattr) {
                    $updates = array();
                    foreach ($attibutemap as $src => $target) {
                        if (isset($qattr[$src]) && trim($qattr[$src]) != '') {
                            $updates[$target] = $qattr[$src];
                        }
                    }
                    if ($changeDB) {
                        foreach ($updates as $key => $value) {
                            $query = "UPDATE {{question_attributes}} SET value=" . Yii::app()->db->quoteValue($value) . " WHERE qid={$qid} and attribute=" . Yii::app()->db->quoteValue($key);
                            Yii::app()->db->createCommand($query)->execute();
                            $query = "DELETE FROM {{question_attributes}} WHERE qid={$qid} and attribute=" . Yii::app()->db->quoteValue($reverseAttributeMap[$key]);
                            Yii::app()->db->createCommand($query)->execute();

                        }
                    }
                }
            }

        }

        /**
         * Return array of language-specific answer codes
         * @param int $surveyid
         * @param int $qid
         * @param string $lang
         * @return <type>
         */
        private function getQuestionAttributesForEM($surveyid = 0, $qid = 0, $lang = '')
        {
            bP();
            $session = App()->surveySessionManager->current;
            if (is_null($qid)) {
                $qid = 0;
            }

            $questions = $qid ? [$session->getQuestion($qid)] : $session->survey->questions;

            /** @var Question $question */
            foreach ($questions as $question) {
                $aAttributesValues = [];
                // Change array lang to value
                foreach ($question->questionAttributes as $questionAttribute) {
                    if ($questionAttribute->language == $session->language || !isset($aAttributesValues[$questionAttribute->attribute])) {
                        $aAttributesValues[$questionAttribute->attribute] = $questionAttribute->value;
                    }
                }
                $aQuestionAttributesForEM[$question->primaryKey] = $aAttributesValues;
            }
            eP();
            return $aQuestionAttributesForEM;
        }

        /**
         * Return array of language-specific answer codes
         * @param int $surveyid
         * @param int $qid
         * @param string $lang
         * @return <type>
         */

        private function getAnswerSetsForEM($surveyId, $questionId, $language) {
            $session = App()->surveySessionManager->current;

            $qans = [];
            if (!isset($questionId)) { throw new \Exception('no");'); }
            $useAssessments = ((isset($this->surveyOptions['assessments'])) ? $this->surveyOptions['assessments'] : false);
            foreach ($session->getQuestion($questionId)->answers as $answer) {
                $qans[$answer->question_id][$answer->scale_id . '~' . $answer->code] = ($useAssessments ? $answer->assessment_value : '0') . '|' . $answer->answer;

            }

            return $qans;
        }

        /**
         * Returns group info needed for indexes
         * @param <type> $surveyid
         * @param string $lang
         * @return <type>
         */

        function getGroupInfoForEM($surveyid, $lang = null)
        {
            if (is_null($lang) && isset($this->lang)) {
                $lang = $this->lang;
            } elseif (is_null($lang)) {
                $lang = Survey::model()->findByPk($surveyid)->language;
            }
            $groups = QuestionGroup::model()->findAllByAttributes(['sid' => $surveyid], ['order' => 'group_order']);
            $qinfo = array();
            $_order = 0;
            foreach ($groups as $group) {
                $gid[$group->primaryKey] = [
                    'group_order' => $_order,
                    'gid' => $group->primaryKey,
                    'group_name' => $group->group_name,
                    'description' => $group->description,
                    'grelevance' => (!($this->sPreviewMode == 'question' || $this->sPreviewMode == 'group')) ? $group->grelevance : 1,
                ];
                $qinfo[$_order] = $gid[$group->primaryKey];
                ++$_order;
            }
            // Needed for Randomization group.
            $groupRemap = (!$this->sPreviewMode && !empty($_SESSION['survey_' . $surveyid]['groupReMap']) && !empty($_SESSION['survey_' . $surveyid]['grouplist']));
            if ($groupRemap) {
                $_order = 0;
                $qinfo = array();
                foreach ($_SESSION['survey_' . $surveyid]['grouplist'] as $info) {
                    $gid[$info['gid']]['group_order'] = $_order;
                    $qinfo[$_order] = $gid[$info['gid']];
                    ++$_order;
                }
            }

            return $qinfo;
        }

        /**
         * Cleanse the $_POSTed data and update $_SESSION variables accordingly
         */
        static function ProcessCurrentResponses()
        {
            $LEM =& LimeExpressionManager::singleton();
            $session = App()->surveySessionManager->current;
            $updatedValues = [];
            if (!isset($LEM->currentQset)) {
                return $updatedValues;
            }
            $data = App()->request->psr7->getParsedBody();

            $radixchange = ($session->survey->getLocalizedNumberFormat() == 2);
            foreach ($LEM->currentQset as $qinfo) {
                $relevant = false;
                if (!isset($qinfo['info'])) {
                    vdd($qinfo);
                }
                $qid = $qinfo['info']['qid'];
                $gseq = $session->getGroupIndex($qinfo['info']['gid']);
                $relevant = isset($data['relevance' . $qid]) && $data['relevance' . $qid] == 1;
                $grelevant = isset($data['relevanceG' . $gseq]) && $data['relevanceG' . $gseq] == 1;
                foreach (explode('|', $qinfo['sgqa']) as $sq) {
                    $sqrelevant = true;
                    if (isset($LEM->subQrelInfo[$qid][$sq]['rowdivid'])) {
                        $rowdivid = $LEM->subQrelInfo[$qid][$sq]['rowdivid'];
                        if ($rowdivid != '' && isset($data['relevance' . $rowdivid])) {
                            $sqrelevant = ($data['relevance' . $rowdivid] == 1);
                        }
                    }
                    $question->type = $qinfo['info']['type'];
                    if (($relevant && $grelevant && $sqrelevant) || !$LEM->surveyOptions['deletenonvalues']) {
                        if ($qinfo['info']['hidden'] && !isset($data[$sq])) {
                            $value = $session->response->$sq;    // if always hidden, use the default value, if any
                        } else {
                            $value = (isset($data[$sq]) ? $data[$sq] : '');
                        }
                        if ($radixchange && isset($LEM->knownVars[$sq]['onlynum']) && $LEM->knownVars[$sq]['onlynum'] == '1') {
                            // convert from comma back to decimal
                            $value = strtr($value, [',' => '.']);
                        }
                        switch ($question->type) {
                            case 'D': //DATE
                                if (isset($data['qattribute_answer' . $sq]))       // push validation message (see qanda_helper) to $_SESSION
                                {
                                    $_SESSION[$LEM->sessid]['qattribute_answer' . $sq] = ($_POST['qattribute_answer' . $sq]);
                                }
                                $value = trim($value);
                                if ($value != "" && $value != 'INVALID') {
                                    $aDateFormatData = getDateFormatDataForQID($qid, $LEM->surveyOptions);
                                    $oDateTimeConverter = new Date_Time_Converter(trim($value),
                                        $aDateFormatData['phpdate']);
                                    $value = $oDateTimeConverter->convert("Y-m-d H:i"); // TODO : control if inverse function original value
                                }
                                break;
                            case '|': //File Upload
                                if (!preg_match('/_filecount$/', $sq)) {
                                    $json = $value;
                                    $phparray = json_decode(stripslashes($json));

                                    // if the files have not been saved already,
                                    // move the files from tmp to the files folder

                                    $tmp = $LEM->surveyOptions['tempdir'] . 'upload' . DIRECTORY_SEPARATOR;
                                    if (!is_null($phparray) && count($phparray) > 0) {
                                        // Move the (unmoved, temp) files from temp to files directory.
                                        // Check all possible file uploads
                                        for ($i = 0; $i < count($phparray); $i++) {
                                            if (file_exists($tmp . $phparray[$i]->filename)) {
                                                $sDestinationFileName = 'fu_' . randomChars(15);
                                                if (!is_dir($LEM->surveyOptions['target'])) {
                                                    mkdir($LEM->surveyOptions['target'], 0777, true);
                                                }
                                                if (!rename($tmp . $phparray[$i]->filename,
                                                    $LEM->surveyOptions['target'] . $sDestinationFileName)
                                                ) {
                                                    echo "Error moving file to target destination";
                                                }
                                                $phparray[$i]->filename = $sDestinationFileName;
                                            }
                                        }
                                        $value = ls_json_encode($phparray);  // so that EM doesn't try to parse it.
                                    }
                                }
                                break;
                        }
                        $session->response->$sq = $value;
                        $_update = array(
                            'type' => $question->type,
                            'value' => $value,
                        );
                        $updatedValues[$sq] = $_update;
                    } else {  // irrelevant, so database will be NULLed separately
                        // Must unset the value, rather than setting to '', so that EM can re-use the default value as needed.
                        unset($session->response->$sq);
                        $_update = array(
                            'type' => $question->type,
                            'value' => null,
                        );
                        $updatedValues[$sq] = $_update;
                    }
                }
            }
            if (isset($_POST['timerquestion'])) {
                $_SESSION[$LEM->sessid][$data['timerquestion']] = sanitize_float($data[$data['timerquestion']]);
            }
            echo "ProcessCurrentResponses<Br>";

            return $updatedValues;
        }

        static public function GetVarAttribute($name, $attr, $default, $gseq, $qseq)
        {
            return LimeExpressionManager::singleton()->_GetVarAttribute($name, $attr, $default, $gseq, $qseq);
        }

        /**
         * Gets the sgqa for a code, or null if code is not found.
         * @param string $code
         * @return string
         */
        private function getSgqa($code) {
            // Use static var inside function for caching during single request.
            static $requestCache = [];
            bP();
            if (empty($requestCache)) {

                $session = App()->surveySessionManager->current;
                $result = null;
                foreach ($session->survey->questions as $question) {
                    $requestCache[$code] = $question->sgqa;
                }

            }
            eP();
            return isset($requestCache[$code]) ? $requestCache[$code] : null;
        }

        private function _GetVarAttribute($name, $attr, $default, $gseq, $qseq)
        {
            $session = App()->surveySessionManager->current;
            $response = $session->response;
            $parts = explode(".", $name);
            if (!isset($attr)) {
                $attr = isset($parts[1]) ? $parts[1] : 'code';
            }

            // Check if this is a valid field in the response.
            if ($response->canGetProperty($parts[0])) {

            } elseif ($knownVars = $this->getKnownVars() && isset($knownVars[$parts[0]])) {
                $var = $knownVars[$parts[0]];
            } else {
                if (isset($this->tempVars[$parts[0]])) {
                    $var = $this->tempVars[$parts[0]];
                } else {
                    return '{' . $name . '}';
                }
            }

            // Like JavaScript, if an answer is irrelevant, always return ''
            if (preg_match('/^code|NAOK|shown|valueNAOK|value$/', $attr) && isset($var['qid']) && $var['qid'] != '') {
                if (!$this->_GetVarAttribute($varName, 'relevanceStatus', false, $gseq, $qseq)) {
                    return '';
                }
            }
            switch ($attr) {
                case 'varName':
                    return $name;
                    break;
                case 'code':
                case 'NAOK':
                    if (isset($var['code'])) {
                        return $var['code'];    // for static values like TOKEN
                    } else {
                        if (isset($response->$sgqa)) {
                            $question->type = $var['type'];
                            switch ($question->type) {
                                case 'Q': //MULTIPLE SHORT TEXT
                                case ';': //ARRAY (Multi Flexi) Text
                                case 'S': //SHORT FREE TEXT
                                case 'D': //DATE
                                case 'T': //LONG FREE TEXT
                                case 'U': //HUGE FREE TEXT
                                    // Minimum sanitizing the string entered by user
                                    return htmlspecialchars($response->$sgqa, ENT_NOQUOTES);
                                case '!': //List - dropdown
                                case 'L': //LIST drop-down/radio-button list
                                case 'O': //LIST WITH COMMENT drop-down/radio-button list + textarea
                                case 'M': //Multiple choice checkbox
                                case 'P': //Multiple choice with comments checkbox + text
                                    if (preg_match('/comment$/', $sgqa) || preg_match('/other$/',
                                            $sgqa) || preg_match('/_other$/', $name)
                                    ) {
                                        // Minimum sanitizing the string entered by user
                                        return htmlspecialchars($response->$sgqa, ENT_NOQUOTES);
                                    }
                                default:
                                    return $response->$sgqa;
                            }
                        } elseif (isset($var['default']) && !is_null($var['default'])) {
                            return $var['default'];
                        }
                        return $default;
                    }
                    break;
                case 'value':
                case 'valueNAOK': {
                    $question->type = $var['type'];
                    $code = $this->_GetVarAttribute($name, 'code', $default, $gseq, $qseq);
                    switch ($question->type) {
                        case '!': //List - dropdown
                        case 'L': //LIST drop-down/radio-button list
                        case 'O': //LIST WITH COMMENT drop-down/radio-button list + textarea
                        case '1': //Array (Flexible Labels) dual scale  // need scale
                        case 'H': //ARRAY (Flexible) - Column Format
                        case 'F': //ARRAY (Flexible) - Row Format
                        case 'R': //RANKING STYLE
                            if ($question->type == 'O' && preg_match('/comment\.value/', $name)) {
                                $value = $code;
                            } else {
                                if (($question->type == 'L' || $question->type == '!') && preg_match('/_other\.value/', $name)) {
                                    $value = $code;
                                } else {
                                    $scale_id = $this->_GetVarAttribute($name, 'scale_id', '0', $gseq, $qseq);
                                    $which_ans = $scale_id . '~' . $code;
                                    $ansArray = $var['ansArray'];
                                    if (is_null($ansArray)) {
                                        $value = $default;
                                    } else {
                                        if (isset($ansArray[$which_ans])) {
                                            $answerInfo = explode('|', $ansArray[$which_ans]);
                                            $answer = $answerInfo[0];
                                        } else {
                                            $answer = $default;
                                        }
                                        $value = $answer;
                                    }
                                }
                            }
                            break;
                        default:
                            $value = $code;
                            break;
                    }

                    return $value;
                }
                    break;
                case 'jsName':
                    if ($session->format == Survey::FORMAT_ALL_IN_ONE
                        || ($session->format == Survey::FORMAT_GROUP && $gseq != -1 && isset($var['gseq']) && $gseq == $var['gseq'])
                        || ($session->format == Survey::FORMAT_QUESTION && $qseq != -1 && isset($var['qseq']) && $qseq == $var['qseq'])
                    ) {
                        return (isset($var['jsName_on']) ? $var['jsName_on'] : (isset($var['jsName'])) ? $var['jsName'] : $default);
                    } else {
                        return (isset($var['jsName']) ? $var['jsName'] : $default);
                    }
                    break;
                case 'sgqa':
                case 'mandatory':
                case 'qid':
                case 'gid':
                case 'grelevance':
                case 'question':
                case 'readWrite':
                case 'relevance':
                case 'rowdivid':
                case 'type':
                case 'qcode':
                case 'gseq':
                case 'qseq':
                case 'ansList':
                case 'scale_id':
                    return (isset($var[$attr])) ? $var[$attr] : $default;
                case 'shown':
                    if (isset($var['shown'])) {
                        return $var['shown'];    // for static values like TOKEN
                    } else {
                        $question->type = $var['type'];
                        $code = $this->_GetVarAttribute($name, 'code', $default, $gseq, $qseq);
                        switch ($question->type) {
                            case '!': //List - dropdown
                            case 'L': //LIST drop-down/radio-button list
                            case 'O': //LIST WITH COMMENT drop-down/radio-button list + textarea
                            case '1': //Array (Flexible Labels) dual scale  // need scale
                            case 'H': //ARRAY (Flexible) - Column Format
                            case 'F': //ARRAY (Flexible) - Row Format
                            case 'R': //RANKING STYLE
                                if ($question->type == 'O' && preg_match('/comment$/', $name)) {
                                    $shown = $code;
                                } else {
                                    if (($question->type == 'L' || $question->type == '!') && preg_match('/_other$/', $name)) {
                                        $shown = $code;
                                    } else {
                                        $scale_id = $this->_GetVarAttribute($name, 'scale_id', '0', $gseq, $qseq);
                                        $which_ans = $scale_id . '~' . $code;
                                        $ansArray = $var['ansArray'];
                                        if (is_null($ansArray)) {
                                            $shown = $code;
                                        } else {
                                            if (isset($ansArray[$which_ans])) {
                                                $answerInfo = explode('|', $ansArray[$which_ans]);
                                                array_shift($answerInfo);
                                                $answer = join('|', $answerInfo);
                                            } else {
                                                $answer = $code;
                                            }
                                            $shown = $answer;
                                        }
                                    }
                                }
                                break;
                            case 'A': //ARRAY (5 POINT CHOICE) radio-buttons
                            case 'B': //ARRAY (10 POINT CHOICE) radio-buttons
                            case ':': //ARRAY (Multi Flexi) 1 to 10
                            case '5': //5 POINT CHOICE radio-buttons
                                $shown = $code;
                                break;
                            case 'D': //DATE
                                $LEM =& LimeExpressionManager::singleton();
                                $aDateFormatData = getDateFormatDataForQID($var['qid'], $LEM->surveyOptions);
                                $shown = '';
                                if (strtotime($code)) {
                                    $shown = date($aDateFormatData['phpdate'], strtotime($code));
                                }
                                break;
                            case 'N': //NUMERICAL QUESTION TYPE
                            case 'K': //MULTIPLE NUMERICAL QUESTION
                            case 'Q': //MULTIPLE SHORT TEXT
                            case ';': //ARRAY (Multi Flexi) Text
                            case 'S': //SHORT FREE TEXT
                            case 'T': //LONG FREE TEXT
                            case 'U': //HUGE FREE TEXT
                            case '*': //Equation
                            case 'I': //Language Question
                            case '|': //File Upload
                            case 'X': //BOILERPLATE QUESTION
                                $shown = $code;
                                break;
                            case 'M': //Multiple choice checkbox
                            case 'P': //Multiple choice with comments checkbox + text
                                if ($code == 'Y' && isset($var['question']) && !preg_match('/comment$/', $sgqa)) {
                                    $shown = $var['question'];
                                } elseif (preg_match('/comment$/', $sgqa)) {
                                    $shown = $code; // This one return sgqa.code
                                } else {
                                    $shown = $default;
                                }
                                break;
                            case 'G': //GENDER drop-down list
                            case 'Y': //YES/NO radio-buttons
                            case 'C': //ARRAY (YES/UNCERTAIN/NO) radio-buttons
                            case 'E': //ARRAY (Increase/Same/Decrease) radio-buttons
                                $ansArray = $var['ansArray'];
                                if (is_null($ansArray)) {
                                    $shown = $default;
                                } else {
                                    if (isset($ansArray[$code])) {
                                        $answer = $ansArray[$code];
                                    } else {
                                        $answer = $default;
                                    }
                                    $shown = $answer;
                                }
                                break;
                        }

                        return $shown;
                    }
                case 'relevanceStatus':
                    $gseq = (isset($var['gseq'])) ? $var['gseq'] : -1;
                    $qid = (isset($var['qid'])) ? $var['qid'] : -1;
                    $rowdivid = (isset($var['rowdivid']) && $var['rowdivid'] != '') ? $var['rowdivid'] : -1;
                    if ($qid == -1 || $gseq == -1) {
                        return true;
                    }
                    if (isset($args[1]) && $args[1] == 'NAOK') {
                        return true;
                    }
                    return true;
                case 'onlynum':
                    if (isset($args[1]) && ($args[1] == 'value' || $args[1] == 'valueNAOK')) {
                        return 1;
                    }

                    return (isset($var[$attr])) ? $var[$attr] : $default;
                    break;
                default:
                    print 'UNDEFINED ATTRIBUTE: ' . $attr . "<br />\n";

                    return $default;
            }

            return $default;    // and throw and error?
        }

        public static function SetVariableValue($op, $name, $value)
        {
            $LEM =& LimeExpressionManager::singleton();

            if (isset($LEM->tempVars[$name])) {
                switch ($op) {
                    case '=':
                        $LEM->tempVars[$name]['code'] = $value;
                        break;
                    case '*=':
                        $LEM->tempVars[$name]['code'] *= $value;
                        break;
                    case '/=':
                        $LEM->tempVars[$name]['code'] /= $value;
                        break;
                    case '+=':
                        $LEM->tempVars[$name]['code'] += $value;
                        break;
                    case '-=':
                        $LEM->tempVars[$name]['code'] -= $value;
                        break;
                }
                $_result = $LEM->tempVars[$name]['code'];
                $session->response->$name = $_result;


                return $_result;
            } else {
                if (!isset($LEM->knownVars[$name])) {
                    if (isset($LEM->qcode2sgqa[$name])) {
                        $name = $LEM->qcode2sgqa[$name];
                    } else {
                        return '';  // shouldn't happen
                    }
                }
                if (isset($session->response->$name)) {
                    $_result = $session->response->$name;
                } else {
                    $_result = (isset($LEM->knownVars[$name]['default']) ? $LEM->knownVars[$name]['default'] : 0);
                }

                switch ($op) {
                    case '=':
                        $_result = $value;
                        break;
                    case '*=':
                        $_result *= $value;
                        break;
                    case '/=':
                        $_result /= $value;
                        break;
                    case '+=':
                        $_result += $value;
                        break;
                    case '-=':
                        $_result -= $value;
                        break;
                }
                $session->response->$name = $_result;
                $_type = $LEM->knownVars[$name]['type'];

                return $_result;
            }
        }


        /**
         * Create HTML view of the survey, showing everything that uses EM
         * @param <type> $sid
         * @param <type> $gid
         * @param <type> $qid
         */
        static public function ShowSurveyLogicFile(
            $sid,
            $gid = null,
            $qid = null,
            $LEMdebugLevel = 0,
            $assessments = false
        ) {
            // Title
            // Welcome
            // G1, name, relevance, text
            // *Q1, name [type], relevance [validation], text, help, default, help_msg
            // SQ1, name [scale], relevance [validation], text
            // A1, code, assessment_value, text
            // End Message

            $LEM =& LimeExpressionManager::singleton();
            $LEM->sPreviewMode = 'logic';
            $aSurveyInfo = getSurveyInfo($sid, $LEM->lang);

            $allErrors = array();
            $warnings = 0;

            $surveyOptions = array(
                'assessments' => ($aSurveyInfo['assessments'] == 'Y'),
                'hyperlinkSyntaxHighlighting' => true,
            );

            $varNamesUsed = array(); // keeps track of whether variables have been declared

            if (!is_null($qid)) {
                $surveyMode = 'question';
                LimeExpressionManager::StartSurvey($sid, Survey::FORMAT_QUESTION, $surveyOptions, false, $LEMdebugLevel);
                $qseq = LimeExpressionManager::GetQuestionSeq($qid);
                $moveResult = LimeExpressionManager::JumpTo($qseq + 1, true, false, true);
            } else {
                if (!is_null($gid)) {
                    $surveyMode = 'group';
                    LimeExpressionManager::StartSurvey($sid, Survey::FORMAT_GROUP, $surveyOptions, false, $LEMdebugLevel);
                    $gseq = LimeExpressionManager::GetGroupSeq($gid);
                    $moveResult = LimeExpressionManager::JumpTo($gseq + 1, true, false, true);
                } else {
                    $surveyMode = 'survey';
                    LimeExpressionManager::StartSurvey($sid, Survey::FORMAT_ALL_IN_ONE, $surveyOptions, false, $LEMdebugLevel);
                    $moveResult = LimeExpressionManager::NavigateForwards();
                }
            }

            $qtypes = getQuestionTypeList('', 'array');

            if (is_null($moveResult) || is_null($LEM->currentQset) || count($LEM->currentQset) == 0) {
                return array(
                    'errors' => 1,
                    'html' => sprintf(gT('Invalid question - probably missing sub-questions or language-specific settings for language %s'),
                        $LEM->lang)
                );
            }

            $surveyname = templatereplace('{SURVEYNAME}', array('SURVEYNAME' => $aSurveyInfo['surveyls_title']));

            $out = '<div id="showlogicfilediv" ><H3>' . gT('Logic File for Survey # ') . '[' . $LEM->sid . "]: $surveyname</H3>\n";
            $out .= "<table id='logicfiletable'>";

            if (is_null($gid) && is_null($qid)) {
                if ($aSurveyInfo['surveyls_description'] != '') {
                    $LEM->ProcessString($aSurveyInfo['surveyls_description'], 0);
                    $sPrint = $LEM->GetLastPrettyPrintExpression();
                    $errClass = ($LEM->em->HasErrors() ? 'LEMerror' : '');
                    $out .= "<tr class='LEMgroup $errClass'><td colspan=2>" . gT("Description:") . "</td><td colspan=2>" . $sPrint . "</td></tr>";
                }
                if ($aSurveyInfo['surveyls_welcometext'] != '') {
                    $LEM->ProcessString($aSurveyInfo['surveyls_welcometext'], 0);
                    $sPrint = $LEM->GetLastPrettyPrintExpression();
                    $errClass = ($LEM->em->HasErrors() ? 'LEMerror' : '');
                    $out .= "<tr class='LEMgroup $errClass'><td colspan=2>" . gT("Welcome:") . "</td><td colspan=2>" . $sPrint . "</td></tr>";
                }
                if ($aSurveyInfo['surveyls_endtext'] != '') {
                    $LEM->ProcessString($aSurveyInfo['surveyls_endtext']);
                    $sPrint = $LEM->GetLastPrettyPrintExpression();
                    $errClass = ($LEM->em->HasErrors() ? 'LEMerror' : '');
                    $out .= "<tr class='LEMgroup $errClass'><td colspan=2>" . gT("End message:") . "</td><td colspan=2>" . $sPrint . "</td></tr>";
                }
                if ($aSurveyInfo['surveyls_url'] != '') {
                    $LEM->ProcessString($aSurveyInfo['surveyls_urldescription'] . " - " . $aSurveyInfo['surveyls_url']);
                    $sPrint = $LEM->GetLastPrettyPrintExpression();
                    $errClass = ($LEM->em->HasErrors() ? 'LEMerror' : '');
                    $out .= "<tr class='LEMgroup $errClass'><td colspan=2>" . gT("End URL:") . "</td><td colspan=2>" . $sPrint . "</td></tr>";
                }
            }

            $out .= "<tr><th>#</th><th>" . gT('Name [ID]') . "</th><th>" . gT('Relevance [Validation] (Default value)') . "</th><th>" . gT('Text [Help] (Tip)') . "</th></tr>\n";

            $_gseq = -1;
            foreach ($LEM->currentQset as $q) {
                $gseq = $q['info']['gseq'];
                $gid = $q['info']['gid'];
                $qid = $q['info']['qid'];
                $qseq = $q['info']['qseq'];

                $errorCount = 0;

                //////
                // SHOW GROUP-LEVEL INFO
                //////
                if ($gseq != $_gseq) {
                    $LEM->ParseResultCache = array(); // reset for each group so get proper color coding?
                    $_gseq = $gseq;
                    $ginfo = $LEM->gseq2info[$gseq];

                    $grelevance = '{' . (($ginfo['grelevance'] == '') ? 1 : $ginfo['grelevance']) . '}';
                    $gtext = ((trim($ginfo['description']) == '') ? '&nbsp;' : $ginfo['description']);

                    $editlink = Yii::app()->getController()->createUrl('admin/survey/sa/view/surveyid/' . $LEM->sid . '/gid/' . $gid);
                    $groupRow = "<tr class='LEMgroup'>"
                        . "<td>G-$gseq</td>"
                        . "<td><b>" . $ginfo['group_name'] . "</b><br />[<a target='_blank' href='$editlink'>GID " . $gid . "</a>]</td>"
                        . "<td>" . $grelevance . "</td>"
                        . "<td>" . $gtext . "</td>"
                        . "</tr>\n";

                    $LEM->ProcessString($groupRow, $qid, null, false, 1, 1, false, false);
                    $out .= $LEM->GetLastPrettyPrintExpression();
                    if ($LEM->em->HasErrors()) {
                        ++$errorCount;
                    }
                }

                //////
                // SHOW QUESTION-LEVEL INFO
                //////
                $mandatory = (($q['info']['mandatory'] == 'Y') ? "<span class='mandatory'>*</span>" : '');
                $question->type = $q['info']['type'];
                $question->typedesc = $qtypes[$question->type]['description'];

                $sgqas = explode('|', $q['sgqa']);
                if (count($sgqas) == 1 && !is_null($q['info']['default'])) {
                    $LEM->ProcessString(htmlspecialchars($q['info']['default']), $qid, null, false, 1, 1, false,
                        false);// Default value is Y or answer code or go to input/textarea, then we can filter it
                    $_default = $LEM->GetLastPrettyPrintExpression();
                    if ($LEM->em->HasErrors()) {
                        ++$errorCount;
                    }
                    $default = '<br />(' . gT('Default:') . '  ' . viewHelper::filterScript($_default) . ')';
                } else {
                    $default = '';
                }

                $qtext = (($q['info']['qtext'] != '') ? $q['info']['qtext'] : '&nbsp');
                $help = (($q['info']['help'] != '') ? '<hr/>[' . gT("Help:") . ' ' . $q['info']['help'] . ']' : '');
                $prettyValidTip = (($q['prettyValidTip'] == '') ? '' : '<hr/>(' . gT("Tip:") . ' ' . $q['prettyValidTip'] . ')');

                //////
                // SHOW QUESTION ATTRIBUTES THAT ARE PROCESSED BY EM
                //////
                $attrTable = '';

                $attrs = (isset($LEM->qattr[$qid]) ? $LEM->qattr[$qid] : array());
                if (isset($LEM->q2subqInfo[$qid]['preg'])) {
                    $attrs['regex_validation'] = $LEM->q2subqInfo[$qid]['preg'];
                }
                if (isset($LEM->questionSeq2relevance[$qseq]['other'])) {
                    $attrs['other'] = $LEM->questionSeq2relevance[$qseq]['other'];
                }
                if (count($attrs) > 0) {
                    $attrTable = "<table id='logicfileattributetable'><tr><th>" . gT("Question attribute") . "</th><th>" . gT("Value") . "</th></tr>\n";
                    $count = 0;
                    foreach ($attrs as $key => $value) {
                        if (is_null($value) || trim($value) == '') {
                            continue;
                        }
                        switch ($key) {
                            // @todo: Rather compares the current attribute value to the defaults in the question attributes array to decide which ones should show (only the ones that are non-standard)
                            default:
                            case 'exclude_all_others':
                            case 'exclude_all_others_auto':
                            case 'hidden':
                                if ($value == false || $value == '0') {
                                    $value = null; // so can skip this one - just using continue here doesn't work.
                                }
                                break;
                            case 'time_limit_action':
                                if ($value == '1') {
                                    $value = null; // so can skip this one - just using continue here doesn't work.
                                }
                            case 'relevance':
                                $value = null;  // means an outdate database structure
                                break;
                            case 'array_filter':
                            case 'array_filter_exclude':
                            case 'code_filter':
                            case 'date_max':
                            case 'date_min':
                            case 'em_validation_q_tip':
                            case 'em_validation_sq_tip':
                                break;
                            case 'equals_num_value':
                            case 'em_validation_q':
                            case 'em_validation_sq':
                            case 'max_answers':
                            case 'max_num_value':
                            case 'max_num_value_n':
                            case 'min_answers':
                            case 'min_num_value':
                            case 'min_num_value_n':
                            case 'min_num_of_files':
                            case 'max_num_of_files':
                            case 'multiflexible_max':
                            case 'multiflexible_min':
                            case 'slider_accuracy':
                            case 'slider_min':
                            case 'slider_max':
                            case 'slider_default':
                                $value = '{' . $value . '}';
                                break;
                            case 'other_replace_text':
                            case 'show_totals':
                            case 'regex_validation':
                                break;
                            case 'other':
                                if ($value == 'N') {
                                    $value = null; // so can skip this one
                                }
                                break;
                        }
                        if (is_null($value)) {
                            continue;   // since continuing from within a switch statement doesn't work
                        }
                        ++$count;
                        $attrTable .= "<tr><td>$key</td><td>$value</td></tr>\n";
                    }
                    $attrTable .= "</table>\n";
                    if ($count == 0) {
                        $attrTable = '';
                    }
                }

                $LEM->ProcessString($qtext . $help . $prettyValidTip . $attrTable, $qid, null, false, 1, 1, false,
                    false);
                $qdetails = viewHelper::filterScript($LEM->GetLastPrettyPrintExpression());
                if ($LEM->em->HasErrors()) {
                    ++$errorCount;
                }

                //////
                // SHOW RELEVANCE
                //////
                // Must parse Relevance this way, otherwise if try to first split expressions, regex equations won't work
                $relevanceEqn = (($q['info']['relevance'] == '') ? 1 : $q['info']['relevance']);
                if (!isset($LEM->ParseResultCache[$relevanceEqn])) {
                    $result = $LEM->em->ProcessBooleanExpression($relevanceEqn, $gseq, $qseq);
                    $prettyPrint = $LEM->em->GetPrettyPrintString();
                    $hasErrors = $LEM->em->HasErrors();
                    $LEM->ParseResultCache[$relevanceEqn] = array(
                        'result' => $result,
                        'prettyprint' => $prettyPrint,
                        'hasErrors' => $hasErrors,
                    );
                }
                $relevance = $LEM->ParseResultCache[$relevanceEqn]['prettyprint'];
                if ($LEM->ParseResultCache[$relevanceEqn]['hasErrors']) {
                    ++$errorCount;
                }

                //////
                // SHOW VALIDATION EQUATION
                //////
                // Must parse Validation this way so that regex (preg) works
                $prettyValidEqn = '';
                if ($q['prettyValidEqn'] != '') {
                    $validationEqn = $q['validEqn'];
                    if (!isset($LEM->ParseResultCache[$validationEqn])) {
                        $result = $LEM->em->ProcessBooleanExpression($validationEqn, $gseq, $qseq);
                        $prettyPrint = $LEM->em->GetPrettyPrintString();
                        $hasErrors = $LEM->em->HasErrors();
                        $LEM->ParseResultCache[$validationEqn] = array(
                            'result' => $result,
                            'prettyprint' => $prettyPrint,
                            'hasErrors' => $hasErrors,
                        );
                    }
                    $prettyValidEqn = '<hr/>(VALIDATION: ' . $LEM->ParseResultCache[$validationEqn]['prettyprint'] . ')';
                    if ($LEM->ParseResultCache[$validationEqn]['hasErrors']) {
                        ++$errorCount;
                    }
                }

                //////
                // TEST VALIDITY OF ROOT VARIABLE NAME AND WHETHER HAS BEEN USED
                //////
                $rootVarName = $q['info']['rootVarName'];
                $varNameErrorMsg = '';
                $varNameError = null;
                if (isset($varNamesUsed[$rootVarName])) {
                    $varNameErrorMsg .= gT('This variable name has already been used.');
                } else {
                    $varNamesUsed[$rootVarName] = array(
                        'gseq' => $gseq,
                        'qid' => $qid
                    );
                }

                if (!preg_match('/^[a-zA-Z][0-9a-zA-Z]*$/', $rootVarName)) {
                    $varNameErrorMsg .= gT('Starting in 2.05, variable names should only contain letters and numbers; and may not start with a number. This variable name is deprecated.');
                }
                if ($varNameErrorMsg != '') {
                    $varNameError = array(
                        'message' => $varNameErrorMsg,
                        'gseq' => $varNamesUsed[$rootVarName]['gseq'],
                        'qid' => $varNamesUsed[$rootVarName]['qid'],
                        'gid' => $gid,
                    );
                    if (!$LEM->sgqaNaming) {
                        ++$errorCount;
                    } else {
                        ++$warnings;
                    }
                }

                //////
                // SHOW ALL SUB-QUESTIONS
                //////
                $sqRows = '';
                $i = 0;
                $sawThis = array(); // array of rowdivids already seen so only show them once
                foreach ($sgqas as $sgqa) {
                    if ($LEM->knownVars[$sgqa]['qcode'] == $rootVarName) {
                        continue;   // so don't show the main question as a sub-question too
                    }
                    $rowdivid = $sgqa;
                    $varName = $LEM->knownVars[$sgqa]['qcode'];
                    switch ($q['info']['type']) {
                        case '1':
                            if (preg_match('/#1$/', $sgqa)) {
                                $rowdivid = null;   // so that doesn't show same message for second scale
                            } else {
                                $rowdivid = substr($sgqa, 0, -2); // strip suffix
                                $varName = substr($LEM->knownVars[$sgqa]['qcode'], 0, -2);
                            }
                            break;
                        case 'P':
                            if (preg_match('/comment$/', $sgqa)) {
                                $rowdivid = null;
                            }
                            break;
                        case ':':
                        case ';':
                            $_rowdivid = $LEM->knownVars[$sgqa]['rowdivid'];
                            if (isset($sawThis[$qid . '~' . $_rowdivid])) {
                                $rowdivid = null;   // so don't show again
                            } else {
                                $sawThis[$qid . '~' . $_rowdivid] = true;
                                $rowdivid = $_rowdivid;
                                $sgqa_len = strlen($sid . 'X' . $gid . 'X' . $qid);
                                $varName = $rootVarName . '_' . substr($_rowdivid, $sgqa_len);
                            }
                    }
                    if (is_null($rowdivid)) {
                        continue;
                    }
                    ++$i;
                    $subQeqn = '&nbsp;';
                    if (isset($LEM->subQrelInfo[$qid][$rowdivid])) {
                        $sq = $LEM->subQrelInfo[$qid][$rowdivid];
                        $subQeqn = $sq['prettyPrintEqn'];   // {' . $sq['eqn'] . '}';  // $sq['prettyPrintEqn'];
                        if ($sq['hasErrors']) {
                            ++$errorCount;
                        }
                    }

                    $sgqaInfo = $LEM->knownVars[$sgqa];
                    $subqText = $sgqaInfo['subqtext'];
                    if (isset($sgqaInfo['default']) && $sgqaInfo['default'] !== '') {
                        $LEM->ProcessString(htmlspecialchars($sgqaInfo['default']), $qid, null, false, 1, 1, false,
                            false);
                        $_default = viewHelper::filterScript($LEM->GetLastPrettyPrintExpression());
                        if ($LEM->em->HasErrors()) {
                            ++$errorCount;
                        }
                        $subQeqn .= '<br />(' . gT('Default:') . '  ' . $_default . ')';
                    }

                    $sqRows .= "<tr class='LEMsubq'>"
                        . "<td>SQ-$i</td>"
                        . "<td><b>" . $varName . "</b></td>"
                        . "<td>$subQeqn</td>"
                        . "<td>" . $subqText . "</td>"
                        . "</tr>";
                }
                $LEM->ProcessString($sqRows, $qid, null, false, 1, 1, false, false);
                $sqRows = viewHelper::filterScript($LEM->GetLastPrettyPrintExpression());
                if ($LEM->em->HasErrors()) {
                    ++$errorCount;
                }

                //////
                // SHOW ANSWER OPTIONS FOR ENUMERATED LISTS, AND FOR MULTIFLEXI
                //////
                $answerRows = '';
                if (isset($LEM->qans[$qid]) || isset($LEM->multiflexiAnswers[$qid])) {
                    $_scale = -1;
                    if (isset($LEM->multiflexiAnswers[$qid])) {
                        $ansList = $LEM->multiflexiAnswers[$qid];
                    } else {
                        $ansList = $LEM->qans[$qid];
                    }
                    foreach ($ansList as $ans => $value) {
                        $ansInfo = explode('~', $ans);
                        $valParts = explode('|', $value);
                        $valInfo[0] = array_shift($valParts);
                        $valInfo[1] = implode('|', $valParts);
                        if ($_scale != $ansInfo[0]) {
                            $i = 1;
                            $_scale = $ansInfo[0];
                        }

                        $subQeqn = '';
                        $rowdivid = $sgqas[0] . $ansInfo[1];
                        if ($q['info']['type'] == 'R') {
                            $rowdivid = $LEM->sid . 'X' . $gid . 'X' . $qid . $ansInfo[1];
                        }
                        if (isset($LEM->subQrelInfo[$qid][$rowdivid])) {
                            $sq = $LEM->subQrelInfo[$qid][$rowdivid];
                            $subQeqn = ' ' . $sq['prettyPrintEqn'];
                            if ($sq['hasErrors']) {
                                ++$errorCount;
                            }
                        }

                        $answerRows .= "<tr class='LEManswer'>"
                            . "<td>A[" . $ansInfo[0] . "]-" . $i++ . "</td>"
                            . "<td><b>" . $ansInfo[1] . "</b></td>"
                            . "<td>[VALUE: " . $valInfo[0] . "]" . $subQeqn . "</td>"
                            . "<td>" . $valInfo[1] . "</td>"
                            . "</tr>\n";
                    }
                    $LEM->ProcessString($answerRows, $qid, null, false, 1, 1, false, false);
                    $answerRows = viewHelper::filterScript($LEM->GetLastPrettyPrintExpression());
                    if ($LEM->em->HasErrors()) {
                        ++$errorCount;
                    }
                }

                //////
                // FINALLY, SHOW THE QUESTION ROW(S), COLOR-CODING QUESTIONS THAT CONTAIN ERRORS
                //////
                $errclass = ($errorCount > 0) ? "class='LEMerror' title='" . sprintf($LEM->ngT("This question has at least %s error.|This question has at least %s errors.",
                        $errorCount), $errorCount) . "'" : '';

                $questionRow = "<tr class='LEMquestion'>"
                    . "<td $errclass>Q-" . $q['info']['qseq'] . "</td>"
                    . "<td><b>" . $mandatory;

                if ($varNameErrorMsg == '') {
                    $questionRow .= $rootVarName;
                } else {
                    $editlink = Yii::app()->getController()->createUrl('admin/survey/sa/view/surveyid/' . $LEM->sid . '/gid/' . $varNameError['gid'] . '/qid/' . $varNameError['qid']);
                    $questionRow .= "<span class='highlighterror' title='" . $varNameError['message'] . "' "
                        . "onclick='window.open(\"$editlink\",\"_blank\")'>"
                        . $rootVarName . "</span>";
                }
                $editlink = Yii::app()->getController()->createUrl('admin/survey/sa/view/surveyid/' . $sid . '/gid/' . $gid . '/qid/' . $qid);
                $questionRow .= "</b><br />[<a target='_blank' href='$editlink'>QID $qid</a>]<br/>$question->typedesc [$question->type]</td>"
                    . "<td>" . $relevance . $prettyValidEqn . $default . "</td>"
                    . "<td>" . $qdetails . "</td>"
                    . "</tr>\n";

                $out .= $questionRow;
                $out .= $sqRows;
                $out .= $answerRows;

                if ($errorCount > 0) {
                    $allErrors[$gid . '~' . $qid] = $errorCount;
                }
            }
            $out .= "</table>";

            LimeExpressionManager::FinishProcessingPage();

            if (count($allErrors) > 0) {
                $out = "<p class='LEMerror'>" . sprintf($LEM->ngT("%s question contains errors that need to be corrected.|%s questions contain errors that need to be corrected.",
                        count($allErrors)), count($allErrors)) . "</p>\n" . $out;
            } else {
                switch ($surveyMode) {
                    case 'survey':
                        $message = gT('No syntax errors detected in this survey.');
                        break;
                    case 'group':
                        $message = gT('This group, by itself, does not contain any syntax errors.');
                        break;
                    case 'question':
                        $message = gT('This question, by itself, does not contain any syntax errors.');
                        break;
                }
                $out = "<p class='LEMheading'>$message</p>\n" . $out . "</div>";
            }

            return [
                'errors' => $allErrors,
                'html' => $out
            ];
        }

        /**
         * TSV survey definition in format readable by TSVSurveyImport
         * one line each per group, question, sub-question, and answer
         * does not use SGQA naming at all.
         * @param type $sid
         * @return type
         */
        static public function TSVSurveyExport($sid)
        {
            $fields = array(
                'class',
                'type/scale',
                'name',
                'relevance',
                'text',
                'help',
                'language',
                'validation',
                'mandatory',
                'other',
                'default',
                'same_default',
                // Advanced question attributes
                'allowed_filetypes',
                'alphasort',
                'answer_width',
                'array_filter',
                'array_filter_exclude',
                'array_filter_style',
                'assessment_value',
                'category_separator',
                'choice_title',
                'code_filter',
                'commented_checkbox',
                'commented_checkbox_auto',
                'date_format',
                'date_max',
                'date_min',
                'display_columns',
                'display_rows',
                'dropdown_dates',
                'dropdown_dates_minute_step',
                'dropdown_dates_month_style',
                'dropdown_prefix',
                'dropdown_prepostfix',
                'dropdown_separators',
                'dropdown_size',
                'dualscale_headerA',
                'dualscale_headerB',
                'em_validation_q',
                'em_validation_q_tip',
                'em_validation_sq',
                'em_validation_sq_tip',
                'equals_num_value',
                'exclude_all_others',
                'exclude_all_others_auto',
                'hidden',
                'hide_tip',
                'input_boxes',
                'location_city',
                'location_country',
                'location_defaultcoordinates',
                'location_mapheight',
                'location_mapservice',
                'location_mapwidth',
                'location_mapzoom',
                'location_nodefaultfromip',
                'location_postal',
                'location_state',
                'max_answers',
                'max_filesize',
                'max_num_of_files',
                'max_num_value',
                'max_num_value_n',
                'maximum_chars',
                'min_answers',
                'min_num_of_files',
                'min_num_value',
                'min_num_value_n',
                'multiflexible_checkbox',
                'multiflexible_max',
                'multiflexible_min',
                'multiflexible_step',
                'num_value_int_only',
                'numbers_only',
                'other_comment_mandatory',
                'other_numbers_only',
                'other_replace_text',
                'page_break',
                'parent_order',
                'prefix',
                'printable_help',
                'public_statistics',
                'random_group',
                'random_order',
                'rank_title',
                'repeat_headings',
                'reverse',
                'samechoiceheight',
                'samelistheight',
                'scale_export',
                'show_comment',
                'show_grand_total',
                'show_title',
                'show_totals',
                'showpopups',
                'slider_accuracy',
                'slider_default',
                'slider_layout',
                'slider_max',
                'slider_middlestart',
                'slider_min',
                'slider_rating',
                'slider_reset',
                'slider_separator',
                'slider_showminmax',
                'statistics_graphtype',
                'statistics_showgraph',
                'statistics_showmap',
                'suffix',
                'text_input_width',
                'time_limit',
                'time_limit_action',
                'time_limit_countdown_message',
                'time_limit_disable_next',
                'time_limit_disable_prev',
                'time_limit_message',
                'time_limit_message_delay',
                'time_limit_message_style',
                'time_limit_timer_style',
                'time_limit_warning',
                'time_limit_warning_2',
                'time_limit_warning_2_display_time',
                'time_limit_warning_2_message',
                'time_limit_warning_2_style',
                'time_limit_warning_display_time',
                'time_limit_warning_message',
                'time_limit_warning_style',
                'thousands_separator',
                'use_dropdown',

            );

            $rows = array();
            $primarylang = 'en';
            $otherlangs = '';
            $langs = array();

            // Export survey-level information
            $query = "select * from {{surveys}} where sid = " . $sid;
            $data = dbExecuteAssoc($query);
            foreach ($data->readAll() as $r) {
                foreach ($r as $key => $value) {
                    if ($value != '') {
                        $row['class'] = 'S';
                        $row['name'] = $key;
                        $row['text'] = $value;
                        $rows[] = $row;
                    }
                    if ($key == 'language') {
                        $primarylang = $value;
                    }
                    if ($key == 'additional_languages') {
                        $otherlangs = $value;
                    }
                }
            }
            $langs = explode(' ', $primarylang . ' ' . $otherlangs);
            $langs = array_unique($langs);

            // Export survey language settings
            $query = "select * from {{surveys_languagesettings}} where surveyls_survey_id = " . $sid;
            $data = dbExecuteAssoc($query);
            foreach ($data->readAll() as $r) {
                $_lang = $r['surveyls_language'];
                foreach ($r as $key => $value) {
                    if ($value != '' && $key != 'surveyls_language' && $key != 'surveyls_survey_id') {
                        $row['class'] = 'SL';
                        $row['name'] = $key;
                        $row['text'] = $value;
                        $row['language'] = $_lang;
                        $rows[] = $row;
                    }
                }
            }

            $surveyinfo = getSurveyInfo($sid);
            $assessments = false;
            if (isset($surveyinfo['assessments']) && $surveyinfo['assessments'] == 'Y') {
                $assessments = true;
            }

            foreach ($langs as $lang) {
                if (trim($lang) == '') {
                    continue;
                }
                SetSurveyLanguage($sid, $lang);
                LimeExpressionManager::StartSurvey($sid, Survey::FORMAT_ALL_IN_ONE,
                    array('sgqaNaming' => 'N', 'assessments' => $assessments), true);
                $moveResult = LimeExpressionManager::NavigateForwards();
                $LEM =& LimeExpressionManager::singleton();

                if (is_null($moveResult) || is_null($LEM->currentQset) || count($LEM->currentQset) == 0) {
                    continue;
                }

                $_gseq = -1;
                foreach ($LEM->currentQset as $q) {
                    $gseq = $q['info']['gseq'];
                    $gid = $q['info']['gid'];
                    $qid = $q['info']['qid'];

                    //////
                    // SHOW GROUP-LEVEL INFO
                    //////
                    if ($gseq != $_gseq) {
                        $_gseq = $gseq;
                        $ginfo = $LEM->gseq2info[$gseq];

                        // if relevance equation is using SGQA coding, convert to qcoding
                        $grelevance = (($ginfo['grelevance'] == '') ? 1 : $ginfo['grelevance']);
                        $LEM->em->ProcessBooleanExpression($grelevance, $gseq, 0);    // $qseq
                        $grelevance = trim(strip_tags($LEM->em->GetPrettyPrintString()));
                        $gtext = ((trim($ginfo['description']) == '') ? '' : $ginfo['description']);

                        $row = array();
                        $row['class'] = 'G';
                        //create a group code to allow proper importing of multi-lang survey TSVs
                        $row['type/scale'] = 'G' . $gseq;
                        $row['name'] = $ginfo['group_name'];
                        $row['relevance'] = $grelevance;
                        $row['text'] = $gtext;
                        $row['language'] = $lang;
                        $row['random_group'] = $ginfo['randomization_group'];
                        $rows[] = $row;
                    }

                    //////
                    // SHOW QUESTION-LEVEL INFO
                    //////
                    $row = array();

                    $mandatory = (($q['info']['mandatory'] == 'Y') ? 'Y' : '');
                    $question->type = $q['info']['type'];

                    $sgqas = explode('|', $q['sgqa']);
                    if (count($sgqas) == 1 && !is_null($q['info']['default'])) {
                        $default = $q['info']['default'];
                    } else {
                        $default = '';
                    }

                    $qtext = (($q['info']['qtext'] != '') ? $q['info']['qtext'] : '');
                    $help = (($q['info']['help'] != '') ? $q['info']['help'] : '');

                    //////
                    // SHOW QUESTION ATTRIBUTES THAT ARE PROCESSED BY EM
                    //////
                    if (isset($LEM->qattr[$qid]) && count($LEM->qattr[$qid]) > 0) {
                        foreach ($LEM->qattr[$qid] as $key => $value) {
                            if (is_null($value) || trim($value) == '') {
                                continue;
                            }
                            switch ($key) {
                                default:
                                case 'exclude_all_others':
                                case 'exclude_all_others_auto':
                                case 'hidden':
                                    if ($value == false || $value == '0') {
                                        $value = null; // so can skip this one - just using continue here doesn't work.
                                    }
                                    break;
                                case 'relevance':
                                    $value = null;  // means an outdate database structure
                                    break;
                            }
                            if (is_null($value) || trim($value) == '') {
                                continue;   // since continuing from within a switch statement doesn't work
                            }
                            $row[$key] = $value;
                        }
                    }

                    // if relevance equation is using SGQA coding, convert to qcoding
                    $relevanceEqn = (($q['info']['relevance'] == '') ? 1 : $q['info']['relevance']);
                    $LEM->em->ProcessBooleanExpression($relevanceEqn, $gseq, $q['info']['qseq']);    // $qseq
                    $relevanceEqn = trim(strip_tags($LEM->em->GetPrettyPrintString()));
                    $rootVarName = $q['info']['rootVarName'];
                    $preg = '';
                    if (isset($LEM->q2subqInfo[$q['info']['qid']]['preg'])) {
                        $preg = $LEM->q2subqInfo[$q['info']['qid']]['preg'];
                        if (is_null($preg)) {
                            $preg = '';
                        }
                    }

                    $row['class'] = 'Q';
                    $row['type/scale'] = $question->type;
                    $row['name'] = $rootVarName;
                    $row['relevance'] = $relevanceEqn;
                    $row['text'] = $qtext;
                    $row['help'] = $help;
                    $row['language'] = $lang;
                    $row['validation'] = $preg;
                    $row['mandatory'] = $mandatory;
                    $row['other'] = $q['info']['other'];
                    $row['default'] = $default;
                    $row['same_default'] = 1;   // TODO - need this: $q['info']['same_default'];

                    $rows[] = $row;

                    //////
                    // SHOW ALL SUB-QUESTIONS
                    //////
                    $sawThis = array(); // array of rowdivids already seen so only show them once
                    foreach ($sgqas as $sgqa) {
                        if ($LEM->knownVars[$sgqa]['qcode'] == $rootVarName) {
                            continue;   // so don't show the main question as a sub-question too
                        }
                        $rowdivid = $sgqa;
                        $varName = $LEM->knownVars[$sgqa]['qcode'];

                        // if SQrelevance equation is using SGQA coding, convert to qcoding
                        $SQrelevance = (($LEM->knownVars[$sgqa]['SQrelevance'] == '') ? 1 : $LEM->knownVars[$sgqa]['SQrelevance']);
                        $LEM->em->ProcessBooleanExpression($SQrelevance, $gseq, $q['info']['qseq']);
                        $SQrelevance = trim(strip_tags($LEM->em->GetPrettyPrintString()));

                        switch ($q['info']['type']) {
                            case '1':
                                if (preg_match('/#1$/', $sgqa)) {
                                    $rowdivid = null;   // so that doesn't show same message for second scale
                                } else {
                                    $rowdivid = substr($sgqa, 0, -2); // strip suffix
                                    $varName = substr($LEM->knownVars[$sgqa]['qcode'], 0, -2);
                                }
                                break;
                            case 'P':
                                if (preg_match('/comment$/', $sgqa)) {
                                    $rowdivid = null;
                                }
                                break;
                            case ':':
                            case ';':
                                $_rowdivid = $LEM->knownVars[$sgqa]['rowdivid'];
                                if (isset($sawThis[$qid . '~' . $_rowdivid])) {
                                    $rowdivid = null;   // so don't show again
                                } else {
                                    $sawThis[$qid . '~' . $_rowdivid] = true;
                                    $rowdivid = $_rowdivid;
                                    $sgqa_len = strlen($sid . 'X' . $gid . 'X' . $qid);
                                    $varName = $rootVarName . '_' . substr($_rowdivid, $sgqa_len);
                                }
                                break;
                        }
                        if (is_null($rowdivid)) {
                            continue;
                        }

                        $sgqaInfo = $LEM->knownVars[$sgqa];
                        $subqText = $sgqaInfo['subqtext'];

                        if (isset($sgqaInfo['default'])) {
                            $default = $sgqaInfo['default'];
                        } else {
                            $default = '';
                        }

                        $row = array();
                        $row['class'] = 'SQ';
                        $row['type/scale'] = 0;
                        $row['name'] = substr($varName, strlen($rootVarName) + 1);
                        $row['relevance'] = $SQrelevance;
                        $row['text'] = $subqText;
                        $row['language'] = $lang;
                        $row['default'] = $default;
                        $rows[] = $row;
                    }

                    //////
                    // SHOW ANSWER OPTIONS FOR ENUMERATED LISTS, AND FOR MULTIFLEXI
                    //////
                    if (isset($LEM->qans[$qid]) || isset($LEM->multiflexiAnswers[$qid])) {
                        $_scale = -1;
                        if (isset($LEM->multiflexiAnswers[$qid])) {
                            $ansList = $LEM->multiflexiAnswers[$qid];
                        } else {
                            $ansList = $LEM->qans[$qid];
                        }
                        foreach ($ansList as $ans => $value) {
                            $ansInfo = explode('~', $ans);
                            $valParts = explode('|', $value);
                            $valInfo[0] = array_shift($valParts);
                            $valInfo[1] = implode('|', $valParts);
                            if ($_scale != $ansInfo[0]) {
                                $_scale = $ansInfo[0];
                            }

                            $row = array();
                            if ($question->type == ':' || $question->type == ';') {
                                $row['class'] = 'SQ';
                            } else {
                                $row['class'] = 'A';
                            }
                            $row['type/scale'] = $_scale;
                            $row['name'] = $ansInfo[1];
                            $row['relevance'] = $assessments == true ? $valInfo[0] : '';
                            $row['text'] = $valInfo[1];
                            $row['language'] = $lang;
                            $rows[] = $row;
                        }
                    }
                }
            }
            // Now generate the array out output data
            $out = array();
            $out[] = $fields;

            foreach ($rows as $row) {
                $tsv = array();
                foreach ($fields as $field) {
                    $val = (isset($row[$field]) ? $row[$field] : '');
                    $tsv[] = $val;
                }
                $out[] = $tsv;
            }

            return $out;
        }


        /*
         * Returns all known variables.
         * * Actual variables are stored in this structure:
         * $knownVars[$sgqa] = array(
         * 'jsName_on' => // the name of the javascript variable if it is defined on the current page - often 'answerSGQA'
         * 'jsName' => // the name of the javascript variable when referenced  on different pages - usually 'javaSGQA'
         * 'readWrite' => // 'Y' for yes, 'N' for no - currently not used
         * 'hidden' => // 1 if the question attribute 'hidden' is true, otherwise 0
         * 'question' => // the text of the question (or sub-question)
         * 'qid' => // the numeric question id - e.g. the Q part of the SGQA name
         * 'gid' => // the numeric group id - e.g. the G part of the SGQA name
         * 'grelevance' =>  // the group level relevance string
         * 'relevance' => // the question level relevance string
         * 'qcode' => // the qcode-style variable name for this question  (or sub-question)
         * 'qseq' => // the 0-based index of the question within the survey
         * 'gseq' => // the 0-based index of the group within the survey
         * 'type' => // the single character type code for the question
         * 'sgqa' => // the SGQA name for the variable
         * 'ansList' => // ansArray converted to a JavaScript fragment - e.g. ",'answers':{ 'M':'Male','F':'Female'}"
         * 'ansArray' => // PHP array of answer strings, keyed on the answer code = e.g. array['M']='Male';
         * 'scale_id' => // '0' for most answers.  '1' for second scale within dual-scale questions
         * 'rootVarName' => // the root code / name / title for the question, without any sub-question or answer-level suffix.  This is from the title column in the questions table
         * 'subqtext' => // the sub-question text
         * 'rowdivid' => // the JavaScript ID of the row identifier for a question.  This is used to show/hide entire question rows
         * 'onlynum' => // 1 if only numbers are allowed for this variable.  If so, then extra processing is needed to ensure that can use comma as a decimal separator
         * );
         *
         * Reserved variables (e.g. TOKEN:xxxx) are stored with this structure:
         * $knownVars[$token] = array(
         * 'code' => // the static value for the variable
         * 'type' => // ''
         * 'jsName_on' => // ''
         * 'jsName' => // ''
         * 'readWrite' => // 'N' - since these are always read-only variables
         * );
         *
         */
        public function getKnownVars() {
            static $requestCache = [];
            // New implementation.
            return $this->getKnownVars2();
            $session = App()->surveySessionManager->current;
            // Do we need to include the response - attributes in this cache key?
            $cacheKey = $session->responseId;
            bP($cacheKey);

            if (!isset($requestCache[$cacheKey])) {
                $fieldmap = createFieldMap($session->surveyId, 'full', false, false, $session->language);

                $questionCounter = 0;
                foreach ($fieldmap as $fielddata) {
                    if (!isset($fielddata['fieldname']) || !preg_match('#^\d+X\d+X\d+#', $fielddata['fieldname'])) {
                        continue;   // not an SGQA value
                    }
                    $sgqa = $fielddata['fieldname'];
                    $fieldNameParts = explode('X', $sgqa);
                    $groupNum = $fieldNameParts[1];
                    $aid = (isset($fielddata['aid']) ? $fielddata['aid'] : '');
                    $sqid = (isset($fielddata['sqid']) ? $fielddata['sqid'] : '');
                    if ($this->sPreviewMode == 'question') {
                        $fielddata['relevance'] = 1;
                    }
                    if ($this->sPreviewMode == 'group') {
                        $fielddata['grelevance'] = 1;
                    }

                    $questionId = $fieldNameParts[2];
                    $question = $session->getQuestion($fielddata['qid']);
                    $relevance = (isset($fielddata['relevance'])) ? $fielddata['relevance'] : 1;
                    $SQrelevance = (isset($fielddata['SQrelevance'])) ? $fielddata['SQrelevance'] : 1;
                    $grelevance = (isset($fielddata['grelevance'])) ? $fielddata['grelevance'] : 1;
                    $defaultValue = (isset($fielddata['defaultvalue']) ? $fielddata['defaultvalue'] : null);
                    // Create list of codes associated with each question
                    $codeList = (isset($this->qid2code[$question->primaryKey]) ? $this->qid2code[$question->primaryKey] : '');
                    if ($codeList == '') {
                        $codeList = $sgqa;
                    } else {
                        $codeList .= '|' . $sgqa;
                    }
                    $this->qid2code[$question->primaryKey] = $codeList;

                    $readWrite = 'Y';

                    // Set $ansArray
                    switch ($question->type) {
                        case '!': //List - dropdown
                        case 'L': //LIST drop-down/radio-button list

                        // Break
                        case '1': //Array (Flexible Labels) dual scale  // need scale
                        case 'O': //LIST WITH COMMENT drop-down/radio-button list + textarea
                        case 'H': //ARRAY (Flexible) - Column Format
                        case 'F': //ARRAY (Flexible) - Row Format
                        case 'R': //RANKING STYLE
                            $ansArray = (isset($this->qans[$question->primaryKey]) ? $this->qans[$question->primaryKey] : null);
                        if ($question->bool_other && ($question->type == Question::TYPE_DROPDOWN_LIST || $question->type == Question::TYPE_RADIO_LIST)) {
                            if (preg_match('/other$/', $sgqa)) {
                                $ansArray = null;   // since the other variable doesn't need it
                            } else {
                                $ansArray['0~-oth-'] = '0|' . !empty($question->other_replace_text) ? trim($question->other_replace_text) : gT('Other:');
                            }
                        }
                            break;
                        case 'G': //GENDER drop-down list
                        case 'Y': //YES/NO radio-buttons
                        case 'C': //ARRAY (YES/UNCERTAIN/NO) radio-buttons
                        case 'E': //ARRAY (Increase/Same/Decrease) radio-buttons
                            $ansArray = $question->answers;
                            break;
                        default:
                            $ansArray = null;
                    }

                    // set $subqtext text - for display of primary sub-question
                    $subqtext = '';
                    switch ($question->type) {
                        default:
                            $subqtext = (isset($fielddata['subquestion']) ? $fielddata['subquestion'] : '');
                            break;
                        case ':': //ARRAY (Multi Flexi) 1 to 10
                        case ';': //ARRAY (Multi Flexi) Text
                            $subqtext = (isset($fielddata['subquestion1']) ? $fielddata['subquestion1'] : '');
                            $ansList = array();
                            if (isset($fielddata['answerList'])) {
                                foreach ($fielddata['answerList'] as $ans) {
                                    $ansList['1~' . $ans['code']] = $ans['code'] . '|' . $ans['answer'];
                                }
                            }
                            break;
                    }

                    // Set $varName (question code / questions.title), $rowdivid, $csuffix, $sqsuffix, and $question
                    $rowdivid = null;   // so that blank for types not needing it.
                    $sqsuffix = '';
                    switch ($question->type) {
                        case '!': //List - dropdown
                        case '5': //5 POINT CHOICE radio-buttons
                        case 'D': //DATE
                        case 'G': //GENDER drop-down list
                        case 'I': //Language Question
                        case 'L': //LIST drop-down/radio-button list
                        case 'N': //NUMERICAL QUESTION TYPE
                        case 'O': //LIST WITH COMMENT drop-down/radio-button list + textarea
                        case 'S': //SHORT FREE TEXT
                        case 'T': //LONG FREE TEXT
                        case 'U': //HUGE FREE TEXT
                        case 'X': //BOILERPLATE QUESTION
                        case 'Y': //YES/NO radio-buttons
                        case '|': //File Upload
                        case '*': //Equation
                            $csuffix = '';
                            $sqsuffix = '';
                            $varName = $fielddata['title'];
                            if ($fielddata['aid'] != '') {
                                $varName .= '_' . $fielddata['aid'];
                            }
                            $questionText = $fielddata['question'];
                            break;
                        case '1': //Array (Flexible Labels) dual scale
                            $csuffix = $fielddata['aid'] . '#' . $fielddata['scale_id'];
                            $sqsuffix = '_' . $fielddata['aid'];
                            $varName = $fielddata['title'] . '_' . $fielddata['aid'] . '_' . $fielddata['scale_id'];;
                            $questionText = $fielddata['subquestion'] . '[' . $fielddata['scale'] . ']';
                            //                    $question = $fielddata['question'] . ': ' . $fielddata['subquestion'] . '[' . $fielddata['scale'] . ']';
                            $rowdivid = substr($sgqa, 0, -2);
                            break;
                        case 'A': //ARRAY (5 POINT CHOICE) radio-buttons
                        case 'B': //ARRAY (10 POINT CHOICE) radio-buttons
                        case 'C': //ARRAY (YES/UNCERTAIN/NO) radio-buttons
                        case 'E': //ARRAY (Increase/Same/Decrease) radio-buttons
                        case 'F': //ARRAY (Flexible) - Row Format
                        case 'H': //ARRAY (Flexible) - Column Format    // note does not have javatbd equivalent - so array filters don't work on it
                        case 'K': //MULTIPLE NUMERICAL QUESTION         // note does not have javatbd equivalent - so array filters don't work on it, but need rowdivid to process validations
                        case 'M': //Multiple choice checkbox
                        case 'P': //Multiple choice with comments checkbox + text
                        case 'Q': //MULTIPLE SHORT TEXT                 // note does not have javatbd equivalent - so array filters don't work on it
                        case 'R': //RANKING STYLE                       // note does not have javatbd equivalent - so array filters don't work on it
                            $csuffix = $fielddata['aid'];
                            $varName = $fielddata['title'] . '_' . $fielddata['aid'];
                            $questionText = $fielddata['subquestion'];
                            //                    $question = $fielddata['question'] . ': ' . $fielddata['subquestion'];
                            if ($question->type != 'H') {
                                if ($question->type == 'P' && preg_match("/comment$/", $sgqa)) {
                                    //                            $rowdivid = substr($sgqa,0,-7);
                                } else {
                                    $sqsuffix = '_' . $fielddata['aid'];
                                    $rowdivid = $sgqa;
                                }
                            }
                            break;
                        case ':': //ARRAY (Multi Flexi) 1 to 10
                        case ';': //ARRAY (Multi Flexi) Text
                            $csuffix = $fielddata['aid'];
                            $sqsuffix = '_' . substr($fielddata['aid'], 0, strpos($fielddata['aid'], '_'));
                            $varName = $fielddata['title'] . '_' . $fielddata['aid'];
                            $questionText = $fielddata['subquestion1'] . '[' . $fielddata['subquestion2'] . ']';
                            //                    $question = $fielddata['question'] . ': ' . $fielddata['subquestion1'] . '[' . $fielddata['subquestion2'] . ']';
                            $rowdivid = substr($sgqa, 0, strpos($sgqa, '_'));
                            break;
                    }

                    // $onlynum
                    $onlynum = false; // the default
                    switch ($question->type) {
                        case 'K': //MULTIPLE NUMERICAL QUESTION
                        case 'N': //NUMERICAL QUESTION TYPE
                        case ':': //ARRAY (Multi Flexi) 1 to 10
                            $onlynum = true;
                            break;
                        case '*': // Equation
                        case ';': //ARRAY (Multi Flexi) Text
                        case 'Q': //MULTIPLE SHORT TEXT
                        case 'S': //SHORT FREE TEXT
                            if (isset($question->bool_numbers_only) && $question->bool_numbers_only) {
                                $onlynum = true;
                            }
                            break;
                        case 'L': //LIST drop-down/radio-button list
                        case 'M': //Multiple choice checkbox
                        case 'P': //Multiple choice with comments checkbox + text
                            $onlynum = $question->other_numbers_only && preg_match('/other$/', $sgqa);
                            break;
                        default:
                            break;
                    }

                    // Set $jsVarName_on (for on-page variables - e.g. answerSGQA) and $jsVarName (for off-page  variables; the primary name - e.g. javaSGQA)
                    switch ($question->type) {
                        case 'R': //RANKING STYLE
                            $jsVarName_on = 'answer' . $sgqa;
                            $jsVarName = 'java' . $sgqa;
                            break;
                        case 'D': //DATE
                        case 'N': //NUMERICAL QUESTION TYPE
                        case 'S': //SHORT FREE TEXT
                        case 'T': //LONG FREE TEXT
                        case 'U': //HUGE FREE TEXT
                        case 'Q': //MULTIPLE SHORT TEXT
                        case 'K': //MULTIPLE NUMERICAL QUESTION
                        case 'X': //BOILERPLATE QUESTION
                            $jsVarName_on = 'answer' . $sgqa;
                            $jsVarName = 'java' . $sgqa;
                            break;
                        case '!': //List - dropdown
                            if (preg_match("/other$/", $sgqa)) {
                                $jsVarName = 'java' . $sgqa;
                                $jsVarName_on = 'othertext' . substr($sgqa, 0, -5);
                            } else {
                                $jsVarName = 'java' . $sgqa;
                                $jsVarName_on = $jsVarName;
                            }
                            break;
                        case 'L': //LIST drop-down/radio-button list
                            if (preg_match("/other$/", $sgqa)) {
                                $jsVarName = 'java' . $sgqa;
                                $jsVarName_on = 'answer' . $sgqa . "text";
                            } else {
                                $jsVarName = 'java' . $sgqa;
                                $jsVarName_on = $jsVarName;
                            }
                            break;
                        case '5': //5 POINT CHOICE radio-buttons
                        case 'G': //GENDER drop-down list
                        case 'I': //Language Question
                        case 'Y': //YES/NO radio-buttons
                        case '*': //Equation
                        case 'A': //ARRAY (5 POINT CHOICE) radio-buttons
                        case 'B': //ARRAY (10 POINT CHOICE) radio-buttons
                        case 'C': //ARRAY (YES/UNCERTAIN/NO) radio-buttons
                        case 'E': //ARRAY (Increase/Same/Decrease) radio-buttons
                        case 'F': //ARRAY (Flexible) - Row Format
                        case 'H': //ARRAY (Flexible) - Column Format
                        case 'M': //Multiple choice checkbox
                        case 'O': //LIST WITH COMMENT drop-down/radio-button list + textarea
                            if ($question->type == 'O' && preg_match('/_comment$/', $varName)) {
                                $jsVarName_on = 'answer' . $sgqa;
                            } else {
                                $jsVarName_on = 'java' . $sgqa;
                            }
                            $jsVarName = 'java' . $sgqa;
                            break;
                        case '1': //Array (Flexible Labels) dual scale
                            $jsVarName = 'java' . str_replace('#', '_', $sgqa);
                            $jsVarName_on = $jsVarName;
                            break;
                        case ':': //ARRAY (Multi Flexi) 1 to 10
                        case ';': //ARRAY (Multi Flexi) Text
                            $jsVarName = 'java' . $sgqa;
                            $jsVarName_on = 'answer' . $sgqa;;
                            break;
                        case '|': //File Upload
                            $jsVarName = $sgqa;
                            $jsVarName_on = $jsVarName;
                            break;
                        case 'P': //Multiple choice with comments checkbox + text
                            if (preg_match("/(other|comment)$/", $sgqa)) {
                                $jsVarName_on = 'answer' . $sgqa;  // is this true for survey.php and not for group.php?
                                $jsVarName = 'java' . $sgqa;
                            } else {
                                $jsVarName = 'java' . $sgqa;
                                $jsVarName_on = $jsVarName;
                            }
                            break;
                    }
                    // Hidden question are never on same page (except for equation)
                    if ($question->hidden && $question->type != "*") {
                        $jsVarName_on = '';
                    }


                    $ansList = '';
                    if (isset($ansArray) && !is_null($ansArray)) {
                        $answers = [];
                        foreach ($ansArray as $key => $value) {
                            $answers[] = "'" . $key . "':'" . htmlspecialchars(preg_replace('/[[:space:]]/', ' ',
                                    $value),
                                    ENT_QUOTES) . "'";
                        }
                        $ansList = ",'answers':{ " . implode(",", $answers) . "}";
                    }

                    // Set mappings of variable names to needed attributes
                    $result[$sgqa] = [
                        'jsName_on' => $jsVarName_on,
                        'jsName' => $jsVarName,
                        'readWrite' => $readWrite,
                        'hidden' => $question->hidden,
                        'question' => $questionText,
                        'qid' => $question->primaryKey,
                        'gid' => $groupNum,
                        'grelevance' => $grelevance,
                        'relevance' => $relevance,
                        'SQrelevance' => $SQrelevance,
                        'qcode' => $varName,
                        'type' => $question->type,
                        'sgqa' => $sgqa,
                        'ansList' => $ansList,
                        'ansArray' => $ansArray,
                        'scale_id' => $question->scale_id,
                        'default' => $defaultValue,
                        'rootVarName' => $fielddata['title'],
                        'subqtext' => $subqtext,
                        'rowdivid' => (is_null($rowdivid) ? '' : $rowdivid),
                        'onlynum' => $onlynum,
                        'gseq' => $session->getGroupIndex($groupNum)
                    ];


                }


                // Now set tokens
                if (isset($session->token)) {
                    //Gather survey data for tokenised surveys, for use in presenting questions
                    $result['TOKEN:TOKEN'] = array(
                        'code' => $session->token->token,
                        'jsName_on' => '',
                        'jsName' => '',
                        'readWrite' => 'N',
                    );

                    foreach ($session->token as $key => $val) {
                        $result["TOKEN:" . strtoupper($key)] = array(
                            'code' => $anonymized ? '' : $val,
                            'jsName_on' => '',
                            'jsName' => '',
                            'readWrite' => 'N',
                        );
                    }
                } else {
                    // Read list of available tokens from the tokens table so that preview and error checking works correctly
                    $attrs = array_keys(getTokenFieldsAndNames($session->surveyId));

                    $blankVal = array(
                        'code' => '',
                        'type' => '',
                        'jsName_on' => '',
                        'jsName' => '',
                        'readWrite' => 'N',
                    );

                    foreach ($attrs as $key) {
                        if (preg_match('/^(firstname|lastname|email|usesleft|token|attribute_\d+)$/', $key)) {
                            $result['TOKEN:' . strtoupper($key)] = $blankVal;
                        }
                    }
                }
                // set default value for reserved 'this' variable
                $result['this'] = [
                    'jsName_on' => '',
                    'jsName' => '',
                    'readWrite' => '',
                    'hidden' => '',
                    'question' => 'this',
                    'qid' => '',
                    'gid' => '',
                    'grelevance' => '',
                    'relevance' => '',
                    'SQrelevance' => '',
                    'qcode' => 'this',
                    'qseq' => '',
                    'gseq' => '',
                    'type' => '',
                    'sgqa' => '',
                    'rowdivid' => '',
                    'ansList' => '',
                    'ansArray' => [],
                    'scale_id' => '',
                    'default' => '',
                    'rootVarName' => 'this',
                    'subqtext' => '',
                    'rowdivid' => '',
                ];
                $requestCache[$cacheKey] = $result;
            }
            eP($cacheKey);
            return $requestCache[$cacheKey];
        }


        public function getKnownVars2() {
            $result = [];
            if (null !== $session = App()->surveySessionManager->current) {
                foreach($session->getGroups() as $group) {
                    foreach($session->getQuestions($group) as $question) {
                        foreach($question->getFields() as $field) {
                            $result[] = $field;
                        }
                    }
                }
            }
            return $result;

        }

        public function getPageRelevanceInfo() {
            $session = App()->surveySessionManager->current;
            switch($session->format) {
                case Survey::FORMAT_GROUP:
                    $pageRelevanceInfo = [];
                    foreach($session->getQuestions($session->getGroupByIndex($session->step)) as $question) {
                        $pageRelevanceInfo[] = $this->processQuestionRelevance($question);

                    }
                    break;
                case Survey::FORMAT_ALL_IN_ONE:
                    $pageRelevanceInfo = [];
                    foreach($session->groups as $group) {
                        $pageRelevanceInfo[] = $this->getGroupRelevanceInfo($group);
                    }
                    break;
                case Survey::FORMAT_QUESTION:
                    $pageRelevanceInfo = [
                    ];
                    break;
                default:
                    throw new \Exception("Unknown survey format");
            }

            return $pageRelevanceInfo;
        }

        /**
         * Process the relevance of a question
         * @todo Remove all functions that return these kinds of arrays.
         * @param Question $question
         */
        public function processQuestionRelevance(Question $question)
        {
            bP();
            $session = App()->surveySessionManager->current;
            // These will be called in the order that questions are supposed to be asked
            // TODO - cache results and generated JavaScript equations?

            $questionSeq = $session->getQuestionIndex($question->primaryKey);
            $groupSeq = $session->getGroupIndex($question->gid);

            $expression = htmlspecialchars_decode($question->relevance, ENT_QUOTES);
            $result = [
                'result' => $this->em->ProcessBooleanExpression($expression, $groupSeq, $questionSeq),
                'relevancejs' => $this->em->GetJavaScriptEquivalentOfExpression(),
                'qid' => $question->primaryKey,
                'gid' => $question->gid,
                'gseq' => $session->getGroupIndex($question->gid),
                'type' => $question->type,
                'hidden' => $question->bool_hidden,
                'relevanceVars' => implode('|', $this->em->GetJSVarsUsed()),
                'numJsVars' => count($this->em->GetJSVarsUsed()),
            ];

            $hasErrors = $this->em->HasErrors();
            eP();
            return $result;
        }

        public function getPageTailorInfo() {
            return $this->em->GetCurrentSubstitutionInfo();
        }


        /**
         * /**
         * mapping of questions to information about their subquestions.
         * One entry per question, indexed on qid
         *
         * @example [702] = array(
         * 'qid' => 702 // the question id
         * 'qseq' => 6 // the question sequence
         * 'gseq' => 0 // the group sequence
         * 'sgqa' => '26626X34X702' // the root of the SGQA code (reallly just the SGQ)
         * 'varName' => 'afSrcFilter_sq1' // the full qcode variable name - note, if there are sub-questions, don't use this one.
         * 'type' => 'M' // the one-letter question type
         * 'fieldname' => '26626X34X702sq1' // the fieldname (used as JavaScript variable name, and also as database column name
         * 'rootVarName' => 'afDS'  // the root variable name
         * 'preg' => '/[A-Z]+/' // regular expression validation equation, if any
         * 'subqs' => array() of sub-questions, where each contains:
         *     'rowdivid' => '26626X34X702sq1' // the javascript id identifying the question row (so array_filter can hide rows)
         *     'varName' => 'afSrcFilter_sq1' // the full variable name for the sub-question
         *     'jsVarName_on' => 'java26626X34X702sq1' // the JavaScript variable name if the variable is defined on the current page
         *     'jsVarName' => 'java26626X34X702sq1' // the JavaScript variable name to use if the variable is defined on a different page
         *     'csuffix' => 'sq1' // the SGQ suffix to use for a fieldname
         *     'sqsuffix' => '_sq1' // the suffix to use for a qcode variable name
         *  );
         *
         * @var type
         */
        public function getSubQuestionInfo(Question $question) {
            bP();
            $result = [
                'qid' => $question->primaryKey,
                'gid' => $question->gid,
                'sgqa' => $question->sgqa,
                'varName' => $question->varName,
                'type' => $question->type,
                'preg' => $question->preg,
//                'rootVarName' =>


            ];

            $subQuestions = [];
            if ($question->hasSubQuestions) {
                /** @var Question $subQuestion */
                foreach($question->subQuestions as $subQuestion) {
                    $subQuestions[] = [
                        'rowdivid' => $question->sgqa . $subQuestion->title,
                        'jsVarName_on' => 'java' . $question->sgqa . $subQuestion->title,
                        'jsVarName' => 'java' . $question->sgqa . $subQuestion->title,
                        'csuffix' => $subQuestion->title,
                        'sqsuffix' => "_" . $subQuestion->title
                    ];
                }
            }
            $result['subqs'] = $subQuestions;
            eP();
            return $result;
        }

        /**
         * Returns an array mapping variable names to field names.
         * @return array
         */
        public function getAliases() {
            $session = App()->surveySessionManager->current;
            $result = [];
            foreach($session->survey->questions as $question) {
                /** @var QuestionResponseField $field */
                foreach ($question->getFields() as $field) {

                    if (substr_compare($field->name, 'other', -5, 5) != 0) {
                        $result[$field->code] = 'java' . $field->name;
                    }

                    $result[$field->name] = 'java' . $field->name;
                }
            }
            return $result;
        }
    }

