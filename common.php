<?php
#require 'libphp-phpmailer/PHPMailerAutoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once "vendor/autoload.php";

require_once('./configuration.php');

/**
 * Used by the index.php view to inform either the Admins or the User for the action taken
 * @param string $userType   User to receive the email
 * @param string $action     Action performed by the user
 * @param array $couNames    List of COU name affected by action
 * @param array $emailList   List of To recipients
 */
function sendUserPostActionEmail($userType, $action, $couNames=array(), $emailList=array()) {
    $mail = new PHPMailer(true);
    try {
        // Server settings
        // $mail->SMTPDebug = 2;
        // $mail->Debugoutput = function($str, $level) {echo "debug level $level; message: $str";};
        $mail->isSMTP();
        $mail->Host = $GLOBALS['email_host'];
        $mail->SMTPSecure = $GLOBALS['email_security'];
        $mail->Port = $GLOBALS['email_port'];

        if(strlen($GLOBALS['email_username']) > 0) {
            $mail->SMTPAuth = true;
            $mail->Username = $GLOBALS['email_username'];
            $mail->Password = $GLOBALS['email_password'];
        }

        // From Recipient
        $mail->setFrom($GLOBALS['email_from_address'], $GLOBALS['email_from_name']);
        // CC-ed Recipients
        foreach($GLOBALS['ccEmails'] as $user) {
            $mail->addCC($user['email'], $user['name']);
        }
        // To Recipients
        foreach($emailList as $mailAddr) {
            $subjectEmail = explode('@', $mailAddr)[0];
            $mail->addAddress($mailAddr, $subjectEmail);
        }
        $couList = !empty($couNames) ? implode(', ', $couNames) : '';
        //Content
        $mail->isHTML(true);
        $mail->Subject = $GLOBALS['email_subject'];
        if($userType === 'manager' && $action === 'apply') {
            $mail->Body = 'Request for ' . $_SERVER[$GLOBALS['username_key']] . ' changed status from '
                . 'Created to Pending Approval (Request additional role(s)) <br />'
                . 'Role list: ' . $couList . ' <br /> <br />'
                . '<a href="' . $GLOBALS['email_admin_url'] . '">Role Management Portal</a>';
            $mail->AltBody = 'Request for ' . $_SERVER[$GLOBALS['username_key']] . ' changed status from '
                . 'Created to Pending Approval (Request additional role(s)). '
                . 'Role list: ' . $couList . ' <br /> <br />'
                . '' . $GLOBALS['email_admin_url'] . '';
        } elseif($userType === 'manager' && $action === 'remove') {
            $mail->Body = 'Request for ' . $_SERVER[$GLOBALS['username_key']] . ' to remove role <b>' . $couList . '</b>. '
                . 'Created to Pending Approval (Request removal of role) <br /> <br />'
                . '<a href="' . $GLOBALS['email_admin_url'] . '">Role Management Portal</a>';
            $mail->AltBody = 'Request for ' . $_SERVER[$GLOBALS['username_key']] . ' to remove role ' . $couList . '. '
                . 'Created to Pending Approval (Request removal of role). '
                . '' . $GLOBALS['email_admin_url'] . '';
        } elseif ($userType === 'member') {
            $mail->Body    = 'Your request to remove role '.$couList.' has been received.';
            $mail->AltBody    = 'Your request to remove role '.$couList.' has been has been received.';
        }
        $mail->send();
    } catch (Exception $e) {
        echo 'Message to cancel role could not be sent. <br />';
        echo 'Mailer Error: ' . $mail->ErrorInfo;
        die($mail->ErrorInfo);
    }
}

/**
 * Used by Admin user to inform the user for the outcome of his/her request
 * @param string $email     The email of the user
 * @param string $role      The COU name
 * @param string $action    The action performed by the Administrator
 * @param string $mailBody  The message emailed to the user
 */
function sendAdminPostActionEmail($email, $role, $action, $mailBody='') {
    $mail = new PHPMailer(true);
    try {
        // Server settings
        // $mail->SMTPDebug = 2;
        // $mail->Debugoutput = function($str, $level) {echo "debug level $level; message: $str";};
        $mail->isSMTP();
        $mail->Host = $GLOBALS['email_host'];
        $mail->SMTPSecure = $GLOBALS['email_security'];
        $mail->Port = $GLOBALS['email_port'];

        if(strlen($GLOBALS['email_username']) > 0) {
            $mail->SMTPAuth = true;
            $mail->Username = $GLOBALS['email_username'];
            $mail->Password = $GLOBALS['email_password'];
        }

        $emailSubject = explode('@', $email)[0];
        //Recipients
        $mail->addAddress($email, $emailSubject);
        $mail->setFrom($GLOBALS['email_from_address'], $GLOBALS['email_from_name']);
        foreach($GLOBALS['ccEmails'] as $user) {
            $mail->addCC($user['email'], $user['name']);
        }

        //Content
        $mail->isHTML(true);
        $mail->Subject = $GLOBALS['email_subject'];
        if(empty($mailBody)) {
            $mail->Body = 'Your request for role ' . $role . ' has been ' . $action;
            $mail->AltBody = 'Your request for role ' . $role . ' has been ' . $action;
        } else {
            $mail->Body = $mailBody;
            $mail->AltBody = $mailBody;
        }
        $mail->send();
    } catch (Exception $e) {
        echo 'Message to user could not be sent.';
        echo 'Mailer Error: ' . $mail->ErrorInfo;
        die($mail->ErrorInfo);
    }
}
