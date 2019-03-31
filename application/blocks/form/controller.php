<?php
namespace Application\Block\Form;

use Concrete\Core\Block\BlockController;
use Concrete\Core\Entity\File\Version;
use Config;
use Core;
use Database;
use Events;
use Exception;
use File;
use FileImporter;
use FileSet;
use Page;
use User;
use UserInfo;

class Controller extends \Concrete\Block\Form\Controller
{

    /**
     * Users submits the completed survey.
     *
     * @param int $bID
     */

    public function action_submit_form($bID = false){
        if($this->post('Submit') == 'success'){
            if ($this->bID != $bID) {
                return false;
            }

            $ip = Core::make('helper/validation/ip');
            $this->view();

            if ($ip->isBlacklisted()) {
                $this->set('invalidIP', $ip->getErrorMessage());

                return;
            }

            $txt = Core::make('helper/text');
            $db = Database::connection();

            //question set id
            $qsID = intval($_POST['qsID']);
            if ($qsID == 0) {
                throw new Exception(t("Oops, something is wrong with the form you posted (it doesn't have a question set id)."));
            }
            $errors = [];

            $token = Core::make('token');
            if (!$token->validate('form_block_submit_qs_' . $qsID)) {
                $errors[] = $token->getErrorMessage();
            }

            //get all questions for this question set
            $rows = $db->GetArray("SELECT * FROM {$this->btQuestionsTablename} WHERE questionSetId=? AND bID=? order by position asc, msqID", [$qsID, intval($this->bID)]);

            if (!count($rows)) {
                throw new Exception(t("Oops, something is wrong with the form you posted (it doesn't have any questions)."));
            }

            $errorDetails = [];

            // check captcha if activated
            if ($this->displayCaptcha && $this->post('form_mode') != 'confirm') {
                $captcha = Core::make('helper/validation/captcha');
                if (!$captcha->check()) {
                    $errors['captcha'] = t('Incorrect captcha code');
                    $_REQUEST['ccmCaptchaCode'] = '';
                }
            }

            //checked required fields
            foreach ($rows as $row) {
                if ($row['inputType'] == 'datetime') {
                    if (!isset($datetime)) {
                        $datetime = Core::make('helper/form/date_time');
                    }
                    $translated = $datetime->translate('Question' . $row['msqID']);
                    if ($translated) {
                        $_POST['Question' . $row['msqID']] = $translated;
                    }
                }
                if (intval($row['required']) == 1) {
                    $notCompleted = 0;
                    if ($row['inputType'] == 'email') {
                        if (!Core::make('helper/validation/strings')->email($_POST['Question' . $row['msqID']])) {
                            $errors['emails'] = t('You must enter a valid email address.');
                            $errorDetails[$row['msqID']]['emails'] = $errors['emails'];
                        }
                    }
                    if ($row['inputType'] == 'checkboxlist') {
                        $answerFound = 0;
                        foreach ($_POST as $key => $val) {
                            if (strstr($key, 'Question' . $row['msqID'] . '_') && strlen($val)) {
                                $answerFound = 1;
                            }
                        }
                        if (!$answerFound) {
                            $notCompleted = 1;
                        }
                    } elseif ($row['inputType'] == 'address') {
                        if(strlen($_POST['Question' . $row['msqID'] . '_post']) == 0 || strlen($_POST['Question' . $row['msqID'] . '_addr']) == 0 ){
                            $notCompleted = 1;
                        }
                    } elseif ($row['inputType'] == 'fileupload') {
                        if (!isset($_FILES['Question' . $row['msqID']]) || !is_uploaded_file($_FILES['Question' . $row['msqID']]['tmp_name'])) {
                            $notCompleted = 1;
                        }
                    } elseif (!strlen(trim($_POST['Question' . $row['msqID']]))) {
                        $notCompleted = 1;
                    }
                    if ($notCompleted) {
                        $errors['CompleteRequired'] = t('Complete required fields *');
                        $errorDetails[$row['msqID']]['CompleteRequired'] = $errors['CompleteRequired'];
                    }
                }
            }

            //try importing the file if everything else went ok
            $tmpFileIds = [];
            if (!count($errors)) {
                foreach ($rows as $row) {
                    if ($row['inputType'] != 'fileupload') {
                        continue;
                    }
                    $questionName = 'Question' . $row['msqID'];
                    if (!intval($row['required']) &&
                        (
                        !isset($_FILES[$questionName]['tmp_name']) || !is_uploaded_file($_FILES[$questionName]['tmp_name'])
                        )
                    ) {
                        continue;
                    }
                    $fi = new FileImporter();
                    $resp = $fi->import($_FILES[$questionName]['tmp_name'], $_FILES[$questionName]['name']);
                    if (!($resp instanceof Version)) {
                        switch ($resp) {
                        case FileImporter::E_FILE_INVALID_EXTENSION:
                            $errors['fileupload'] = t('Invalid file extension.');
                            $errorDetails[$row['msqID']]['fileupload'] = $errors['fileupload'];
                            break;
                        case FileImporter::E_FILE_INVALID:
                            $errors['fileupload'] = t('Invalid file.');
                            $errorDetails[$row['msqID']]['fileupload'] = $errors['fileupload'];
                            break;
                    }
                    } else {
                        $tmpFileIds[intval($row['msqID'])] = $resp->getFileID();
                        if (intval($this->addFilesToSet)) {
                            $fs = new FileSet();
                            $fs = $fs->getByID($this->addFilesToSet);
                            if ($fs->getFileSetID()) {
                                $fs->addFileToSet($resp);
                            }
                        }
                    }
                }
            }

            if (count($errors)) {
                $this->set('formResponse', t('Please correct the following errors:'));
                $this->set('errors', $errors);
                $this->set('errorDetails', $errorDetails);
            } else { //no form errors
                //save main survey record
                $u = new User();
                $uID = 0;
                if ($u->isRegistered()) {
                    $uID = $u->getUserID();
                }
                $q = "insert into {$this->btAnswerSetTablename} (questionSetId, uID) values (?,?)";
                $db->query($q, [$qsID, $uID]);
                $answerSetID = $db->Insert_ID();
                $this->lastAnswerSetId = $answerSetID;

                $questionAnswerPairs = [];

                if (Config::get('concrete.email.form_block.address') && strstr(Config::get('concrete.email.form_block.address'), '@')) {
                    $formFormEmailAddress = Config::get('concrete.email.form_block.address');
                } else {
                    $adminUserInfo = UserInfo::getByID(USER_SUPER_ID);
                    $formFormEmailAddress = $adminUserInfo->getUserEmail();
                }
                $replyToEmailAddress = $formFormEmailAddress;
                //loop through each question and get the answers
                foreach ($rows as $row) {
                    //save each answer
                    $answerDisplay = '';
                    if ($row['inputType'] == 'checkboxlist') {
                        $answer = [];
                        $answerLong = '';
                        $keys = array_keys($_POST);
                        foreach ($keys as $key) {
                            if (strpos($key, 'Question' . $row['msqID'] . '_') === 0) {
                                $answer[] = $txt->sanitize($_POST[$key]);
                            }
                        }
                    } elseif ($row['inputType'] == 'text') {
                        $answerLong = $txt->sanitize($_POST['Question' . $row['msqID']]);
                        $answer = '';
                    } elseif ($row['inputType'] == 'fileupload') {
                        $answerLong = '';
                        $answer = intval($tmpFileIds[intval($row['msqID'])]);
                        if ($answer > 0) {
                            $answerDisplay = File::getByID($answer)->getVersion()->getDownloadURL();
                        } else {
                            $answerDisplay = t('No file specified');
                        }
                    } else if ($row['inputType'] == 'datetime') {

                        $formPage = $this->getCollectionObject();
                        $answer = $txt->sanitize($_POST['Question' . $row['msqID']]);
                        if ($formPage) {
                            $site = $formPage->getSite();
                            $timezone = $site->getTimezone();
                            $date = $this->app->make('date');
                            $answerDisplay = $date->formatDateTime($txt->sanitize($_POST['Question' . $row['msqID']]), false, false, $timezone);
                        } else {
                            $answerDisplay = $txt->sanitize($_POST['Question' . $row['msqID']]);
                        }

                    } elseif ($row['inputType'] == 'url') {
                        $answerLong = '';
                        $answer = $txt->sanitize($_POST['Question' . $row['msqID']]);
                    } elseif ($row['inputType'] == 'email') {
                        $answerLong = '';
                        $answer = $txt->sanitize($_POST['Question' . $row['msqID']]);
                        if (!empty($row['options'])) {
                            $settings = unserialize($row['options']);
                            if (is_array($settings) && array_key_exists('send_notification_from', $settings) && $settings['send_notification_from'] == 1) {
                                $email = $txt->email($answer);
                                if (!empty($email)) {
                                    $replyToEmailAddress = $email;
                                }
                            }
                        }
                    } elseif ($row['inputType'] == 'telephone') {
                        $answerLong = '';
                        $answer = $txt->sanitize($_POST['Question' . $row['msqID']]);
                    } elseif ($row['inputType'] == 'address') {
                        $answerLong = '';
                        $answer = $_POST['Question' . $row['msqID'] . '_post'] . " " . $_POST['Question' . $row['msqID'] . '_addr'];
                    } else {
                        $answerLong = '';
                        $answer = $txt->sanitize($_POST['Question' . $row['msqID']]);
                    }

                    if (is_array($answer)) {
                        $answer = implode(',', $answer);
                    }

                    $questionAnswerPairs[$row['msqID']]['question'] = $row['question'];
                    $questionAnswerPairs[$row['msqID']]['answer'] = $txt->sanitize($answer . $answerLong);
                    $questionAnswerPairs[$row['msqID']]['answerDisplay'] = strlen($answerDisplay) ? $answerDisplay : $questionAnswerPairs[$row['msqID']]['answer'];

                    $v = [$row['msqID'], $answerSetID, $answer, $answerLong];
                    $q = "insert into {$this->btAnswersTablename} (msqID,asID,answer,answerLong) values (?,?,?,?)";
                    $db->query($q, $v);
                }
                $foundSpam = false;

                $submittedData = '';
                foreach ($questionAnswerPairs as $questionAnswerPair) {
                    $submittedData .= $questionAnswerPair['question'] . "\r\n" . $questionAnswerPair['answer'] . "\r\n" . "\r\n";
                }
                $antispam = Core::make('helper/validation/antispam');
                if (!$antispam->check($submittedData, 'form_block')) {
                    // found to be spam. We remove it
                    $foundSpam = true;
                    $q = "delete from {$this->btAnswerSetTablename} where asID = ?";
                    $v = [$this->lastAnswerSetId];
                    $db->Execute($q, $v);
                    $db->Execute("delete from {$this->btAnswersTablename} where asID = ?", [$this->lastAnswerSetId]);
                }

                if (intval($this->notifyMeOnSubmission) > 0 && !$foundSpam) {
                    if (Config::get('concrete.email.form_block.address') && strstr(Config::get('concrete.email.form_block.address'), '@')) {
                        $formFormEmailAddress = Config::get('concrete.email.form_block.address');
                    } else {
                        $adminUserInfo = UserInfo::getByID(USER_SUPER_ID);
                        $formFormEmailAddress = $adminUserInfo->getUserEmail();
                    }
                    //管理者へメール
                    $mh = Core::make('helper/mail');
                    $mh->to($this->recipientEmail);
                    $mh->from($formFormEmailAddress);
                    $mh->replyto($replyToEmailAddress);
                    $mh->addParameter('formName', $this->surveyName);
                    $mh->addParameter('questionSetId', $this->questionSetId);
                    $mh->addParameter('questionAnswerPairs', $questionAnswerPairs);
                    $mh->load('block_form_submission');
                    @$mh->sendMail();

                    //投稿者へメール
                    $c = new Page();
                    $q = "select created from {$this->btAnswerSetTablename} where asID = ?";
                    $v = $this->lastAnswerSetId;
                    $created_date = $db->GetOne($q,[$v]);
                    $submitQRcord = $c->getCollectionID() . "," . $c->getCollectionName() . "," . $this->surveyName . "," . $this->lastAnswerSetId . "," . $created_date;
                    $mh1 = Core::make('helper/mail');
                    $mh1->to($replyToEmailAddress);
                    $mh1->from($formFormEmailAddress);
                    $mh1->addParameter('formName', $this->surveyName);
                    $mh1->addParameter('questionSetId', $this->questionSetId);
                    $mh1->addParameter('questionAnswerPairs', $questionAnswerPairs);
                    $mh1->addParameter('submitQRcord', $submitQRcord);
                    $mh1->load('block_form_contributor');
                    //echo $mh->body.'<br>';
                    @$mh1->sendMail();
                }

                //launch form submission event with dispatch method
                $formEventData = [];
                $formEventData['bID'] = intval($this->bID);
                $formEventData['questionSetID'] = $this->questionSetId;
                $formEventData['replyToEmailAddress'] = $replyToEmailAddress;
                $formEventData['formFormEmailAddress'] = $formFormEmailAddress;
                $formEventData['questionAnswerPairs'] = $questionAnswerPairs;
                $event = new \Symfony\Component\EventDispatcher\GenericEvent();
                $event->setArgument('formData', $formEventData);
                Events::dispatch('on_form_submission', $event);

                if (!$this->noSubmitFormRedirect) {
                    $targetPage = null;
                    if ($this->redirectCID > 0) {
                        $pg = Page::getByID($this->redirectCID);
                        if (is_object($pg) && $pg->cID) {
                            $targetPage = $pg;
                        }
                    }
                    if (is_object($targetPage)) {
                        $response = \Redirect::page($targetPage);
                    } else {
                        $response = \Redirect::page(Page::getCurrentPage());
                        $url = $response->getTargetUrl() . '?surveySuccess=1&qsid=' . $this->questionSetId . '#formblock' . $this->bID;
                        $response->setTargetUrl($url);
                    }
                    $response->send();
                    exit;
                }
            }

        }else{
            $this->view();
        }
    }
    public function action_confirm_form($bID = false)
    {
        if ($this->bID != $bID) {
            return false;
        }

        $ip = Core::make('helper/validation/ip');
        $this->view();

        if ($ip->isBlacklisted()) {
            $this->set('invalidIP', $ip->getErrorMessage());

            return;
        }

        $txt = Core::make('helper/text');
        $db = Database::connection();

        //question set id
        $qsID = intval($_POST['qsID']);
        if ($qsID == 0) {
            throw new Exception(t("Oops, something is wrong with the form you posted (it doesn't have a question set id)."));
        }
        $errors = [];

        $token = Core::make('token');
        if (!$token->validate('form_block_submit_qs_' . $qsID)) {
            $errors[] = $token->getErrorMessage();
        }

        //get all questions for this question set
        $rows = $db->GetArray("SELECT * FROM {$this->btQuestionsTablename} WHERE questionSetId=? AND bID=? order by position asc, msqID", [$qsID, intval($this->bID)]);

        if (!count($rows)) {
            throw new Exception(t("Oops, something is wrong with the form you posted (it doesn't have any questions)."));
        }

        $errorDetails = [];

        // check captcha if activated
        if ($this->displayCaptcha) {
            $captcha = Core::make('helper/validation/captcha');
            if (!$captcha->check()) {
                $errors['captcha'] = t('Incorrect captcha code');
                $_REQUEST['ccmCaptchaCode'] = '';
            }
        }

        //checked required fields
        foreach ($rows as $row) {
            if ($row['inputType'] == 'datetime') {
                if (!isset($datetime)) {
                    $datetime = Core::make('helper/form/date_time');
                }
                $translated = $datetime->translate('Question' . $row['msqID']);
                if ($translated) {
                    $_POST['Question' . $row['msqID']] = $translated;
                }
            }
            if (intval($row['required']) == 1) {
                $notCompleted = 0;
                if ($row['inputType'] == 'email') {
                    if (!Core::make('helper/validation/strings')->email($_POST['Question' . $row['msqID']])) {
                        $errors['emails'] = t('You must enter a valid email address.');
                        $errorDetails[$row['msqID']]['emails'] = $errors['emails'];
                    }
                }
                if ($row['inputType'] == 'checkboxlist') {
                    $answerFound = 0;
                    foreach ($_POST as $key => $val) {
                        if (strstr($key, 'Question' . $row['msqID'] . '_') && strlen($val)) {
                            $answerFound = 1;
                        }
                    }
                    if (!$answerFound) {
                        $notCompleted = 1;
                    }
                } elseif ($row['inputType'] == 'fileupload') {
                    if (!isset($_FILES['Question' . $row['msqID']]) || !is_uploaded_file($_FILES['Question' . $row['msqID']]['tmp_name'])) {
                        $notCompleted = 1;
                    }
                } elseif ($row['inputType'] == 'address') {
                    if(strlen($_POST['Question' . $row['msqID'] . '_post']) == 0 || strlen($_POST['Question' . $row['msqID'] . '_addr']) == 0 ){
                        $notCompleted = 1;
                    }
                } elseif (!strlen(trim($_POST['Question' . $row['msqID']]))) {
                    $notCompleted = 1;
                }
                if ($notCompleted) {
                    $errors['CompleteRequired'] = t('Complete required fields *');
                    $errorDetails[$row['msqID']]['CompleteRequired'] = $errors['CompleteRequired'];
                }
            }
        }

        //try importing the file if everything else went ok
        $tmpFileIds = [];
        if (!count($errors)) {
            $this->set('form_mode','confirm');
        }

        if (count($errors)) {
            $this->set('formResponse', t('Please correct the following errors:'));
            $this->set('errors', $errors);
            $this->set('errorDetails', $errorDetails);
            $this->set('form_mode','');
        }
    }
}
