<?php
/************************************************************
 * DESIGNERS: SCROLL DOWN! (IGNORE ALL THIS STUFF AT THE TOP)
 ************************************************************/
defined('C5_EXECUTE') or die("Access Denied.");

$survey = $controller;
$miniSurvey = new \Application\Block\Form\MiniSurvey($b);
$miniSurvey->frontEndMode = true;
//Clean up variables from controller so html is easier to work with...
$bID = intval($bID);
$qsID = intval($survey->questionSetId);
if($form_mode == 'confirm'){
	$formAction = $view->action('submit_form').'#formblock'.$bID;
}else{
	$formAction = $view->action('confirm_form').'#formblock'.$bID;
}
$questionsRS = $miniSurvey->loadQuestions($qsID, $bID);
$questions = [];
$isAddress = false;
while ($questionRow = $questionsRS->fetchRow()) {
    $question = $questionRow;
    if($form_mode == 'confirm'){
	    $question['input'] = $miniSurvey->loadInputTypeConfirm($questionRow, false);
    }else{
	    $question['input'] = $miniSurvey->loadInputType($questionRow, false);
    }
    //Make type names common-sensical
    if ($questionRow['inputType'] == 'text') {
        $question['type'] = 'textarea';
    } elseif ($questionRow['inputType'] == 'field') {
        $question['type'] = 'text';
    } elseif ($questionRow['inputType'] == 'address') {
        $isAddress = true;
    } else {
        $question['type'] = $questionRow['inputType'];
    }

    $question['labelFor'] = 'for="Question' . $questionRow['msqID'] . '"';

    //Remove hardcoded style on textareas
    if ($question['type'] == 'textarea') {
        $question['input'] = str_replace('style="width:95%"', '', $question['input']);
    }

    $questions[] = $question;
}

if($isAddress) { ?>
    <script src="//jpostal-1006.appspot.com/jquery.jpostal.js"></script>
<?php } 

//Prep thank-you message
$success = (\Request::request('surveySuccess') && \Request::request('qsid') == intval($qsID));
$thanksMsg = $survey->thankyouMsg;

//Collate all errors and put them into divs
$errorHeader = isset($formResponse) ? $formResponse : null;
$errors = isset($errors) && is_array($errors) ? $errors : [];
if (isset($invalidIP) && $invalidIP) {
    $errors[] = $invalidIP;
}
$errorDivs = '';
foreach ($errors as $error) {
    $errorDivs .= '<div class="error">'.$error."</div>\n"; //It's okay for this one thing to have the html here -- it can be identified in CSS via parent wrapper div (e.g. '.formblock .error')
}

//Prep captcha
$surveyBlockInfo = $miniSurvey->getMiniSurveyBlockInfoByQuestionId($qsID, $bID);
$captcha = $surveyBlockInfo['displayCaptcha'] ? Loader::helper('validation/captcha') : false;

/******************************************************************************
* DESIGNERS: CUSTOMIZE THE FORM HTML STARTING HERE...
*/?>

<div id="formblock<?php  echo $bID; ?>" class="ccm-block-type-form">
<form enctype="multipart/form-data" class="form-stacked miniSurveyView" id="miniSurveyView<?php  echo $bID; ?>" method="post" action="<?php  echo $formAction ?>">
    <?=Core::make('token')->output('form_block_submit_qs_'.$qsID);?>
    <?php if ($form_mode == 'confirm') { ?>
    	<input type="hidden" name="form_mode" value="confirm">
    <?php } ?>
	<?php  if ($success): ?>

		<div class="alert alert-success">
			<?php  echo h($thanksMsg); ?>
		</div>

	<?php  elseif ($errors): ?>

		<div class="alert alert-danger">
			<?php  echo $errorHeader; ?>
			<?php  echo $errorDivs; /* each error wrapped in <div class="error">...</div> */ ?>
		</div>

	<?php  endif; ?>


	<div class="fields">

		<?php  foreach ($questions as $question): ?>
			<div class="form-group field field-<?php  echo $question['type']; ?> <?php echo isset($errorDetails[$question['msqID']]) ? 'has-error' : ''?>">
				<label class="control-label" <?php  echo $question['labelFor']; ?>>
					<?php  echo $question['question']; ?>
                    <?php if ($question['required'] && $form_mode != 'confirm'): ?>
                        <span class="text-muted small" style="font-weight: normal"><?=t("Required")?></span>
                    <?php  endif; ?>
				</label>
				<?php  echo $question['input']; ?>
			</div>
		<?php  endforeach; ?>

	</div><!-- .fields -->
	<?php  if ($captcha && $form_mode != 'confirm'): ?>
		<div class="form-group captcha">
			<?php
            $captchaLabel = $captcha->label();
            if (!empty($captchaLabel)) {
                ?>
				<label class="control-label"><?php echo $captchaLabel; ?></label>
				<?php

            }
            ?>
			<div><?php  $captcha->display(); ?></div>
			<div><?php  $captcha->showInput(); ?></div>
		</div>
	<?php  endif; ?>

	<div class="form-actions">
		<?php if($form_mode == 'confirm'){ ?>
			<button type="submit" name="Submit" class="btn btn-default" value="back"><?php echo t('Back');?></button>
			<button type="submit" name="Submit" class="btn btn-primary" value="success"><?php echo h(t($survey->submitText)); ?></button>
		<?php }else{ ?>
			<button type="submit" name="Submit" class="btn btn-primary" value="success"><?php  echo t('Confirm'); ?></button>
		<?php } ?>

<!--		<input type="submit" name="Submit" class="btn btn-primary" value="<?php  echo h(t($survey->submitText)); ?>" /> -->
	</div>

	<input name="qsID" type="hidden" value="<?php  echo $qsID; ?>" />
	<input name="pURI" type="hidden" value="<?php  echo isset($pURI) ? $pURI : ''; ?>" />

</form>
</div><!-- .formblock -->
