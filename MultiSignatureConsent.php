<?php
namespace Stanford\MultiSignatureConsent;

require_once "emLoggerTrait.php";

class MultiSignatureConsent extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public $evalLogic;
    public $destinationFileField;
    public $inputForms = [];
    public $header;
    public $footer;
    public $saveToFileRepo;
    public $saveToExternalStorage;
    public $saveToAsSurvey;

    private static $MAKING_PDF = false;

    private static $KEEP_RECORD_ID_FIELD = false;
    private static $KEEP_PAGE_BREAKS = false;

    public function __construct() {
		parent::__construct();
		// Other code to run when object is instantiated
	}


	public function initialize() {
        $this->evalLogic            = $this->getProjectSetting('eval-logic');
        $this->destinationFileField = $this->getProjectSetting('destination-file-field');
        $this->header               = $this->getProjectSetting('header');
        $this->footer               = $this->getProjectSetting('footer');
        $this->saveToFileRepo       = $this->getProjectSetting('save-to-file-repo');
        $this->saveToExternalStorage= $this->getProjectSetting('save-to-external-storage');
        $this->saveToAsSurvey       = $this->getProjectSetting('save-to-as-survey');
        $this::$KEEP_PAGE_BREAKS    = $this->getProjectSetting('keep-page-breaks');
        $this::$KEEP_RECORD_ID_FIELD= $this->getProjectSetting('keep-record-id-field');

        $instances = $this->framework->getSubSettings('instance');
        foreach ($instances as $instance) {
            $this->inputForms[] = $instance['form-name'];
        }
        // $this->emDebug($instances, $this->inputForms);
    }


    public function redcap_every_page_before_render() {
        if (PAGE == 'FileRepository/index.php') {
            global $Proj;
            $this->initialize();
            $firstForm = $this->inputForms[0];
            if (empty($firstForm)) return;
            if (empty($Proj->forms[$firstForm]['survey_id'])) return;
            $survey_id = $Proj->forms[$firstForm]['survey_id'];
            $Proj->surveys[$survey_id]['pdf_auto_archive']=true;
        }
    }


	public function redcap_pdf ($project_id, $metadata, $data, $instrument = NULL, $record = NULL, $event_id = NULL, $instance = 1 ) {
        if (self::$MAKING_PDF) {


            // We were called from inside of this EM
            $this->emDebug("In PDF Hook!", func_get_args(), $this->inputForms, $this->evalLogic);

            // Build metadata from all forms
            global $Proj;

            // Get fields in all forms
            $new_meta = [];
            $fields = [];
            foreach ($Proj->metadata as $field_name => $field_meta) {
                if (in_array($field_meta['form_name'], $this->inputForms)) {
                    // This field is in our form


                    // Skip form_complete fields
                    if ($field_meta['form_name'] . "_complete" == $field_meta['field_name']) {
                        continue;
                    }

                    // Skip @HIDDEN-PDF fields
                    if (strpos($field_meta['misc'], '@HIDDEN-PDF') !== FALSE) {
                        continue;
                    }

                    // Skip record id field unless told otherwise
                    if (! $this::$KEEP_RECORD_ID_FIELD &&
                        $field_meta['field_order'] == 1
                    ) {
                        continue;
                    }

                    // In order to get all signatures on 'same page' of PDF
                    // I make it appear as all fields are on the first form
                    if (! $this::$KEEP_PAGE_BREAKS &&
                        $field_meta['form_name'] !== $instrument
                    ) {
                        $field_meta['form_name'] = $instrument;
                    }

                    // This is not a hidden PDF field
                    $new_meta[] = $field_meta;
                    $fields[] = $field_name;
                }
            }

            // Get the updated data
            $new_data = \REDCap::getData('array', $record, $fields, $event_id);

            return array('metadata'=>$new_meta, 'data'=>$new_data);
        }
    }


	public function redcap_save_record( $project_id, $record, $instrument, $event_id, $group_id = NULL, $survey_hash = NULL, $response_id = NULL, $repeat_instance = 1) {
        try {

            global $Proj;
            $this->initialize();

            // Make sure we are in one of the input forms
            if (!in_array($instrument, $this->inputForms)) {
                $this->emDebug("$instrument is not in " . implode(",", $this->inputForms) . " -- skipping");
                return false;
            }

            $this->emDebug("Saving $record on $instrument, event $event_id with logic $this->evalLogic");
            // $event_name = $Proj->longitudinal ? \REDCap::getEventNames(true,true,$event_id) : null;
            $logic = \REDCap::evaluateLogic($this->evalLogic, $project_id, $record, $event_id);

            if (empty($this->evalLogic) || $logic == false) {
                // Skip - nothing to do here
                $this->emDebug("Skip");
                return false;
            }

            $this->emDebug("Logic True");

            // Make a PDF
            //$this->emDebug("Making PDF", self::$MAKING_PDF);
            self::$MAKING_PDF = true;

            // Always start with the 'first form' as the template
            $first_form = $this->inputForms[0];
            $last_form = $this->inputForms[count($this->inputForms) -1 ];

            $pdf        = \REDCap::getPDF($record, $first_form, $event_id, false, $repeat_instance,
                true, $this->header, $this->footer);

            // Get a temp filename
            // $filename = APP_PATH_TEMP . date('YmdHis') . "_" .
            //     $this->PREFIX . "_" .
            //     $record . ".pdf";
            $recordFilename = str_replace(" ", "_", trim(preg_replace("/[^0-9a-zA-Z- ]/", "", $record)));
            $formFilename   = str_replace(" ", "_", trim(preg_replace("/[^0-9a-zA-Z- ]/", "", $Proj->forms[$first_form]['menu'])));
            $filename       = APP_PATH_TEMP . "pid" . $this->getProjectId() .
                "_form" . $formFilename . "_id" . $recordFilename . "_" . date('Y-m-d_His') . ".pdf";

            // Make a file with the PDF
            file_put_contents($filename, $pdf);

            // Add PDF to edocs_metadata table
            $pdfFile = array('name' => basename($filename), 'type' => 'application/pdf',
                'size' => filesize($filename), 'tmp_name' => $filename);
            $edoc_id = \Files::uploadFile($pdfFile);
            if ($this->saveToExternalStorage) {
                $externalFileStoreWrite=\Files::writeFilePdfAutoArchiverToExternalServer( basename($filename), $pdf);
                \REDCap::logEvent($this->getModuleName(), "A PDF (" .
                basename($filename) .
                ") has been written to the external storage containing data from " .
                    implode(",", $this->inputForms), "", $record, $event_id);

            }
            // Upload to file_field to EDOCS
            // $edoc_id = $this->framework->saveFile($filename);
            $this->emDebug($edoc_id);

            // Remove it from TEMP
            unlink($filename);

            if ($edoc_id == 0) {
                $this->emError("Unable to get edoc id!");
                return false;
            }

            // Save it to the record
            if (!empty($this->destinationFileField)) {
                $data = [
                    $record => [
                        $event_id => [
                            $this->destinationFileField => $edoc_id
                        ]
                    ]
                ];

                $result = \Records::saveData(
                    $project_id,
                    'array',        //$dataFormat = (isset($args[1])) ? strToLower($args[1]) : 'array';
                    $data,          // = (isset($args[2])) ? $args[2] : "";
                    'normal',       //$overwriteBehavior = (isset($args[3])) ? strToLower($args[3]) : 'normal';
                    'YMD',          //$dateFormat = (isset($args[4])) ? strToUpper($args[4]) : 'YMD';
                    'flat',         //$type = (isset($args[5])) ? strToLower($args[5]) : 'flat';
                    $group_id,      // = (isset($args[6])) ? $args[6] : null;
                    true,           //$dataLogging = (isset($args[7])) ? $args[7] : true;
                    true,           //$performAutoCalc = (isset($args[8])) ? $args[8] : true;
                    true,           //$commitData = (isset($args[9])) ? $args[9] : true;
                    false,          //$logAsAutoCalculations = (isset($args[10])) ? $args[10] : false;
                    true,           //$skipCalcFields = (isset($args[11])) ? $args[11] : true;
                    [],             //$changeReasons = (isset($args[12])) ? $args[12] : array();
                    false,          //$returnDataComparisonArray = (isset($args[13])) ? $args[13] : false;
                    false,          //**** $skipFileUploadFields = (isset($args[14])) ? $args[14] : true;
                    false,          //$removeLockedFields = (isset($args[15])) ? $args[15] : false;
                    false,          //$addingAutoNumberedRecords = (isset($args[16])) ? $args[16] : false;
                    false           //$bypassPromisCheck = (isset($args[17])) ? $args[17] : false;
                );

                \REDCap::logEvent($this->getModuleName(), $this->destinationFileField .
                    " was updated with a new PDF containing data from " .
                    implode(",", $this->inputForms), "", $record, $event_id);
            }


            // // Save to file repository
            if ($this->saveToFileRepo) {
                $pdf_form = empty($this->saveToAsSurvey) ? $last_form : $this->saveToAsSurvey;
                if (empty($Proj->forms[$pdf_form]['survey_id'])) {
                    \REDCap::logEvent($this->getModuleName() . " Error",
                        "Cannot save to file repository unless the pdf_form is a survey ($pdf_form)", "", $record, $event_id);
                } else {
                    // Add values to redcap_surveys_pdf_archive table
                    $survey_id = $Proj->forms[$pdf_form]['survey_id'];

                    $ip          = \System::clientIpAddress();
                    $nameDobText = $this->getModuleName();
                    $versionText = $typeText = "";
                    $sql         = "replace into redcap_surveys_pdf_archive (doc_id, record, event_id, survey_id, instance, identifier, version, type, ip) values
                            ($edoc_id, '" . db_escape($record) . "', '" . db_escape($event_id) . "', '" . db_escape($survey_id) . "', '" . db_escape($repeat_instance) . "',
                            " . checkNull($nameDobText) . ", " . checkNull($versionText) . ", " . checkNull($typeText) . ", " . checkNull($ip) . ")";
                    $q           = db_query($sql);
                    $this->emDebug($sql, $q);
                }
            }

            self::$MAKING_PDF = false;
        } catch(\Exception $e) {
            $this->emError($e->getMessage(), "Line: " . $e->getLine(), $e->getTraceAsString());
        }
    }


}
