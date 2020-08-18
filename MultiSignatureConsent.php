<?php
namespace Stanford\MultiSignatureConsent;

require_once "emLoggerTrait.php";

class MultiSignatureConsent extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;
    //TODO: Instantiate array versions to capture as well as vars
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

    //TODO: Convert to capture as arrays rather than dims using array names from above
    public function initializeArr() {
        $this->evalLogicArr[]            = $this->getProjectSetting('eval-logic');
        $this->destinationFileFieldArr[] = $this->getProjectSetting('destination-file-field');
        $this->headerArr[]               = $this->getProjectSetting('header');
        $this->footerArr[]               = $this->getProjectSetting('footer');
        $this->saveToFileRepoArr[]       = $this->getProjectSetting('save-to-file-repo');
        $this->saveToExternalStorageArr[]= $this->getProjectSetting('save-to-external-storage');
        $this->saveToAsSurveyArr[]       = $this->getProjectSetting('save-to-as-survey');
        $this::$KEEP_PAGE_BREAKSArr[]    = $this->getProjectSetting('keep-page-breaks'); //TODO were these reserved tokens?
        $this::$KEEP_RECORD_ID_FIELDArr[]= $this->getProjectSetting('keep-record-id-field'); //TODO were these reserved tokens?

        $instances[]                     = $this->getProjectSetting('instance'); //TODO: Does this detonate on multidimensional arrays?
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
        REDCap::logEvent($this->getModuleName() . " Debug",
        "Save Record triggered", "", $record, $event_id);
        try {

            global $Proj;

            REDCap::logEvent($this->getModuleName() . " Debug",
                            "Check config with initializeArr()", "", $record, $event_id);
            //TODO: Check config with initializeArr() array-capturing version of initialize()
            $this->initializeArr();

            // Start loop through configured eval logics 

            $target_cnt=count($evalLogicArr[] );
            REDCap::logEvent($this->getModuleName() . " Target count",
            $target_cnt, "", $record, $event_id);


            }
            } catch(\Exception $e) {
                $this->emError($e->getMessage(), "Line: " . $e->getLine(), $e->getTraceAsString());
            }
        //TODO: End loop?
        
        //TODO: Add catch around loop
    }


}
