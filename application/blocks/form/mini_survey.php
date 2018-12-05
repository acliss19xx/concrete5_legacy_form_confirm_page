<?php
namespace Application\Block\Form;

use Core;
use Database;
use Request;

class MiniSurvey extends \Concrete\Block\Form\MiniSurvey
{

    public function loadInputTypeConfirm($questionData, $showEdit)
    {
        $options = explode('%%', $questionData['options']);
        $defaultDate = $questionData['defaultDate'];
        $msqID = intval($questionData['msqID']);
        $datetime = Core::make('helper/form/date_time');
        $html = '';
        switch ($questionData['inputType']) {
            case 'checkboxlist':
                for ($i = 0; $i < count($options); ++$i) {
                    if (strlen(trim($options[$i])) == 0) {
                        continue;
                    }
                    if(Request::request('Question'.$msqID.'_'.$i) == trim($options[$i])){
                        $html .= '<span class="confirm">' . stripslashes(htmlspecialchars(trim($options[$i]))) . '</span><input name="Question'.$msqID.'_'.$i . '" id="Question'.$msqID.'" type="hidden" value="' .stripslashes(htmlspecialchars(trim($options[$i]))). '"><br/>';
                    }
                }
                //}
                return $html;

            case 'select':
                foreach ($options as $option) {
                    if (Request::request('Question'.$msqID) == trim($option)) {
                        $html .= '<span class="confirm">' . stripslashes(htmlspecialchars(trim($option))) . '</span><input name="Question'.$msqID.'" id="Question'.$msqID.'" type="hidden" value="' .stripslashes(htmlspecialchars(trim($option))). '">';
                    }
                }
                return $html;

            case 'radios':
                foreach ($options as $option) {
                    if (Request::request('Question'.$msqID) == trim($option)) {
                        $html .= '<span class="confirm">' . stripslashes(htmlspecialchars(trim($option))) . '</span><input name="Question'.$msqID.'" id="Question'.$msqID.'" type="hidden" value="' .stripslashes(htmlspecialchars(trim($option))). '">';
                    }
                }

                return $html;

            case 'fileupload':
                $html = '<input type="file" name="Question'.$msqID.'" class="form-control" id="Question'.$msqID.'" />';

                return $html;

            case 'date':
                $val = (Request::request('Question'.$msqID)) ? Request::request('Question'.$msqID) : $defaultDate;

                return '<span class="confirm">' . stripslashes(htmlspecialchars($val)) . '</span><input name="Question'.$msqID.'" id="Question'.$msqID.'" type="hidden" value="' .stripslashes(htmlspecialchars($val)). '">';
//                return $datetime->date('Question'.$msqID, $val);
            case 'datetime':
                $val = Request::request('Question'.$msqID);
                if (!isset($val)) {
                    if (
                        Request::request('Question'.$msqID.'_dt') && Request::request('Question'.$msqID.'_h')
                        && Request::request('Question'.$msqID.'_m') && Request::request('Question'.$msqID.'_a')
                    ) {
                        $val = Request::request('Question'.$msqID.'_dt') . ' ' . Request::request('Question'.$msqID.'_h')
                            . ':' . Request::request('Question'.$msqID.'_m') . ' ' . Request::request('Question'.$msqID.'_a');
                    } else {
                        $val = $defaultDate;
                    }
                }
                return '<span class="confirm">' . $val . '</span><div style="display:none">' . $datetime->datetime('Question'.$msqID, $val,false,true,'invisible') . '</div>';
            case 'field':
            default:
                $val = (Request::request('Question'.$msqID)) ? Request::request('Question'.$msqID) : '';
                return '<span class="confirm">' . nl2br(stripslashes(htmlspecialchars($val))) . '</span><input name="Question'.$msqID.'" id="Question'.$msqID.'" type="hidden" value="' .stripslashes(htmlspecialchars($val)). '">';
        }
    }
}
